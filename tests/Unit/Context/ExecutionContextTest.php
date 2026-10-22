<?php

namespace ShibbirWeb\Peep\Tests\Unit\Context;

use ShibbirWeb\Peep\Context\ExecutionContext;
use ShibbirWeb\Peep\Tests\TestCase;

final class ExecutionContextTest extends TestCase
{
    public function testDetectsTheCliProcess(): void
    {
        $current = (new ExecutionContext())->current();

        $this->assertSame('cli', $current['kind']);
        $this->assertNotSame('', $current['label']);
        $this->assertGreaterThan(0, $current['started_at']);
    }

    public function testIdIsStableUntilANewContextBegins(): void
    {
        $context = new ExecutionContext();
        $first = $context->current();

        $this->assertSame($first['id'], $context->current()['id']);

        $context->begin('job', 'App\\Jobs\\SendInvoice');
        $job = $context->current();
        $this->assertSame('job', $job['kind']);
        $this->assertSame('App\\Jobs\\SendInvoice', $job['label']);
        $this->assertNotSame($first['id'], $job['id']);

        $context->end();
        $this->assertSame($first['id'], $context->current()['id']);
    }

    public function testEndNeverRemovesTheProcessContext(): void
    {
        $context = new ExecutionContext();
        $first = $context->current();

        $context->end();

        $this->assertSame($first['id'], $context->current()['id']);
    }

    public function testResetStartsANewContext(): void
    {
        $context = new ExecutionContext();
        $first = $context->current();

        $context->reset();

        $this->assertNotSame($first['id'], $context->current()['id']);
    }
}
