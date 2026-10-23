# Peep

[![CI](https://github.com/shibbirweb/peep/actions/workflows/ci.yml/badge.svg)](https://github.com/shibbirweb/peep/actions/workflows/ci.yml)
[![Latest release](https://img.shields.io/github/v/release/shibbirweb/peep?include_prereleases&sort=semver)](https://github.com/shibbirweb/peep/releases)
[![Packagist version](https://img.shields.io/packagist/v/shibbirweb/peep)](https://packagist.org/packages/shibbirweb/peep)
[![Packagist downloads](https://img.shields.io/packagist/dt/shibbirweb/peep)](https://packagist.org/packages/shibbirweb/peep)
[![PHP 7.1 to 8.5](https://img.shields.io/badge/PHP-7.1%20to%208.5-777bb4?logo=php&logoColor=white)](docs/wiki/usage/Version-Support.md)
[![Laravel 5.5 to 13](https://img.shields.io/badge/Laravel-5.5%20to%2013-ff2d20?logo=laravel&logoColor=white)](docs/wiki/usage/Version-Support.md#laravel-and-php)
[![License: MIT](https://img.shields.io/github/license/shibbirweb/peep)](LICENSE)
[![GitHub stars](https://img.shields.io/github/stars/shibbirweb/peep?style=social)](https://github.com/shibbirweb/peep/stargazers)

Debug any PHP app in a separate window. Call `peep($value)` anywhere and watch values, database queries, jobs, logs and exceptions arrive live, in your browser or in a desktop app. Peep also catches the mail and SMS your app sends, and can call your app back with signed provider webhooks.

![The Peep viewer showing cards from a shop app, grouped by request](https://raw.githubusercontent.com/shibbirweb/peep/main/docs/wiki/images/viewer.png)

Works with PHP 7.1 to 8.5 and Laravel 5.5 to 13. More screenshots are in the [wiki](https://github.com/shibbirweb/peep/wiki).

## Installation

Peep has two parts: the **viewer**, where the cards appear, and the **client**, the Composer package your app calls.

### 1. Start the viewer

Pick one:

- **Desktop app** for macOS, Windows and Linux: download it from the [releases page](https://github.com/shibbirweb/peep/releases) and open it. It brings its own PHP, so nothing else is needed.
- **`peep-server.phar`**, with PHP 8.5: download it from the same page and run it.

  ```bash
  php peep-server.phar serve        # opens http://127.0.0.1:23517 in your browser
  ```

### 2. Add the client to your app

```bash
composer require shibbirweb/peep --dev
```

Laravel picks the package up by itself. Any other PHP app only needs Composer's autoloader. Apps without Composer can use the [global install](docs/wiki/usage/Global-Install.md) instead.

### 3. Turn it on and send something

- **Laravel**: Peep is already on in every environment except `production`.
- **Any other app**: set `PEEP_ENABLED=true`, or add a `peep.php` file returning `['enabled' => true]` to the project root.

```php
peep('Hello');
peep($user)->green()->label('Signed in user');
peep()->table($rows, 'Users');
```

The cards show up at once. If your app runs in Docker, see [Docker and other machines](docs/wiki/usage/Installation.md#docker-and-other-machines).

> **Before the first release.** Nothing is published yet, so the releases page and Packagist are still empty. Until then, run the viewer from source (PHP 8.5 and [Bun](https://bun.sh)): `cd server && composer install && composer build:ui && php bin/peep-server serve`. Betas come first; while only betas exist, install the client with `composer require shibbirweb/peep:@beta --dev`.

## What it does

- `peep($anything)` from any PHP code, with colors, labels, tables, JSON, timing, traces, counters and pause.
- Laravel out of the box: queries (with slow and duplicate detection), jobs, events, cache, the HTTP client, views, requests, logs, exceptions, mail and `dump()`.
- Query debugging that you switch on and off from the viewer while the app runs.
- Any PHP app through PSR-3, Monolog, Symfony VarDumper and a plain error handler.
- A mail and SMS catcher (SMTP, a Laravel mailer, and Mailgun-, Twilio- and Vonage-style APIs), with a webhook simulator that calls your app back with signed, templated payloads.
- The viewer in a browser tab or in the desktop app; choose in Settings.
- Debugging by AI agents: `vendor/bin/peep report` and an MCP server give Claude Code, Codex, Cursor and others what Peep caught as text, and the bundled skill teaches them to use it.
- Safe by default: off in production, and it never slows down or breaks your app when the viewer is closed.

## Requirements

| Part | Needs |
|------|-------|
| Client (`shibbirweb/peep`, in your app) | PHP 7.1 to 8.5 |
| Viewer (`peep-server.phar`) | PHP 8.5 |
| Desktop app | macOS 11+, Windows 10 or 11, or Linux with WebKitGTK 4.1; brings its own PHP |

Every Laravel version from 5.5 to 13 is tested on every PHP version it supports: see [Version support](docs/wiki/usage/Version-Support.md).

## Laravel in a minute

```php
peep()->showQueries();                         // every query, with bindings and time
peep()->showSlowQueries(100);                  // queries slower than 100 ms
peep()->showDuplicateQueries();                // the same query run again (N+1)
peep()->countQueries(fn () => $order->load('items'));
peep()->showJobs()->showHttpClientRequests();
peep()->model($user);
collect($rows)->peep()->map(...);
```

Logs, reported exceptions, `dump()` and sent mail show up without any call. Set `MAIL_MAILER=peep` to send every mail to the viewer's inbox. To change what is captured, publish the config with `php artisan vendor:publish --tag=peep-config`. More in the [Laravel guide](docs/wiki/usage/Laravel.md).

## Debugging with an AI agent

```bash
vendor/bin/peep skill --install          # the Peep skill, for Claude Code (.claude/skills/peep)
claude mcp add peep -- php vendor/bin/peep mcp   # optional: Peep's MCP tools
```

Then ask: "Use Peep to find out why checkout fails, fix it, and show me the report." The agent adds `peep()` calls, runs the code, reads `vendor/bin/peep report` (problems first, then every request), fixes the bug and removes its calls. See [AI agents](docs/wiki/usage/AI-Agents.md).

## Documentation

The [wiki](https://github.com/shibbirweb/peep/wiki) has everything, with screenshots. It is written in [docs/wiki](docs/wiki) and published by CI.

- [Getting started](docs/wiki/usage/Getting-Started.md) and [Installation](docs/wiki/usage/Installation.md)
- [Version support](docs/wiki/usage/Version-Support.md): PHP 7.1 to 8.5, Laravel 5.5 to 13
- [API reference](docs/wiki/usage/API-Reference.md)
- [Laravel](docs/wiki/usage/Laravel.md), [other frameworks and plain PHP](docs/wiki/usage/Configuration.md), [mail and SMS](docs/wiki/usage/Mail-and-SMS.md), [webhooks](docs/wiki/usage/Webhooks.md), [desktop app](docs/wiki/usage/Desktop-App.md), [AI agents](docs/wiki/usage/AI-Agents.md)
- [Releasing](docs/wiki/developer/Releasing.md): betas from `develop`, stable releases from `main`
- [Protocol](docs/protocol.md), [roadmap](docs/plan/roadmap.md), [feature list](docs/plan/features.md) and [implementation plan](docs/plan/implementation.md)

## Support Peep

- **Star the repository.** If Peep saves you time, a [star on GitHub](https://github.com/shibbirweb/peep/stargazers) helps other PHP developers find it.
- **Report a bug.** Something wrong? [Open a bug report](https://github.com/shibbirweb/peep/issues/new?template=bug_report.yml) with your Peep and PHP versions and the steps to reproduce.
- **Request a feature.** Missing something? [Suggest a feature](https://github.com/shibbirweb/peep/issues/new?template=feature_request.yml), starting with the problem it would solve.
- **Security problem?** Please report it privately: see [SECURITY.md](SECURITY.md).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Pull requests go into `develop`.

## License

MIT. See [LICENSE](LICENSE).
