<?php
namespace Grepodata\Library\Ratchet;
use Grepodata\Library\Logger\Pushbullet;
use Grepodata\Library\Redis\RedisClient;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use React\EventLoop\LoopInterface;


class Notification implements MessageComponentInterface {
  protected $loop;
  protected $clients;
  protected $startup_time;
  protected $redis_heartbeat;

  public function __construct(LoopInterface $loop) {
    try {
      $this->loop = $loop;
      $this->startup_time = time();
      $this->redis_heartbeat = time();

      $this->clients = new \SplObjectStorage;

      # Add periodic status check
      $timer = $loop->addPeriodicTimer(30, [$this, 'checkStatus']);

      $this->log("WebSocket Server Online");
    } catch (\Exception $e) {
      Pushbullet::SendPushMessage("CRITICAL: WebSocket startup failure: ".$e->getMessage() . " [".$e->getTraceAsString()."]");
    }
  }

  private function log($Message) {
    $CurrentTime = date('Y-m-d H:i:s');
    echo "[{$CurrentTime}] {$Message}\n";
  }

  public function checkStatus() {
    $NowUnix = time();
    $Uptime = $NowUnix - $this->startup_time;
    $NumConnections = count($this->clients);
    $TimeSinceHeartbeat = $NowUnix - $this->redis_heartbeat;
    $this->log("Uptime: {$Uptime}, Connections: {$NumConnections}, Time since heartbeat: {$TimeSinceHeartbeat}");

    if (!bDevelopmentMode && $TimeSinceHeartbeat > (REDIS_BACKBONE_HEARTBEAT_INTERVAL * 2)+5) {
      $this->log("Missed 2+ backbone heartbeats. Restarting..");
      Pushbullet::SendPushMessage("CRITICAL: WebSocket missed 2+ backbone heartbeats. Restarting..");
      die();
    }
  }

  public function onPush($channel, $payload) {
    if ($channel === REDIS_BACKBONE_CHANNEL) {
      // pubsub message received on backbone
      $this->log("message received on backbone: " . $payload);

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
            $this->log("Unknown message type received on backbone: {$aPayload['type']}");
        }
      }
    } else{
      $this->log("Message received on illegal channel: ". $channel);
    }
  }

  public function onOpen(ConnectionInterface $conn) {
    // Store the new connection to send messages to later
    $this->clients->attach($conn);

    $this->log("New connection ({$conn->resourceId})");
  }

  public function onMessage(ConnectionInterface $conn, $msg) {
    try {
      if (property_exists($conn, 'authenticated') && $conn->authenticated === true) {
        $this->log("Client is already authenticated ({$conn->resourceId})");
        return;
      }

      $aData = json_decode($msg, true);
      if (key_exists('websocket_token', $aData)) {
        $this->log("Attempting client authentication ({$conn->resourceId})");

        RedisClient::get($aData['websocket_token'], function ($payload) use ($conn) {
          if (empty($payload)) {
            // Unable to find wst
            // TODO: let client know auth was unsuccessful and let them retry
            $this->log("Auth error: unknown wst ({$conn->resourceId})");
            $conn->close();
            return;
          }

          $aPayload = json_decode($payload, true);

          if ($conn->remoteAddress != $aPayload['client']) {
            // Client mismatch, close connection
            // TODO: let client know auth was unsuccessful and let them retry
            $this->log("Auth error: illegal client {$conn->remoteAddress} != {$aPayload['client']} ({$conn->resourceId})");
            $conn->close();
            return;
          }

          // Auth was successful, add user info to connection
          $conn->authenticated = true;
          $conn->user_id = $aPayload['user_id'];
          $conn->teams = $aPayload['teams'];
          $this->log("Successful authentication ({$conn->resourceId})");
        });
      } else {
        // Invalid message, close connection
        $conn->close();
      }
    } catch (\Exception $e) {
      $this->log("Error authenticating client: " . $e->getMessage() . ' [' . $e->getTraceAsString() . ']');
    }
  }

  public function onClose(ConnectionInterface $conn) {
    // The connection is closed, remove it, as we can no longer send it messages
    $this->clients->detach($conn);

    $this->log("Connection {$conn->resourceId} has disconnected");
  }

  public function onError(ConnectionInterface $conn, \Exception $e) {
    $this->log("An error has occurred: {$e->getMessage()}");

    $conn->close();
  }
}
