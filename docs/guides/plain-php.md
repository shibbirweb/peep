# Plain PHP

For scripts and apps without a framework. With Composer:

```bash
composer require shibbirweb/peep --dev
```

Without Composer, use the [global install](global-install.md) instead.

## Send values

```php
require __DIR__ . '/vendor/autoload.php';

peep('Hello');
peep($order)->green()->label('Order');
peep()->table($rows, 'Rows');
peep()->measure();            // time since the last measure() call
```

Turn Peep on with `PEEP_ENABLED=true` or a `peep.php` file (see the [guides index](README.md)).

## Errors, uncaught exceptions and fatal errors

One line, as early as possible (right after the autoloader):

```php
\ShibbirWeb\Peep\Bridge\ErrorHandler\ErrorHandler::register();
```

- Warnings, notices and deprecations show up as log cards (errors hidden by `error_reporting` or `@` too, marked `silenced`). The error handler that was there before still runs, so `display_errors` and your own handler behave as they did.
- An uncaught exception is shown as an exception card, then passed on to the previous exception handler (or PHP's default output).
- A fatal error (out of memory, a parse error in an included file, a time limit) is shown when the script shuts down.

Choose what to catch with the three arguments:

```php
ErrorHandler::register($errors = true, $exceptions = true, $fatal = true);
```

## Logs

Any library that accepts a PSR-3 logger can log to Peep (needs `psr/log` 1, 2 or 3):

```php
$logger = new \ShibbirWeb\Peep\Bridge\Psr\PeepLogger();
$logger->warning('Payment {id} failed', ['id' => 42, 'exception' => $exception]);
```

With Monolog (1, 2 or 3), add the handler to a logger. `create()` picks the right handler class for the installed Monolog version:

```php
use Monolog\Logger;
use ShibbirWeb\Peep\Bridge\Monolog\PeepHandlerFactory;

$log = new Logger('app');
$log->pushHandler(PeepHandlerFactory::create(Logger::INFO));   // INFO and above
```

## dump() and dd()

With `symfony/var-dumper` installed:

```php
\ShibbirWeb\Peep\Bridge\VarDumper\DumpHandler::register();
```

`dump()` now shows in Peep and still prints where it did before. Pass `false` to send dumps only to Peep:

```php
DumpHandler::register(false);
```

## The three lines

Everything above together:

```php
require __DIR__ . '/vendor/autoload.php';

\ShibbirWeb\Peep\Bridge\ErrorHandler\ErrorHandler::register();
\ShibbirWeb\Peep\Bridge\VarDumper\DumpHandler::register();
```
