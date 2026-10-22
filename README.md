# Peep

Debug any PHP app in a separate window. Call `peep($value)` anywhere and see values, database queries, jobs, logs and exceptions live, in your browser or in a desktop app. Peep also catches the mail and SMS your app sends and can simulate provider webhooks back to it.

> **Status: early development.** Nothing is released yet. The core client and the browser viewer work; framework integrations, mail and SMS catching and the desktop app are in progress. Progress is tracked in [docs/plan/features.md](docs/plan/features.md).

## Planned features

- `peep($anything)` from any PHP code, with colors, labels, tables, JSON, timing, traces, counters and pause.
- Laravel integration out of the box: queries (with slow and duplicate detection), jobs, events, cache, HTTP client, views, requests, logs, exceptions, mail and dumps.
- Query debugging you can switch on and off from the viewer while the app runs.
- Works in any PHP app through PSR-3, Monolog, Symfony VarDumper and a plain error handler.
- Mail and SMS catcher (SMTP, Mailgun-, Twilio- and Vonage-style APIs) with a webhook simulator that calls your app back with signed, templated payloads.
- Viewer in the browser or as a desktop app for macOS, Windows and Linux; you choose in the settings.
- Safe by default: off in production, never slows or breaks your app when the viewer is closed.

## Requirements

| Part | PHP |
|------|-----|
| Client (`shibbirweb/peep`, the package you install in your app) | 7.1 to 8.5 |
| Viewer (`peep-server.phar`) | 8.5, or none with the desktop app (it bundles PHP) |

## Usage (from source, until the first release)

Start the viewer (PHP 8.5, [Bun](https://bun.sh) for the UI build):

```bash
cd server
composer install
composer build:ui
php bin/peep-server serve        # opens http://127.0.0.1:23517 in your browser
```

In your app:

```bash
composer require shibbirweb/peep --dev
```

```php
// Enable with PEEP_ENABLED=true or a peep.php file returning ['enabled' => true].
peep('Hello');
peep($user)->green()->label('Signed in user');
peep()->table($rows, 'Users');
```

Planned next: `peep()->showQueries()` and the other Laravel watchers.

## Documentation

- [Roadmap](docs/plan/roadmap.md)
- [Feature list](docs/plan/features.md)
- [Implementation plan](docs/plan/implementation.md)

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Security issues: see [SECURITY.md](SECURITY.md).

## License

MIT. See [LICENSE](LICENSE).
