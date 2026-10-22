# Peep Implementation Plan

How each part of Peep works and will be built. Read with [roadmap.md](roadmap.md) (phases) and [features.md](features.md) (checklist). Feature IDs in brackets, e.g. [CL-05], point to the checklist.

Code samples in the client sections are valid PHP 7.1 (no typed properties, arrow functions, trailing commas in calls, `match`, or any newer syntax). Code samples in the viewer sections are PHP 8.5.

## Contents

1. [Architecture](#1-architecture)
2. [Repository and tooling (Phase 0)](#2-repository-and-tooling-phase-0)
3. [Protocol v1 (Phase 1)](#3-protocol-v1-phase-1)
4. [Core client (Phase 1 and 3)](#4-core-client-phase-1-and-3)
5. [Viewer server (Phase 2 and 6)](#5-viewer-server-phase-2-and-6)
6. [Viewer UI (Phase 2 and 6)](#6-viewer-ui-phase-2-and-6)
7. [Laravel adapter (Phase 4)](#7-laravel-adapter-phase-4)
8. [Query debugging switch (Phase 4 and 6)](#8-query-debugging-switch-phase-4-and-6)
9. [Bridges for any PHP app (Phase 5)](#9-bridges-for-any-php-app-phase-5)
10. [Mail and SMS catching (Phase 7)](#10-mail-and-sms-catching-phase-7)
11. [Webhook simulator (Phase 7)](#11-webhook-simulator-phase-7)
12. [Desktop app (Phase 8)](#12-desktop-app-phase-8)
13. [Release pipeline (Phase 9)](#13-release-pipeline-phase-9)
14. [Testing strategy](#14-testing-strategy)
15. [Security rules](#15-security-rules)

## 1. Architecture

```
 App (PHP 7.1 to 8.5)                         Viewer (PHP 8.5, one process)
 ┌───────────────────────────────┐            ┌─────────────────────────────────────┐
 │ peep($x)->red()               │            │ HTTP :23517                          │
 │  Peep -> PayloadFactory       │  HTTP/JSON │  /_peep/envelope   ingest            │
 │       -> Serializer           │ ─────────▶ │  /_peep/switches   query switch etc. │
 │       -> Dispatcher           │ ◀───────── │  /_peep/locks/*    pause             │
 │       -> Transport            │  switches  │  /_peep/stream     SSE to the UI     │
 │ Laravel watchers (DB, queue,  │            │  /_peep/mail, Mailgun, Twilio, Vonage│
 │  events, cache, http, ...)    │            │  /                 UI (public/)      │
 │ Bridges (PSR-3, Monolog,      │  SMTP      │ SMTP :D5 port      mail catcher      │
 │  VarDumper, error handler)    │ ─────────▶ │ Stores: cards, mail, sms, webhooks   │
 └───────────────────────────────┘            │ Webhook runner (outbound HTTP)       │
                                              └──────────────┬──────────────────────┘
                                                             │ same page
                                          ┌──────────────────┴──────────────────┐
                                          │ Browser tab        Tauri window      │
                                          └─────────────────────────────────────┘
```

Rules that shape every part:

- **The client never hurts the app.** Short timeouts, every error caught, nothing sent unless enabled, near-zero cost when the viewer is closed.
- **The viewer is the single source of truth** for UI state, settings, switches, locks, mail, SMS and webhook rules. The browser tab and the Tauri window are only views of it.
- **Everything the app sends is data, never code.** The viewer never evaluates anything it receives.

## 2. Repository and tooling (Phase 0)

Layout (PSR-4, pds/skeleton, singular namespace folders):

```
peep/
├── composer.json            shibbirweb/peep, "php": ">=7.1"
├── config/peep.php          Laravel config
├── resources/stubs/         peep.php stub for plain PHP projects
├── src/                     ShibbirWeb\Peep\   (client + Laravel/ adapter)
├── tests/                   ShibbirWeb\Peep\Tests\
├── server/                  viewer, own composer.json, "php": ">=8.5"
│   ├── bin/peep-server
│   ├── public/              UI build output
│   ├── resources/ui/        UI source
│   ├── src/                 ShibbirWeb\PeepServer\
│   └── tests/
├── desktop/                 Tauri (src-tauri/)
└── docs/
```

Client `src/` components: `Config/`, `Transport/`, `Envelope/`, `Payload/`, `Serializer/` (with `Caster/`), `Origin/`, `Context/`, `Limiter/`, `Control/` (switches; `Switch` is a reserved word in PHP 7 namespaces), `Bridge/`, `Support/`, `Exception/`, `Laravel/`.

### Tooling [SU-07 to SU-14]

| Tool | Client (`/`) | Viewer (`server/`) |
|------|--------------|--------------------|
| PHPUnit | `^7.5.20 \|\| ^8.5.40 \|\| ^9.6 \|\| ^11.5 \|\| ^12.5` with `yoast/phpunit-polyfills` 4 (PHPUnit 10 is skipped: the polyfills do not support it); Composer picks the right one per PHP version | PHPUnit 12 |
| Style | PHP-CS-Fixer `@PSR12` | PHP-CS-Fixer `@PER-CS2.0` |
| Static analysis | PHPStan level 8, types and bugs only | PHPStan level 9 |
| Compatibility | PHP_CodeSniffer + PHPCompatibility, `testVersion 7.1-` | not needed |

Quality tools (PHPStan, PHP-CS-Fixer, PHPCompatibility) live in their own Composer project in `tools/`, because they cannot install on PHP 7.1 and would break the client's dependency resolution there. PHPStan does not check PHP 7.1 compatibility: it judges functions by the PHP it runs on, and pinning `phpVersion` to 7.1 stops it from reading the PHPUnit installed for analysis. Compatibility comes from PHPCompatibility, `php -l` on 7.1 and the tests on every version.

PHPUnit 7.5 (the only PHPUnit for PHP 7.1) is affected by advisory PKSA-z3gr-8qht-p93v (CVE-2026-24765, PHPT code coverage). Composer blocks it by default, so `composer.json` ignores that one advisory with the reason recorded; the project uses neither PHPT tests nor coverage on 7.1.

- No `composer.lock` for the client (it is a library); CI runs `composer update` on each PHP version so each gets compatible dev tools. `server/` and `tools/` commit their lock files.
- CI installs Composer 2.2 LTS on PHP 7.1 and 7.2 (Composer 2.3+ needs PHP 7.2.5).
- PHPUnit 12 lowers `error_reporting` during tests, so both `phpunit.xml.dist` files set `error_reporting=-1` and the base `TestCase` sets `E_ALL` itself.
- Tests extend the polyfill `TestCase` and use `set_up()` / `tear_down()`, so one suite runs on PHPUnit 7.5 and current versions.
- Base `TestCase` installs an error handler that throws on every warning, notice and deprecation [SU-08]. This is how "no deprecations on 8.5" is enforced.

### CI jobs (GitHub Actions)

1. `client-tests`: matrix PHP 7.1, 7.2, 7.3, 7.4, 8.0, 8.1, 8.2, 8.3, 8.4, 8.5 (`shivammathur/setup-php`).
2. `client-syntax`: `php -l` on every file in `src/` with PHP 7.1.
3. `client-quality`: PHP 8.5 runner: PHP-CS-Fixer (dry run), PHPStan, PHPCompatibility.
4. `laravel-tests`: matrix of supported Laravel versions with Orchestra Testbench (decision D1).
5. `server-tests`: PHP 8.5: tests, PHPStan, PER-CS.
6. `client-phar`: builds `peep.phar` on PHP 8.5, then runs `install`, `uninstall` and an `auto_prepend_file` script with it on PHP 7.1 and 8.5.
7. `dist-check` [SU-14]: `git archive HEAD` and assert it contains only `src/`, `config/`, `resources/`, `composer.json`, `LICENSE`, `README.md`, `CHANGELOG.md` (proves `.gitattributes` export-ignore).

## 3. Protocol v1 (Phase 1)

Written to `docs/protocol.md` [PR-01] before client or viewer code, so both sides are built against the same contract.

### Endpoints (viewer, default `127.0.0.1:23517`)

All Peep endpoints live under `/_peep/` so the provider-compatible paths (Mailgun, Twilio, Vonage) and the UI at `/` never collide.

| Method and path | Used by | Purpose |
|-----------------|---------|---------|
| `GET /_peep/health` | client, CLI, Tauri | `200 {"app":"peep","protocol":1,"version":"x.y.z"}` [PR-06] |
| `POST /_peep/envelope` | client | Ingest one envelope. `202` with switches in the body [PR-07] |
| `GET /_peep/switches?project=` | client | Current switches when nothing was sent recently |
| `GET /_peep/locks/{name}` | client | Poll a pause lock created by a `lock` payload [PR-05] |
| `GET /_peep/stream` | UI | Server-Sent Events |
| `POST /_peep/ui/*`, `GET /_peep/ui/settings`, `GET /_peep/ui/screens/{id}` | UI | UI actions: clear, lock continue and stop, switches, pause receiving, new screen, settings (webhooks in Phase 7) |
| `POST /_peep/mail`, `POST /_peep/sms` | client, any app | Generic mail and SMS capture |
| `POST /v3/{domain}/messages`, `POST /v3/{domain}/messages.mime` | apps using Mailgun API | Mailgun-compatible |
| `POST /2010-04-01/Accounts/{sid}/Messages.json` | apps using Twilio API | Twilio-compatible |
| `POST /sms/json` | apps using Vonage API | Vonage-compatible |

### Envelope [PR-02]

```json
{
  "protocol": 1,
  "card": "9b2f0c1e-6a4d-4c1b-9f53-2a7d1c0e8b11",
  "payloads": [
    {
      "type": "log",
      "content": { "values": [ { "type": "string", "value": "hello" } ] },
      "origin": { "file": "/app/src/UserService.php", "line": 42, "function": "store", "class": "App\\UserService" }
    }
  ],
  "meta": {
    "project": "my-app",
    "hostname": "dev-laptop",
    "php_version": "8.5.12",
    "peep_version": "0.1.0",
    "request": { "id": "1f6c...", "kind": "http", "label": "POST /users", "started_at": 1792663321.512 },
    "sent_at": 1792663321.734,
    "pid": 41231
  }
}
```

- `card` is one `peep()` instance. Every chained call (`->red()`, `->label()`, `->send()`, `->remove()`) reuses it, so the viewer updates the same card instead of adding a new one [PR-04]. Generated with `random_bytes(16)` formatted as UUID v4 (no dependency).
- `meta.request` groups everything from one HTTP request, CLI command or queued job [CL-12].
- Unknown fields are ignored by the viewer; new optional fields do not need a protocol bump. Removing or changing a field does.

### Payload types

`log`, `text`, `html`, `json`, `xml`, `table`, `image`, `file`, `link`, `trace`, `caller`, `exception`, `measure`, `query`, `event`, `job`, `cache`, `http`, `view`, `request`, `mail_preview`, `model`, `notify`, and card updates `color`, `size`, `label`, `hide`, `expand`, `remove`, `replace`, plus screen commands `new_screen`, `clear_screen`, `clear_all`, `show_app`, `hide_app`, and `lock`.

### Value tree [PR-03]

Values are sent as a typed JSON tree, never as pre-rendered HTML, so the UI can search, filter and collapse inside them.

```json
{
  "type": "object", "class": "App\\User", "id": 12,
  "children": [
    { "key": "email", "visibility": "public", "node": { "type": "string", "value": "a@b.c" } },
    { "key": "password", "visibility": "private", "node": { "type": "string", "value": "***", "redacted": true } },
    { "key": "manager", "visibility": "public", "node": { "type": "ref", "id": 12 } }
  ],
  "truncated": 0
}
```

Node types: `null`, `bool`, `int`, `float`, `string`, `array`, `object`, `ref` (already shown object), `resource`, `enum`, `closure`, `binary`, `max_depth`. Limits (configurable): depth 5, 100 items per array or object, 10,000 characters per string, 5,000 nodes per value; anything cut is counted in `truncated` or `length`.

### Switch response [PR-07]

```json
{
  "accepted": true,
  "switches": { "queries": "default", "slow_queries": "on", "duplicate_queries": "off", "slow_query_ms": 100 },
  "switches_ttl": 5
}
```

Each switch is `on`, `off` or `default` (follow the app's own config). See section 8.

## 4. Core client (Phase 1 and 3)

### Request flow

```
peep($user)                          helpers.php
  -> PeepFactory::instance()->make() shared Config, Dispatcher, Serializer (built once per process)
  -> Peep::send($user)               returns early if disabled, muted (if/once/limit) or rate limited
     -> PayloadFactory::forValue()   picks a payload type, serializes with ValueSerializer
     -> OriginResolver::resolve()    file and line of the user's call
     -> Envelope                     card id + payloads + meta
     -> Dispatcher::dispatch()       availability check, transport, switches update, error isolation
```

### Helper [CL-01]

```php
<?php

use ShibbirWeb\Peep\Peep;
use ShibbirWeb\Peep\PeepFactory;

if (! function_exists('peep')) {
    /**
     * @param mixed ...$values
     */
    function peep(...$values): Peep
    {
        $peep = PeepFactory::instance()->make();

        if ($values !== []) {
            $peep->send(...$values);
        }

        return $peep;
    }
}
```

`pd(...$values)` [API-20] is the same with `->die()` appended.

### Configuration [CL-03, CL-04]

Resolved once per process, later sources win:

1. Defaults: disabled, `127.0.0.1`, port `23517`, connect timeout 50 ms, total timeout 500 ms.
2. `peep.php` found by walking up from `getcwd()` and from the entry script's directory until the filesystem root (stops at the first match).
3. Environment: `PEEP_ENABLED`, `PEEP_HOST`, `PEEP_PORT`, `PEEP_PROJECT`, `PEEP_REMOTE_PATH`, `PEEP_LOCAL_PATH`, read through `getenv()`, then `$_ENV`, then `$_SERVER` (frameworks with immutable dotenv repositories do not always call `putenv`).
4. Framework config (Laravel adapter, section 7).
5. Runtime: `peep()->enable()` / `disable()`.

`Config` is an immutable value object; `withEnabled(bool)` returns a copy.

### Dispatcher and transport [CL-05 to CL-07]

```php
public function dispatch(string $card, array $payloads): void
{
    if (! $this->enabled || $payloads === [] || ! $this->availability->isUp($this->endpoint)) {
        return;
    }

    // Socket warnings must not reach the app's error handler (Laravel turns them into exceptions).
    set_error_handler(function () {
        return true;
    });

    try {
        $body = $this->encoder->encode(new Envelope($card, $payloads, $this->metaFactory->create()));

        if ($body !== null) {
            $this->transport->post(self::ENVELOPE_PATH, $body);
            $this->availability->markUp($this->endpoint);
        }
    } catch (TransportException $exception) {
        $this->availability->markDown($this->endpoint);
    } catch (\Throwable $exception) {
        // A bug inside Peep must never break the app.
    } finally {
        restore_error_handler();
    }
}
```

- **Transport choice:** `StreamTransport` when `stream_socket_client` is available and not in `disable_functions`, else `CurlTransport` when ext-curl is loaded, else `NullTransport`.
- **StreamTransport:** `stream_socket_client('tcp://host:port', $errno, $errstr, 0.05)`, `stream_set_timeout($socket, 0, 500000)`, writes a minimal HTTP/1.1 request (`Content-Type: application/json`, `Content-Length`, `Connection: close`), reads the status line and body, checks `stream_get_meta_data()['timed_out']`. Throws `TransportException` on any failure.
- **CurlTransport:** `CURLOPT_CONNECTTIMEOUT_MS => 50`, `CURLOPT_TIMEOUT_MS => 500`. Calls `curl_close()` only when `PHP_VERSION_ID < 80000` (deprecated in 8.5).
- **Fast path:** `Peep` asks `Dispatcher::isActive()` (enabled and the viewer not known to be down) before serializing a value or walking the backtrace. Measured: 100 `peep()` calls with the viewer closed take about 3 ms on PHP 7.1 and 8.5; with Peep disabled, about 0.1 ms.
- Switches from the envelope response (PR-07) are read by the client from Phase 4 (LV-31).
- **AvailabilityCache:** after a failed connect the endpoint is "down" for 30 seconds and every send returns immediately. Stored in a static array (resets per request under FPM, survives in workers), plus APCu when `apcu_enabled()` so FPM requests share it. A refused connect on localhost costs well under 1 ms, so even without APCu a closed viewer costs one fast failure per request.
- **Encoding:** `json_encode` with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION`, plus `JSON_INVALID_UTF8_SUBSTITUTE` when defined (7.2+). On 7.1, strings are checked with `preg_match('//u', $string)` first and invalid ones sent as `binary` (base64). `json_last_error()` is checked after encoding (no `JSON_THROW_ON_ERROR` on 7.1).

### Origin [CL-08, CL-09]

```php
public function resolve(): ?Origin
{
    $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40);

    foreach ($frames as $index => $frame) {
        if (! isset($frame['file']) || $this->isSkipped($frame['file'])) {
            continue;
        }

        $caller = isset($frames[$index + 1]) ? $frames[$index + 1] : [];

        return new Origin(
            $this->pathMapper->map($this->viewSource($frame['file'])),
            isset($frame['line']) ? $frame['line'] : 0,
            isset($caller['function']) ? $caller['function'] : null,
            isset($caller['class']) ? $caller['class'] : null
        );
    }

    return null;
}
```

- Frames are innermost first. The first frame whose file is outside Peep is the user's call site. `isSkipped()` checks Peep's own `src/` directory plus prefixes registered by adapters (e.g. Laravel's `Macroable` and collection files, so `collect()->peep()` points at the user's line, not the macro).
- `viewSource()`: Laravel compiled Blade files end with `/**PATH /path/to/view.blade.php ENDPATH**/`; when present, the origin points at the `.blade.php` file instead of `storage/framework/views/<hash>.php`.
- `PathMapper` replaces the `remote_path` prefix with `local_path` so IDE links work for code running in Docker or a VM.

### Serializer [CL-10, CL-11, API-35 to API-38]

- **Objects** are read with an `(array)` cast. It returns public, protected and private properties in every PHP version from 7.1 to 8.5 without Reflection and without `setAccessible()`. Mangled keys give the visibility: `"\0*\0name"` is protected, `"\0Class\0name"` is private. Integer keys are cast to string first.
- `__debugInfo()` is used when defined (called inside `try`), matching `var_dump`.
- **Loops and repeats:** objects are numbered in a `SplObjectStorage` (used through `ArrayAccess`, because `attach()` and `contains()` are deprecated in 8.5); it keeps a reference to each object, so a freed object can never be mistaken for a new one. An object seen again becomes a `ref` node.
- **Casters** run before the generic object logic, first match wins: `DateTimeCaster` (`DateTimeInterface`, Carbon included), `ThrowableCaster` (class, message, code, file, line, limited trace), `ClosureCaster` (`ReflectionFunction`: file, lines, parameters), `EnumCaster` (`$value instanceof \UnitEnum`, which is simply false on PHP below 8.1, so no version-specific file is needed), plus adapter casters (Laravel models, collections).
- **Never trigger side effects:** generators and iterators show their class only (iterating would consume them); PHP 8.4 lazy objects are checked with `ReflectionClass::isUninitializedLazyObject()` when the method exists and shown as "lazy, not initialized"; Laravel models use `getAttributes()` / `getRelations()` (no accessors, no lazy loading).
- **Numbers:** `INF`, `-INF`, `NAN` sent as strings with `type: float`; integers above 2^53 sent as strings with `type: int` (JavaScript precision).
- **Redaction:** keys matching a configurable list (`password`, `password_confirmation`, `token`, `secret`, `authorization`, `api_key`, ...) are replaced with `***` and `redacted: true`.

### Execution context [CL-12]

- One request id per HTTP request (static, so FPM resets it per request), per CLI process, and per queued job (adapters call `ExecutionContext::begin('job', $name)` / `end()`).
- `kind`: `http` when `PHP_SAPI !== 'cli'`, else `cli`; `label`: `"{$_SERVER['REQUEST_METHOD']} {$_SERVER['REQUEST_URI']}"` or the command line.
- Long-running servers (Octane, RoadRunner, Swoole) reset it through adapter hooks on request start.

### Fluent API logic [API-01 to API-34]

| Feature | Logic |
|---------|-------|
| `color()`, `label()`, `small()`, `large()`, `hide()`, `expand()` | Send an update payload for the same `card` |
| `send($value)` [API-27] | `replace` payload for the same card |
| `remove()`, `removeIf()`, `removeWhen()` [API-28] | `remove` payload; condition may be a callable |
| `pass($value)` [API-19] | Send, then return the value instead of `$this` |
| `die()` [API-20] | Send, then `exit(1)` |
| `once()` [API-21] | Key = origin `file:line`; static set; a repeat call mutes the instance so every chained call is a no-op |
| `limit($n)` [API-22] | Static counter per origin key; mute after n |
| `count($name)` [API-17] | Static counter per name (or origin); one stable card id per counter, so the viewer updates "called 12 times" in place |
| `rateLimiter()` [API-23] | Static process-wide limiter: total max and per-second window; sends one notice when the limit is hit |
| `if($condition)` [API-24] | Condition (or callable result) false mutes the rest of the chain; with a callback, the callback runs only when true and the chain continues |
| `measure()` [API-16] | `Clock`: `hrtime(true)` when it exists, else `microtime(true) * 1e9`. First call stores the start; later calls send time and memory since the previous call. `measure(callable)` times one call and uses `memory_reset_peak_usage()` when it exists (8.2+) for an accurate peak |
| `caller()`, `trace()` [API-13, API-14] | `debug_backtrace` frames after the origin, limited to 50 |
| `exception($e)` [API-15] | `ThrowableCaster` plus a source snippet (10 lines around the line, read with `file()`) |
| `catch(callable, $filter = null)` [API-18] | Runs the callback and returns its result. A thrown exception that matches the filter (none, a class name, an array of class names, or a closure whose parameter type is the class) is sent as an `exception` payload and `null` is returned; any other exception is rethrown immediately |
| `pause()` [API-26] | See below |
| `newScreen()`, `clearScreen()`, `clearAll()`, `showApp()`, `hideApp()`, `notify()` [API-29 to API-32] | Screen and app command payloads |
| `macro()` [API-33] | Static map name to callable, bound to the instance with `Closure::bind` and called from `__call` |

`pause()` [API-26]:

1. If the viewer is unavailable, return immediately.
2. `$name = bin2hex(random_bytes(8))`; send a `lock` payload; the viewer registers the lock and shows a Continue / Stop banner (`POST /_peep/ui/locks/{name}/continue` or `/stop`).
3. `set_time_limit(0)` where allowed (pause is an explicit developer action), then poll `GET /_peep/locks/{name}` every 500 ms.
4. `{"active": false}` returns; `{"active": false, "stop": true}` throws `StopExecutionException`; the viewer becoming unreachable returns.

## 5. Viewer server (Phase 2 and 6)

### Runtime

PHP 8.5 CLI with an async event loop, so one process serves HTTP, SSE streams, SMTP and outbound webhook calls at the same time. **Decided (D6): AMPHP v3** (fibers, so the code reads top to bottom; `amphp/http-server` for HTTP and SSE, `amphp/socket` for SMTP, `amphp/http-client` for webhooks with TLS).

### Start-up [VW-01, VW-10, VW-11]

```
peep-server serve [--host 127.0.0.1] [--port 23517] [--open=browser|window|none]
```

1. Load settings (below).
2. `GET http://host:port/_peep/health`. If it answers as Peep: do not start a second server; just open the chosen view and exit. If something else owns the port: exit with a clear error naming the port.
3. Bind HTTP and SMTP listeners, start the loop.
4. Open the view: browser through `open` (macOS), `xdg-open` (Linux) or `cmd /c start ""` (Windows); window through the `peep://open` deep link registered by the desktop app, falling back to the browser with a printed reason when the app is not installed.

### HTTP layer

`Router` maps method and path to small controller classes (`Http/Controller/`). Every request first passes `HostGuard` and `OriginGuard` (section 15).

### Cards and screens [VW-03, VW-06, VF-08, VF-11]

```
Card { id, project, requestId, createdAt, updatedAt, origin, payloads[], color, label, size, hidden, removed }
Screen { id, project, title, cardIds[] }
```

- Ingest: decode, validate (`protocol === 1`, required fields, size limit 8 MB), then apply each payload: content payloads append to the card (creating it on first sight), update payloads change fields of the existing card, screen payloads act on the project's screen list.
- Store: `CardStore` keeps the working set in memory (default 10,000 cards per project, oldest dropped; the `max_cards` setting) and tells a `CardStorage` about every change [VF-21]. `SqliteCardStorage` (decision D4) keeps one row per card holding its JSON in `history.sqlite` next to `settings.json`; saves are buffered and written in one transaction every 0.5 seconds, and on start-up the newest cards of each project are loaded back. `NullCardStorage` keeps nothing (history off, or no `pdo_sqlite`). SQLite calls block the event loop for a moment, which is fine for one developer's traffic.
- Screens [VF-11]: a new screen (`newScreen()` in the app or the New screen button) moves the project's cards into an archive; the last 10 per project are kept in memory and opened read-only from the toolbar. Clear drops cards without archiving.
- Pause receiving [VF-13]: while paused, envelopes are answered `202` with `accepted: false` and dropped, locks included, so a paused app is never left waiting.
- Every change is published to the SSE hub.

### Server-Sent Events [VW-05]

- `GET /_peep/stream?project=...` keeps the connection open and sends events: `card.created`, `card.updated`, `card.removed`, `screen.created`, `screen.cleared`, `lock.created`, `lock.released`, `mail.received`, `sms.received`, `webhook.delivered`, `switches.changed`, `settings.changed`.
- Each event has an increasing `id`. On reconnect the browser sends `Last-Event-ID` and the hub replays from its 1,000-event history; if the id is too old, it sends a full snapshot instead.
- A comment line every 15 seconds keeps proxies and the Tauri webview from closing idle streams.

### Locks [VF-12]

`LockRegistry` keeps `name => {project, active, stop, createdAt}`. UI Continue sets `active=false`; Stop sets `active=false, stop=true`. Locks expire after one hour.

### Settings [VF-15 to VF-20]

- File: `PEEP_CONFIG_DIR/settings.json` when set, else `%APPDATA%\peep\settings.json` (Windows), `$XDG_CONFIG_HOME/peep/settings.json`, or `~/.config/peep/settings.json` (macOS and Linux).
- Written atomically (temp file plus `rename`, mode 0600 because it can hold the token). Only the viewer writes it; the settings page and the Tauri app go through `POST /_peep/ui/settings`.
- `Settings` validates with PHP 8.4 property hooks (`ide`, `host`, `port`, `token`, `max_cards`); a broken file loads with defaults for the broken keys, while a change from the settings page is all or nothing and answers `422` naming the setting. `DisplayMode` (`Window`, `Browser`) and `Theme` (`System`, `Light`, `Dark`) are enums.
- Keys now: `display_mode`, `ide`, `path_mappings`, `host`, `port`, `theme`, `token`, `max_cards`, `history`, `notifications`, `switches` (per project). Phase 7 adds `smtp_port` and `webhooks`. `host`, `port`, `token`, `history` and `max_cards` apply on the next start; the page says so.
- `serve` uses `host`, `port` and `display_mode` when `--host`, `--port` and `--open` are not given [VF-16, VF-18]. Listening beyond loopback is refused until a token is set [VF-20].
- Token [VF-20]: `TokenGuard` runs before the other guards. Requests from loopback addresses pass. Other addresses (another machine, or Docker on Linux through the bridge) get only the client API, and only with `X-Peep-Token` equal to the token (`hash_equals`). The client sends the header when `token` / `PEEP_TOKEN` is set (both transports).

### Projects [VF-22]

Every envelope, mail and SMS carries a project name (`meta.project`, SMTP username, `X-Peep-Project` header, or `default`). The UI has a project switcher; switches and webhook rules are per project.

## 6. Viewer UI (Phase 2 and 6)

**Decided (D3): Svelte 5**, source in `server/resources/ui/`, built with Vite run through Bun (`bun install`, `bun run build`) into `server/public/`. Bun is the package manager and runtime; Vite and the official Svelte plugin do the bundling.

- **State:** a store keyed by card id, filled from the initial snapshot and updated by SSE events. Mail, SMS and webhook deliveries are separate collections.
- **List:** virtualised (only visible rows rendered) so 10,000+ cards stay smooth [VF-10]; grouped by request id with collapsible groups [VF-01].
- **Renderers** per payload type: value tree (lazy expand, search highlight), table, JSON, XML, HTML (sandboxed iframe), image, exception (stack trace with source snippet), query (SQL with bindings inlined per driver quoting, time, slow and duplicate badges) [VF-05 to VF-07].
- **Filters and search:** by kind (from payload types), color and project [VF-02 to VF-04]. Search looks in a lower-case text form of each card (values, SQL, file, request label) built once per card object and cached; it runs 150 ms after typing stops. Measured on 10,000 cards: about 220 ms for the first search, less afterwards, so no Web Worker was needed.
- **Virtual list** [VF-10]: `VirtualList.svelte` renders only rows within 1,200 px of the viewport, measures each row with a `ResizeObserver` and positions rows from running height totals. When new cards arrive on top while the user is scrolled down, or a row above the viewport changes height, the page scrolls by the same amount so the cards being read stay still. Measured: 60 fps scrolling (p95 16.7 ms) through 10,000 cards with about 20 cards in the DOM.
- **Card limit** per project, in the viewer and in the page, so a busy project never pushes a quiet one out [VF-22].
- **Query switches** [VF-25]: shown for the selected project (or the only one), with All, Slow, Duplicates and the threshold; the dot shows the state (gray app default, green on, red off). Changes are saved in the settings and reach running apps with their next envelope or within the 5-second switch TTL.
- **Settings page** [VF-15]: a dialog that loads `GET /_peep/ui/settings`; editor and path mappings apply to file links at once (mappings run after the client's own remote-to-local mapping), theme sets `data-theme` on the page [VF-17, VF-19].
- **IDE links** [VW-08]: `phpstorm://open?file={file}&line={line}`, `vscode://file/{file}:{line}`, `cursor://file/{file}:{line}`, `zed://file/{file}:{line}`, `subl://open?url=file://{file}&line={line}`, after path mapping.
- **Actions** call `POST /_peep/ui/*` with the `X-Peep-UI` header.
- **Tauri detection:** when `window.__TAURI__` exists, the UI reports unread counts and notifications to the native shell; otherwise it uses the browser Notification API [VF-14].

## 7. Laravel adapter (Phase 4)

### Service provider [LV-01 to LV-06]

`PeepServiceProvider` (auto-discovered):

- `register()`: merge `config/peep.php`; bind `PeepFactory` with Laravel config applied (`enabled` defaults to `env('PEEP_ENABLED', ! app()->environment('production'))`, project from `config('app.name')`).
- `boot()`: when enabled, register each supported watcher, macros, Blade directives and `vendor:publish` config. When disabled, nothing is registered, so production pays nothing.
- `AbstractWatcher::isSupported()` checks the event classes it needs with `class_exists()`; unsupported watchers are skipped silently on older Laravel versions.
- Request id: `RequestHandled` / `JobProcessing` / `JobProcessed` / `CommandStarting` / `CommandFinished` begin and end execution contexts; Octane's `RequestReceived` resets it when present.

### Watchers

| Watcher | Hook | Payload |
|---------|------|---------|
| Query [LV-07 to LV-12] | `Event::listen(QueryExecuted::class)` | SQL, bindings (normalised), connection, driver, time ms |
| Slow query [LV-09] | same, `time >= slow_query_ms` | as above, flagged |
| Duplicate query [LV-10] | same; key `md5(connection + sql + json(bindings))` counted per request id | first repeat creates a card, later repeats update its count |
| Event [LV-13] | `Event::listen('*', ...)` | name and payload (depth 2); ignore list for framework noise |
| Job [LV-14] | `JobQueued` (8+), `JobProcessing`, `JobProcessed`, `JobFailed` | name, queue, connection, attempts, time, exception |
| Cache [LV-15] | `CacheHit`, `CacheMissed`, `KeyWritten`, `KeyForgotten` | key, store, ttl |
| HTTP client [LV-16] | `RequestSending`, `ResponseReceived`, `ConnectionFailed` (Laravel 8+) | method, URL, headers (redacted), body, status, time |
| View [LV-17] | `View::composer('*', ...)` | name, path, data keys and values (depth 1) |
| Request [LV-18] | `RequestHandled` | method, URI, route, controller, middleware, status, duration (`LARAVEL_START`), memory |
| Log [LV-19] | `MessageLogged` | level, message, context; skipped when it carries an exception and the exception watcher is on |
| Exception [LV-20] | `MessageLogged` with `context['exception']` instanceof `Throwable` | exception payload |
| Mail [LV-21] | `MessageSending` | adds `X-Peep-Project` and `X-Peep-Request-Id` headers (links caught mail to the request) and sends a preview card |
| Dump [LV-22] | `VarDumper::setHandler()` | sends to Peep, then calls the previous handler so `dump()` output still appears (configurable) |
| Deprecation [LV-23] | chained error handler for `E_DEPRECATED` and `E_USER_DEPRECATED` | message, file, line |

Each watcher has a config key (`PEEP_SEND_QUERIES`, ...) and runtime methods (`peep()->showQueries()`, `stopShowingQueries()`), plus closure forms that enable it only inside a callback.

### Macros and Blade [LV-28, LV-29]

```php
Collection::macro('peep', function ($label = null) {
    $peep = peep($this);

    if ($label !== null) {
        $peep->label($label);
    }

    return $this;
});

Blade::directive('peep', function ($expression) {
    return "<?php peep({$expression}); ?>";
});
```

Same pattern for `Stringable`, query `Builder` (sends SQL and bindings, returns the builder) and `TestResponse`. `@peepAll` sends the view's data; `@peepMeasure` / `@endpeepMeasure` wrap `peep()->measure()`.

Notes from building it:

- Laravel-only Peep methods are macros (`Laravel/Macro/PeepMacros`), documented with `@method` tags on `Peep` for IDEs and static analysis.
- Origins skip the app's `vendor/` and the directory Laravel itself was loaded from (found from the `Application` class file); Composer's `ClassLoader` location cannot be trusted because tools such as Laravel Herd preload their own.
- Watchers type their parameters with current Laravel classes in docblocks and still check `method_exists()` / `class_exists()` for old versions; PHPStan runs with `treatPhpDocTypesAsCertain: false` so those checks are not reported as redundant.
- Test matrix: Orchestra Testbench per version; the job removes the PHPUnit polyfills (Testbench 3.5 needs PHPUnit 6), installs with `--no-plugins` (an old dependency ships a Composer plugin), and adds Guzzle for Laravel 8+ (Laravel only suggests it).

## 8. Query debugging switch (Phase 4 and 6)

Lets a developer turn query capture on and off from the viewer while the app keeps running [PR-07, LV-31, VF-25].

### Viewer side

- Switches per project: `queries`, `slow_queries`, `duplicate_queries`, each `on`, `off` or `default`, plus `slow_query_ms`.
- Shown in the toolbar at all times; changes are saved in settings and published as `switches.changed`.

### Client side

- `Control/Switches` (inside the `Dispatcher`) holds the last known values with an expiry (`switches_ttl`, 5 seconds).
- Updated for free from every ingest response.
- When a watcher needs a decision and the values have expired, the client calls `GET /_peep/switches?project=` at most once per TTL (one sub-millisecond localhost call; shared through APCu when available).
- If the viewer is unavailable, nothing is sent anyway, so the switch does not matter.

### Decision order inside the query watcher

1. Code override for this process (`peep()->showQueries()` / `stopShowingQueries()`), if set.
2. Viewer switch, if `on` or `off`.
3. App config (`PEEP_SEND_QUERIES`, ...) when the switch is `default`.

The `QueryExecuted` listener is always registered while Peep is enabled; when the decision is "off" it returns before touching the query, so nothing is collected or sent [LV-31].

## 9. Bridges for any PHP app (Phase 5)

| Bridge | Logic |
|--------|-------|
| PSR-3 `PeepLogger` [BR-01] | Implements `Psr\Log\LoggerInterface` with untyped parameters and `void` returns where psr/log 3 needs them. On PHP 7.1 only psr/log 1 installs; parameter type widening keeps the same class valid against psr/log 2 and 3 on PHP 8 |
| Monolog `PeepHandler` [BR-02] | Extends `AbstractProcessingHandler`; one class per Monolog major selected at runtime (`Logger::API` constant: 2 or 3; missing means Monolog 1) because the `write()` signature changed (array record vs `LogRecord`) |
| VarDumper `DumpHandler` [BR-03] | `VarDumper::setHandler()`, chaining the previous handler (passthrough); with no previous handler it prints through VarDumper's default once and puts itself back. VarDumper's own directory is skipped when finding the origin, so cards point at the `dump()` call. The Laravel dump watcher uses the same class |
| `VarDumperSerializer` [BR-04] | Opt-in (`serializer: var-dumper`), because casters can call code on values. `VarCloner` data is walked by `TreeDumper` (a `DumperInterface`) into the value tree; any failure falls back to Peep's own serializer. Works with VarDumper 3.4 to 8.x |
| `ErrorHandler` [BR-05] | `set_error_handler` and `set_exception_handler`, both chaining previous handlers (errors silenced by `error_reporting` or `@` are sent, marked `silenced`); `register_shutdown_function` reads `error_get_last()` for `E_ERROR`, `E_PARSE`, `E_CORE_ERROR`, `E_COMPILE_ERROR`, `E_USER_ERROR` and sends an exception card. PHP 8.5 adds a `trace` key there (`fatal_error_backtraces`), used as the card's frames; older versions get no frames rather than the shutdown handler's own |
| Global install [BR-06] | A client PHAR (`build/peep.phar`, PHP 7.1+, built with Box from `box.json.dist`, `composer build:phar`). Its stub runs `bin/peep` (install, uninstall, status, version) when executed and the prepend file when loaded through `auto_prepend_file`. `install` copies the PHAR to `~/.peep/peep.phar` and writes `99-peep.ini` (with a marker line) into the ini scan directory; it refuses to replace another `auto_prepend_file` without `--force`; `uninstall` removes only a file with the marker. The `PEEP_GLOBAL_INSTALL` constant makes Peep enabled by default (lowest priority: `peep.php` and `PEEP_ENABLED` still win) |
| Setup guides [BR-07] | `docs/guides/`: plain PHP, Symfony, Slim, WordPress, global install |

Global install versus a project that also requires `shibbirweb/peep`: the prepend file registers a lazy autoloader for `ShibbirWeb\Peep\` classes (appended), and Composer registers its own with `prepend = true`, so the project's version of the classes wins. `peep()` from the PHAR is defined first but only calls `PeepFactory`, so it works with either version.

PHP 7 lesson from this phase: never call `set_error_handler()` inside Peep's send path. On PHP 7, calling it from inside a running error handler and then restoring loses the app's handler, so a framework that logs a deprecation through Peep would stop handling errors. The dispatcher suppresses transport warnings with `@` and checks results instead.

## 10. Mail and SMS catching (Phase 7)

All mail ends up as raw MIME and goes through one parser (`zbateson/mail-mime-parser` 3), whatever way it arrived. `Inbox\Catcher` stores what was caught, publishes it to the UI and tells listeners (the webhook simulator).

### Ways mail arrives

| Way | Logic |
|-----|-------|
| SMTP [MS-01] | `Smtp\SmtpSession` is a line-based state machine without sockets (unit tested); `Smtp\SmtpServer` feeds it from an AMPHP socket on the `smtp_port` setting (default 23525, decision D5) on the viewer's host. `220` greeting, `EHLO`/`HELO` (advertises `AUTH PLAIN LOGIN`, `SIZE` 25 MB, `8BITMIME`, `SMTPUTF8`), any login accepted and its username becomes the project, `MAIL FROM`, up to 1,000 `RCPT TO`, `DATA` read until a lone `.` with dot-unstuffing, `RSET`, `NOOP`, `QUIT`; `STARTTLS` is refused. Bcc = RCPT addresses not in To or Cc. Clients on other machines must log in with the token as password; without a token they are refused |
| Laravel `peep` transport [MS-02] | `Laravel\Mail\MailTransportRegistrar` extends the mail manager (Laravel 7+) or the Swift transport manager (5.5 to 6) when they are resolved, and adds a `mailers.peep` entry when the app has none, so `MAIL_MAILER=peep` (or `MAIL_DRIVER=peep`) works without editing `config/mail.php`. Symfony Mailer (9+): `PeepSymfonyTransport`; SwiftMailer (5.5 to 8): `PeepSwiftTransport`. Both post the finished message (base64) to `/_peep/mail`, even when Peep's cards are off, and drop it when the viewer is closed. Registered even when Peep is disabled, so the mailer always resolves |
| Mailgun API [MS-03] | `POST /v3/{domain}/messages` (multipart or form: `from`, `to` repeated or comma separated, `cc`, `bcc`, `subject`, `text`, `html`, files `attachment` and `inline`, `h:*` headers, `v:*` variables as `X-Mailgun-Variables`) built into MIME by `Mail\MimeBuilder`; `/messages.mime` takes the `message` file. Answer `200 {"id": "<{YmdHis}.{16 hex}@{domain}>", "message": "Queued. Thank you."}`; the id is the stored Message-ID. Any API key is accepted. For apps using the Mailgun PHP SDK (its endpoint is configurable) or plain HTTP; Laravel's and Symfony's Mailgun transports always use `https://`, so Laravel apps use `MAIL_MAILER=peep` or SMTP instead |
| Generic [MS-04] | `POST /_peep/mail` with raw MIME (`message/rfc822`), JSON `{"raw_base64"}`, or JSON fields (`from`, `to`, `cc`, `bcc`, `subject`, `text`, `html`) |

Project: SMTP username, then `X-Peep-Project` (HTTP header, or the message header the Laravel mail watcher adds), then `default`. The request id comes from the `X-Peep-Request-Id` message header the mail watcher adds [MS-08].

Stored mail (`Mail\Mail`): id, project, request id, Message-ID, from, to, cc, bcc, subject, HTML, text, headers, attachment list, source and the raw message. The inbox (`Mail\MailStore`) is in memory: at most 1,000 mails or 256 MB, oldest dropped. Attachments are taken from the raw message when downloaded, and are always served as `application/octet-stream` with `Content-Disposition: attachment`, `nosniff` and a sandbox CSP, so app content never runs on the viewer's origin. The HTML body is shown in a sandboxed iframe without scripts, at desktop width or 375 px [MS-06].

### Ways SMS arrives

| Way | Logic |
|-----|-------|
| Generic [MS-09] | `POST /_peep/sms` JSON or form: `to`, `body` (or `text`), `from`, `project`, `request_id`, `status_callback` |
| Twilio [MS-10] | `POST /2010-04-01/Accounts/{sid}/Messages.json` with form fields `To`, `From` or `MessagingServiceSid`, `Body`, `MediaUrl`, `StatusCallback`. Answer `201` with a Twilio message resource: `sid` = `SM` + 32 hex, `status: "queued"`, `num_segments`, `direction: "outbound-api"`, `api_version`, `date_created` (RFC 2822), `uri`, `price: null`, `error_code: null`. Twilio's own errors for a missing `To` (21604) or body (21602). Project from the `X-Peep-Project` header |
| Vonage [MS-11] | `POST /sms/json` (form or JSON: `from`, `to`, `text`, `callback`). Answer `{"message-count", "messages": [{"to", "message-id", "status": "0", ...}]}` with one entry per part |
| Laravel notifications [MS-12] | `SmsWatcher` listens to `NotificationSending`. For the `vonage`, `nexmo` and `twilio` channels (names or channel classes), it calls `toVonage()` / `toNexmo()` / `toTwilio()`, reads `content` and `from` (properties or getters), gets the number from `routeNotificationFor()`, and posts to `/_peep/sms`. With `block_real_sms` (`PEEP_BLOCK_SMS`) on and outside production it returns `false`, which makes Laravel skip the real send |
| Client API [MS-13] | `peep()->sms($to, $body, $from)` posts to `/_peep/sms` through `Dispatcher::postJson()`, which adds the project and request id |

SMS details [MS-14]: segments counted as GSM-7 (160 per message, 153 per part; `^{}\[~]|€` and form feed count twice) unless any character is outside GSM-7, then UCS-2 in UTF-16 units (70, 67 per part). The inbox (`Sms\SmsStore`) is in memory, at most 2,000 messages. The UI shows one thread per project and number; opening a thread marks it read.

## 11. Webhook simulator (Phase 7)

`Inbox\Catcher` tells `Webhook\WebhookRunner` about every caught mail and SMS; the runner finds the rules that apply and calls the app back.

### Rule model [WH-01, WH-11]

```json
{
  "id": "wh_3f9a1c2b7d10",
  "project": "my-app",
  "name": "Mailgun delivered",
  "trigger": "mail.received",
  "filter": { "to_contains": "" },
  "url": "http://my-app.test/webhooks/mailgun",
  "use_status_callback": false,
  "method": "POST",
  "headers": { "User-Agent": "mailgun/treq-2" },
  "format": "json",
  "body": "{ ...template... }",
  "signature": { "type": "mailgun", "secret": "key-local-signing", "header": "", "value": "" },
  "steps": [ { "event": "accepted", "delay_ms": 0 }, { "event": "delivered", "delay_ms": 2000 } ],
  "retry": { "enabled": false, "max": 3 },
  "vars": { "account_sid": "AC..." },
  "enabled": true
}
```

`Webhook\Rule::fromArray()` validates every field and names the bad one (`422` with `field`): triggers `mail.received`, `sms.received`, `manual`; `project` `*` for every project; methods `POST`, `PUT`, `PATCH`, `GET`, `DELETE`; formats `json`, `form`, `xml`, `raw`; the URL must be `http(s)://host...` (it may contain placeholders); headers cannot set `Host`, `Content-Length`, `Transfer-Encoding` or `Connection`; up to 20 steps, each delayed 0 to 600,000 ms; retry up to 10 attempts. Rules are kept in `settings.json` under `webhooks` (`Webhook\WebhookRules`), exported as `{"peep_webhooks": 1, "rules": [...]}` and imported from that or a plain list.

### Template engine [WH-02, WH-03]

Nothing is ever evaluated as PHP. `Webhook\Template\Expression` tokenizes and parses one `{{ }}` expression by recursive descent:

```
expression := term ("~" term)*
term       := string | number | call | path | "(" expression ")"
call       := name "(" [expression ("," expression)*] ")"
path       := name ("." name)*
```

Unknown variables and functions render as an empty string and are listed as warnings on the delivery; a template that cannot be read (unclosed `{{`, string or call, invalid JSON body) fails the delivery with the reason.

- Variables (`Webhook\Variables`): `event`, `project`, `rule.id`, `rule.name`, `now` (ISO 8601 with milliseconds), `now.unix`, `now.unix_float`, `now.ms`, `mail.id`, `mail.message_id` (without `< >`), `mail.from`, `mail.from_email`, `mail.to`, `mail.to_list`, `mail.recipient` (first To address), `mail.cc`, `mail.subject`, `mail.text`, `mail.headers.<lower-case name>`, `mail.request_id`, `sms.id`, `sms.sid`, `sms.message_id` (Vonage style), `sms.to`, `sms.from`, `sms.body`, `sms.segments`, `sms.status_callback`, `sms.request_id`, `signature.*` (Mailgun) and `vars.*`.
- Functions (`Webhook\Template\Functions`): `uuid()`, `random_int(min, max)`, `random_hex(length)`, `hmac_sha256(key, data)`, `hmac_sha1(key, data)`, `sha1`, `sha256`, `md5`, `base64`, `url_encode`, `date(format, unix)` (UTC), `upper`, `lower`, `json`.
- Rendering by format (`TemplateRenderer`): `json` parses the body as JSON and renders each string leaf, and a leaf that is exactly one placeholder keeps the value's type; `form` is a JSON object of fields, each rendered as text and then URL-encoded; `xml` escapes every inserted value; `raw` inserts as is. Header values and the URL are rendered as text, with line breaks removed from headers.

### Presets [WH-04]

`Webhook\Presets` (also `GET /_peep/ui/webhooks/presets`): Mailgun events (JSON with `signature` and `event-data`; events `accepted`, `delivered`, `opened`, `clicked`, `failed`, `complained`, `unsubscribed`), Twilio status callback (form `MessageSid`, `SmsSid`, `MessageStatus`, `SmsStatus`, `To`, `From`, `AccountSid`, `ApiVersion`; `queued`, `sent`, `delivered` after 0, 1 and 3 s; uses the app's `StatusCallback`), Vonage delivery receipt (JSON), and a generic JSON body.

### Signatures [WH-05]

- **Mailgun** (before rendering, as `signature.*`): `timestamp` = Unix time, `token` = 50 random hex characters, `signature = hash_hmac('sha256', timestamp . token, signingKey)`.
- **Twilio** (after rendering, header `X-Twilio-Signature`): `base64(hmac_sha1(url . sorted fields as name followed by value, authToken))` over the exact URL called, query included; checked against the example in Twilio's documentation in the tests.
- **Custom:** a header name and a template, with the rendered body available as `body`, e.g. `{{ hmac_sha256(vars.secret, body) }}`.

### Running a rule [WH-06 to WH-10, WH-12]

1. A trigger fires: a mail or SMS is stored, a manual send from the inbox (one event or all steps) [WH-07], or Test with built-in sample data [WH-10].
2. The steps run in one fiber, each after its delay from the start.
3. Each call: build variables, sign (Mailgun), render URL, headers and body, sign (Twilio or custom), send with the AMPHP HTTP client: no redirects followed, 10-second connect, transfer and inactivity timeouts.
4. The delivery is logged (`Webhook\Delivery`: request, response with the body cut at 64 KB, duration, error, template warnings, attempt) and published as `webhook.delivered`. The log keeps the last 500 in memory. Resend sends the logged request again unchanged [WH-08].
5. Retry (only when switched on) after a network error or a status of 500 or more: 1 s, 2 s, 4 s... up to the rule's maximum [WH-09].

Target URL rules [WH-12]: the rule's URL is whatever the user typed. A status callback URL comes from captured data, so it is used only when the rule has `use_status_callback` on and its host is `localhost`, `127.0.0.1`, `[::1]`, `*.test` or `*.localhost`; any other host fails the delivery with the reason.

## 12. Desktop app (Phase 8)

Tauri 2 in `desktop/src-tauri/` (Rust: `lib.rs` for the app, `server.rs` for the viewer process, `settings.rs` for `settings.json`). The window shows the viewer's own page over `http://127.0.0.1:{port}`; a small local page (`desktop/loading/`) is shown until then and reports start-up failures.

### Start-up [DT-03 to DT-06]

1. Read `port` and `display_mode` from `settings.json`, found by the same rules as the viewer (`PEEP_CONFIG_DIR`, `%APPDATA%\peep`, `$XDG_CONFIG_HOME/peep`, `~/.config/peep`).
2. `GET http://127.0.0.1:{port}/_peep/health`. If Peep answers, reuse that server (started from the CLI or another app instance). If something else owns the port, show why.
3. Otherwise start the sidecar: the bundled static PHP runs `-d zend.assertions=-1 peep-server.phar serve --open=none --port={port} --exit-with={app pid}` (`bundle.externalBin`, the PHAR in `bundle.resources`). Poll health every 100 ms for up to 15 s (macOS scans a new app's binaries on first launch); on failure show the error page with the sidecar's output.
4. `window`: navigate the main window to the viewer. `browser`: keep the window hidden (tray only) and open the URL in the system browser.
5. On quit, stop the sidecar only if this app started it: SIGTERM first so it can save its history, then kill. `--exit-with` makes the viewer check every second that the app still runs (`posix_kill(pid, 0)`, `tasklist` on Windows) and stop when it does not, so a crash or force quit leaves nothing behind.
6. A second launch, or a `peep://open?url=...` link (from `peep-server serve --open=window`, which detects the installed app on macOS, Linux and Windows), goes to the running instance (`tauri-plugin-single-instance` with deep-link support), which shows its window; the `url` is followed only for local viewers.

### Native features [DT-07 to DT-12]

| Feature | Plugin / logic |
|---------|----------------|
| Tray with unread count | Tray menu (Show Peep, Open in browser, Always on top, Start at login, Quit). The viewer page calls `set_unread` with the unread mail and SMS count: the tray title (macOS), tooltip and dock badge |
| Global hotkey | `tauri-plugin-global-shortcut`, Cmd/Ctrl+Alt+P toggles the window (Cmd+Shift+P is left to editors) |
| Always on top | `set_pinned`, the tray item, `window.set_always_on_top` |
| Notifications | `tauri-plugin-notification` through the `notify` command, only while the window is not focused; the viewer's notifications setting decides whether the page sends them |
| `showApp()` / `hideApp()` | The page turns the viewer's `app.command` events into `show_window` / `hide_window` |
| Auto-start | `tauri-plugin-autostart`, off by default, switched in the tray menu |
| Auto-update | `tauri-plugin-updater` reading `latest.json` from GitHub Releases, in release builds that carry `TAURI_UPDATER_PUBKEY`; installs in the background and says so in a notification |
| `peep://open` | `tauri-plugin-deep-link` |
| Closing the window | Hides it; Quit is in the tray |

Security: the viewer page is a remote origin to Tauri. The `viewer` capability allows `http://127.0.0.1:*` and `http://localhost:*` to call only the five app commands (`set_unread`, `notify`, `show_window`, `hide_window`, `set_pinned`, declared in `build.rs`); no plugin APIs. The local start-up page may only listen for events.

### Bundled PHP [DT-02]

static-php-cli's published "common" builds of PHP 8.5.8 (Windows: the "spc-max" build of 8.5.5, the newest published), downloaded by `desktop/scripts/fetch-php.sh` and checked against pinned SHA-256 sums, for `aarch64-apple-darwin`, `x86_64-apple-darwin`, `x86_64-unknown-linux-gnu`, `aarch64-unknown-linux-gnu` and `x86_64-pc-windows-msvc`, named `php-{target-triple}` as Tauri's sidecar convention requires. They include every extension the viewer needs (`openssl`, `mbstring`, `pdo_sqlite`, `phar`, `zlib`, `sockets`, `pcntl`, `posix`). CI checks that the bundled PHP loads `pdo_sqlite` and `openssl` and runs the PHAR. Downloading pinned builds instead of compiling PHP in CI keeps a release build at minutes instead of most of an hour per platform; to update, change the version and sums in the script.

### CI [DT-13 to DT-15]

`.github/workflows/desktop.yml`: Rust format, clippy and tests; the viewer PHAR built once; then one build per target (macOS arm64 and x86_64 on an arm64 runner, Linux x86_64 and arm64, Windows) with `tauri-apps/tauri-action`, attached to a draft release for `v*` tags. macOS signing and notarization, Windows signing and update signing run when their secrets are set (listed in `desktop/README.md`).

## 13. Release pipeline (Phase 9)

One tag `vX.Y.Z` releases everything [RL-03] (`.github/workflows/release.yml`):

1. `scripts/set-version.sh --check X.Y.Z`: the client and viewer `Version` constants, `tauri.conf.json` and `Cargo.toml` all say X.Y.Z, and `CHANGELOG.md` has a `## [X.Y.Z]` section. `scripts/set-version.sh X.Y.Z` (without `--check`) writes them before tagging.
2. The client, Laravel and viewer workflows run as reusable workflows; all must pass.
3. Build `peep.phar` (client, global install) and `peep-server.phar` (viewer, after the UI build) with Box, with `SHA256SUMS`.
4. Create a draft GitHub Release with both PHARs, the sums and the changelog section as notes.
5. The desktop workflow (reusable) builds the static-PHP bundles for every target, signed when the certificates are set [DT-13, DT-14], and attaches them and `latest.json` to the same draft.
6. The owner reviews and publishes the draft. Packagist updates from its GitHub webhook [RL-02]; Composer installs the tag's archive, which `.gitattributes` keeps to client files (plus `bin/peep-clean` and `phpstan/rules.neon`).

### Leftover calls [RL-09]

`Clean\CallFinder` reads PHP files with `token_get_all()` (PHP 7.1+), so strings, comments, methods named `peep` and other namespaces' functions are never matched. It removes a `peep()` / `pd()` call only when it is a whole statement (`peep($x)->red();`), taking its line with it, and a `->peep()` step in a chain (the macro returns its target); other uses are reported to fix by hand. `Clean\Cleaner` walks paths (skipping `vendor/`, `node_modules/`, `storage/`, `.git/`), handles `@peep`, `@peepAll` and `@peepMeasure` in Blade files, and reports `file:line`. `bin/peep-clean` and `php artisan peep:clean` exit with 1 while calls remain, for CI. `PHPStan\NoPeepCallsRule` (identifier `peep.leftoverCall`) is opt-in through `phpstan/rules.neon`.

## 14. Testing strategy

| Layer | How |
|-------|-----|
| Client unit | Every class, on PHP 7.1 to 8.5, deprecations fail the test |
| Client integration | A tiny fake viewer (PHP built-in server script) records envelopes; asserts JSON shape, origins, card updates, timeouts and the closed-viewer fast path |
| Performance guard | 100 `peep()` calls with the viewer closed must add less than 5 ms (Phase 1 exit criterion) |
| Laravel | Orchestra Testbench per supported Laravel version: each watcher fires and is skipped cleanly where unsupported |
| Viewer unit | Router, guards, ingest, stores, template engine, signers (Twilio and Mailgun signatures checked against the providers' documented examples), SMTP state machine, MIME parsing |
| Viewer end-to-end | Start the server, run real clients (PHP 7.1 and 8.5 containers), real SMTP with Symfony Mailer, Twilio-style and Mailgun-style requests, webhook delivery to a test receiver |
| UI | Component tests for renderers; browser end-to-end for the main flows |
| Desktop | Smoke test per platform in CI: app starts, sidecar answers health, window loads |

## 15. Security rules

The viewer listens on the developer's machine, and any web page in a browser can try to send requests to `localhost`. These rules stop that.

- **Bind to `127.0.0.1`** by default. Binding elsewhere (Docker, remote) requires a `token`; clients send it as `X-Peep-Token`.
- **TokenGuard:** requests from other machines reach only the client API, and only with the token; the UI, the stream and UI actions answer only to loopback addresses, so a token never exposes the cards to the network.
- **HostGuard:** for the UI, its assets, the stream and UI actions, the `Host` header must be `127.0.0.1`, `localhost`, `[::1]` or the configured host, with the right port. Blocks DNS-rebinding attacks. The client API (`/_peep/envelope`, `/_peep/switches`, `/_peep/health`, `GET /_peep/locks/{name}`) skips it, so apps in Docker can use `host.docker.internal`: a rebinding page can only read switch states, lock states and the version there, and cannot post envelopes because browsers send `Origin` with every POST.
- **OriginGuard:** requests with an `Origin` header (browsers) are accepted only from the viewer's own origin or the Tauri origin. Server-side clients (PHP) send no `Origin`, so ingest still works; a random web page cannot post envelopes, trigger webhooks or change settings.
- **UI actions** additionally require the `X-Peep-UI` header (a custom header forces a CORS preflight, which the viewer refuses for foreign origins).
- **HTML from apps** (mail bodies, `html()` payloads) is rendered only in sandboxed iframes without `allow-same-origin`, so it cannot call the viewer's API.
- **Webhooks** only call URLs from rules, follow no redirects, and use the allow-list for callback URLs taken from captured data.
- **Redaction** of sensitive keys happens on the client, before data leaves the app.
- **Production:** the client is disabled unless explicitly enabled; the Laravel adapter registers nothing when disabled.
