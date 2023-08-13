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

  public function __construct(LoopInterface $loop) {
    try {
      $this->startup_time = time();

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
    $Uptime = time() - $this->startup_time;
    $NumConnections = count($this->clients);
    echo "[{$CurrentTime}] Uptime: {$Uptime}, Connections: {$NumConnections}\n";

    // TODO: check if Redis PubSub listener is still alive (listener dies if Redis restarts); if not, we need to restart
    // 1. cronjob sends Redis heartbeat over backbone every 5 minutes
    // 2. if websocket server has not received a heartbeat for more than 5 minutes, the listener is dead
    // 3. if listener is dead, restart websocket server gracefully
  }

  public function onPush($channel, $payload) {
    if ($channel === REDIS_BACKBONE_CHANNEL) {
      // pubsub message received on backbone
      echo "message received on backbone: " . $payload . "\n";

      $aPayload = json_decode($payload, true);

      if (key_exists('type', $aPayload)) {
        switch ($aPayload['type']) {
          case 'notify_user':
            // Send a notification to a specific user
            foreach ($this->clients as $client) {
              if (!empty($client->user_id) && $aPayload['user_id'] === $client->user_id) {
                $client->send($aPayload['msg']);
              }
            }
            break;
          case 'notify_team':
            // Send a notification to everybody that is subscribed to the team
            foreach ($this->clients as $client) {
              if (!empty($client->teams) && in_array($aPayload['team'], $client->teams)) {
                $client->send($aPayload['msg']);
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
            echo "Auth error: unknown wst ({$conn->resourceId})\n";
            $conn->close();
            return;
          }

          $aPayload = json_decode($payload, true);

          if ($conn->remoteAddress != $aPayload['client']) {
            // Client mismatch, close connection
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
