# Setup guides

Peep works in any PHP app from PHP 7.1 to 8.5. Laravel is set up automatically (see the main [README](../../README.md)); for everything else, pick a guide:

- [Plain PHP](plain-php.md): scripts and apps without a framework.
- [Symfony](symfony.md): Monolog, `dump()` and errors in a Symfony app.
- [Slim](slim.md): Slim 3 and 4.
- [WordPress](wordpress.md): themes and plugins.
- [Global install](global-install.md): `peep()` in every script of a PHP install, without Composer.

In every case, start the viewer first:

```bash
php peep-server.phar serve
```

Peep is off until you turn it on, so it never runs by accident in production. Turn it on with the `PEEP_ENABLED=true` environment variable or a `peep.php` file in your project root:

```php
<?php

return [
    'enabled' => true,
    'project' => 'shop',      // shown in the viewer to keep apps apart
];
```

`peep.php` is found by walking up from the working directory and from the directory of the script PHP runs. Environment variables (`PEEP_ENABLED`, `PEEP_HOST`, `PEEP_PORT`, `PEEP_PROJECT`, `PEEP_REMOTE_PATH`, `PEEP_LOCAL_PATH`, `PEEP_SERIALIZER`, `PEEP_TOKEN`) win over the file.

## Apps in Docker or a VM

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

## The bridges at a glance

| You want | Use |
|----------|-----|
| A PSR-3 logger that writes to Peep | `new \ShibbirWeb\Peep\Bridge\Psr\PeepLogger()` |
| Monolog records in Peep (Monolog 1, 2 or 3) | `\ShibbirWeb\Peep\Bridge\Monolog\PeepHandlerFactory::create()` |
| `dump()` and `dd()` in Peep | `\ShibbirWeb\Peep\Bridge\VarDumper\DumpHandler::register()` |
| Warnings, uncaught exceptions and fatal errors in Peep | `\ShibbirWeb\Peep\Bridge\ErrorHandler\ErrorHandler::register()` |
| Values shown with Symfony VarDumper's casters | `'serializer' => 'var-dumper'` in `peep.php` |

Log messages use PSR-3 placeholders (`'User {id} signed in'` with `['id' => 7]`), and an `exception` key in the context is shown as an exception card. Every bridge keeps working when the viewer is closed: calls are skipped after the first failed connection.
