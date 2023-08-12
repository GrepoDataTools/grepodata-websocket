<?php


namespace Grepodata\Library\Ratchet;


use Grepodata\Library\Redis\RedisClient;
use Ratchet\Http\HttpServer;
use Ratchet\Http\OriginCheck;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\LoopInterface;
use React\Socket\SocketServer;

class Server
{
  public static function setup(LoopInterface $loop)
  {
    # Define socket handler
    $notification_service = new Notification;

    # Create redis backbone listener (messages from REST API will be transmitted over this channel)
    RedisClient::subscribe($loop, REDIS_BACKBONE_CHANNEL, array($notification_service, 'onPush'));

    # Create WebSocket server and set Notification as the event handler
    $webSock = new SocketServer('0.0.0.0:'.WEBSOCKET_PORT, array(), $loop); // Binding to 0.0.0.0 means remotes can connect
    $webServer = new IoServer(
      new HttpServer(
        new WsServer(
          $notification_service
        )
      ),
      $webSock
    );
  }
}
