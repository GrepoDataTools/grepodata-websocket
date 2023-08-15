<?php


namespace Grepodata\Library\Ratchet;


use Grepodata\Library\Redis\RedisClient;
use Ratchet\Http\HttpServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\LoopInterface;
use React\Socket\SecureServer;
use React\Socket\SocketServer;

class Server
{
  public static function setup(LoopInterface $loop)
  {
    # Define socket handler
    $oNotificationService = new Notification($loop);

    # Create redis backbone listener (messages from REST API will be transmitted over this channel)
    RedisClient::subscribe($loop, REDIS_BACKBONE_CHANNEL, array($oNotificationService, 'onPush'));

    # Create server
    $WebSocketAdress = '0.0.0.0:'.WEBSOCKET_PORT;
    echo "Listening on {$WebSocketAdress}\n";
    //$oWebSock = new SocketServer('[::]:'.WEBSOCKET_PORT, array(), $loop); // IPv6
    $oWebSock = new SocketServer($WebSocketAdress, array(), $loop); // Binding to 0.0.0.0 means remotes can connect

    # Upgrade to SSL
    if (!bDevelopmentMode) {
      # RE: SSL https://stackoverflow.com/questions/62819928/handling-expiring-lets-encyrpt-ssl-in-a-websocket-server-with-ratchet-php
      $oWebSock = new SecureServer($oWebSock, $loop, [
        'local_cert' => SSL_CERT_PATH,
        'local_pk' => SSL_PK_PATH,
        'allow_self_signed' => true,
        'verify_peer' => false
      ]);
    }

    # Create WebSocket server and set Notification as the event handler
    return new IoServer(
      new HttpServer(
        new WsServer(
          $oNotificationService
        )
      ),
      $oWebSock,
      $loop
    );
  }
}
