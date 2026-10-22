# Peep Protocol v1

The contract between the Peep client (inside a PHP app) and the Peep viewer. Any client that follows it can send to the viewer.

- Transport: HTTP/1.1, JSON bodies encoded as UTF-8.
- Default address: `http://127.0.0.1:23517`.
- Protocol version: `1`.

## Versioning

- Every envelope carries `"protocol": 1`.
- Adding optional fields, payload types or endpoints does not change the version. Receivers ignore fields and payload types they do not know.
- Removing a field, changing its meaning or making an optional field required needs version 2. The viewer answers `400` with `{"error": "unsupported_protocol", "supported": [1]}` to versions it does not support.

## Endpoints

All Peep endpoints are under `/_peep/`, so provider-compatible paths (Mailgun, Twilio, Vonage) and the UI at `/` never collide with them.

| Method and path | Purpose | Success |
|-----------------|---------|---------|
| `GET /_peep/health` | Is a Peep viewer listening here? | `200` |
| `POST /_peep/envelope` | Send one envelope | `202` |
| `GET /_peep/switches?project={project}` | Read the switches for a project | `200` |
| `GET /_peep/locks/{name}` | Poll a pause lock (created by a `lock` payload) | `200` |

Requests from the viewer's own machine need nothing else. Requests from other machines (including Docker on Linux, which connects through the bridge network) are accepted only when the viewer has a token in its settings (needed before it listens on anything other than loopback), only on the four endpoints above, and only with that token in the `X-Peep-Token` header:

| Situation | Answer |
|-----------|--------|
| Other machine, viewer has no token | `403` `remote_disabled` |
| Other machine, any other path (UI, stream, UI actions) | `403` `local_only` |
| Other machine, missing or wrong token | `401` `invalid_token` |

The client sends `X-Peep-Token` when its `token` option (`PEEP_TOKEN`) is set.

### Health

```http
GET /_peep/health
```

```json
{ "app": "peep", "protocol": 1, "version": "0.1.0" }
```

Clients and tools use this to tell a Peep viewer apart from another program on the same port: anything that does not answer with `"app": "peep"` is not a Peep viewer.

### Envelope

```http
POST /_peep/envelope
Content-Type: application/json
```

Response `202`:

```json
{
  "accepted": true,
  "switches": { "queries": "default", "slow_queries": "on", "duplicate_queries": "off", "slow_query_ms": 100 },
  "switches_ttl": 5
}
```

`400` with `{"error": "...", "message": "..."}` when the envelope is invalid; `413` when it is larger than 8 MB.

While the user has paused receiving in the viewer, the answer is still `202`, with `"accepted": false, "paused": true` and the switches: the envelope is dropped, and a `lock` in it is not created (the app's `pause()` goes on at once).

### Switches

```http
GET /_peep/switches?project=my-app
```

Same `switches` and `switches_ttl` fields as the envelope response. See [Switches](#switches-1).

### Locks

A lock is created by sending a `lock` payload (`{"name": "a3f9c2d17b4e8a10"}`, 1 to 64 characters from `A-Z a-z 0-9 _ -`) in an envelope. The viewer shows the card with Continue and Stop buttons. The client then polls:

```http
GET /_peep/locks/a3f9c2d17b4e8a10
```

```json
{ "active": true, "stop": false }
```

- `active: true`: keep waiting.
- `active: false, stop: false`: the user clicked Continue.
- `active: false, stop: true`: the user clicked Stop; the client throws so the request ends.
- `404`: unknown or expired lock; the client continues.

Clients poll every 500 ms. Locks expire after one hour.

### Mail and SMS

Apps send mail and SMS to the viewer instead of a provider. These endpoints are part of the client API (other machines may use them with the token).

| Method and path | Body | Answer |
|-----------------|------|--------|
| `POST /_peep/mail` | Raw MIME (`Content-Type: message/rfc822`), JSON `{"raw_base64": "..."}`, or JSON `{"from", "to", "cc", "bcc", "subject", "text", "html"}` (`to`, `cc`, `bcc`: a list or a comma-separated string) | `202 {"id", "message_id"}` |
| `POST /v3/{domain}/messages` | Mailgun's fields, multipart or form | `200 {"id": "<...>", "message": "Queued. Thank you."}` |
| `POST /v3/{domain}/messages.mime` | Mailgun's `to` and `message` (file) | as above |
| `POST /_peep/sms` | JSON or form: `to`, `body`, `from`, `status_callback` | `202` with the stored SMS |
| `POST /2010-04-01/Accounts/{sid}/Messages.json` | Twilio's form fields | `201` Twilio message resource |
| `POST /sms/json` | Vonage's fields, form or JSON | `200` Vonage answer |

The project is taken from the `X-Peep-Project` header, then a `project` field (or, for mail, the `X-Peep-Project` message header), then `default`. A `request_id` field (SMS) or `X-Peep-Request-Id` message header (mail) links the message to the request that sent it. Mail is also accepted over SMTP on port 23525 (the `smtp_port` setting), where the login username is the project.

### Viewer UI endpoints

These serve the viewer's own page and are not part of the client contract; they are listed so other tools (the desktop app) can use them. They answer only to this machine, and every `POST` needs the `X-Peep-UI: 1` header.

| Method and path | Purpose |
|-----------------|---------|
| `GET /_peep/stream` | Server-Sent Events: `snapshot` first (cards, projects, active locks, switches, receiving state, screens, public settings, mails, SMS), then `card.created`, `card.updated`, `card.removed`, `screen.cleared`, `screen.archived`, `lock.released`, `switches.changed`, `receiving.changed`, `settings.changed`, `app.command`, `mail.received`, `mail.updated`, `mail.removed`, `mail.cleared`, `sms.received`, `sms.updated`, `sms.removed`, `sms.cleared` |
| `POST /_peep/ui/clear` | `{"project": "shop"}`, or `null` for every project |
| `POST /_peep/ui/locks/{name}/continue`, `.../stop` | Release a pause lock |
| `POST /_peep/ui/switches` | `{"project": "shop", "switches": {"queries": "on"}}`; saved in the settings |
| `POST /_peep/ui/receiving` | `{"paused": true}` |
| `POST /_peep/ui/screens` | `{"project": "shop"}`: move the cards to a previous screen |
| `GET /_peep/ui/screens/{id}` | A previous screen with its cards (the last 10 per project are kept, in memory) |
| `GET /_peep/ui/settings` | Settings, with the token, where history is kept and which changes need a restart |
| `POST /_peep/ui/settings` | `{"changes": {...}}`; `422` with `setting` and `message` when a value is invalid |
| `GET /_peep/ui/mail/{id}` | A mail with HTML, text, headers and attachments |
| `GET /_peep/ui/mail/{id}/source`, `.../attachments/{index}` | The raw message as text; an attachment as a download |
| `POST /_peep/ui/mail/{id}/read`, `.../delete`, `POST /_peep/ui/mail/clear` | Inbox actions (`{"read": false}` marks unread) |
| `POST /_peep/ui/sms/read`, `/_peep/ui/sms/{id}/delete`, `/_peep/ui/sms/clear` | SMS actions (`read` takes `{"project", "to"}`) |

## Envelope format

```json
{
  "protocol": 1,
  "card": "9b2f0c1e-6a4d-4c1b-9f53-2a7d1c0e8b11",
  "payloads": [
    {
      "type": "log",
      "content": { "values": [ { "type": "string", "value": "hello", "length": 5 } ] },
      "origin": { "file": "/app/src/UserService.php", "line": 42, "function": "store", "class": "App\\UserService" }
    }
  ],
  "meta": {
    "project": "my-app",
    "hostname": "dev-laptop",
    "php_version": "8.5.12",
    "peep_version": "0.1.0",
    "request": { "id": "1f6c2d3e-...", "kind": "http", "label": "POST /users", "started_at": 1792663321.512 },
    "sent_at": 1792663321.734,
    "pid": 41231
  }
}
```

| Field | Type | Required | Meaning |
|-------|------|----------|---------|
| `protocol` | int | yes | `1` |
| `card` | string | yes | UUID of the card. One `peep()` call is one card; every chained call on it reuses the id |
| `payloads` | array | yes | One or more payloads, applied in order |
| `meta.project` | string | yes | Project name; the viewer keeps projects apart |
| `meta.hostname` | string | yes | Machine the app runs on |
| `meta.php_version` | string | yes | `PHP_VERSION` of the app |
| `meta.peep_version` | string | yes | Client version |
| `meta.request` | object | yes | The HTTP request, CLI command or queued job this card belongs to |
| `meta.request.id` | string | yes | UUID shared by every card of the same request |
| `meta.request.kind` | string | yes | `http`, `cli` or `job` |
| `meta.request.label` | string | yes | e.g. `POST /users`, `artisan migrate`, `App\Jobs\SendInvoice` |
| `meta.request.started_at` | float | yes | Unix time with microseconds |
| `meta.sent_at` | float | yes | Unix time with microseconds |
| `meta.pid` | int | no | Process id |

### Payload

| Field | Type | Required | Meaning |
|-------|------|----------|---------|
| `type` | string | yes | Payload type (below) |
| `content` | object | yes | Type-specific content |
| `origin` | object or null | content payloads: yes; update payloads: no | Where the call was made |
| `origin.file` | string | yes | Absolute path, already mapped to the developer's machine when path mapping is configured |
| `origin.line` | int | yes | Line number |
| `origin.function` | string or null | no | Function or method containing the call |
| `origin.class` | string or null | no | Class of that method |

## Payload types in v1

### Content payloads

Add content to a card. The viewer shows them in the order received.

| Type | Content |
|------|---------|
| `log` | `{ "values": [node, ...] }`: one or more values (see Value tree) |
| `text` | `{ "text": "..." }`: plain text, shown with whitespace kept, never as HTML |
| `html` | `{ "html": "..." }`: HTML, shown in a sandboxed frame |
| `json` | `{ "json": "..." }`: a JSON string; the viewer pretty-prints it, or shows it as text when it is not valid JSON |
| `table` | `{ "rows": node, "label": "..." or null }`: an array value shown as a table |

### Update payloads

Change an existing card. `origin` may be `null`. An update for a card the viewer has not seen creates an empty card that later content fills.

| Type | Content |
|------|---------|
| `color` | `{ "color": "red" }`: one of `red`, `green`, `blue`, `orange`, `purple`, `gray`, `yellow` |
| `label` | `{ "label": "..." }` |
| `size` | `{ "size": "small" }`: `small`, `normal` or `large` |
| `replace` | `{ "values": [node, ...] }`: replace the card's content with these values |
| `remove` | `{}`: remove the card |
| `hide` | `{}`: show the card collapsed |
| `expand` | `{ "level": int or null, "keys": ["user", "user.roles"], "all": bool }`: how far to open the card's values |

### More content payloads

| Type | Content |
|------|---------|
| `xml` | `{ "xml": "..." }`: shown indented |
| `image` | `{ "src": "https://... or data:image/...", "error": null or "why it is not shown" }` |
| `file` | `{ "path", "size", "binary", "contents", "truncated", "error" }`: text contents (first 1 MB), base64 when `binary` |
| `link` | `{ "url", "label" }`: only `http`, `https` and `mailto` URLs become links |
| `separator` | `{}` |
| `trace` | `{ "frames": [ { "file", "line", "function", "class" }, ... ] }`: frame 0 is the call site |
| `caller` | `{ "frames": [ frame ] }`: where the function containing the call was called; empty at the top level |
| `exception` | `{ "class", "message", "code", "file", "line", "snippet": { "start", "highlight", "lines" } or null, "frames", "previous": [ ... same shape ... ] }` |
| `measure` | `{ "started": true }` for the first stopwatch call; otherwise `{ "total_ms", "since_last_ms", "memory", "peak_memory" }`, or `{ "callable": true, "total_ms", "memory", "peak_memory" }` |
| `count` | `{ "name": string or null, "count": int }`: a counter card keeps only its latest count |
| `notify` | `{ "message" }` |
| `lock` | `{ "name" }`: a pause lock (see Locks) |
| `new_screen` | `{ "title" }`: clears the project, then this card shows the title |

### Commands

Commands act on the screen or the app and create no card.

| Type | Effect |
|------|--------|
| `clear_screen` | Clear the project's cards |
| `clear_all` | Clear every project's cards |
| `show_app`, `hide_app` | Show or hide the desktop app window (ignored by the browser) |

Later protocol additions (still version 1) add more types, for example `query` and mail previews. Receivers that do not know a type show it as raw JSON.

## Value tree

Values are sent as a typed tree, never as pre-rendered HTML, so the viewer can search, filter and collapse inside them.

### Node

| Field | Present for | Meaning |
|-------|-------------|---------|
| `type` | all | Node type (below) |
| `value` | scalars, `string`, `binary`, `resource` | The value |
| `length` | `string`, `binary`, `array`, `object` | Full length (characters, bytes or element count) before truncation |
| `class` | `object`, `max_depth` (objects) | Class name |
| `id` | `object`, `ref` | Number of the object within this value, so repeats can point to it |
| `children` | `array`, `object` | List of `{ "key": string or int, "visibility": "public" / "protected" / "private" (objects only), "node": node }` |
| `truncated` | `string`, `binary`, `array`, `object` | How many characters, bytes or children were left out |
| `redacted` | any | `true` when the value was hidden because its key is sensitive |
| `lazy` | `object` | `true` for a PHP 8.4 lazy object that was not initialized (no children are read) |
| `summary` | `object` | One-line description from a caster: a date, an exception message, a closure signature, an enum case |

### Node types

| Type | Example |
|------|---------|
| `null` | `{ "type": "null" }` |
| `bool` | `{ "type": "bool", "value": true }` |
| `int` | `{ "type": "int", "value": 42 }`; integers outside ±2^53 are sent as strings: `{ "type": "int", "value": "9007199254740993" }` |
| `float` | `{ "type": "float", "value": 1.5 }`; `INF`, `-INF` and `NAN` are sent as the strings `"INF"`, `"-INF"`, `"NAN"` |
| `string` | `{ "type": "string", "value": "hello", "length": 5 }` |
| `binary` | `{ "type": "binary", "value": "<base64>", "length": 1024 }`: a string that is not valid UTF-8 |
| `array` | `{ "type": "array", "length": 2, "children": [ { "key": 0, "node": ... }, { "key": "name", "node": ... } ] }` |
| `object` | `{ "type": "object", "class": "App\\User", "id": 1, "length": 3, "children": [ { "key": "email", "visibility": "public", "node": ... } ] }` |
| `ref` | `{ "type": "ref", "id": 1 }`: the object with this id was already shown in this value (repeats and loops) |
| `resource` | `{ "type": "resource", "value": "stream" }`, or `"value": "closed"` |
| `max_depth` | `{ "type": "max_depth", "class": "App\\User" }` or `{ "type": "max_depth", "length": 12 }` for arrays: nesting limit reached |

### Limits

The client cuts values to keep payloads small. Defaults, all configurable:

| Limit | Default |
|-------|---------|
| Nesting depth | 5 |
| Children per array or object | 100 |
| Characters per string | 10,000 |
| Nodes per value | 5,000 |

What was cut is reported in `truncated`, and `length` always gives the full size.

### Redaction

Children whose key matches the client's sensitive-key list (default: `password`, `password_confirmation`, `secret`, `token`, `api_key`, `apikey`, `authorization`, `cookie`; case-insensitive) are replaced with `{ "type": "string", "value": "***", "redacted": true }`. Redaction happens in the client, before data leaves the app.

## Switches

Switches let the viewer turn captures on and off in running apps.

| Switch | Values |
|--------|--------|
| `queries` | `on`, `off`, `default` |
| `slow_queries` | `on`, `off`, `default` |
| `duplicate_queries` | `on`, `off`, `default` |
| `slow_query_ms` | int, or `null` to use the app's own threshold |

- `default` means "use the app's own configuration".
- Clients cache switches for `switches_ttl` seconds. They get fresh values with every envelope response; when they need a value and nothing was sent recently, they call `GET /_peep/switches`.
- A switch set in code (for example `peep()->showQueries()`) wins over the viewer.

## Client behaviour

- **Disabled by default.** A client sends nothing until enabled by configuration.
- **Never breaks the app.** Connection, timeout, encoding and HTTP errors are swallowed.
- **Fast when the viewer is closed.** Connect timeout 50 ms, total timeout 500 ms. After a failed connection the client treats the viewer as down for 30 seconds and sends nothing.
- **HTTP errors are not "down".** A `4xx` or `5xx` answer means the viewer is running; the client does not stop sending because of it.
