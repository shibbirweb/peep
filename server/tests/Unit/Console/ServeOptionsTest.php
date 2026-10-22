<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Tests\Unit\Console;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ShibbirWeb\PeepServer\Console\OpenMode;
use ShibbirWeb\PeepServer\Console\ServeOptions;

final class ServeOptionsTest extends TestCase
{
    public function testDefaults(): void
    {
        $options = ServeOptions::fromArguments([]);

        $this->assertSame('127.0.0.1', $options->host);
        $this->assertSame(23517, $options->port);
        $this->assertSame(OpenMode::Browser, $options->open);
    }

    public function testBothOptionStyles(): void
    {
        $options = ServeOptions::fromArguments(['--port', '9000', '--open=none', '--host=localhost']);

        $this->assertSame(9000, $options->port);
        $this->assertSame(OpenMode::None, $options->open);
        $this->assertSame('localhost', $options->host);
    }

    public function testRejectsInvalidOptions(): void
    {
        foreach ([['--port=0'], ['--port=abc'], ['--open=tab'], ['--verbose'], ['--host=0.0.0.0'], ['--host=192.168.1.5']] as $arguments) {
            try {
                ServeOptions::fromArguments($arguments);
                $this->fail('Expected an error for ' . implode(' ', $arguments));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
