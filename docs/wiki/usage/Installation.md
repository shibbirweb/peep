# Installation

Peep has two parts: the **client** you add to your app, and the **viewer** where the cards appear.

## 1. The viewer

Pick one:

- **Desktop app** (macOS, Windows, Linux): download it from the [releases page](https://github.com/shibbirweb/peep/releases) and open it. It brings its own PHP; nothing else is needed. It is not signed yet, so macOS and Windows need one extra step the first time: see [Desktop app: install](Desktop-App.md#install).
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

## 4. Send something

Put this anywhere in your code, then run that code (open the page, run the command or the test):

```php
peep('Hello from my app');
peep($user);
peep()->table($rows);
```

A card appears in the viewer. Nothing there? See [Troubleshooting](Troubleshooting.md). Every method is in the [API reference](API-Reference.md).

## Laravel in a minute

Logs, reported exceptions, `dump()` and sent mail show up without any call. A few lines show the rest:

```php
peep()->showQueries();                         // every query, with bindings and time
peep()->showSlowQueries(100);                  // queries slower than 100 ms
peep()->showDuplicateQueries();                // the same query run again (N+1)
peep()->countQueries(fn () => $order->load('items'));
peep()->showJobs()->showHttpClientRequests();
peep()->model($user);
collect($rows)->peep()->map(...);
```

Set `MAIL_MAILER=peep` to send every mail to the viewer's inbox. More in the [Laravel guide](Laravel.md).

## Debugging with an AI agent

The Peep skill teaches Claude Code, Codex, Cursor and other agents to add `peep()` calls, read what was caught, fix the bug and clean up:

```bash
vendor/bin/peep skill --install                  # the Peep skill, for Claude Code
claude mcp add peep -- php vendor/bin/peep mcp   # optional: Peep's MCP tools
```

Then ask: "Use Peep to find out why checkout fails, fix it, and show me the report." See [AI agents](AI-Agents.md).

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
