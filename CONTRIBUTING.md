# Contributing to Peep

Thanks for helping. This guide covers the setup, the layout and the rules every change must follow. The wiki's [Contributing](docs/wiki/developer/Contributing.md) and [Releasing](docs/wiki/developer/Releasing.md) pages add what CI runs and how releases are cut.

Pull requests go into `develop`, the beta line; `main` holds stable releases and only takes merges from `develop` when a release is promoted. Add every user-visible change under **Unreleased** in `CHANGELOG.md` in the same pull request: it becomes the release notes.

## Setup

Requirements: PHP 8.5 and Composer 2 for development (the client itself supports PHP 7.1+).

```bash
composer update                 # client dependencies (no lock file: it is a library)
composer tools:install          # PHPStan, PHP-CS-Fixer, PHPCompatibility in tools/
composer install --working-dir=server
```

Viewer UI (Svelte, needs [Bun](https://bun.sh)):

```bash
composer --working-dir=server build:ui   # build into server/public
composer --working-dir=server test:ui    # UI unit tests
cd server/resources/ui && bun run dev    # hot reload; forwards /_peep to a viewer on port 23517
```

Viewer PHAR: `composer --working-dir=server build:phar` (writes `server/build/peep-server.phar`).

Checks:

```bash
composer check                  # client: tests, code style, PHPStan, PHP 7.1 compatibility
composer --working-dir=server check
composer cs:fix                 # fix client code style
```

## Repository layout

```
src/        client, namespace ShibbirWeb\Peep\ (PHP 7.1+), the only Composer package
tests/      client tests, ShibbirWeb\Peep\Tests\
server/     viewer, ShibbirWeb\PeepServer\ (PHP 8.5), own composer.json, not on Packagist
desktop/    Tauri desktop app
tools/      quality tools, kept apart because they need a newer PHP than the client
docs/plan/  roadmap, feature list and implementation plan
```

Standards: PSR-4 autoloading, PSR-1, PHP-FIG naming (`...Interface`, `Abstract...`, `...Trait`, `...Exception`), pds/skeleton layout, singular namespace folders (`Payload/`, `Watcher/`). Code style: PSR-12 for the client, PER Coding Style 2.0 for the viewer.

## Client rules: code valid on PHP 7.1 to 8.5

The client is loaded into every app being debugged, so it must parse on PHP 7.1 and run on PHP 8.5 with no warnings or deprecations.

1. **No syntax newer than PHP 7.1**, anywhere in `src/` or `tests/`. Not allowed: typed properties, arrow functions `fn() =>`, `??=`, array spread, trailing commas in calls or parameter lists, `match`, nullsafe `?->`, named arguments, union types, constructor promotion, `mixed`, enums, `readonly`, `never`, first-class callables `foo(...)`, property hooks, `|>`, `clone` with properties. A parse error cannot be caught at runtime, so this cannot be solved with an `if`.
2. **No code removed or deprecated in later versions.** Use `===`, never loose comparisons; declare every property (no dynamic properties); `?Type $x = null`, never `Type $x = null`; `"{$var}"`, never `"${var}"`; cast before passing possibly-null values to built-in functions; `(int)`, `(bool)`, `(float)` casts only; do not call `curl_close()` on PHP 8+ or `setAccessible()` on PHP 8.1+.
3. **Newer functions only behind checks.** `function_exists()`, `class_exists()` or `PHP_VERSION_ID`, with a fallback for older versions. Newer syntax, if ever needed, goes in a separate file that is only loaded on that version.
4. **Attributes are fine** on their own line (`#[\ReturnTypeWillChange]`): PHP 7 reads them as comments.
5. **No `declare(strict_types=1)` in the client**, so it never throws where PHP would coerce a value. The viewer uses strict types everywhere.
6. **The client must never break the app.** Catch `\Throwable` around anything that can fail, and never let a warning reach the app's error handler.

These rules are enforced by CI: tests on every PHP version from 7.1 to 8.5 (warnings and deprecations fail the test), `php -l` on PHP 7.1, and PHPCompatibility.

## Tests

Laravel adapter tests run against Orchestra Testbench for one Laravel version at a time (CI covers 5.5 to 13, see `.github/workflows/laravel.yml`). Locally, in a throwaway copy of the repository:

```bash
composer remove --dev --no-update yoast/phpunit-polyfills phpunit/phpunit
composer require --dev --no-plugins -W "orchestra/testbench:^11.0" "guzzlehttp/guzzle:^7.8"
vendor/bin/phpunit --configuration phpunit.laravel.xml.dist
```

- Client tests extend `ShibbirWeb\Peep\Tests\TestCase` and use `set_up()` / `tear_down()` instead of `setUp()` / `tearDown()`, so one suite runs on PHPUnit 7.5 to 12.
- Every feature needs tests before it is ticked in [docs/plan/features.md](docs/plan/features.md).

## Commits and pull requests

- Commit messages: `feat:[ID] summary` for features and `fix:[ID] summary` for bug fixes, where `ID` is the feature ID from [docs/plan/features.md](docs/plan/features.md), e.g. `feat:[CL-05] add stream transport`. Use `docs:`, `test:`, `chore:` or `ci:` for other changes.
- Tick the feature in `docs/plan/features.md` in the same pull request, and update the progress table.
- Pull request test plans: plain bullet points describing what was run and checked.
