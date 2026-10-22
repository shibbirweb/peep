# Finding leftover calls

Debug calls are easy to forget. Peep does nothing in production by default, but a leftover `peep()` is still noise in a pull request. Three tools find them.

## peep-clean

```bash
vendor/bin/peep-clean              # lists every peep(), pd(), ->peep() and @peep call
vendor/bin/peep-clean --fix        # removes the ones that can go safely
vendor/bin/peep-clean src tests    # only these folders
php artisan peep:clean --fix       # the same in Laravel, over app/, routes/, resources/views/ and more
```

```text
app/Http/Controllers/OrderController.php:24  peep($order)->green();  (removable)
app/Services/Pricing.php:51  peep($total)  (fix by hand)
resources/views/orders/show.blade.php:3  @peep($order)  (removable)

3 peep() calls in 214 files searched. Run with --fix to remove the removable ones.
```

It reads the code with PHP's tokenizer, so strings, comments, methods called `peep` and other namespaces' functions are never touched. A call is removed when it is a statement on its own (the whole line goes) or a `->peep()` step in a chain (which only passes the value on). A call whose result is used, such as `$total = peep($total);`, is listed for you to fix. `vendor/`, `node_modules/` and `storage/` are skipped.

The exit code is 1 while calls remain, so CI can fail on them:

```yaml
- run: vendor/bin/peep-clean
```

## PHPStan

```neon
includes:
    - vendor/shibbirweb/peep/phpstan/rules.neon
```

Every call is reported as `peep.leftoverCall`, with the line.
