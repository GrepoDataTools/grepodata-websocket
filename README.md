# grepodata-websocket

ReactPHP WebSocket server for grepodata.com.

We use a Redis PubSub architecture for communication between the REST backend and websocket server.

## Building
- Run `composer install` to install the required packages
- Copy `config.example.php` to `config.private.php` and set the Redis connection details
- Run `Software/Application/WebSocket/server.php` to start the WebSocket server

## Deploy with supervisor

- Install supervisor: `sudo apt-get install supervisor`
- Override main supervisor conf: `sudo cp supervisor/supervisord.conf /etc/supervisor/supervisord.conf`
- Register websocket server as supervisor program: `sudo cp supervisor/ratchet.conf /etc/supervisor/conf.d/ratchet.conf`
- Enable as systemd service: `sudo systemctl enable supervisor`
- Start service: `sudo systemctl start supervisor`
- Reread conf.d/* files: `sudo supervisorctl reread`
- Apply updates to conf: `sudo supervisorctl update`
- Check program status: `sudo supervisorctl status`
- Restart the websocket server: `sudo supervisorctl restart ratchet:Ratchet`
