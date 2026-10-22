# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Project skeleton: client package (`shibbirweb/peep`, PHP 7.1+), viewer package (`server/`, PHP 8.5), quality tools (`tools/`).
- Test setup that runs one suite on PHPUnit 7.5 to 12 and fails on any warning, notice or deprecation.
- Code style (PSR-12 for the client, PER-CS 2.0 for the viewer), PHPStan, and PHPCompatibility for PHP 7.1+.
- CI for PHP 7.1 to 8.5, the viewer, and a check that Composer installs only client files.
