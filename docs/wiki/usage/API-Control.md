# API: control

Methods that decide what is sent, turn Peep on and off, drive the viewer, and extend `peep()`. Every method here returns the card, unless it says otherwise. See the [API reference](API-Reference.md) for how cards work.

## Put the value after the condition

`peep($value)` sends the value right away, before any method after it runs. So the methods below that mute a card must come **before** the value:

```php
peep()->once($user);              // right: sends the first time only
peep()->limit(3)->send($user);    // right
peep()->if($debug)->send($user);  // right

peep($user)->once();              // wrong: $user is already sent, every time
```

## once

```php
once(mixed ...$values): Peep
```

Sends only the first time this line runs; later runs of the same line send nothing, and every call on those cards does nothing. Values passed to `once()` are sent on the first run. "This line" is the file and line of the call, and the count lasts for the PHP process: one request in PHP-FPM, but every job of a queue worker or every request under Octane.

```php
foreach ($rows as $row) {
    peep()->once($row)->label('first row');
}
```

## limit

```php
limit(int $times): Peep
```

Sends from this line at most `$times` times; after that, the card is muted. Zero or less means no limit.

```php
foreach ($orders as $order) {
    peep()->limit(5)->send($order);   // the first five orders only
}
```

## if and showIf

```php
if(mixed $condition, ?callable $callback = null): Peep
showIf(mixed $condition): Peep
```

`$condition` is any value (checked for truthiness) or a closure that returns one; the closure is called once, right away.

- **Without a callback**, a false condition mutes the card: everything after it in the chain, and every later call on the card, does nothing. A true condition changes nothing.
- **With a callback**, only the callback depends on the condition: it is called with the card when the condition holds, and the card is never muted.

`showIf()` is `if()` without a callback.

```php
peep()->if($order->total > 1000)->send($order)->label('big order');

peep($order)->if($order->isLate(), function ($peep) {
    $peep->red()->label('late');
});
```

## remove, removeIf and removeWhen

```php
remove(): Peep
removeIf(mixed $condition): Peep
removeWhen(mixed $condition): Peep
```

Removes the card from the viewer. `removeIf()` and `removeWhen()` (the same method) remove it only when the condition, a value or a closure, holds. Use them for a "working..." card that should disappear when all went well:

```php
$card = peep('Syncing customers...');
$failed = $sync->run();
$card->removeIf($failed === []);
```

## rateLimiter

```php
rateLimiter(): RateLimiter
```

Returns the process-wide rate limiter, **not the card**. It counts every message the process sends to the viewer, including updates such as `label()` or `red()`.

| Method | Does |
|--------|------|
| `max(int $maxCalls)` | At most this many messages in total |
| `perSecond(int $maxCalls)` | At most this many per second |
| `clear()` | Removes both limits and starts counting again |

Zero or less removes that limit. Both methods return the limiter, so they chain. When a limit is hit, the viewer gets one notice ("Peep rate limit reached: further calls are not sent."), and later messages are dropped.

```php
peep()->rateLimiter()->max(500)->perSecond(20);
```

## enable, disable, enabled and disabled

```php
enable(): Peep
disable(): Peep
enabled(): bool
disabled(): bool
```

`enable()` and `disable()` turn Peep on or off for every later `peep()` call in this process, whatever the configuration says. `enabled()` and `disabled()` return the on or off setting as a boolean; they do not say whether the viewer can be reached (use `isActive()`).

```php
peep()->disable();
$importer->run();      // nothing from the import reaches the viewer
peep()->enable();
```

## isActive

```php
isActive(): bool
```

Whether calls on this card are sent right now: Peep is on, the viewer is not known to be down, and the card is not muted. Use it to skip work that only serves Peep:

```php
if (peep()->isActive()) {
    peep(expensiveDiagnostics());
}
```

## newScreen, clearScreen and clearAll

```php
newScreen(string $title = ''): Peep
clearScreen(): Peep
clearAll(): Peep
```

`newScreen()` moves this project's cards to **Previous screens** in the viewer and starts an empty screen, with `$title` as its first card when given. Nothing is lost: a previous screen can be opened again. `clearScreen()` clears this project's cards, and `clearAll()` the cards of every project. See [The viewer](The-Viewer.md#screens).

```php
peep()->newScreen('Checkout, attempt 2');
```

## notify

```php
notify(string $message): Peep
```

Shows the message on a card, and as a system notification while the viewer is in the background: in the desktop app, or in the browser when notifications are switched on in **Settings**. Good for long jobs: `peep()->notify('Import finished');`.

## showApp and hideApp

```php
showApp(): Peep
hideApp(): Peep
```

Brings the desktop app's window to the front, or hides it. A viewer open in a browser ignores them.

## sms

```php
sms(string $to, string $body, ?string $from = null): Peep
```

Puts an SMS in the viewer's SMS inbox instead of sending it to a phone, for apps with their own SMS code. No card is added. Webhook rules for caught SMS run as for any other caught SMS (see [Webhooks](Webhooks.md)).

```php
peep()->sms('+15550101', 'Your code is 123456', 'Shop');
```

## Macros

```php
Peep::macro(string $name, callable $macro): void
Peep::hasMacro(string $name): bool
```

`Peep::macro()` adds a method to every card. A closure is bound to the card, so `$this` is the card; the call returns what the macro returns, so return `$this` to keep chaining. `Peep::hasMacro()` says whether a macro of that name exists. Macros cannot replace built-in methods, and calling a method that is neither raises `BadMethodCallException`.

```php
use ShibbirWeb\Peep\Peep;

Peep::macro('money', function (int $cents, string $currency = 'USD') {
    return $this->text(number_format($cents / 100, 2) . ' ' . $currency)->green();
});

peep()->money($order->total_cents)->label('Order total');
```

Register macros once, early: in a service provider's `boot()`, or in a file your app loads first. The Laravel methods are macros themselves (see [Laravel methods](API-Laravel.md)).

## payload and sendCustom

```php
payload(string $type, array $content = []): Peep
sendCustom(mixed $value, string $label): Peep
```

For macros and framework adapters. `payload()` sends a payload of any type with any JSON content; the viewer shows the payloads it knows (`query`, `log`, `job` and others, see the [protocol](../../protocol.md)) and shows unknown types as JSON. `sendCustom()` is `send($value)` and `label($label)` in one call.

## card

```php
card(): string
```

Returns the card's id, a UUID, **not the card**. The viewer and the agent commands show the start of it in square brackets (`[3f2a9c1b]`).
