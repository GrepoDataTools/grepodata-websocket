<?php

namespace Grepodata;

define('bDevelopmentMode', true);

define('PRIVATE_IP', '');

define('REDIS_HOST', PRIVATE_IP);
define('REDIS_PORT', 6379);
define('REDIS_BACKBONE_CHANNEL', "");
define('REDIS_BACKBONE_HEARTBEAT_INTERVAL', 120);  // Nominal heartbeat interval; server restarts if it misses 2 heartbeats

define('WEBSOCKET_PORT', 0);

define('PRIVATE_PUSHBULLET_TOKEN', '');

define('SSL_CERT_PATH', '');
define('SSL_PK_PATH', '');
