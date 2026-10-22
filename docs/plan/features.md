# Peep Feature List

How each feature works is described in [implementation.md](implementation.md). Tick a feature when it is merged with tests. Add `(in progress)` after the name while working on it. Reference the ID in commits and pull requests. Phases are described in [roadmap.md](roadmap.md).

## Progress

| Area | Phase | Done |
|------|-------|------|
| SU: Project setup | 0 | 12 / 14 |
| PR: Protocol | 1 | 7 / 7 |
| CL: Core client MVP | 1 | 14 / 14 |
| VW: Viewer MVP | 2 | 12 / 12 |
| API: Full client API | 3 | 0 / 38 |
| LV: Laravel adapter | 4 | 0 / 31 |
| BR: Bridges for any PHP app | 5 | 0 / 7 |
| VF: Full viewer | 6 | 0 / 25 |
| MS: Mail and SMS catching | 7 | 0 / 15 |
| WH: Webhook simulator | 7 | 0 / 13 |
| DT: Desktop app (Tauri) | 8 | 0 / 15 |
| RL: Release | 9 | 0 / 10 |

## SU: Project setup (Phase 0)

- [x] SU-01 Repository skeleton in pds/skeleton layout (`src/`, `tests/`, `docs/`, `server/`, `desktop/`, `tools/`; `config/` and `resources/` are added with their first file in LV-02 and CL-03)
- [x] SU-02 Root `composer.json`: `shibbirweb/peep`, `"php": ">=7.1"`, PSR-4 `ShibbirWeb\Peep\` (the `files` autoload for `src/helpers.php` comes with CL-01 and Laravel auto-discovery with LV-01, so nothing points at classes that do not exist yet)
- [x] SU-03 `server/composer.json`: `"php": ">=8.5"`, PSR-4 `ShibbirWeb\PeepServer\`
- [x] SU-04 `.gitattributes` export-ignore for `server/`, `desktop/`, `docs/`, `tests/` and dev config files
- [x] SU-05 `.editorconfig`, `.gitignore`
- [x] SU-06 `LICENSE` (MIT), `README.md`, `CHANGELOG.md`, `CONTRIBUTING.md`, `SECURITY.md`
- [x] SU-07 PHPUnit with `yoast/phpunit-polyfills` so one suite runs on PHPUnit 7.5 (PHP 7.1) to current
- [x] SU-08 Base `TestCase` that turns warnings, notices and deprecations into failures
- [x] SU-09 PHP-CS-Fixer: PSR-12 for the client, PER-CS 2.0 for the viewer
- [x] SU-10 PHPStan for types and bugs: level 8 for the client, level 9 for the viewer (PHP 7.1 compatibility is checked by SU-11, SU-12 and SU-13, not PHPStan)
- [x] SU-11 PHP_CodeSniffer with PHPCompatibility, `testVersion 7.1-`
- [ ] SU-12 CI: client tests on PHP 7.1, 7.2, 7.3, 7.4, 8.0, 8.1, 8.2, 8.3, 8.4, 8.5 (in progress: workflow written; first run waits for the GitHub repository)
- [ ] SU-13 CI: `php -l` of every client file on PHP 7.1; viewer tests on 8.5; lint and static analysis jobs (in progress: workflows written; first run waits for the GitHub repository)
- [x] SU-14 CI: install check from a test project proves only client files are downloaded

## PR: Protocol (Phase 1)

- [x] PR-01 Protocol v1 spec in `docs/protocol.md`: endpoints, envelope JSON, payload types, versioning
- [x] PR-02 Envelope: `uuid`, `payloads[]`, `meta` (protocol version, PHP version, hostname, project, request id)
- [x] PR-03 Value format: structured JSON tree (type, class, properties, depth and size limits), not HTML
- [x] PR-04 Card updates by `uuid` (color, size, label, hide, remove, replace content)
- [x] PR-05 Lock endpoints for `pause()`: create, poll (`active`, `stop`); specified here, used by API-26
- [x] PR-06 Availability endpoint and expected status code
- [x] PR-07 (specified; the client side is LV-31) Viewer-to-client switches: the viewer tells clients which captures are on (e.g. queries), returned with each ingest response and cached by the client for a few seconds, so switches apply to running apps without a restart

## CL: Core client MVP (Phase 1)

- [x] CL-01 `peep(...$values)` helper, guarded with `function_exists`, returns a `Peep` instance
- [x] CL-02 `Peep` fluent class and `PeepFactory`
- [x] CL-03 `Config` value object and `ConfigLoader` (env vars, `peep.php` file found by walking up from the working directory)
- [x] CL-04 Disabled unless explicitly enabled; `enable()`, `disable()`, `enabled()`, `disabled()`
- [x] CL-05 `TransportInterface`, `StreamTransport` (`stream_socket_client`), `CurlTransport` (when ext-curl exists), `NullTransport`
- [x] CL-06 Short timeouts (connect about 50 ms, total about 500 ms), never throws into the app (catches `\Throwable`)
- [x] CL-07 `AvailabilityCache`: viewer marked down for 30 s after a failed connect (shared through APCu when enabled); checked before any serializing, so a closed viewer costs about 3 ms per 100 calls
- [x] CL-08 `OriginResolver`: file and line of the `peep()` call, skipping Peep's own frames
- [x] CL-09 `PathMapper`: `remote_path` to `local_path` for Docker and VMs
- [x] CL-10 `ValueSerializer`: scalars, arrays, objects, depth and size limits, loop detection
- [x] CL-11 Private and protected properties read with an `(array)` cast (no Reflection, no `setAccessible()`, no getters)
- [x] CL-12 `ExecutionContext`: one request id per HTTP request or CLI run
- [x] CL-13 Payloads: log (default), text, html, json, table
- [x] CL-14 Styling: `red()`, `green()`, `blue()`, `orange()`, `purple()`, `gray()`, `yellow()`, `color()`, `label()`, `small()`, `large()`

## VW: Viewer MVP (Phase 2)

- [x] VW-01 `bin/peep-server serve` command (`--port`, `--host`, `--open=browser|window`)
- [x] VW-02 Non-blocking HTTP server (AMPHP v3), bound to `127.0.0.1:23517` by default; Host and Origin guards against DNS rebinding and other web pages; non-loopback hosts refused until token support (VF-20)
- [x] VW-03 Ingest endpoint: decode and validate protocol v1 envelopes
- [x] VW-04 Availability endpoint
- [x] VW-05 Server-Sent Events stream to the page
- [x] VW-06 In-memory event store
- [x] VW-07 UI (Svelte 5, built with Bun and Vite): live message list with value tree, origin, color, label, size; text, HTML (sandboxed), JSON and table payloads
- [x] VW-08 UI: click origin to open in IDE (PhpStorm, VS Code, Cursor, Zed, Sublime); the editor choice is kept in the browser until the settings page (VF-17)
- [x] VW-09 UI: clear screen
- [x] VW-10 Opens the default browser on start
- [x] VW-11 Detects an already running server and only opens the view
- [x] VW-12 `peep-server.phar` built with Box in CI (UI built first, production dependencies only) and smoke-tested; attaching it to GitHub Releases is RL-03

## API: Full client API (Phase 3)

Formats:
- [ ] API-01 `json($string)` and `toJson($value)`
- [ ] API-02 `xml($string)`
- [ ] API-03 `image($pathOrUrlOrBase64)`
- [ ] API-04 `file($path)`
- [ ] API-05 `link($url, $label)` / `url()`
- [ ] API-06 `date($dateTime)` (DateTimeInterface, Carbon when present)
- [ ] API-07 `className($object)`
- [ ] API-08 `raw($value)` (no casters)
- [ ] API-09 `invade($object)` for private properties and methods
- [ ] API-10 `phpinfo(...$keys)`
- [ ] API-11 `expand($levelOrKeys)`, `expandAll()`, `hide()`
- [ ] API-12 `separator()`

Inspection:
- [ ] API-13 `caller()`
- [ ] API-14 `trace()` / `backtrace()`
- [ ] API-15 `exception($throwable)` with source snippet
- [ ] API-16 `measure()` and `measure(callable)` (hrtime with microtime fallback)
- [ ] API-17 `count($name)` and `counterValue($name)`
- [ ] API-18 `catch(callable, $filter)`: run a callback, send matching exceptions, rethrow others

Control:
- [ ] API-19 `pass($value)`
- [ ] API-20 `die()` and `pd(...)` shortcut
- [ ] API-21 `once()`
- [ ] API-22 `limit($n)`
- [ ] API-23 `rateLimiter()->max()`, `perSecond()`, `clear()`
- [ ] API-24 `if($condition)` and `if($condition, $callback)`
- [ ] API-25 `showIf($condition)`
- [ ] API-26 `pause()` with Continue and Stop (`StopExecutionException`)

Card updates:
- [ ] API-27 `send($value)` replaces the content of a held instance
- [ ] API-28 `remove()`, `removeIf()`, `removeWhen()`

Screens and app:
- [ ] API-29 `newScreen($title)`
- [ ] API-30 `clearScreen()` and `clearAll()`
- [ ] API-31 `notify($message)`
- [ ] API-32 `showApp()` and `hideApp()`

Extending:
- [ ] API-33 `Peep::macro($name, $callable)`
- [ ] API-34 Custom payload support (`sendCustom($value, $label)`)

Serializer casters:
- [ ] API-35 DateTime caster
- [ ] API-36 Throwable caster
- [ ] API-37 Closure caster
- [ ] API-38 Enum caster (`instanceof \UnitEnum`, simply false below PHP 8.1)

## LV: Laravel adapter (Phase 4)

Setup:
- [ ] LV-01 `PeepServiceProvider`, auto-discovered
- [ ] LV-02 `config/peep.php` with `env()` keys, publishable (`vendor:publish --tag=peep-config`)
- [ ] LV-03 Enabled by default only outside production
- [ ] LV-04 Request id per HTTP request, queued job and artisan command
- [ ] LV-05 Project name from `config('app.name')`
- [ ] LV-06 `AbstractWatcher` with runtime `class_exists` checks per Laravel version

Watchers (each with a config key and a runtime toggle such as `peep()->showQueries()` / `stopShowingQueries()`):
- [ ] LV-07 Queries, with bindings and time
- [ ] LV-08 Select / insert / update / delete query filters
- [ ] LV-09 Slow queries (threshold in ms)
- [ ] LV-10 Duplicate queries (N+1 hints)
- [ ] LV-11 Conditional queries (`showConditionalQueries(callable)`)
- [ ] LV-12 `countQueries(callable)`
- [ ] LV-13 Events
- [ ] LV-14 Jobs (dispatched, processed, failed)
- [ ] LV-15 Cache (hit, miss, write, forget)
- [ ] LV-16 HTTP client requests and responses (Laravel 8+, when the client events exist)
- [ ] LV-17 Views with data
- [ ] LV-18 Incoming requests (route, controller, middleware, status, duration, memory)
- [ ] LV-19 Log calls
- [ ] LV-20 Exceptions (from `MessageLogged`)
- [ ] LV-21 Mail sent to the `log` mailer
- [ ] LV-22 `dump()` / `dd()` routed to Peep
- [ ] LV-23 Deprecation notices

Objects and helpers:
- [ ] LV-24 `model($model)` payload (attributes, loaded relations)
- [ ] LV-25 `mailable($mailable)` payload (rendered)
- [ ] LV-26 `markdown($string)`
- [ ] LV-27 `context()` for Laravel 11+ Context
- [ ] LV-28 Macros: `Collection::peep()`, `Stringable::peep()`, `Builder::peep()`, `TestResponse::peep()`
- [ ] LV-29 Blade directives: `@peep`, `@peepAll`, `@measure`
- [ ] LV-30 Laravel test matrix on every supported Laravel version (Orchestra Testbench)
- [ ] LV-31 Query debugging follows the viewer's on/off switch at runtime (PR-07), for all, slow and duplicate queries; no `.env` change or restart. When off, queries are neither collected nor sent

## BR: Bridges for any PHP app (Phase 5)

- [ ] BR-01 PSR-3 logger (`PeepLogger`), works with psr/log 1, 2 and 3
- [ ] BR-02 Monolog handler, Monolog 1, 2 and 3
- [ ] BR-03 Symfony VarDumper handler (`dump()` / `dd()` to Peep), VarDumper 4.4 to 8.x
- [ ] BR-04 `VarDumperSerializer` used for values when symfony/var-dumper is installed
- [ ] BR-05 Error handler for plain PHP: errors, uncaught exceptions, fatal errors on shutdown (with PHP 8.5 fatal error backtraces when available)
- [ ] BR-06 Global install through `auto_prepend_file` (PHAR plus install command)
- [ ] BR-07 Setup guides: plain PHP, Symfony, Slim, WordPress

## VF: Full viewer (Phase 6)

Message list:
- [ ] VF-01 Group by request id (collapsible per request, job, command)
- [ ] VF-02 Filter by type (log, query, exception, job, event, http, dump)
- [ ] VF-03 Filter by color label
- [ ] VF-04 Text search inside values
- [ ] VF-05 Query view: SQL with bindings inlined, time, slow and duplicate badges
- [ ] VF-06 Exception view with stack trace and source snippet
- [ ] VF-07 Table, JSON, XML, HTML, image renderers
- [ ] VF-08 Card updates and removal by `uuid`
- [ ] VF-09 Copy value as text
- [ ] VF-10 Virtualised list for 10,000+ messages

Screens and controls:
- [ ] VF-11 Screens: new screen, clear, archive of previous screens
- [ ] VF-12 Pause banner with Continue and Stop
- [ ] VF-13 Pause receiving (ignore incoming messages)
- [ ] VF-14 In-app notifications for `notify()` and exceptions (browser Notification API)

Settings (saved in `~/.config/peep/settings.json` / `%APPDATA%\peep\settings.json`):
- [ ] VF-15 Settings page
- [ ] VF-16 Display mode: window or browser
- [ ] VF-17 IDE choice and path mapping
- [ ] VF-18 Port and host
- [ ] VF-19 Theme: light, dark, follow system
- [ ] VF-20 Optional shared token for Docker or remote use

Query debugging:
- [ ] VF-25 Query debugging on/off switch in the viewer toolbar (per project), with sub-switches for slow and duplicate queries and the slow threshold; state shown at all times

Storage and protocols:
- [ ] VF-21 Event store interface with in-memory and SQLite implementations (see decision D4)
- [ ] VF-22 Multiple projects / hosts shown separately
- [ ] VF-23 Ray protocol decoder (only if decision D2 is yes)
- [ ] VF-24 Symfony VarDumper TCP server on 9912 (only if decision D2 is yes)

## MS: Mail and SMS catching (Phase 7)

The viewer catches mail and SMS the app sends, so nothing reaches real people during development, and shows them in an inbox.

Mail:
- [ ] MS-01 SMTP server in the viewer (configurable port, accepts any login, no TLS required)
- [ ] MS-02 Laravel mail transport `peep` (`MAIL_MAILER=peep`): sends mail to the viewer over HTTP, no SMTP setup
- [ ] MS-03 Mailgun-compatible HTTP API endpoint (`POST /v3/{domain}/messages`) with a Mailgun-style response, for apps that use the Mailgun API instead of SMTP
- [ ] MS-04 Generic HTTP mail endpoint (JSON) for any app
- [ ] MS-05 Mail inbox UI: list, search, HTML, text and raw source views, headers, attachments download
- [ ] MS-06 HTML preview in a sandboxed iframe, desktop and mobile widths
- [ ] MS-07 Unread count, mark read, delete, clear inbox
- [ ] MS-08 Link from a mail to the request / job that sent it (its queries, logs, exceptions)

SMS:
- [ ] MS-09 Generic SMS endpoint (JSON or form: `to`, `from`, `body`)
- [ ] MS-10 Twilio-compatible endpoint (`POST /2010-04-01/Accounts/{sid}/Messages.json`) returning a Twilio-style message resource, so app code continues normally
- [ ] MS-11 Vonage-compatible endpoint
- [ ] MS-12 Laravel: capture SMS notifications (`NotificationSending` for the Vonage and Twilio channels), with an option to stop the real send in local
- [ ] MS-13 Client API `peep()->sms($to, $body, $from)` for apps with custom SMS code
- [ ] MS-14 SMS inbox UI: thread per phone number, length and segment count, Unicode detection
- [ ] MS-15 Link from an SMS to the request / job that sent it

## WH: Webhook simulator (Phase 7)

After the viewer catches a mail or SMS, it can call back a URL the user sets up, with a body format the user defines and dynamic values filled in. This simulates provider webhooks (delivered, opened, bounced, SMS status) so the app's webhook handling can be tested locally.

- [ ] WH-01 Webhook rules: trigger (mail received, SMS received, manual), target URL, HTTP method, headers, body format (JSON, form-urlencoded, XML)
- [ ] WH-02 Body template with dynamic values, e.g. `{{ mail.message_id }}`, `{{ mail.to }}`, `{{ mail.subject }}`, `{{ sms.sid }}`, `{{ sms.to }}`, `{{ sms.body }}`, `{{ event }}`, `{{ now }}`, `{{ now.unix }}`, `{{ uuid }}`, `{{ random.int }}`, plus user-defined variables
- [ ] WH-03 Template functions: `hmac_sha256`, `sha1`, `base64`, `url_encode`, date formatting
- [ ] WH-04 Presets: Mailgun events (delivered, opened, clicked, failed, bounced, complained), Twilio status callbacks (queued, sent, delivered, failed, undelivered), Vonage delivery receipts
- [ ] WH-05 Provider signatures so the app's verification passes: Mailgun (`timestamp`, `token`, `signature` with the webhook signing key), Twilio (`X-Twilio-Signature`)
- [ ] WH-06 Sequences with delays, e.g. queued, then sent after 1 s, then delivered after 3 s
- [ ] WH-07 Manual send from a mail or SMS card: pick an event and send
- [ ] WH-08 Delivery log: request sent, response status, headers, body and duration; resend button
- [ ] WH-09 Optional retry on failure (off by default)
- [ ] WH-10 "Test" button that sends a rule with sample data
- [ ] WH-11 Rules per project, saved in the settings file; import and export as JSON
- [ ] WH-12 Outbound calls only to URLs the user configured, with timeouts; never follows redirects to other hosts
- [ ] WH-13 Webhook settings page in the viewer

## DT: Desktop app, Tauri (Phase 8)

- [ ] DT-01 Tauri project in `desktop/`
- [ ] DT-02 Static PHP 8.5 binaries per platform (static-php-cli) built in CI
- [ ] DT-03 Viewer started as a sidecar (`bundle.externalBin`), stopped on quit
- [ ] DT-04 Window loads `http://127.0.0.1:23517`; reuses a server already running
- [ ] DT-05 Display mode: window, or tray only and open browser
- [ ] DT-06 `peep-server serve --open=window` launches the app when installed
- [ ] DT-07 Tray icon with unread count
- [ ] DT-08 Global hotkey to show and hide
- [ ] DT-09 Always on top (pin)
- [ ] DT-10 Native notifications
- [ ] DT-11 Auto-start at login (optional)
- [ ] DT-12 Auto-update
- [ ] DT-13 macOS signing and notarization
- [ ] DT-14 Windows signing
- [ ] DT-15 CI builds for macOS, Windows, Linux attached to the GitHub Release

## RL: Release (Phase 9)

- [ ] RL-01 Trademark search for "Peep" (USPTO, EUIPO, class 9)
- [ ] RL-02 Packagist package `shibbirweb/peep` registered with GitHub webhook
- [ ] RL-03 Release workflow: one tag releases the client, PHAR and desktop builds
- [ ] RL-04 Docs: installation (Composer, global, Docker)
- [ ] RL-05 Docs: API reference
- [ ] RL-06 Docs: Laravel guide
- [ ] RL-07 Docs: other frameworks and plain PHP
- [ ] RL-08 Docs: desktop app and display mode
- [ ] RL-09 Detect leftover calls: PHPStan rule and a `peep:clean` style command
- [ ] RL-10 Homebrew cask `peep` (if the name is free)
