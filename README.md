# grepodata-websocket

ReactPHP WebSocket server for grepodata.com.

We use a Redis PubSub architecture for communication between the REST backend and websocket server.

## Building
- Run `composer install` to install the required packages
- Copy `config.example.php` to `config.private.php` and set the Redis connection details
- Run `Software/Application/WebSocket/server.php` to start the WebSocket server

## Deploy with supervisor

### Install LibEv
LibEv is needed to get around the default open file limit of 1024.
- `sudo apt install php-dev` (Needed to run PHPIZE in the next step)
- `sudo pecl install ev`
- `echo 'extension=ev.so' > /etc/php/7.2/mods-available/ev.ini`
- `sudo phpenmod ev`

If the install was successful, the event loop should now be of type `React\EventLoop\ExtEvLoop`

### Setup Supervisor
- Install supervisor: `sudo apt-get install supervisor`
- Override main supervisor conf: `sudo cp supervisor/supervisord.conf /etc/supervisor/supervisord.conf`
- Register websocket server as supervisor program: `sudo cp supervisor/ratchet.conf /etc/supervisor/conf.d/ratchet.conf`
- Enable as systemd service: `sudo systemctl enable supervisor`
- Start service: `sudo systemctl start supervisor`

### Operational commands
- Reread updated config files: `sudo supervisorctl reread`
- Apply updates to conf: `sudo supervisorctl update`
- Check program status: `sudo supervisorctl status`
- Restart the websocket server: `sudo supervisorctl restart ratchet:Ratchet`

### Scheduled Restart
The ReactPHP server is a bit unstable. A small amount of connections is never properly closed, leading to an accumulation of ghost connections.
For this reason, we restart the server every other night using the following crontab: `11 02 */2 * * /usr/bin/supervisorctl restart ratchet:Ratchet`
