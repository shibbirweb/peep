<?php

// Client code style: PSR-12. PER Coding Style 2.0 is not used here because it
// requires trailing commas in multi-line calls and parameter lists, which is
// PHP 7.3 / 8.0 syntax and would break the client on PHP 7.1.

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/resources',
    ])
    ->append([
        __DIR__ . '/bin/peep',
    ]);

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache');
