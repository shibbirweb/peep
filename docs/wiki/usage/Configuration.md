# Configuration

How the client finds the viewer, when it is on, and the bridges for apps without Laravel. Every option is also listed in [Installation](Installation.md#all-client-options).

## Turning it on

Peep is off until you turn it on, so it never runs by accident in production (Laravel turns it on outside `production` for you). Turn it on with the `PEEP_ENABLED=true` environment variable or a `peep.php` file in your project root:

```php
<?php

return [
    'enabled' => true,
    'project' => 'shop',      // shown in the viewer to keep apps apart
];
```

`peep.php` is found by walking up from the working directory and from the directory of the script PHP runs. Environment variables (`PEEP_ENABLED`, `PEEP_HOST`, `PEEP_PORT`, `PEEP_PROJECT`, `PEEP_REMOTE_PATH`, `PEEP_LOCAL_PATH`, `PEEP_SERIALIZER`, `PEEP_TOKEN`, `PEEP_LOG_PATH`, `PEEP_LOG_TIMEZONE`) win over the file. `log_path` names the log folder the viewer's [Logs](Logs.md) tab shows.

## Apps in Docker or a VM

See also [Installation](Installation.md#docker-and-other-machines) for the token that apps on other machines need.

Point the app at your machine and map the paths, so "open in editor" links work:

```bash
PEEP_HOST=host.docker.internal
PEEP_REMOTE_PATH=/var/www/html
PEEP_LOCAL_PATH=/Users/you/code/shop
```

Docker Desktop (macOS and Windows) and OrbStack forward `host.docker.internal` to your machine's loopback address, where the viewer listens, so nothing else is needed.

On Linux, `--add-host=host.docker.internal:host-gateway` points at the Docker bridge, so the viewer has to listen there too, which needs a token:

1. In the viewer's Settings, generate a token and save.
2. Restart the viewer listening on every address: `php peep-server.phar serve --host=0.0.0.0` (or set the host in Settings).
3. Give the app the token: `PEEP_TOKEN=...` (in Laravel, `PEEP_TOKEN` in `.env`).

Other machines (and containers) can then only send to the viewer; the viewer page itself still opens only on your machine. The same steps work for an app on another computer, with `PEEP_HOST` set to your machine's address.

## Bridges for other apps

| You want | Use |
|----------|-----|
| A PSR-3 logger that writes to Peep | `new \ShibbirWeb\Peep\Bridge\Psr\PeepLogger()` |
| Monolog records in Peep (Monolog 1, 2 or 3) | `\ShibbirWeb\Peep\Bridge\Monolog\PeepHandlerFactory::create()` |
| `dump()` and `dd()` in Peep | `\ShibbirWeb\Peep\Bridge\VarDumper\DumpHandler::register()` |
| Warnings, uncaught exceptions and fatal errors in Peep | `\ShibbirWeb\Peep\Bridge\ErrorHandler\ErrorHandler::register()` |
| Values shown with Symfony VarDumper's casters | `'serializer' => 'var-dumper'` in `peep.php` |

Log messages use PSR-3 placeholders (`'User {id} signed in'` with `['id' => 7]`), and an `exception` key in the context is shown as an exception card. Every bridge keeps working when the viewer is closed: calls are skipped after the first failed connection.
