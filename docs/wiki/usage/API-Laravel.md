# API: Laravel methods

In a Laravel app, the package's service provider (found by package discovery) adds these methods to `peep()` as [macros](API-Control.md#macros), adds `->peep()` to some Laravel classes, and registers Blade directives. Outside Laravel they do not exist, and calling one raises `BadMethodCallException`. What Laravel shows without any call (logs, exceptions, mail, `dump()`) is on the [Laravel](Laravel.md) page.

## How the show methods work

Every `show...()` method below works the same way:

- **Without a callback**, it turns the watcher on for the rest of the PHP process (the request, the command, or the queue worker) and returns the card.
- **With a callback**, it turns the watcher on only while the callback runs, then puts it back as it was, and **returns the callback's result**.
- Its `stopShowing...()` partner turns the watcher off and returns the card.

```php
peep()->showQueries();                               // from here on
$orders = peep()->showQueries(fn () => Order::with('items')->get());  // only for this
peep()->stopShowingQueries();
```

A call in code wins over the viewer's query switch, which wins over `config/peep.php`. See [Query debugging](Query-Debugging.md) for the switches.

## Queries

```php
showQueries(?callable $callback = null): mixed
queries(?callable $callback = null): mixed
stopShowingQueries(): Peep
```

Shows every query on its own card: the SQL, its bindings, the time it took, the connection and driver, and its type (`select`, `insert`, `update`, `delete` or `other`). `queries()` is the same method. `stopShowingQueries()` also forgets a type set by the methods below.

```php
showSelectQueries(?callable $callback = null): mixed
showInsertQueries(?callable $callback = null): mixed
showUpdateQueries(?callable $callback = null): mixed
showDeleteQueries(?callable $callback = null): mixed
```

The same, for one type of query only.

### Slow queries

```php
showSlowQueries(int|callable|null $thresholdOrCallback = null, ?callable $callback = null): mixed
slowQueries(int|callable|null $thresholdOrCallback = null, ?callable $callback = null): mixed
stopShowingSlowQueries(): Peep
```

Shows only queries that take at least the threshold, in milliseconds, on orange cards. Without a threshold, it uses the viewer's slow threshold, else `slow_query_ms` in the config (500 by default). All four forms work:

```php
peep()->showSlowQueries();
peep()->showSlowQueries(100);
peep()->showSlowQueries(100, fn () => $report->build());
peep()->showSlowQueries(fn () => $report->build());
```

### Repeated queries

```php
showDuplicateQueries(?callable $callback = null): mixed
stopShowingDuplicateQueries(): Peep
```

Finds the same query run again in one request: the same SQL with the same bindings on the same connection, the usual sign of an N+1 problem. Nothing is shown the first time; from the second run on, one yellow card counts the runs in place.

### Queries you choose

```php
showConditionalQueries(callable $condition, ?callable $callback = null): mixed
stopShowingConditionalQueries(): Peep
```

Calls `$condition` with each `Illuminate\Database\Events\QueryExecuted` event and shows the queries for which it returns true. With a callback, only while it runs.

```php
peep()->showConditionalQueries(function ($query) {
    return str_contains($query->sql, 'coupons');
});
```

### Counting queries

```php
countQueries(callable $callback): mixed
```

Runs the callback, shows one card with how many queries it ran and how long they took in total, and returns the callback's result. It does not show the queries themselves (unless `showQueries()` is on as well).

```php
$user = peep()->countQueries(fn () => User::with('roles', 'teams')->find($id));
```

## Other watchers

| Methods | Shows | Config key |
|---------|-------|------------|
| `showEvents()`, `stopShowingEvents()` | Every dispatched event and its payload, except the framework events listed in `ignore_events` | `send_events` |
| `showJobs()`, `stopShowingJobs()` | Queued jobs on one card per job, from queued (Laravel 8+) to processing, processed or failed | `send_jobs` |
| `showCache()`, `stopShowingCache()` | Cache hits, misses, writes and deletes | `send_cache` |
| `showHttpClientRequests()`, `stopShowingHttpClientRequests()` | Requests made with Laravel's HTTP client (Laravel 8+), one card per request, updated when the answer arrives | `send_http_client` |
| `showViews()`, `stopShowingViews()` | Rendered views with their data | `send_views` |
| `showRequests()`, `stopShowingRequests()` | Each handled request: route, controller, middleware, status, duration and memory | `send_requests` |

Each `show...()` method takes the optional callback described above. The config keys turn the watchers on for every request; all are off by default.

## Models, mail and Context

### model and models

```php
model(mixed ...$models): Peep
models(mixed ...$models): Peep
```

Shows an Eloquent model with its attributes and loaded relations, labeled with its class. Attributes are read as stored, without accessors, and relations that are not loaded are not loaded, so showing a model never runs a query. `models()` is the same method. Pass one model per call: with several, the card ends up showing only the last one.

```php
peep()->model($order->load('items'));
```

A model passed to `peep()` or `send()` is shown the same way, without the label.

### mailable

```php
mailable(object $mailable): Peep
```

Renders a mailable and shows its HTML, labeled `Mailable: ` and its subject (or its class when it has none). Anything that cannot be rendered is shown as a value.

```php
peep()->mailable(new OrderShipped($order));
```

### markdown

```php
markdown(string $markdown): Peep
```

Renders Markdown to HTML and shows it (with `Str::markdown()` on Laravel 8+, Laravel's mail Markdown parser before).

### context and hiddenContext

```php
context(string ...$keys): Peep
hiddenContext(string ...$keys): Peep
```

Shows Laravel's [Context](https://laravel.com/docs/context) (Laravel 11+): every value, or only the given keys, labeled `Context`. `hiddenContext()` shows the hidden values. Before Laravel 11, the card says that Context needs Laravel 11.

## `->peep()` on Laravel classes

Each returns the object it was called on, so the chain goes on unchanged.

| Class | Shows |
|-------|-------|
| `Collection` | The items, with an optional label: `collect($users)->peep('active users')->map(...)` |
| `Stringable` (Laravel 7+) | The string, with an optional label: `Str::of($name)->slug()->peep()` |
| Query builder and Eloquent builder | The SQL and bindings, without running the query: `User::where('active', 1)->peep()->get()` |
| `TestResponse` | The status, headers and body (decoded when it is JSON), labeled `Test response`: `$this->get('/')->peep()->assertOk()` |

## Blade

| Directive | Does |
|-----------|------|
| `@peep($user, $order)` | `peep($user, $order)` |
| `@peepAll` | Shows every variable of the view (without `__env`, `app` and `errors`), labeled `View data` |
| `@peepMeasure` ... `@endpeepMeasure` | Both are `peep()->measure()`: the second shows the time since the first |

Cards from views point at the `.blade.php` file, not the compiled view.

## Artisan

`php artisan peep:clean` lists and, with `--fix`, removes leftover calls (see [Finding leftover calls](Finding-Leftover-Calls.md)). `php artisan vendor:publish --tag=peep-config` publishes `config/peep.php`.
