<?php
namespace Grepodata\Library\Ratchet;
use Grepodata\Library\Logger\Pushbullet;
use Grepodata\Library\Redis\RedisClient;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use React\EventLoop\LoopInterface;


class Notification implements MessageComponentInterface {
  protected $clients;
  protected $startup_time;
  protected $redis_heartbeat;

  public function __construct(LoopInterface $loop) {
    try {
      $this->startup_time = time();
      $this->redis_heartbeat = time();

      $this->clients = new \SplObjectStorage;

      # Add periodic status check
      $timer = $loop->addPeriodicTimer(5, [$this, 'checkStatus']);

      echo "finished setup\n";
    } catch (\Exception $e) {
      Pushbullet::SendPushMessage("CRITICAL: WebSocket startup failure: ".$e->getMessage() . " [".$e->getTraceAsString()."]");
    }
  }

  public function checkStatus() {
    $CurrentTime = date('Y-m-d h:i:s');
    $NowUnix = time();
    $Uptime = $NowUnix - $this->startup_time;
    $NumConnections = count($this->clients);
    $TimeSinceHeartbeat = $NowUnix - $this->redis_heartbeat;
    echo "[{$CurrentTime}] Uptime: {$Uptime}, Connections: {$NumConnections}, Time since heartbeat: {$TimeSinceHeartbeat}\n";

    if (!bDevelopmentMode && $TimeSinceHeartbeat > (REDIS_BACKBONE_HEARTBEAT_INTERVAL * 2)+5) {
      // We missed 2 or more heartbeats; time for a restart!
      echo "Missed 2+ backbone heartbeats. Restarting..\n";
      Pushbullet::SendPushMessage("CRITICAL: WebSocket missed 2+ backbone heartbeats. Restarting..");
      $this->gracefulRestart();
    }
  }

  public function gracefulRestart() {
    // Let all clients know that server is restarting
    $RestartPayload = json_encode(array(
      'action' => 'graceful_restart'
    ));
    foreach ($this->clients as $client) {
      $client->send($RestartPayload);
      $client->close();
    }
    die();
  }

  public function onPush($channel, $payload) {
    if ($channel === REDIS_BACKBONE_CHANNEL) {
      // pubsub message received on backbone
      echo "message received on backbone: " . $payload . "\n";

      $aPayload = json_decode($payload, true);

      if (key_exists('type', $aPayload)) {
        switch ($aPayload['type']) {
          case 'redis_heartbeat':
            // Update redis_heartbeat; backbone listener is still alive!
            $this->redis_heartbeat = time();
            break;
          case 'notify_user':
            // Send a notification to a specific user
            foreach ($this->clients as $client) {
              if (!empty($client->user_id) && $aPayload['user_id'] === $client->user_id) {
                $client->send($payload);
              }
            }
            break;
          case 'notify_team':
            // Send a notification to everybody that is subscribed to the team
            foreach ($this->clients as $client) {
              if (!empty($client->teams) && in_array($aPayload['team'], $client->teams)) {
                $client->send($payload);
              }
            }
            break;
          default:
            echo "Unknown message type received on backbone: {$aPayload['type']}\n";
        }
      }
    } else{
      echo "Message received on illegal channel: ". $channel ."\n";
    }
  }

  public function onOpen(ConnectionInterface $conn) {
    // Store the new connection to send messages to later
    $this->clients->attach($conn);

    echo "New connection ({$conn->resourceId})\n";
  }

  public function onMessage(ConnectionInterface $conn, $msg) {
    try {
      if (property_exists($conn, 'authenticated') && $conn->authenticated === true) {
        echo "Client is already authenticated ({$conn->resourceId})\n";
        return;
      }

      $aData = json_decode($msg, true);
      if (key_exists('websocket_token', $aData)) {
        echo "Attempting client authentication ({$conn->resourceId})\n";

        RedisClient::get($aData['websocket_token'], function ($payload) use ($conn) {
          if (empty($payload)) {
            // Unable to find wst
            // TODO: let client know auth was unsuccessful and let them retry
            echo "Auth error: unknown wst ({$conn->resourceId})\n";
            $conn->close();
            return;
          }

          $aPayload = json_decode($payload, true);

          if ($conn->remoteAddress != $aPayload['client']) {
            // Client mismatch, close connection
            // TODO: let client know auth was unsuccessful and let them retry
            echo "Auth error: illegal client {$conn->remoteAddress} != {$aPayload['client']} ({$conn->resourceId})\n";
            $conn->close();
            return;
          }

          // Auth was successful, add user info to connection
          $conn->authenticated = true;
          $conn->user_id = $aPayload['user_id'];
          $conn->teams = $aPayload['teams'];
          echo "Successful authentication ({$conn->resourceId})\n";
        });
      } else {
        // Invalid message, close connection
        $conn->close();
      }
    } catch (\Exception $e) {
      echo "Error authenticating client: " . $e->getMessage() . ' [' . $e->getTraceAsString() . ']';
    }
  }

  public function onClose(ConnectionInterface $conn) {
    // The connection is closed, remove it, as we can no longer send it messages
    $this->clients->detach($conn);

    echo "Connection {$conn->resourceId} has disconnected\n";
  }

  public function onError(ConnectionInterface $conn, \Exception $e) {
    echo "An error has occurred: {$e->getMessage()}\n";

    $conn->close();
  }
}
