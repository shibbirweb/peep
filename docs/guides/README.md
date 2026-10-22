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

`peep.php` is found by walking up from the working directory and from the directory of the script PHP runs. Environment variables (`PEEP_ENABLED`, `PEEP_HOST`, `PEEP_PORT`, `PEEP_PROJECT`, `PEEP_REMOTE_PATH`, `PEEP_LOCAL_PATH`, `PEEP_SERIALIZER`) win over the file.

## Apps in Docker or a VM

Point the app at your machine and map the paths, so "open in editor" links work:

```bash
PEEP_HOST=host.docker.internal
PEEP_REMOTE_PATH=/var/www/html
PEEP_LOCAL_PATH=/Users/you/code/shop
```

Docker Desktop (macOS and Windows) and OrbStack forward `host.docker.internal` to your machine's loopback address, where the viewer listens. On Linux, `--add-host=host.docker.internal:host-gateway` points at the Docker bridge instead, which the viewer does not listen on yet; listening beyond loopback needs the access token planned for the full viewer (VF-20).

## The bridges at a glance

| You want | Use |
|----------|-----|
| A PSR-3 logger that writes to Peep | `new \ShibbirWeb\Peep\Bridge\Psr\PeepLogger()` |
| Monolog records in Peep (Monolog 1, 2 or 3) | `\ShibbirWeb\Peep\Bridge\Monolog\PeepHandlerFactory::create()` |
| `dump()` and `dd()` in Peep | `\ShibbirWeb\Peep\Bridge\VarDumper\DumpHandler::register()` |
| Warnings, uncaught exceptions and fatal errors in Peep | `\ShibbirWeb\Peep\Bridge\ErrorHandler\ErrorHandler::register()` |
| Values shown with Symfony VarDumper's casters | `'serializer' => 'var-dumper'` in `peep.php` |

Log messages use PSR-3 placeholders (`'User {id} signed in'` with `['id' => 7]`), and an `exception` key in the context is shown as an exception card. Every bridge keeps working when the viewer is closed: calls are skipped after the first failed connection.
