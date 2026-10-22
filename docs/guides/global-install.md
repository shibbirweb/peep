# Global install

The global install makes `peep()` available to every script a PHP install runs, with no Composer and no code changes: legacy apps, WordPress, one-off scripts.

It is a PHAR loaded through PHP's `auto_prepend_file`. Install it for each PHP version you use (Peep supports 7.1 to 8.5).

## Install

Download `peep.phar` from the [releases page](https://github.com/shibbirweb/peep/releases), then:

```bash
php peep.phar install
```

This copies the PHAR to `~/.peep/peep.phar` and writes `99-peep.ini` into PHP's ini scan directory. Check the result with:

```bash
php ~/.peep/peep.phar status
```

Restart PHP-FPM (or Herd, Valet, Apache) so web requests pick up the change.

Options:

| Option | Does |
|--------|------|
| `--dry-run` | Shows what would change, changes nothing |
| `--ini-dir=DIR` | Writes the ini file into `DIR` instead of the scan directory |
| `--force` | Replaces an `auto_prepend_file` that something else set |

Writing into a system directory such as `/etc/php/8.3/cli/conf.d` may need `sudo`. If the PHP has no scan directory, `install` prints the line to add to `php.ini` yourself.

PHP allows one `auto_prepend_file`. Laravel Herd, for example, uses it for its own dump capture, so `install` stops instead of replacing it. Use `--force` only if you no longer need the other one, or install Peep with Composer for those projects.

## On by default

A script loaded through the global install has Peep on unless something says otherwise, because installing it globally is already the opt-in. Turn it off:

- for one project, with a `peep.php` file returning `['enabled' => false]`;
- for one run, with `PEEP_ENABLED=false`.

When the viewer is not running, each script pays a few milliseconds once and then skips Peep.

## Projects that use Composer

A project that requires `shibbirweb/peep` uses its own version, not the PHAR. The PHAR registers its autoloader last, so Composer's autoloader loads Peep's classes first.

## Docker

Mount the PHAR into the container and load it there:

```bash
docker run --rm \
    --add-host=host.docker.internal:host-gateway \
    -e PEEP_HOST=host.docker.internal \
    -v ~/.peep:/peep \
    php:8.3-cli php -d auto_prepend_file=/peep/peep.phar script.php
```

## Uninstall

```bash
php ~/.peep/peep.phar uninstall
```

It removes the ini file only if Peep wrote it, and deletes `~/.peep/peep.phar`.
