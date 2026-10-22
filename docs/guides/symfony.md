# Symfony

```bash
composer require shibbirweb/peep --dev
```

`peep()` works right away in controllers, services and commands once Peep is on (`PEEP_ENABLED=true` in `.env.local`, or a `peep.php` file in the project root). The setup below adds logs, `dump()` and values shown with VarDumper's casters.

## Logs through MonologBundle

Register the handler as a service in `config/services_dev.yaml` (or in `config/services.yaml` under `when@dev:`):

```yaml
services:
    peep.monolog_handler:
        class: Monolog\Handler\AbstractProcessingHandler
        factory: ['ShibbirWeb\Peep\Bridge\Monolog\PeepHandlerFactory', 'create']
        arguments: ['debug']
```

Then use it in `config/packages/dev/monolog.yaml` (or under `when@dev:` in `config/packages/monolog.yaml`):

```yaml
monolog:
    handlers:
        peep:
            type: service
            id: peep.monolog_handler
            channels: ['!event', '!doctrine']
```

Every log record of the selected channels now shows in Peep, and a logged exception (`$logger->error('...', ['exception' => $e])`) is shown as an exception card.

## dump() in Peep

Symfony's DebugBundle sends `dump()` to the profiler. Register Peep's handler after the bundles have booted, so the profiler keeps getting the dumps too. In `src/Kernel.php`:

```php
public function boot(): void
{
    parent::boot();

    if ($this->getEnvironment() === 'dev') {
        \ShibbirWeb\Peep\Bridge\VarDumper\DumpHandler::register();
    }
}
```

## Doctrine entities and other objects

Peep's own serializer never calls code on the values it shows. To get VarDumper's casters (Doctrine proxies, `DateTime`, DOM, Intl and more), switch the serializer in `peep.php`:

```php
return [
    'enabled' => true,
    'serializer' => 'var-dumper',
];
```

Casters can call methods on the values (a Doctrine proxy may load itself), which is why this is opt-in.

## Exceptions

Symfony already handles errors and exceptions. They reach Peep through the Monolog handler above (the `request` and `php` channels log every exception). There is no need for Peep's own `ErrorHandler` in a Symfony app.
