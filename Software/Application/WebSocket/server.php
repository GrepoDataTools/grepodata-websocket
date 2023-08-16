<?php

namespace Grepodata\Application\WebSocket;

require('./../../config.php');

use Grepodata\Library\Ratchet\Server;
use React\EventLoop\Loop;

$loop  = Loop::get();

$server = Server::setup($loop);

echo "Using event loop type: " . get_class($server->loop) . PHP_EOL;

$server->run();
