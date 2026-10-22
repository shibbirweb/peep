# Laravel

```bash
composer require shibbirweb/peep --dev
```

Laravel 5.5 to 13 discover the package by themselves. Peep is on in every environment except `production` (force it with `PEEP_ENABLED`). Publish the config to change what is captured:

```bash
php artisan vendor:publish --tag=peep-config
```

## What shows up without any call

| Setting (`config/peep.php`) | Default | Shows |
|-----------------------------|---------|-------|
| `send_logs` | on | Log messages, with their context; logged exceptions as exception cards |
| `send_exceptions` | on | Reported exceptions |
| `send_mail` | on | A preview card of every mail sent, linked to the inbox |
| `send_sms` | on | SMS notifications in the SMS inbox |
| `send_dumps` | on | `dump()` and `dd()`, which still print as usual (`dumps_passthrough`) |
| `send_queries` | off | Every query (`query_types` picks which kinds) |
| `send_slow_queries`, `slow_query_ms` | off, 500 | Queries slower than the threshold |
| `send_duplicate_queries` | off | Queries repeated within a request (N+1) |
| `send_events`, `send_jobs`, `send_cache`, `send_http_client`, `send_views`, `send_requests` | off | The other watchers |
| `send_deprecations` | off | PHP and framework deprecations |

Every card carries the request, job or command it came from, and the viewer groups them.

## Query debugging from the viewer

The toolbar of the viewer has **Queries** switches for each project: All, Slow (with a threshold) and Duplicates, each on, off, or "App default" (the config). A running app picks up a change within five seconds, with no restart.

## In code

```php
peep()->showQueries();                                  // from here on
$orders = peep()->showQueries(fn () => Order::with('items')->get());   // only inside
peep()->showSlowQueries(100);
peep()->showDuplicateQueries();
peep()->countQueries(fn () => $order->load('items'));   // how many queries, and their total time

peep()->showJobs()->showHttpClientRequests();
peep()->model($user);                                   // attributes, changes, relations
peep()->mailable(new InvoiceMail($order));              // rendered
collect($rows)->peep()->sum('total');                   // ->peep() passes the collection on
User::where('active', true)->peep()->get();             // the SQL with bindings
$this->get('/orders')->peep()->assertOk();              // a test response
```

The full list is in the [API reference](../api.md#laravel).

## Blade

```blade
@peep($order)
@peepAll          {{-- every variable the view has --}}
@peepMeasure      {{-- time since the last @peepMeasure --}}
```

## Mail and SMS

```dotenv
MAIL_MAILER=peep      # Laravel 7+ (MAIL_DRIVER=peep on 5.5 to 6): every mail goes to the viewer's inbox
PEEP_BLOCK_SMS=true   # SMS notifications go to the inbox only, never to a phone (never in production)
```

See [Mail and SMS](mail-and-sms.md) and, to simulate delivery webhooks back to your app, [Webhooks](webhooks.md).

## Docker (Sail)

```dotenv
PEEP_HOST=host.docker.internal
PEEP_REMOTE_PATH=/var/www/html
PEEP_LOCAL_PATH=/Users/you/code/shop
```

On Linux, also see [Docker and other machines](installation.md#docker-and-other-machines).

## Before you ship

```bash
php artisan peep:clean          # lists leftover peep() calls; exit code 1 when there are any
php artisan peep:clean --fix
```
