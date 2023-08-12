<?php

namespace Grepodata\Application\WebSocket;

require('./../../config.php');

use Grepodata\Library\Ratchet\Server;
use React\EventLoop\Loop;

$loop  = Loop::get();

Server::setup($loop);

$loop->run();
