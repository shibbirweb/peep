<?php

// Stub of peep.phar. Run directly ("php peep.phar install"), it is the command line tool.
// Loaded through auto_prepend_file, it only makes peep() available to every script.

Phar::mapPhar('peep.phar');

$peepRunDirectly = PHP_SAPI === 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__);

if ($peepRunDirectly) {
    unset($peepRunDirectly);
    require 'phar://peep.phar/bin/peep';
} else {
    unset($peepRunDirectly);
    require 'phar://peep.phar/resources/global/prepend.php';
}

__HALT_COMPILER();
