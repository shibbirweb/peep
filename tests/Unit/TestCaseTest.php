<?php

namespace ShibbirWeb\Peep\Tests\Unit;

use ErrorException;
use ShibbirWeb\Peep\Tests\TestCase;

final class TestCaseTest extends TestCase
{
    public function testDeprecationFailsTheTest(): void
    {
        $this->expectException(ErrorException::class);

        trigger_error('Old API', E_USER_DEPRECATED);
    }

    public function testWarningFailsTheTest(): void
    {
        $this->expectException(ErrorException::class);

        trigger_error('Something went wrong', E_USER_WARNING);
    }

    public function testSilencedErrorIsPassedToPhp(): void
    {
        error_clear_last();

        @trigger_error('Silenced', E_USER_NOTICE);

        $lastError = error_get_last();
        $this->assertIsArray($lastError);
        $this->assertSame('Silenced', $lastError['message']);
    }
}
