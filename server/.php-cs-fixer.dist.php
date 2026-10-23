<?php

declare(strict_types=1);

// Viewer code style: PER Coding Style 2.0 (the viewer runs on PHP 8.5 only).

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    // Runs on the PHP and Monolog versions whose output it records, not on the viewer's.
    ->notPath('Fixture/logs/make.php');

return (new PhpCsFixer\Config())
    ->setRules([
        '@PER-CS2.0' => true,
        'declare_strict_types' => true,
    ])
    ->setRiskyAllowed(true)
    ->setFinder($finder)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache');
