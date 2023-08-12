<?php

namespace Grepodata\Library\Redis;

use Clue\React\Redis\Factory;
use React\EventLoop\LoopInterface;

class RedisClient
{
  /**
   * Subscribe to messages from $channel and run the $listener callback when a message is received
   * @param LoopInterface $loop
   * @param $channel
   * @param callable $listener
   */
  public static function subscribe(LoopInterface $loop, $channel, callable $listener)
  {
    $redis = self::getInstance($loop);
    $redis->subscribe($channel);
    $redis->on('message', $listener);
  }

  /**
   * Get the given key from Redis and pass it to the callback function
   * @param $key
   * @param callable $callback
   */
  public static function get($key, callable $callback)
  {
    $redis = self::getInstance();
    $redis->get($key)->then($callback);
    $redis->end();
  }

  private static function getInstance(LoopInterface $loop = null)
  {
    $factory = new Factory($loop);
    return $factory->createLazyClient(REDIS_HOST . ':' . REDIS_PORT);
  }
}
