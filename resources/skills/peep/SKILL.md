---
name: peep
description: Debug a PHP app (Laravel, Symfony, Slim, WordPress or plain PHP) with the Peep debugger, then give the user a debug report. Add peep() calls, reproduce the problem, read what Peep caught (values, queries, exceptions, jobs, logs, mail, SMS) with `vendor/bin/peep` or the Peep MCP tools, fix, and clean up. Use when the user asks to debug with Peep, to "use peep", for a Peep report, or when a PHP project that requires shibbirweb/peep has a bug that needs runtime values.
---

# Debugging with Peep

Peep is a PHP debugger. The app sends values, queries, exceptions, jobs, logs, mail and SMS to the **Peep viewer** (a desktop app or `peep-server.phar`), and you read them back as text with the `peep` command (or the Peep MCP tools). You do not need to see the viewer's window.

Work in this order: check, clear, instrument, reproduce, read, fix, clean up, report.

## 1. Check that Peep is ready

```bash
vendor/bin/peep status
```

| Answer | Do |
|--------|----|
| `Peep viewer ... receiving` | Ready. Note the project names. |
| `No Peep viewer is running at ...` | Ask the user to open the Peep desktop app or run `php peep-server.phar serve`, then check again. Do not try to install the viewer yourself. |
| `PAUSED` | Ask the user to press Resume in the viewer. |
| `vendor/bin/peep: No such file` | Peep is not in the project. Ask before adding it: `composer require shibbirweb/peep --dev`. With a global install, use `php ~/.peep/peep.phar` instead of `vendor/bin/peep`. |
| `no agent endpoints` | The viewer is older than the package. Ask the user to update the desktop app or `peep-server.phar`. |

Peep must be on in the app. Laravel turns it on outside `production`. Anywhere else it needs `PEEP_ENABLED=true` (environment or `.env`) or a `peep.php` file in the project returning `['enabled' => true]`. If the project's cards never arrive, check this first.

When the project has more than one app or the status lists several projects, pass `--project=NAME` to every command (or set `PEEP_PROJECT`).

## 2. Clear, so the report holds only your run

```bash
vendor/bin/peep clear --project=shop
```

Or use `--since=2m` on the read commands instead of clearing, when the user wants to keep what is in the viewer.

## 3. Instrument the code

Add `peep()` calls where you need runtime values. Give each card a label so you can find yours:

```php
peep($order)->label('order before save');
peep($request->all(), $user->id)->label('checkout input');
peep()->exception($e);                     // an exception you caught, with its trace
peep()->trace();                           // how this line was reached
peep()->measure();                         // first call starts a stopwatch, later calls show the time
peep()->count('retry');                    // how often this runs
peep()->table($rows, 'imported rows');
```

Laravel sends logs, reported exceptions, mail previews and `dump()` with no code. Turn on more when the bug needs it:

```php
peep()->showQueries();                     // every query with bindings and time, from here on
peep()->showSlowQueries(100);              // only queries slower than 100 ms
peep()->showDuplicateQueries();            // N+1 queries
peep()->countQueries(fn () => $order->load('items'));
peep()->showJobs()->showHttpClientRequests()->showEvents();
User::where('active', true)->peep()->get(); // the SQL of one query
```

Rules:

- Never use `pause()` or `pd()`/`die()`: `pause()` waits for a click in the viewer, `pd()` stops the request.
- Secrets in keys like `password`, `token` and `authorization` are redacted by the client. Do not dump whole config arrays or `.env` values anyway.
- In code that must run on PHP 7.x, use `function () { ... }` instead of `fn () =>`.

## 4. Reproduce

Run what shows the bug: the failing test (`php artisan test --filter=...`, `vendor/bin/phpunit --filter=...`), an artisan or console command, or an HTTP request (`curl -s http://app.test/orders -d ...`). When the user has to click something in the browser, ask them to, then wait:

```bash
vendor/bin/peep wait --timeout=120          # returns as soon as a new card arrives
vendor/bin/peep wait --count=5 --timeout=60 # or after five
```

`wait` also helps with queued jobs, which run after the request.

## 5. Read what Peep caught

```bash
vendor/bin/peep report                      # start here: problems first, then each request
vendor/bin/peep cards --search="order before save"
vendor/bin/peep cards --request=1f6c2d3e    # every card of one request (id from the report)
vendor/bin/peep cards --card=3f2a9c1b       # one card in full
vendor/bin/peep mail                        # caught mail; --id=mail_... for one with its body
vendor/bin/peep sms
```

Each card starts with `[id]`, its age, the request (`POST /orders`, a command or a job), and the file and line of the call. File paths are relative to the project. The report's **Problems** section lists exceptions, failed jobs, error responses, failed HTTP calls, error logs, slow queries and repeated (N+1) queries.

Add `--json` to `status`, `cards`, `mail` and `sms` for machine-readable output.

## 6. Fix and check again

Change the code, `clear`, reproduce, and read the report again. Compare before and after (the slow query is gone, the exception no longer appears, the value is right).

## 7. Clean up before you finish

Remove every `peep()` call you added, and turn off anything you switched on:

```bash
vendor/bin/peep-clean          # lists peep(), pd(), ->peep() and @peep calls left in the code
vendor/bin/peep-clean --fix    # removes the ones that are statements on their own
```

Fix by hand whatever it lists after `--fix`. Leave calls that were in the code before you started, unless the user asks.

## 8. Report to the user

When the user asked for a report, save it and show it:

```bash
vendor/bin/peep report --output=peep-report.md
```

Then answer with:

1. **What was wrong**: the root cause in one or two sentences, with the file and line.
2. **Evidence**: the parts of the Peep report that prove it (the exception, the wrong value, the slow or repeated query), quoted from the report.
3. **What you changed**, and the report after the fix showing it works.
4. Where the full report is (`peep-report.md`), and that the debug calls were removed.

Do not commit `peep-report.md` unless the user asks.

## MCP instead of the command line

When you have the Peep MCP tools (`peep_status`, `peep_report`, `peep_cards`, `peep_mail`, `peep_sms`, `peep_wait`, `peep_clear`), use them the same way. They take the same filters (`project`, `since`, `request`, `search`, `limit`). To add them to an MCP client, run the server from the project directory:

```json
{
  "mcpServers": {
    "peep": { "command": "php", "args": ["vendor/bin/peep", "mcp"] }
  }
}
```

For Claude Code: `claude mcp add peep -- php vendor/bin/peep mcp`.

## Command reference

| Command | Does |
|---------|------|
| `peep status` | Is the viewer running, projects and their counts, query switches |
| `peep report` | Markdown report; `--output=FILE` also saves it |
| `peep cards` | Cards as text, oldest first |
| `peep mail`, `peep sms` | Caught mail and SMS |
| `peep wait` | Wait for new cards (`--timeout=30`, `--count=1`) |
| `peep clear` | Empty the viewer (`--what=all\|cards\|mail\|sms`) |
| `peep mcp` | MCP server on stdio |
| `peep skill` | Print this skill; `--install` writes it to `.claude/skills/peep/` |

Filters: `--project=NAME`, `--since=5m` (or a unix time), `--request=ID`, `--card=ID`, `--search=TEXT`, `--limit=50`.
