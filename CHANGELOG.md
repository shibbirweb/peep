# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

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
