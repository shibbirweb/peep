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

[![Watch the 2-minute tour of Peep](https://raw.githubusercontent.com/shibbirweb/peep/main/demo/peep-tour.png)](https://github.com/shibbirweb/peep/blob/main/demo/peep-tour.mp4)

![The Peep viewer showing cards from a shop app, grouped by request](https://raw.githubusercontent.com/shibbirweb/peep/main/docs/wiki/images/viewer.png)

Works with PHP 7.1 to 8.5 and Laravel 5.5 to 13. More screenshots are in the [wiki](https://github.com/shibbirweb/peep/wiki).

## Installation

Peep has two pieces. You set up each one once:

| Piece | What it does | Where it goes |
|-------|--------------|---------------|
| **The viewer** | The window where everything your app sends shows up | On your computer |
| **The `peep()` package** (`shibbirweb/peep`) | Adds `peep()` to your code and sends what you give it to the viewer | In each PHP project you want to debug |

> Peep is in beta. Until version 1.0, install the package with `:@beta` as shown below, and get the viewer from the newest [release](https://github.com/shibbirweb/peep/releases), marked *Pre-release*.

### Step 1: Install the viewer

Choose **one** of these. Most people want the desktop app.

| | Desktop app (recommended) | Viewer in your browser |
|---|---|---|
| **Good for** | Everyone | Computers where you cannot install apps |
| **You need** | macOS 11 or later, Windows 10 or 11, or Linux | PHP 8.5 on your computer |
| **You get** | Its own window, a tray icon, notifications and updates | The viewer in a browser tab |

<details open>
<summary><b>Option A: the desktop app</b></summary>

1. Open the [newest release](https://github.com/shibbirweb/peep/releases) and download the file for your computer from **Assets**:

   | Your computer | Download |
   |---------------|----------|
   | Mac with Apple silicon (M1 or later) | `Peep_<version>_aarch64.dmg` |
   | Mac with an Intel chip | `Peep_<version>_x64.dmg` |
   | Windows | `Peep_<version>_x64-setup.exe` |
   | Ubuntu or Debian | `Peep_<version>_amd64.deb` |
   | Any other Linux | `Peep_<version>_amd64.AppImage` |

   Not sure which Mac you have? Apple menu → **About This Mac** shows the chip.

2. Install it and open it. The app is not signed with an Apple or Microsoft certificate yet, so the first time your system asks you to confirm:

   - **macOS**: open the `.dmg` and drag **Peep** into **Applications**. Open Peep; when macOS says it cannot open it, click **Done**. Go to **System Settings → Privacy & Security**, scroll down to *"Peep" was blocked*, click **Open Anyway** and enter your password. If macOS says Peep *is damaged*, run this in Terminal, then open Peep again:

     ```bash
     xattr -dr com.apple.quarantine /Applications/Peep.app
     ```

   - **Windows**: run the installer. When *Windows protected your PC* appears, click **More info**, then **Run anyway**.
   - **Linux**: `sudo apt install ./Peep_<version>_amd64.deb`, or make the AppImage runnable with `chmod +x` and start it.

   You only do this once. Every step in detail, and help when something goes wrong: [installing the desktop app](docs/wiki/usage/Desktop-App.md#install).

3. Peep opens its window. It also lives in the tray (the menu bar on macOS) while it runs.

The desktop app brings its own PHP, so you do not need to install PHP for it.

</details>

<details>
<summary><b>Option B: the viewer in your browser</b></summary>

1. Check that this computer has PHP 8.5: `php -v` should print `PHP 8.5`.
2. Download `peep-server.phar` from the [newest release](https://github.com/shibbirweb/peep/releases).
3. Start it in a terminal, from the folder you saved it to, and keep that terminal open:

   ```bash
   php peep-server.phar serve
   ```

   Your browser opens the viewer at http://127.0.0.1:23517. Press Ctrl+C in the terminal to stop it.

</details>

### Step 2: Add Peep to your project

In your project's folder (the one with `composer.json`):

```bash
composer require shibbirweb/peep:@beta --dev
```

`--dev` keeps Peep out of your production install. Your project can use any PHP from 7.1 to 8.5; only the viewer cares about PHP 8.5, and the desktop app brings its own.

No Composer in this project? The [global install](docs/wiki/usage/Global-Install.md) adds `peep()` to every PHP script on your computer instead.

### Step 3: Turn Peep on

- **Laravel**: nothing to do. Peep is on in every environment except `production`.
- **Any other PHP app**: set the environment variable `PEEP_ENABLED=true` (in your `.env` file, if your app loads one), or create a `peep.php` file in the project's root folder:

  ```php
  <?php

  return ['enabled' => true];
  ```

Peep is off until you turn it on, so it never runs on a live site by accident.

### Step 4: See it work

Put this anywhere in your code, then run that code (open the page, run the command or the test):

```php
peep('Hello from my app');
```

A card with *Hello from my app* appears in the viewer. From here, send anything: `peep($user)`, `peep($request->all())`, `peep()->table($rows)`. See the [API reference](docs/wiki/usage/API-Reference.md).

Nothing appears? Check that:

1. The viewer is running (the desktop app is open, or `php peep-server.phar serve` is still running).
2. Peep is on (Step 3).
3. Your app runs on the same computer as the viewer. For Docker, Laravel Sail, a VM or another computer, see [Docker and other machines](docs/wiki/usage/Installation.md#docker-and-other-machines).

More help: [Troubleshooting](docs/wiki/usage/Troubleshooting.md).

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

| You use | It needs |
|---------|----------|
| Your PHP app, with the `peep()` package | PHP 7.1 to 8.5. Laravel 5.5 to 13 work out of the box |
| The desktop app | macOS 11 or later, Windows 10 or 11, or Linux with WebKitGTK 4.1. It brings its own PHP |
| The viewer in your browser (`peep-server.phar`) | PHP 8.5, on the computer that runs it |

Your app does **not** need PHP 8.5. Only the browser viewer does, and only if you choose it over the desktop app. Every Laravel version from 5.5 to 13 is tested on every PHP version it supports: see [Version support](docs/wiki/usage/Version-Support.md).

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
