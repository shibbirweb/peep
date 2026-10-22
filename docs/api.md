# API reference

`peep()` returns a card. Every method returns the card, so calls chain; each `peep()` call is one card in the viewer, and later calls on the same card update it.

```php
peep($order)->green()->label('Order')->expand(2);
```

Nothing is sent while Peep is off (the default outside development), and a closed viewer costs a few milliseconds once, then nothing.

## Values

| Method | Shows |
|--------|-------|
| `peep(...$values)`, `send(...$values)` | One or more values; on a card that already shows values, replaces them |
| `pass($value)` | The value, and returns it: `return peep()->pass($total);` |
| `raw(...$values)` | Values without casters: every property as stored |
| `text(string $text)` | Text with whitespace kept, never rendered as HTML |
| `html(string $html)` | HTML, in a sandboxed frame |
| `json(string $json)` | A JSON string, pretty-printed |
| `toJson(...$values)` | Values encoded with `json_encode()` |
| `xml(string $xml)` | XML, indented |
| `table(array $rows, ?string $label = null)` | An array as a table |
| `image(string $source)` | An image from a URL, a file path, a data URI or base64 |
| `file(string $path)` | A file's contents (the first 1 MB) |
| `link(string $url, ?string $label = null)`, `url(...)` | A link (http, https and mailto only) |
| `date($date)` | A date, to the microsecond, with its UTC offset |
| `className($object)` | An object's class |
| `invade($object)` | An object whose private properties and methods you can read and call: `peep()->invade($service)->cache` |
| `phpinfo(string ...$keys)` | PHP's configuration, or the given ini settings |
| `separator()` | A divider |

## Look of the card

| Method | Does |
|--------|------|
| `color(string $color)`, `red()`, `green()`, `blue()`, `orange()`, `purple()`, `gray()`, `yellow()` | Colors the card (and lets you filter by color) |
| `label(string $label)` | A title for the card |
| `small()`, `large()` | Text size |
| `hide()` | Shows the card collapsed |
| `expand(...$levelOrKeys)`, `expandAll()` | Opens nested values: `expand()` one level more, `expand(3)` three levels, `expand('user', 'user.roles')` those keys |

## Where and when

| Method | Does |
|--------|------|
| `caller()` | Shows where the function containing the call was called from |
| `trace(int $limit = 50)`, `backtrace(...)` | Shows the call stack |
| `exception(Throwable $exception)` | Shows an exception with its trace and source (red) |
| `measure(?callable $callback = null)` | Without a callback, the first call starts a stopwatch and later calls show the time and memory since the start and since the previous call. With a callback, times that call |
| `count(?string $name = null)` | Counts how often this line (or a named counter) runs; one card updates in place |
| `counterValue(string $name)` | Returns a named counter's value |
| `catch(callable $callback, $filter = null)` | Runs the callback; an exception matching the filter (a class name, a list of them, or a closure whose parameter type names the class, which is then called with the exception and the card) is shown instead of thrown |
| `pause()` | Stops until you click Continue in the viewer; Stop throws `StopExecutionException` |
| `die()`, `pd(...$values)` | Sends, then stops the script (like `dd()`) |

## Sending less

| Method | Does |
|--------|------|
| `once(...$values)` | Sends only the first time this line runs |
| `limit(int $times)` | Sends at most that many times from this line |
| `if($condition, ?callable $callback = null)` | Without a callback, the rest of the chain only runs when the condition (a value or a closure) holds; with one, calls it with the card when it holds |
| `showIf($condition)` | Sends only when the condition (a value or a closure) is truthy |
| `remove()`, `removeIf($condition)`, `removeWhen($condition)` | Removes the card |
| `rateLimiter()` | Process-wide limits: `peep()->rateLimiter()->max(100)->perSecond(10)` |
| `enable()`, `disable()`, `enabled()`, `disabled()` | Turns Peep on or off for the rest of the process, or asks |
| `isActive()` | Whether calls are sent right now (on, viewer reachable, not muted) |

## The viewer

| Method | Does |
|--------|------|
| `newScreen(string $title = '')` | Moves the project's cards to "Previous screens" and starts a new one |
| `clearScreen()`, `clearAll()` | Clears this project's cards, or every project's |
| `notify(string $message)` | A notification (in the desktop app or the browser, when switched on in Settings) |
| `showApp()`, `hideApp()` | Shows or hides the desktop app's window |
| `sms(string $to, string $body, ?string $from = null)` | Puts an SMS in the viewer's SMS inbox instead of sending it |

## Extending

| Method | Does |
|--------|------|
| `Peep::macro(string $name, callable $macro)` | Adds a method: `Peep::macro('money', function ($amount) { return $this->text(number_format($amount, 2)); });` |
| `Peep::hasMacro(string $name)` | Whether a macro exists |
| `payload(string $type, array $content = [])` | Sends a payload of any type (the viewer shows unknown types as JSON) |
| `sendCustom($value, string $label)` | A value with a label, in one call |

## Laravel

With the Laravel package (auto-discovered), these are added:

| Method | Does |
|--------|------|
| `showQueries(?callable $callback = null)`, `queries(...)`, `stopShowingQueries()` | Every query with its bindings and time. With a callback, only while it runs, and returns its result |
| `showSelectQueries()`, `showInsertQueries()`, `showUpdateQueries()`, `showDeleteQueries()` | Queries of one type |
| `showSlowQueries($thresholdMs = null, ?callable $callback = null)`, `slowQueries(...)`, `stopShowingSlowQueries()` | Queries slower than the threshold (default: `slow_query_ms`) |
| `showDuplicateQueries(?callable $callback = null)`, `stopShowingDuplicateQueries()` | The same query run again (N+1) |
| `showConditionalQueries(callable $condition, ?callable $callback = null)`, `stopShowingConditionalQueries()` | Queries for which the condition returns true |
| `countQueries(callable $callback)` | Runs the callback and shows how many queries it ran, and how long they took |
| `showEvents()`, `showJobs()`, `showCache()`, `showHttpClientRequests()`, `showViews()`, `showRequests()` and their `stopShowing...()` | The other watchers; each takes an optional callback too |
| `model(...$models)`, `models(...)` | Models with their attributes, changes and loaded relations |
| `mailable($mailable)` | A rendered mailable |
| `markdown(string $markdown)` | Markdown rendered as HTML |
| `context(string ...$keys)`, `hiddenContext(...)` | Laravel's Context (Laravel 11+) |

Also `->peep()` on collections, `Str::of()` strings, query builders (shows the SQL) and test responses, and the Blade directives `@peep($value)`, `@peepAll` (every view variable) and `@peepMeasure`.

## Finding leftover calls

```bash
vendor/bin/peep-clean           # lists peep(), pd(), ->peep() and @peep calls; exit code 1 when there are any
vendor/bin/peep-clean --fix     # removes the ones that can go safely
php artisan peep:clean --fix    # the same, in Laravel
```

A call is removed when it is a statement on its own (`peep($x)->red();`) or a `->peep()` step in a chain. Calls used as a value, such as `$user = peep($user);`, are listed to fix by hand. For CI, the PHPStan rule reports every call:

```neon
includes:
    - vendor/shibbirweb/peep/phpstan/rules.neon
```
