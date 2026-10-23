# Installation

Peep has two parts: the **client** you add to your app, and the **viewer** where the cards appear.

## 1. The viewer

Pick one:

- **Desktop app** (macOS, Windows, Linux): download it from the [releases page](https://github.com/shibbirweb/peep/releases) and open it. It brings its own PHP; nothing else is needed. See [Desktop app](Desktop-App.md).
- **`peep-server.phar`** (needs PHP 8.5): download it from the releases page and run

  ```bash
  php peep-server.phar serve          # opens http://127.0.0.1:23517 in your browser
  ```

  Options: `--port=9000`, `--open=browser|window|none`, `--host=0.0.0.0` (needs a token, see Docker below). Without options, the port and display mode come from the viewer's Settings.

Check the download against `SHA256SUMS` on the same release.

## 2. The client

### With Composer (recommended)

```bash
composer require shibbirweb/peep --dev
```

The client runs on PHP 7.1 to 8.5. Laravel (5.5 to 13) picks it up on its own. Elsewhere, Composer's autoloader is all you need.

### Without Composer

The [global install](Global-Install.md) loads Peep into every script a PHP install runs:

```bash
php peep.phar install
```

## 3. Turn it on

Peep is off until you turn it on, so it never runs by accident in production.

- Laravel: on in every environment except `production`. Force it with `PEEP_ENABLED=true` or `false` in `.env`.
- Anything else: `PEEP_ENABLED=true` in the environment, or a `peep.php` file in the project root:

  ```php
  <?php

  return [
      'enabled' => true,
      'project' => 'shop',
  ];
  ```

Then:

```php
peep('Hello');
```

## Docker and other machines

On Docker Desktop (macOS, Windows) and OrbStack, point the app at your machine:

```dotenv
PEEP_HOST=host.docker.internal
PEEP_REMOTE_PATH=/var/www/html          # the path in the container
PEEP_LOCAL_PATH=/Users/you/code/shop    # the same folder on your machine, for editor links
```

On Linux, and for apps on another computer, the viewer has to listen beyond your machine, which needs a token:

1. In the viewer's Settings, generate a token and save.
2. Start the viewer with `--host=0.0.0.0` (or set the host in Settings and restart).
3. Give the app `PEEP_TOKEN=...` and `PEEP_HOST` (your machine's address; in Docker on Linux, start the container with `--add-host=host.docker.internal:host-gateway`).

Other machines can then send to the viewer, but the viewer page itself still opens only on your machine.

## All client options

| Option (`peep.php`) | Environment | Default |
|---------------------|-------------|---------|
| `enabled` | `PEEP_ENABLED` | off (Laravel: on outside production) |
| `host` | `PEEP_HOST` | `127.0.0.1` |
| `port` | `PEEP_PORT` | `23517` |
| `project` | `PEEP_PROJECT` | `default` (Laravel: `APP_NAME`) |
| `remote_path`, `local_path` | `PEEP_REMOTE_PATH`, `PEEP_LOCAL_PATH` | none |
| `serializer` | `PEEP_SERIALIZER` | `peep`; `var-dumper` uses Symfony VarDumper's casters |
| `token` | `PEEP_TOKEN` | none |
| `log_path`, `log_timezone` | `PEEP_LOG_PATH`, `PEEP_LOG_TIMEZONE` | none (Laravel: `storage/logs` and `app.timezone`); the folder the [Logs](Logs.md) tab shows |
| `max_depth`, `max_items`, `max_string_length`, `max_nodes` | | 5, 100, 10,000, 5,000 |
| `redact_keys` | | `password`, `secret`, `token`, `api_key`, `authorization`, `cookie` and more |
