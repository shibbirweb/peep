# Peep Roadmap

Peep is a debugger for PHP: call `peep($value)` anywhere in a PHP app and see values, queries, jobs, logs and exceptions live in a separate window, in the browser or in a desktop app. It also catches the mail and SMS an app sends and can simulate provider webhooks back to the app.

- Repository: `github.com/shibbirweb/peep` (one repository, open source, MIT)
- Client + Laravel adapter: Composer package `shibbirweb/peep`, PHP 7.1 to 8.5
- Viewer: `server/` folder, PHP 8.5, released as `peep-server.phar`
- Desktop app: `desktop/` folder, Tauri (macOS, Windows, Linux)

The feature checklist is in [features.md](features.md) and how each part works is in [implementation.md](implementation.md). Each feature has an ID (e.g. `CL-03`) to reference in commits and pull requests.

## How to track progress

- Tick a feature in [features.md](features.md) when it is merged with tests: `- [x]`.
- Mark work in progress by adding `(in progress)` after the feature name.
- A phase is done when every feature in it is ticked and its exit criteria below are met. Then set its status in the table.

## Phases

| Phase | Goal | Release | Status |
|-------|------|---------|--------|
| 0 | Project setup | | In progress (CI first run pending) |
| 1 | Protocol and core client MVP | | Done (CI first run pending) |
| 2 | Viewer MVP in the browser | v0.1.0 | Done (CI first run and release pending) |
| 3 | Full client API | v0.2.0 | Done (CI first run and release pending) |
| 4 | Laravel adapter | v0.3.0 | Done (CI first run and release pending) |
| 5 | Bridges for any PHP app | v0.4.0 | Done (CI first run and release pending) |
| 6 | Full viewer | v0.5.0 | Done (CI first run and release pending) |
| 7 | Mail, SMS and webhook simulator | v0.6.0 | Done (CI first run and release pending) |
| 8 | Tauri desktop app | v0.7.0 | Done except signing (certificates) and the first CI run |
| 9 | Public release | v1.0.0 | In progress: release workflow, docs and leftover-call tools done; accounts, signing and the official name search are left (release-checklist.md) |

### Phase 0: Project setup

Repository skeleton in the PSR / pds-skeleton layout, tooling, and CI, before any feature code.

Exit criteria:
- `composer install` works for the root package and for `server/`.
- CI runs on every push: client tests on PHP 7.1 to 8.5, viewer tests on 8.5, lint, static analysis, compatibility check.
- `composer require shibbirweb/peep` from a test project downloads only `src/`, `config/`, `resources/`, `composer.json`, `LICENSE` and `README.md` (`.gitattributes` export-ignore works).

### Phase 1: Protocol and core client MVP

The wire protocol is written down first, then the smallest useful client: `peep($value)` sends a value, with its file and line, to the viewer.

Exit criteria:
- Protocol v1 spec merged (`docs/protocol.md`).
- `peep('hello', ['a' => 1], $object)` sends one card with three values, with origin, from PHP 7.1 and from PHP 8.5.
- With the viewer closed, a request with 100 `peep()` calls is slowed by less than 5 ms in total.
- Disabled by default; nothing is sent unless enabled.

### Phase 2: Viewer MVP in the browser (v0.1.0)

`php peep-server.phar serve` opens a browser page that shows messages live.

Exit criteria:
- Messages appear in the browser within 100 ms of the `peep()` call.
- Clicking the origin opens the file at the line in the configured IDE.
- First release tag; PHAR attached to the GitHub Release; package listed on Packagist.

### Phase 3: Full client API (v0.2.0)

The rest of the `peep()` fluent API: formats, timing, tracing, limits, conditions, pause, screens.

Exit criteria:
- Every client API feature in [features.md](features.md) has a test on PHP 7.1 and 8.5.
- `pause()` blocks until Continue is clicked in the viewer.

### Phase 4: Laravel adapter (v0.3.0)

Auto-discovered service provider with watchers for queries, jobs, events, cache, HTTP client, views, requests, logs, exceptions, mail and dumps.

Exit criteria:
- Fresh Laravel app: `composer require shibbirweb/peep --dev` and turning on queries in `.env` shows queries with no other setup.
- Tests pass on every supported Laravel version (oldest version still to be decided, see open decisions).
- Events from one HTTP request, one queued job or one artisan command share a request id.

### Phase 5: Bridges for any PHP app (v0.4.0)

PSR-3, Monolog, VarDumper and error-handler bridges, plus the global install for apps without Composer.

Exit criteria:
- A plain PHP script (no framework) can send logs, dumps and uncaught exceptions to Peep with at most three lines of setup.
- Global install through `auto_prepend_file` works on a PHP 7.1 and a PHP 8.5 install.

### Phase 6: Full viewer (v0.5.0)

Grouping, filters, search, query view, a query debugging on/off switch that applies to running apps, pause controls, history, settings including the display mode.

Exit criteria:
- 10,000 messages in a session stay responsive (scroll, filter, search).
- Settings page changes the display mode, IDE, port and theme, saved to the settings file.
- Turning query debugging off in the viewer stops query capture in a running Laravel app within a few seconds, and turning it on starts it again, with no restart.

### Phase 7: Mail, SMS and webhook simulator (v0.6.0)

The viewer catches mail (SMTP, Laravel transport, Mailgun-style API) and SMS (Twilio / Vonage-style APIs, Laravel notifications) in an inbox, and can call back the app's webhook URLs with user-defined, dynamic, correctly signed payloads to simulate provider events.

Exit criteria:
- A Laravel app using SMTP, `MAIL_MAILER=peep`, or the Mailgun API pointed at the viewer shows every sent mail in the inbox; nothing is delivered for real.
- A Twilio-style SMS call to the viewer shows the SMS and the app receives a valid Twilio-style response.
- A webhook rule posts a Mailgun-signed "delivered" event and a Twilio-signed status callback to the app, and the app's signature checks pass.
- Delivery log shows every webhook call with its response.

### Phase 8: Tauri desktop app (v0.7.0)

Native window wrapping the same UI, with the viewer bundled as a sidecar.

Exit criteria:
- Signed installers for macOS, Windows and Linux built in CI and attached to the GitHub Release.
- Works on a machine with no PHP installed.
- Display mode switch between window and browser works from both the app and the CLI.

### Phase 9: Public release (v1.0.0)

Exit criteria:
- Trademark search for "Peep" done (USPTO, EUIPO, class 9).
- Documentation complete (install, API reference, Laravel, other frameworks, Docker, desktop app).
- Protocol v1 marked stable; semantic versioning from here on.

## Open decisions

| # | Decision | Needed before |
|---|----------|---------------|

## Decided

- Name Peep, helper `peep()`, package `shibbirweb/peep`, repository `github.com/shibbirweb/peep`, MIT.
- PHP only. Client supports PHP 7.1 to 8.5 with code valid on every version; viewer on PHP 8.5.
- One repository: client at the root (only Packagist package), viewer in `server/` (PHAR), desktop app in `desktop/`.
- PSR-4, PSR-1, PSR-12 (client), PER-CS 2.0 (viewer), PHP-FIG naming, pds/skeleton; singular namespace folders.
- Viewer runs in the browser and in a Tauri window; the user picks with the "Display mode" setting. Browser first, Tauri after.
- The viewer catches mail and SMS, and simulates provider webhooks back to the app with user-defined dynamic payloads.
- Query debugging can be switched on and off from the viewer while the app is running.
- Laravel adapter tested and supported on Laravel 5.5 to 13 (decision D1, 2026-10-22). Laravel 5.5 to 5.8 run on PHP 7.1; watchers whose events do not exist in an old version (HTTP client: 8+, Context: 11+) are skipped there.
- Viewer server: PHP 8.5 with AMPHP v3 (fibers; HTTP server, SSE, SMTP sockets and HTTPS client from one library family). Decision D6, 2026-10-22.
- Viewer UI: Svelte 5, built with Vite and run through Bun (`bun install`, `bun run build`); the build output ships inside the PHAR and the desktop app. Decision D3, 2026-10-22.
- The viewer does not accept the Ray or Symfony VarDumper server protocols for now (VF-23 and VF-24 dropped); `dump()` reaches Peep through the VarDumper bridge instead. Decision D2, 2026-10-22; can be revisited after 1.0.
- SMTP listens on port 23525 by default, next to the viewer's 23517, so it never clashes with Mailpit (1025) or Herd (2525); changeable in the settings. Decision D5, 2026-10-22.
- History across restarts in SQLite (`history.sqlite` next to `settings.json`) when `pdo_sqlite` is available, memory only otherwise or when switched off. Decision D4, 2026-10-22.
