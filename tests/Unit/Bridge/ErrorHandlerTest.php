<?php

namespace ShibbirWeb\Peep\Tests\Unit\Bridge;

use RuntimeException;
use ShibbirWeb\Peep\Bridge\ErrorHandler\ErrorHandler;
use ShibbirWeb\Peep\Tests\Unit\Api\ApiTestCase;

final class ErrorHandlerTest extends ApiTestCase
{
    /**
     * @var int
     */
    private $handlersBefore = 0;

    public function testWarningsAreShownAndThePreviousHandlerStillRuns(): void
    {
        $previousCalls = 0;
        set_error_handler(function () use (&$previousCalls) {
            $previousCalls++;

            return true;
        });

        try {
            ErrorHandler::register(true, false, false, $this->factory);
            $this->handlersBefore++;

            trigger_error('Undefined thing', E_USER_WARNING);
            trigger_error('Old way', E_USER_DEPRECATED);
        } finally {
            restore_error_handler();
            restore_error_handler();
        }

        $logs = [];
        foreach ($this->payloads() as $payload) {
            if ($payload['type'] === 'log_message') {
                $logs[] = $payload['content'];
            }
        }

        $this->assertSame('warning', $logs[0]['level']);
        $this->assertSame('Undefined thing', $logs[0]['message']);
        $this->assertSame('deprecation', $logs[1]['level']);
        $this->assertSame(2, $previousCalls);
    }

    public function testUncaughtExceptionsAreShownBeforeThePreviousHandler(): void
    {
        $handled = null;
        set_exception_handler(function ($exception) use (&$handled) {
            $handled = $exception->getMessage();
        });

        try {
            $handler = ErrorHandler::register(false, true, false, $this->factory);
            $handler->handleException(new RuntimeException('Boom'));
        } finally {
            restore_exception_handler();
            restore_exception_handler();
        }

        $this->assertSame('Boom', $handled);
        $this->assertSame('exception', $this->payloads()[0]['type']);
    }

    public function testFatalErrorsAreShownWithTheirBacktraceWhenPhpHasOne(): void
    {
        $handler = ErrorHandler::register(false, false, false, $this->factory);

        $handler->reportFatal(['type' => E_WARNING, 'message' => 'not fatal', 'file' => __FILE__, 'line' => 1]);
        $this->assertSame([], $this->payloads());

        // PHP 8.5 (fatal_error_backtraces) adds "trace"; older versions do not.
        $handler->reportFatal([
            'type' => E_COMPILE_ERROR,
            'message' => 'Cannot declare class A',
            'file' => __FILE__,
            'line' => 10,
            'trace' => [['function' => 'load', 'class' => 'App\\Loader', 'type' => '->', 'file' => __FILE__, 'line' => 20]],
        ]);
        $handler->reportFatal(['type' => E_ERROR, 'message' => 'Out of memory', 'file' => __FILE__, 'line' => 30]);

        $payloads = [];
        foreach ($this->payloads() as $payload) {
            if ($payload['type'] === 'exception') {
                $payloads[] = $payload;
            }
        }

        $this->assertCount(2, $payloads);
        $this->assertSame('Cannot declare class A', $payloads[0]['content']['message']);
        $this->assertSame('ErrorException', $payloads[0]['content']['class']);
        $this->assertCount(1, $payloads[0]['content']['frames']);
        $this->assertSame(20, $payloads[0]['content']['frames'][0]['line']);

        $this->assertSame('Out of memory', $payloads[1]['content']['message']);
        $this->assertSame([], $payloads[1]['content']['frames'], 'No made-up frames from the shutdown handler.');
    }
}
