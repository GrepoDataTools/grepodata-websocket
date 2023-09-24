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
  protected $verbose = false;

  public function __construct(LoopInterface $loop) {
    try {
      $this->loop = $loop;
      $this->startup_time = time();
      $this->redis_heartbeat = time();

      $this->clients = new \SplObjectStorage;
      $this->teams = array();
      $this->users = array();

      # Add periodic status check
      $timer = $loop->addPeriodicTimer(15, [$this, 'checkStatus']);

      $this->log("WebSocket Server Online");
    } catch (\Exception $e) {
      Pushbullet::SendPushMessage("CRITICAL: WebSocket startup failure: ".$e->getMessage() . " [".$e->getTraceAsString()."]");
    }
  }

  /**
   * Check self status and monitor backbone health. This function is called at a periodic interval.
   */
  public function checkStatus() {
    $NowUnix = time();
    $Uptime = $NowUnix - $this->startup_time;
    $NumConnections = count($this->clients);
    $TimeSinceHeartbeat = $NowUnix - $this->redis_heartbeat;
    if ($Uptime > 3600*24) {
      $Uptime = floor($Uptime/(3600*24)) . ' days';
    } elseif ($Uptime > 3600) {
      $Uptime = floor($Uptime/3600) . ' hours';
    } else {
      $Uptime = $Uptime . ' seconds';
    }
    $this->log("Uptime: {$Uptime}, Connections: {$NumConnections}, Time since heartbeat: {$TimeSinceHeartbeat}");

    if (!bDevelopmentMode && $TimeSinceHeartbeat > (REDIS_BACKBONE_HEARTBEAT_INTERVAL * 2)+5) {
      $this->log("Missed 2+ backbone heartbeats. Restarting..");
      Pushbullet::SendPushMessage("CRITICAL: WebSocket missed 2+ backbone heartbeats. Restarting..");
      die();
    }
  }

  /**
   * Handles push messages from the backend received via the Redis PubSub backbone.
   * @param $channel
   * @param $payload
   */
  public function onPush($channel, $payload) {
    try {
      if ($channel === REDIS_BACKBONE_CHANNEL) {
        // pubsub message received on backbone
        $aPayload = json_decode($payload, true);
        $num_receivers = 0;

        if (key_exists('type', $aPayload)) {
          switch ($aPayload['type']) {
            case 'redis_heartbeat':
              // Update redis_heartbeat; backbone listener is still alive!
              $this->redis_heartbeat = time();
              break;
            case 'notify_user':
              // Send a notification to a specific user
              if (key_exists($aPayload['user_id'], $this->users)) {
                foreach ($this->users[$aPayload['user_id']] as $client) {
                  $sent = $this->_send($client, $payload);
                  if ($sent) {
                    $num_receivers++;
                  }
                }
              }
              break;
            case 'notify_team':
              // Send a notification to everybody that is subscribed to the team
              if (key_exists($aPayload['team'], $this->teams)) {
                foreach ($this->teams[$aPayload['team']] as $client) {
                  $sent = $this->_send($client, $payload);
                  if ($sent) {
                    $num_receivers++;
                  }
                }
              }
              break;
            default:
              $this->log("Unknown message type received on backbone: {$aPayload['type']}");
          }
        }

        $this->log("Message received on backbone. Receivers: " . $num_receivers . ", Payload: " . $payload);
      } else{
        $this->log("Message received on illegal channel: ". $channel);
      }
    } catch (\Exception $e) {
      $this->log("Error pushing backbone message: " . $e->getMessage() . ' [' . $e->getTraceAsString() . ']');
    }
  }

  /**
   * Handles messages received from connected clients.
   * Used exclusively by clients to send authentication requests to upgrade their connection.
   * If authentication is successful, the client is subscribed to all relevant topics
   * @param ConnectionInterface $conn
   * @param string $msg
   */
  public function onMessage(ConnectionInterface $conn, $msg) {
    try {
      if (property_exists($conn, 'authenticated') && $conn->authenticated === true) {
        $this->log("Client is already authenticated ({$conn->resourceId})");
        return;
      }

      $aData = json_decode($msg, true);
      if (key_exists('websocket_token', $aData)) {
        !$this->verbose ?? $this->log("Attempting client authentication ({$conn->resourceId})");

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

          // Subscribe to user & teams
          $this->_subscribe_client($conn);

          !$this->verbose ?? $this->log("Successful authentication ({$conn->resourceId})");
        });
      } else {
        // Invalid message, close connection
        $conn->close();
      }
    } catch (\Exception $e) {
      $this->log("Error authenticating client: " . $e->getMessage() . ' [' . $e->getTraceAsString() . ']');
    }
  }

  /**
   * Called when a new WebSocket connection is initiated
   * @param ConnectionInterface $conn
   */
  public function onOpen(ConnectionInterface $conn) {
    // Store the new connection to send messages to later
    $this->clients->attach($conn);

    !$this->verbose ?? $this->log("New connection ({$conn->resourceId})");
  }

  /**
   * Called when a WebSocket connection is closed
   * @param ConnectionInterface $conn
   */
  public function onClose(ConnectionInterface $conn) {
    // The connection is closed, remove it, as we can no longer send it messages
    $this->clients->detach($conn);

    // Unsubscribe from user & teams
    $this->_unsubscribe_client($conn);

    !$this->verbose ?? $this->log("Connection {$conn->resourceId} has disconnected");
  }

  /**
   * Called when a WebSocket connection raises an Exception
   * @param ConnectionInterface $conn
   * @param \Exception $e
   */
  public function onError(ConnectionInterface $conn, \Exception $e) {
    $this->log("An error has occurred: {$e->getMessage()}");

    $conn->close();
  }

  /**
   * Helper function to send a payload to a client
   * @param $client
   * @param $payload
   * @return bool
   */
  private function _send($client, $payload)
  {
    try {
      $client->send($payload);
      return true;
    } catch (\Exception $e) {
      $this->log("Error sending payload to client: " . $e->getMessage() . ' [' . $e->getTraceAsString() . ']');
    }
    return false;
  }

  /**
   * Attaches the client to all relevant user/team topics
   * @param $client
   */
  private function _subscribe_client($client)
  {
    // Add client to user_id subscription
    $user_id = $client->user_id;
    try {
      if (!key_exists($user_id, $this->users)) {
        $this->users[$user_id] = new \SplObjectStorage;
      }
      $this->users[$user_id]->attach($client);
    } catch (\Exception $e) {
      $this->log("Error subscribing client to user_id: " . $e->getMessage() . ' [' . $e->getTraceAsString() . ']');
    }

    // Add client to teams subscription
    $teams = $client->teams;
    try {
      foreach ($teams as $team) {
        if (!key_exists($team, $this->teams)) {
          $this->teams[$team] = new \SplObjectStorage;
        }
        $this->teams[$team]->attach($client);
      }
    } catch (\Exception $e) {
      $this->log("Error subscribing client to teams: " . $e->getMessage() . ' [' . $e->getTraceAsString() . ']');
    }
  }

  /**
   * Detaches the client from all relevant user/team topics
   * @param $client
   */
  private function _unsubscribe_client($client)
  {
    // detach client from user_id
    $user_id = $client->user_id;
    try {
      if (key_exists($user_id, $this->users) && !is_null($this->users[$user_id])) {
        $this->users[$user_id]->detach($client);
      }
    } catch (\Exception $e) {
      $this->log("Error unsubscribing client from user_id: " . $e->getMessage() . ' [' . $e->getTraceAsString() . ']');
    }

    // detach client from teams
    $teams = $client->teams;
    try {
      foreach ($teams as $team) {
        if (key_exists($team, $this->teams) && !is_null($this->teams[$team])) {
          $this->teams[$team]->detach($client);
        }
      }
    } catch (\Exception $e) {
      $this->log("Error unsubscribing client from teams: " . $e->getMessage() . ' [' . $e->getTraceAsString() . ']');
    }
  }

  /**
   * Helper function for internal log messages
   * @param $Message
   */
  private function log($Message) {
    $CurrentTime = date('Y-m-d H:i:s');
    echo "[{$CurrentTime}] {$Message}\n";
  }

}
