# API: values

Methods that put something on a card. Every method here returns the card, so calls chain, unless it says otherwise. See the [API reference](API-Reference.md) for how cards work.

## How values are shown

Values become a tree you can open in the viewer: scalars, arrays and objects with their properties and visibility. To keep cards fast and safe:

| Limit | Default | Setting |
|-------|---------|---------|
| Nesting depth | 5 levels | `max_depth` |
| Items per array or object | 100 | `max_items` |
| Characters per string | 10,000 | `max_string_length` |
| Nodes per value | 5,000 | `max_nodes` |

What is cut off is marked (`+40 more`). Values under keys named `password`, `password_confirmation`, `secret`, `token`, `api_key`, `apikey`, `authorization` or `cookie` (any case) are shown as `***`; change the list with `redact_keys`. Set these in `peep.php` or `config/peep.php` (see [Configuration](Configuration.md)).

Some objects are shown more usefully by casters: dates as `2026-10-24 09:15:02.123456 +06:00`, exceptions with their message, closures with their signature, and enums as `Status::Paid`. Laravel adds models and collections (see [Laravel methods](API-Laravel.md)). An object seen twice in one value is shown once, with a reference after that. Peep never calls your methods to show an object, unless you choose the `var-dumper` serializer (`PEEP_SERIALIZER=var-dumper`), which uses Symfony VarDumper's casters.

Integers larger than JavaScript can hold keep every digit, `NAN` and `INF` are shown as such, and strings that are not UTF-8 are shown as binary.

## send

```php
send(mixed ...$values): Peep
```

Shows one or more values on the card. `peep($a, $b)` calls it for you. Called again on a card that already shows values, it replaces them instead of adding more, so a card can show the latest state:

```php
$progress = peep(0)->label('Progress');
foreach ($batches as $index => $batch) {
    $progress->send($index + 1);   // the card shows 1, then 2, then 3
}
```

Without values, it does nothing.

## pass

```php
pass(mixed $value): mixed
```

Shows the value and **returns the value**, not the card, so you can wrap an expression without changing the code around it:

```php
return peep()->pass($cart->total());
$user = peep()->pass(User::find($id));
```

## raw

```php
raw(mixed ...$values): Peep
```

Shows values without casters: every object with its properties exactly as stored, without the date, exception, closure, enum or model views. Use it when a caster hides the property you need.

## text

```php
text(string $text): Peep
```

Shows text as written: spaces, tabs and line breaks are kept, and HTML in it is shown as text, never rendered.

```php
peep()->text($process->getOutput());
```

## html

```php
html(string $html): Peep
```

Renders HTML in a sandboxed frame on the card. Scripts in it do not run and it cannot reach the viewer.

## json

```php
json(string $json): Peep
```

Shows a JSON string pretty-printed. A string that is not valid JSON is shown as it is, grayed out. Use it for a JSON body you already have as text.

## toJson

```php
toJson(mixed ...$values): Peep
```

Encodes each value with `json_encode()` and shows each as JSON, on its own block. Unlike `send()`, you see exactly what your API would return: `JsonSerializable` objects use their `jsonSerialize()`, and private properties are left out. A value that cannot be encoded shows the reason: `json_encode() failed: Malformed UTF-8 characters`.

```php
peep()->toJson($apiPayload);
```

## xml

```php
xml(string $xml): Peep
```

Shows an XML string, indented.

## table

```php
table(array $rows, ?string $label = null): Peep
```

Shows an array as a table. When every item is itself an array or object (a list of rows), each key becomes a column, the first column holds the row's key, and a row without a key has an empty cell. Otherwise it is a two-column table of keys and values. `$label` is shown above it. The value limits above apply.

```php
peep()->table([
    ['id' => 1, 'email' => 'ada@example.test'],
    ['id' => 2, 'email' => 'grace@example.test'],
], 'New users');
```

## image

```php
image(string $source): Peep
```

Shows an image. `$source` can be:

- a URL: `https://...`, `http://...` or `//...`, loaded by the viewer;
- a data URI: `data:image/png;base64,...`;
- a file path on the app's machine: read and embedded, up to 5 MB, with the type taken from the extension (`png`, `jpg`, `gif`, `webp`, `svg`, `avif`, `bmp`, `ico`; anything else is sent as PNG);
- bare base64 data, taken as PNG.

Anything else, or a file over 5 MB, shows the reason on the card instead of an image.

```php
peep()->image(storage_path('app/avatars/18.png'));
```

## file

```php
file(string $path): Peep
```

Shows a file's contents, read on the app's machine. Only the first 1 MB is read, and the card says when the file is longer. Files that are not UTF-8 text are sent as binary. The path shown is mapped to your machine like the call site. A missing or unreadable file shows that on the card.

## link and url

```php
link(string $url, ?string $label = null): Peep
url(string $url, ?string $label = null): Peep
```

Shows a link, with `$label` as its text when given. The viewer only makes `http:`, `https:` and `mailto:` links clickable; anything else is shown as text. `url()` is the same method.

```php
peep()->link(route('orders.show', $order), 'Open the order');
```

## date

```php
date(mixed $date): Peep
```

Shows a date. A `DateTimeInterface` (including Carbon) is shown to the microsecond with its UTC offset, such as `2026-10-24 09:15:02.123456 +06:00`, and opens to its `date`, `timezone` name and unix `timestamp`. Other values are shown as they are; `date()` is `send()` with a name that says what you mean.

## className

```php
className(object $object): Peep
```

Shows the object's fully qualified class name.

## invade

```php
invade(object $object): Invader
```

Returns a helper, **not the card**: reading a property on it, or calling a method, gives you the private value or the result, and shows it on the card with the label `Class::$property` or `Class::method()`.

```php
$total = peep()->invade($order)->secretTotal;
$discount = peep()->invade($order)->calculateDiscount(10);
```

It reaches members declared in the object's own class, including private ones; private members of a parent class are not visible this way. The value is returned even while Peep is off.

## phpinfo

```php
phpinfo(string ...$keys): Peep
```

Without arguments, shows a table of the PHP version, SAPI, operating system, loaded `php.ini`, `memory_limit`, `max_execution_time`, `error_reporting`, `display_errors`, `opcache.enable` and the loaded extensions. With setting names, shows only those settings, and `(unknown setting)` for names PHP does not know.

```php
peep()->phpinfo('memory_limit', 'upload_max_filesize', 'post_max_size');
```

## separator

```php
separator(): Peep
```

Adds a divider to the card, to separate groups of values.
