<?php

namespace ShibbirWeb\Peep\Tests\Unit\Bridge;

use Monolog\Logger;
use RuntimeException;
use ShibbirWeb\Peep\Bridge\LogRecord;
use ShibbirWeb\Peep\Bridge\Monolog\PeepHandlerFactory;
use ShibbirWeb\Peep\Bridge\Psr\PeepLogger;
use ShibbirWeb\Peep\Tests\Unit\Api\ApiTestCase;

final class LoggingBridgesTest extends ApiTestCase
{
    public function testPsrLoggerSendsEveryLevelWithInterpolatedMessages(): void
    {
        $logger = new PeepLogger($this->factory);

        $logger->warning('User {id} failed to sign in {times} times', ['id' => 7, 'times' => 3]);
        $logger->info('Plain message');

        $logs = $this->messages();
        $this->assertSame('warning', $logs[0]['content']['level']);
        $this->assertSame('User 7 failed to sign in 3 times', $logs[0]['content']['message']);
        $this->assertSame('array', $logs[0]['content']['context']['type']);
        $this->assertSame('info', $logs[1]['content']['level']);
        $this->assertContains(['color' => 'orange'], $this->contentsOfType('color'));
    }

    public function testExceptionsInTheContextBecomeExceptionCards(): void
    {
        (new PeepLogger($this->factory))->error('Checkout failed', ['exception' => new RuntimeException('Card declined')]);

        $exceptions = $this->contentsOfType('exception');
        $this->assertSame('Card declined', $exceptions[0]['message']);
        $this->assertSame(['label' => 'Error: Checkout failed'], $this->contentsOfType('label')[0]);
    }

    public function testMonologHandlerForTheInstalledVersion(): void
    {
        $logger = new Logger('payments');
        $logger->pushHandler(PeepHandlerFactory::create(100, true, $this->factory));

        $logger->notice('Refund {id} issued', ['id' => 'R-1']);

        $logs = $this->messages();
        $this->assertSame('notice', $logs[0]['content']['level']);
        $this->assertSame('payments', $logs[0]['content']['channel']);
        $this->assertSame('Refund R-1 issued', $logs[0]['content']['message']);
    }

    public function testInterpolation(): void
    {
        $this->assertSame('a 1 true null {missing}', LogRecord::interpolate('a {n} {b} {x} {missing}', ['n' => 1, 'b' => true, 'x' => null]));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function messages(): array
    {
        $found = [];
        foreach ($this->payloads() as $payload) {
            if ($payload['type'] === 'log_message') {
                $found[] = $payload;
            }
        }

        return $found;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function contentsOfType(string $type): array
    {
        $found = [];
        foreach ($this->payloads() as $payload) {
            if ($payload['type'] === $type) {
                $found[] = $payload['content'];
            }
        }

        return $found;
    }
}
