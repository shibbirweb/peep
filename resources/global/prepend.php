<?php

// Runs before every PHP script when Peep is installed globally (auto_prepend_file).
// Nothing is loaded here: an autoloader for Peep's classes is registered last, so a
// project that requires shibbirweb/peep through Composer (whose autoloader goes first)
// always uses its own version, and peep() is defined only if nothing defined it yet.

if (! defined('PEEP_GLOBAL_INSTALL')) {
    define('PEEP_GLOBAL_INSTALL', true);
}

spl_autoload_register(function ($class) {
    $prefix = 'ShibbirWeb\\Peep\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $file = 'phar://peep.phar/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

require_once 'phar://peep.phar/src/helpers.php';
