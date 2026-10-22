# WordPress

WordPress sites usually have no Composer setup, so the easiest way is the [global install](Global-Install.md): `peep()` then works in every theme and plugin file without changes.

With Composer (for example in a Bedrock site, or a plugin that has its own `vendor/`):

```bash
composer require shibbirweb/peep --dev
```

## Turn it on

Add a `peep.php` file next to `wp-config.php` (it is found by walking up from the directory of the script PHP runs):

```php
<?php

return [
    'enabled' => true,
    'project' => 'blog',
];
```

Keep it out of production deployments, or set `'enabled' => false` there.

## Use it

```php
peep(get_queried_object())->label('Queried object');
peep($wp_query->request)->label('Main query SQL');
peep()->trace();
```

## PHP errors and fatal errors

WordPress shows a "critical error" page for fatal errors and hides warnings when `WP_DEBUG` is off. Send them to Peep from a must-use plugin, which loads before every other plugin. Create `wp-content/mu-plugins/peep.php`:

```php
<?php
/**
 * Plugin Name: Peep
 * Description: Sends PHP errors and fatal errors to the Peep viewer.
 */

if (class_exists('ShibbirWeb\\Peep\\Bridge\\ErrorHandler\\ErrorHandler')) {
    \ShibbirWeb\Peep\Bridge\ErrorHandler\ErrorHandler::register();
}
```

Without the global install, add `require_once` for your Composer `vendor/autoload.php` above the `class_exists()` check.

WordPress core and many plugins still raise deprecations on new PHP versions, so the viewer can get busy. To see only fatal errors and uncaught exceptions, turn off the first argument:

```php
\ShibbirWeb\Peep\Bridge\ErrorHandler\ErrorHandler::register(false);
```

Errors hidden by `error_reporting` or the `@` operator are still shown, marked `silenced`, because they are often what you are looking for.

## Hooks

To see which actions run on a request, add this to the must-use plugin while you debug:

```php
add_action('all', function ($hook) {
    if (strpos($hook, 'gettext') === false) {
        peep()->count($hook);
    }
});
```
