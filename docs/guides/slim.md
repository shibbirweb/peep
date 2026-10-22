# Slim

```bash
composer require shibbirweb/peep --dev
```

Turn Peep on with `PEEP_ENABLED=true` or a `peep.php` file in the project root. Then `peep()` works in routes and middleware:

```php
$app->get('/orders/{id}', function ($request, $response, array $args) {
    peep($args)->label('Route arguments');

    return $response;
});
```

## Slim 4

Slim 4 catches exceptions in its error middleware. Log them to Peep with a PSR-3 logger, which `addErrorMiddleware()` accepts as its fifth argument:

```php
use ShibbirWeb\Peep\Bridge\Psr\PeepLogger;

$app->addErrorMiddleware(true, true, true, new PeepLogger());
```

If the app already has a Monolog logger (for example from PHP-DI), add Peep's handler to it instead:

```php
$logger->pushHandler(\ShibbirWeb\Peep\Bridge\Monolog\PeepHandlerFactory::create());
```

PHP warnings and fatal errors that happen outside Slim's middleware are caught by Peep's error handler; register it in `public/index.php` before the app is created:

```php
require __DIR__ . '/../vendor/autoload.php';

\ShibbirWeb\Peep\Bridge\ErrorHandler\ErrorHandler::register(true, false, true);
```

The second argument is `false` because Slim already handles exceptions.

## Slim 3

Slim 3 has no logger argument. Register the error handler with exceptions included, and log in your own error handler if you replaced Slim's:

```php
\ShibbirWeb\Peep\Bridge\ErrorHandler\ErrorHandler::register();

$container['errorHandler'] = function () {
    return function ($request, $response, $exception) {
        peep()->exception($exception);

        return $response->withStatus(500);
    };
};
```
