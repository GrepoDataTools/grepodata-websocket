# grepodata-websocket

ReactPHP WebSocket server for grepodata.com.

We use a Redis PubSub architecture for communication between the REST backend and websocket server.

## Building
- Run `composer install` to install the required packages
- Copy `config.example.php` to `config.private.php` and set the Redis connection details
- Run `Software/Application/WebSocket/server.php` to start the WebSocket server
