# API reference

Everything you do with Peep starts with `peep()`. This page explains how a card works and lists every method. Each method is documented in full, with its parameters, return value, behavior and examples, on one of these pages:

| Page | Covers |
|------|--------|
| [Values](API-Values.md) | Sending values in every format, and how values are shown |
| [Inspecting](API-Inspecting.md) | The card's look, traces, exceptions, timing, counting, catching, pausing and stopping |
| [Control](API-Control.md) | Sending less, turning Peep on and off, the viewer, SMS, and macros |
| [Laravel](API-Laravel.md) | Query and watcher switches, models, mail, Context, `->peep()` on Laravel classes and Blade |

## The helpers

```php
peep(mixed ...$values): Peep
pd(mixed ...$values): never
```

`peep()` creates a new card. With values, it sends them right away, so `peep($user)` is the same as `peep()->send($user)`. It always returns the card, an instance of `ShibbirWeb\Peep\Peep`.

`pd()` sends the values, then stops the script with exit code 1, like Laravel's `dd()`.

Both helpers are defined only when no function of that name exists yet, so they never clash with another package.

## How a card works

- **One call, one card.** Each `peep()` call makes a new card in the viewer.
- **Methods chain.** Almost every method returns the same card, so you can keep calling. The exceptions are documented: `pass()`, `catch()`, `invade()`, `counterValue()`, `rateLimiter()`, `isActive()`, `enabled()`, `disabled()` and `card()` return something else.
- **Later calls update the card.** Methods called on a card change that card, not a new one. Keep the object to update it later:

```php
$card = peep('Importing...')->label('Import')->blue();

foreach ($rows as $row) {
    // ...
}

$card->send("Imported {$count} rows")->green();  // the same card, now green with the new text
```

- **Where it was called.** Each card shows the file and line of the call (mapped to your machine in Docker, see [Configuration](Configuration.md)), with a link that opens it in your editor.
- **Grouped by request.** Cards are grouped by the request, command or job that sent them, and kept apart by project. See [The viewer](The-Viewer.md).

## When nothing is sent

Peep stays out of the way when it cannot or should not send:

- **Off.** Plain PHP apps start with Peep off: turn it on with `PEEP_ENABLED=true` or a `peep.php` file. Laravel turns it on everywhere except `production`. The [global install](Global-Install.md) is on by default. See [Configuration](Configuration.md#turning-it-on).
- **No viewer.** When the viewer does not answer, Peep remembers it for 30 seconds (across PHP processes when APCu is installed) and sends nothing in that time, so a closed viewer costs a few milliseconds once.
- **Muted.** `once()`, `limit()` and `if()` can mute a card: then every later call on it does nothing.

While nothing is sent, methods do no work: values are not serialized and files are not read. They still return what they normally return, so code around them behaves the same. A problem inside Peep never reaches your app: it is swallowed and the call does nothing.

## Every method

### Values

| Method | Does |
|--------|------|
| [`send`](API-Values.md#send) | Shows values; on a card that has some, replaces them |
| [`pass`](API-Values.md#pass) | Shows a value and returns it |
| [`raw`](API-Values.md#raw) | Shows values without casters |
| [`text`](API-Values.md#text) | Text with whitespace kept |
| [`html`](API-Values.md#html) | HTML in a sandboxed frame |
| [`json`](API-Values.md#json), [`toJson`](API-Values.md#tojson) | A JSON string, or values encoded as JSON |
| [`xml`](API-Values.md#xml) | XML, indented |
| [`table`](API-Values.md#table) | An array as a table |
| [`image`](API-Values.md#image) | An image from a URL, file, data URI or base64 |
| [`file`](API-Values.md#file) | A file's contents |
| [`link`, `url`](API-Values.md#link-and-url) | A clickable link |
| [`date`](API-Values.md#date) | A date to the microsecond |
| [`className`](API-Values.md#classname) | An object's class |
| [`invade`](API-Values.md#invade) | Private properties and methods |
| [`phpinfo`](API-Values.md#phpinfo) | PHP's configuration |
| [`separator`](API-Values.md#separator) | A divider |

### Inspecting

| Method | Does |
|--------|------|
| [`color`, `red()` ... `yellow()`](API-Inspecting.md#colors) | Colors the card |
| [`label`](API-Inspecting.md#label) | A title for the card |
| [`small`, `large`](API-Inspecting.md#small-and-large) | Text size |
| [`hide`](API-Inspecting.md#hide) | Shows the card collapsed |
| [`expand`, `expandAll`](API-Inspecting.md#expand-and-expandall) | Opens nested values |
| [`caller`](API-Inspecting.md#caller) | Where the current function was called from |
| [`trace`, `backtrace`](API-Inspecting.md#trace-and-backtrace) | The call stack |
| [`exception`](API-Inspecting.md#exception) | An exception with its trace and source |
| [`measure`](API-Inspecting.md#measure) | Time and memory |
| [`count`, `counterValue`](API-Inspecting.md#count-and-countervalue) | How often a line runs |
| [`catch`](API-Inspecting.md#catch) | Shows exceptions instead of throwing them |
| [`pause`](API-Inspecting.md#pause) | Waits for a click in the viewer |
| [`die`](API-Inspecting.md#die) | Stops the script |

### Control

| Method | Does |
|--------|------|
| [`once`](API-Control.md#once), [`limit`](API-Control.md#limit) | Sends only the first times a line runs |
| [`if`, `showIf`](API-Control.md#if-and-showif) | Sends only when a condition holds |
| [`remove`, `removeIf`, `removeWhen`](API-Control.md#remove-removeif-and-removewhen) | Removes the card |
| [`rateLimiter`](API-Control.md#ratelimiter) | Process-wide limits |
| [`enable`, `disable`, `enabled`, `disabled`](API-Control.md#enable-disable-enabled-and-disabled) | Turns Peep on or off, or asks |
| [`isActive`](API-Control.md#isactive) | Whether calls are sent right now |
| [`newScreen`, `clearScreen`, `clearAll`](API-Control.md#newscreen-clearscreen-and-clearall) | Screens and clearing |
| [`notify`](API-Control.md#notify) | A notification |
| [`showApp`, `hideApp`](API-Control.md#showapp-and-hideapp) | The desktop app's window |
| [`sms`](API-Control.md#sms) | An SMS in the viewer's inbox |
| [`Peep::macro`, `Peep::hasMacro`](API-Control.md#macros) | Your own methods |
| [`payload`, `sendCustom`](API-Control.md#payload-and-sendcustom) | Payloads for macros and adapters |
| [`card`](API-Control.md#card) | The card's id |

### Laravel

`showQueries()` and the other query switches, `countQueries()`, `showEvents()`, `showJobs()`, `showCache()`, `showHttpClientRequests()`, `showViews()`, `showRequests()`, `model()`, `mailable()`, `markdown()`, `context()`, `hiddenContext()`, `->peep()` on collections, strings, query builders and test responses, and the Blade directives. See [Laravel methods](API-Laravel.md).

## Finding leftover calls

`vendor/bin/peep-clean` lists the `peep()`, `pd()`, `->peep()` and `@peep` calls left in your code, and `--fix` removes the safe ones. A PHPStan rule reports them in CI. See [Finding leftover calls](Finding-Leftover-Calls.md).
