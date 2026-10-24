# API: inspecting

How a card looks, and methods that show where code runs, how long it takes and how often, or that stop it. Every method here returns the card, unless it says otherwise. See the [API reference](API-Reference.md) for how cards work.

## Colors

```php
color(string $color): Peep
red(): Peep    green(): Peep    blue(): Peep    orange(): Peep
purple(): Peep gray(): Peep     yellow(): Peep
```

Colors the card's left edge. The viewer's color filter shows only cards of the colors you pick. `color()` takes one of `red`, `green`, `blue`, `orange`, `purple`, `gray` or `yellow`; any other name is ignored and the card keeps its color. The last color set wins.

Peep uses some colors itself: exceptions are red, slow queries orange and repeated queries yellow.

```php
peep($payment)->color($payment->failed() ? 'red' : 'green');
```

## label

```php
label(string $label): Peep
```

Gives the card a title. Labels make your cards easy to find with the viewer's search, and they are how an AI agent finds its own cards (`peep cards --search="checkout input"`). Calling it again replaces the label.

## small and large

```php
small(): Peep
large(): Peep
```

Shows the card's text smaller or larger.

## hide

```php
hide(): Peep
```

Shows the card collapsed: only its header is visible until you open it. Useful for big values you only sometimes need.

## expand and expandAll

```php
expand(int|string ...$levelOrKeys): Peep
expandAll(): Peep
```

Chooses how much of the card's values is open when it appears. By default the first two levels are open.

| Call | Opens |
|------|-------|
| `expand()` | Three levels, one more than the default |
| `expand(4)` | Four levels |
| `expand('user', 'user.roles')` | The default levels, plus the path to each key; dots go deeper |
| `expand(1, 'items')` | One level, plus `items` |
| `expandAll()` | Everything |

Integers set the level (the last one wins) and strings name keys; empty strings are ignored.

## caller

```php
caller(): Peep
```

Shows where the function that contains the call was called from: one frame with its file, line, class and function, linked to your editor. Frames inside Peep itself are skipped, and Blade views point at the `.blade.php` file, not the compiled one.

```php
function chargeCard(Order $order)
{
    peep()->caller();   // shows the line that called chargeCard()
}
```

## trace and backtrace

```php
trace(int $limit = 50): Peep
backtrace(int $limit = 50): Peep
```

Shows the call stack from the line of the call outwards, at most `$limit` frames, each with file, line, class and function. Arguments are not included. `backtrace()` is the same method.

## exception

```php
exception(Throwable $exception): Peep
```

Shows an exception: class, message, code, file and line, the source lines around the line that threw, and the stack trace with editor links. Up to five previous exceptions (`getPrevious()`) are shown below it. The card turns red.

```php
try {
    $gateway->charge($order);
} catch (PaymentFailed $exception) {
    peep()->exception($exception)->label('charge failed');
    throw $exception;
}
```

Laravel shows reported exceptions without this call (see [Laravel](Laravel.md)).

## measure

```php
measure(?callable $callback = null): Peep
```

**Without a callback**, it is a stopwatch shared by the whole process. The first call starts it; every later call shows the time since the first call, the time since the previous call, and the memory in use and its peak.

```php
peep()->measure();   // started
$rows = $importer->read();
peep()->measure();   // 120 ms since the start, 120 ms since the last
$importer->save($rows);
peep()->measure();   // 480 ms since the start, 360 ms since the last
```

**With a callback**, it runs the callback and shows how long it took, the memory in use and the peak memory (on PHP 8.2 and later the peak covers only the callback; before, the whole request). It returns the card, not the callback's result. While Peep is off, the callback still runs.

```php
peep()->measure(function () use ($report) {
    $report->build();
});
```

In Blade, `@peepMeasure` and `@endpeepMeasure` time part of a view (see [Laravel methods](API-Laravel.md#blade)).

## count and counterValue

```php
count(?string $name = null): Peep
counterValue(string $name): int
```

`count()` counts how often a line runs and shows it on one card that updates in place ("Called 12 times", with the name when it has one), instead of a card per run. Without a name, each line counts on its own. With a name, every `count('name')` call adds to the same counter, wherever it is:

```php
foreach ($orders as $order) {
    peep()->count();                     // this line: called 250 times
    if ($order->isLate()) {
        peep()->count('late orders');    // one counter for this name
    }
}
```

`counterValue()` returns a named counter's current value as an integer. Counters last for the PHP process (one request, command or queue worker) and only count while Peep is active, so `counterValue()` returns 0 while Peep is off.

## catch

```php
catch(callable $callback, string|array|Closure|null $filter = null): mixed
```

Runs the callback and **returns its result**. When it throws, an exception that matches the filter is shown on the card (as with `exception()`) instead of being thrown, and `catch()` returns `null`. Exceptions that do not match are thrown on as usual.

| `$filter` | Catches |
|-----------|---------|
| `null` | Every exception |
| A class name | Exceptions of that class or a subclass |
| A list of class names | Exceptions of any of them |
| A closure | Exceptions of the class its first parameter is typed with (any member of a union type; every exception when untyped). The closure is called with the exception and the card instead of the exception being shown |

```php
$rate = peep()->catch(function () use ($api) {
    return $api->exchangeRate('EUR');
}, ConnectionException::class);

peep()->catch(fn () => $mailer->send($mail), function (TransportException $exception, $peep) {
    $peep->send($exception->getDebug())->label('SMTP conversation')->red();
});
```

The callback runs even while Peep is off; then matching exceptions are still caught, but not shown.

## pause

```php
pause(): Peep
```

Stops the script on this line until you click **Continue** in the viewer, like a breakpoint without a debugger. The card, with the file and line of the call, says "The app is paused here" above the two buttons. **Stop** ends the script by throwing `ShibbirWeb\Peep\Exception\StopExecutionException`. While paused, the script checks the viewer twice a second, and PHP's `max_execution_time` is lifted so the wait does not time out.

When Peep is off or the viewer cannot be reached, `pause()` returns at once. Never leave it in code that others run, and do not ask an AI agent to use it: nobody may be there to click.

## die

```php
die(): never
```

Stops the script with exit code 1. `peep($order)->die()` is the same as `pd($order)`: send, then stop, like `dd()`. Unlike the other methods, `die()` stops the script even while Peep is off.
