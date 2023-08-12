<?php
namespace Grepodata\Library\Ratchet;
use Grepodata\Library\Logger\Pushbullet;
use Grepodata\Library\Redis\RedisClient;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;


class Notification implements MessageComponentInterface {
  protected $clients;
  protected $redis;

  public function __construct() {
    try {
      $this->clients = new \SplObjectStorage;

      echo "finished setup\n";
    } catch (\Exception $e) {
      Pushbullet::SendPushMessage("CRITICAL: WebSocket startup failure: ".$e->getMessage() . " [".$e->getTraceAsString()."]");
    }
  }

  public function onPush($channel, $payload) {
    // pubsub message received on given $channel
    echo "message received on backbone: ". $payload ."\n";
  }

  public function onOpen(ConnectionInterface $conn) {
    // Store the new connection to send messages to later
    $this->clients->attach($conn);

    echo "New connection ({$conn->resourceId})\n";
  }

  public function onMessage(ConnectionInterface $conn, $msg) {
    try {
      $aData = json_decode($msg, true);
      if (key_exists('websocket_token', $aData)) {
        echo "Attempting client authentication ({$conn->resourceId})\n";

        RedisClient::get($aData['websocket_token'], function ($payload) use ($conn) {
          if (empty($payload)) {
            // Unable to find wst
            echo "Auth error: unknown wst ({$conn->resourceId})\n";
            $conn->close();
          }

          $aPayload = json_decode($payload, true);

          if ($conn->remoteAddress != $aPayload['client']) {
            // Client mismatch, close connection
            echo "Auth error: illegal client {$conn->remoteAddress} != {$aPayload['client']} ({$conn->resourceId})\n";
            $conn->close();
          }

          // Auth was successful, add user info to connection
          $conn->user_id = $aPayload['user_id'];
          $conn->teams = $aPayload['teams'];
          echo "Successful authentication ({$conn->resourceId})\n";
        });

      }
    } catch (\Exception $e) {
      echo "Error authenticating client: " . $e->getMessage() . ' [' . $e->getTraceAsString() . ']';
    }

    // Invalid message, close connection
    //$conn->close();
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
