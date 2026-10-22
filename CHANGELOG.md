# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

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
