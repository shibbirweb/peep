# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Webhook simulator: rules that call the app back when a mail or SMS is caught (or by hand from the inbox), with a template language for the body (`{{ mail.message_id }}`, `{{ now.unix_float }}`, `hmac_sha256(...)` and more), JSON, form, XML or raw bodies, timed steps, Mailgun and Twilio signatures that the providers' own checks accept, presets for Mailgun, Twilio and Vonage, optional retries, a delivery log with resend, a Test button, and import and export.
- Mail catching: SMTP on port 23525, the Laravel `peep` mailer (`MAIL_MAILER=peep`, Laravel 5.5 to 13), a Mailgun-compatible API and a generic `/_peep/mail` endpoint. A mail inbox with HTML (desktop and mobile width), text, source, headers and attachment downloads, unread counts, and a link to the request that sent the mail.
- SMS catching: generic `/_peep/sms`, Twilio- and Vonage-compatible APIs that answer like the real ones, Laravel SMS notifications (Vonage, Nexmo, Twilio channel; optionally blocking the real send), and `peep()->sms()`. An SMS inbox with a thread per number, segment counts and GSM-7 or UCS-2 encoding.
- Full viewer: cards grouped by request, filters by kind and color, search, copy as text, a virtual list that stays smooth with 10,000+ cards, project tabs with counts, and the card limit per project.
- Query debugging switches in the viewer toolbar (all, slow with threshold, duplicates), saved and applied to running apps within seconds.
- Pause receiving, New screen with the last 10 previous screens per project, and desktop notifications for `notify()` and exceptions.
- Settings page and `settings.json`: display mode, editor and path mappings, theme, host, port, token, cards per project, history, notifications. `serve` uses them when no option is given.
- History: cards are kept across viewer restarts in SQLite when `pdo_sqlite` is available.
- Token for apps on other machines or in Docker on Linux: `PEEP_TOKEN` in the app (`token` in Laravel's config), sent as `X-Peep-Token`; the viewer can then listen beyond loopback, and its page stays local.
- Bridges for any PHP app: PSR-3 logger (`PeepLogger`, psr/log 1 to 3), Monolog handler (`PeepHandlerFactory::create()`, Monolog 1 to 3), `dump()` and `dd()` to Peep (`DumpHandler`, VarDumper 3.4 to 8.x), opt-in VarDumper casters for values (`serializer: var-dumper`), and an error handler for warnings, uncaught exceptions and fatal errors (with PHP 8.5 fatal error backtraces).
- Global install: `peep.phar install` loads Peep into every script of a PHP install through `auto_prepend_file`; `uninstall` and `status`; projects with Composer keep their own version.
- Setup guides for plain PHP, Symfony, Slim, WordPress and the global install (`docs/guides/`).

- Laravel adapter (Laravel 5.5 to 13), auto-discovered: watchers for queries (all, by type, slow, duplicate, conditional, counted), events, jobs, cache, HTTP client, views, requests, logs, reported exceptions, mail, dumps and deprecations; request ids per job and command; `peep()->showQueries()` and the other show/stop methods, `model()`, `mailable()`, `markdown()`, `context()`; `->peep()` on collections, strings, query builders and test responses; Blade `@peep`, `@peepAll`, `@peepMeasure`; disabled in production by default.
- Query debugging follows the viewer's switch while the app runs (`GET /_peep/switches` and envelope answers).
- Viewer: cards for queries (bindings inlined), query counts, events, jobs, cache, HTTP calls, views, requests, logs and mail previews; query, HTTP and job cards update in place.
- Full client API: `toJson()`, `xml()`, `image()`, `file()`, `link()`/`url()`, `date()`, `className()`, `raw()`, `invade()`, `phpinfo()`, `separator()`, `hide()`, `expand()`/`expandAll()`, `caller()`, `trace()`, `exception()`, `measure()`, `count()`/`counterValue()`, `catch()`, `pass()`, `die()` and `pd()`, `once()`, `limit()`, `rateLimiter()`, `if()`/`showIf()`, `pause()`, `remove()`/`removeIf()`, `newScreen()`, `clearScreen()`, `clearAll()`, `notify()`, `showApp()`/`hideApp()`, macros, `sendCustom()`.
- Casters for dates, exceptions, closures and enums, with one-line summaries in the viewer.
- Viewer: renderers for every new payload type, collapsed cards, expand levels and keys, pause banner with Continue and Stop, screen commands, counters that update in place.
- Viewer (`server/`, PHP 8.5, AMPHP v3): `peep-server serve` with `--host`, `--port` and `--open=browser|window|none`; detects an already running viewer and a port used by another program; stops cleanly on Ctrl+C.
- Viewer endpoints: health, envelope ingest (validated, 8 MB limit), Server-Sent Events stream with snapshot and replay after reconnect, clear.
- Viewer security: Host header check (DNS rebinding), Origin check and a required UI header for UI actions, Content Security Policy, HTML payloads in sandboxed frames.
- Viewer UI (Svelte 5, Bun, Vite): live cards with color, label and size, value tree with visibility markers and redaction, text, HTML, JSON and table payloads, project filter, clear, open-in-editor links.
- `peep-server.phar` built with Box.
- Protocol v1 specification (`docs/protocol.md`): endpoints, envelope, payload types, value tree, switches, locks.
- `peep()` helper and `Peep` card API: `send()`, `text()`, `html()`, `json()`, `table()`, colors, `label()`, `small()`, `large()`, `enable()`, `disable()`.
- Configuration from `peep.php` and `PEEP_*` environment variables; disabled unless enabled.
- Value serializer with depth, size and node limits, loop detection, redaction of sensitive keys, and private properties read without side effects.
- Stream (sockets) and curl transports with short timeouts; a closed viewer is skipped for 30 seconds and costs about 3 ms per 100 calls.
- Origin (file, line, function) of each call, with remote to local path mapping for Docker and VMs.
- Request id per HTTP request, CLI command or job, so the viewer can group cards.
- Project skeleton: client package (`shibbirweb/peep`, PHP 7.1+), viewer package (`server/`, PHP 8.5), quality tools (`tools/`).
- Test setup that runs one suite on PHPUnit 7.5 to 12 and fails on any warning, notice or deprecation.
- Code style (PSR-12 for the client, PER-CS 2.0 for the viewer), PHPStan, and PHPCompatibility for PHP 7.1+.
- CI for PHP 7.1 to 8.5, the viewer, and a check that Composer installs only client files.

### Fixed

- Peep no longer calls `set_error_handler()` while sending, which on PHP 7 could remove the app's error handler when Peep was called from inside it.
- The viewer's Host check now covers only the UI and the stream, so apps in Docker can send through `host.docker.internal`.
