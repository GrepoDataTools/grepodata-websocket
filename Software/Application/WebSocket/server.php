<?php

namespace Grepodata\Application\WebSocket;

require('./../../config.php');

use Grepodata\Library\Ratchet\Server;
use React\EventLoop\Loop;

$loop  = Loop::get();

$server = Server::setup($loop);

$server->run();
