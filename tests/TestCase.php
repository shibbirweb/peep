<?php

namespace ShibbirWeb\Peep\Tests;

use ErrorException;
use Yoast\PHPUnitPolyfills\TestCases\TestCase as PolyfillTestCase;

/**
 * Base test case for the client.
 *
 * The client must run cleanly on every PHP version from 7.1 to 8.5, so every
 * warning, notice and deprecation raised during a test is turned into an
 * exception and fails the test. Errors silenced with @ are left alone.
 *
 * Tests use set_up() and tear_down() (from yoast/phpunit-polyfills) instead of
 * setUp() and tearDown(), so the same suite runs on PHPUnit 7.5 and current versions.
 */
abstract class TestCase extends PolyfillTestCase
{
    /**
     * @var int
     */
    private $previousErrorReporting = 0;

    protected function set_up()
    {
        parent::set_up();

        $this->previousErrorReporting = error_reporting(E_ALL);

        set_error_handler(function ($severity, $message, $file, $line) {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            throw new ErrorException($message, 0, $severity, $file, $line);
        });
    }

    protected function tear_down()
    {
        restore_error_handler();
        error_reporting($this->previousErrorReporting);

        parent::tear_down();
    }
}
