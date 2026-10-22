<?php

namespace ShibbirWeb\Peep\Tests\Unit\Api;

use BadMethodCallException;
use ShibbirWeb\Peep\Exception\StopExecutionException;
use ShibbirWeb\Peep\Exception\TransportException;
use ShibbirWeb\Peep\Peep;
use ShibbirWeb\Peep\Transport\TransportResponse;

final class ControlTest extends ApiTestCase
{
    public function testOnceSendsOnlyTheFirstTimeALineRuns(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->peep()->once($i)->red();
        }

        $this->assertSame(['log', 'color'], $this->types());
        $this->assertSame(0, $this->payloads()[0]['content']['values'][0]['value']);
    }

    public function testLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->peep()->limit(2)->send($i);
        }

        $this->assertCount(2, $this->payloads());
    }

    public function testIfMutesTheRestOfTheChain(): void
    {
        $this->peep()->if(false)->send('hidden')->red();
        $this->peep()->if(function () {
            return true;
        })->send('shown');
        $this->peep()->showIf(0)->send('hidden');

        $this->assertSame(['log'], $this->types());
        $this->assertSame('shown', $this->onlyPayload()['content']['values'][0]['value']);
    }

    public function testIfWithACallback(): void
    {
        $this->peep()->send('always')->if(false, function (Peep $peep) {
            $peep->red();
        })->if(true, function (Peep $peep) {
            $peep->green();
        })->label('still runs');

        $this->assertSame(['log', 'color', 'label'], $this->types());
        $this->assertSame(['color' => 'green'], $this->payloads()[1]['content']);
    }

    public function testRateLimiterSendsOneNotice(): void
    {
        $this->peep()->rateLimiter()->max(2);

        for ($i = 0; $i < 5; $i++) {
            $this->peep()->send($i);
        }

        $this->assertSame(['log', 'log', 'notify'], $this->types());

        $this->peep()->rateLimiter()->clear();
        $this->peep()->send('after clear');
        $this->assertCount(4, $this->payloads());
    }

    public function testMacros(): void
    {
        Peep::macro('money', function ($amount) {
            return $this->send(number_format($amount, 2))->green();
        });

        $this->assertTrue(Peep::hasMacro('money'));
        // Same as $this->peep()->money(1234.5), spelled out so static analysis sees the magic call.
        $this->peep()->__call('money', [1234.5]);

        $this->assertSame('1,234.50', $this->payloads()[0]['content']['values'][0]['value']);
        $this->assertSame(['log', 'color'], $this->types());
    }

    public function testUnknownMethodsThrow(): void
    {
        $this->expectException(BadMethodCallException::class);

        $this->peep()->__call('noSuchMethod', []);
    }

    public function testPauseWaitsUntilContinue(): void
    {
        $this->transport->getResponses = [
            new TransportResponse(200, '{"active":true,"stop":false}'),
            new TransportResponse(200, '{"active":false,"stop":false}'),
        ];

        $this->peep()->pause();

        $lock = $this->onlyPayload();
        $this->assertSame('lock', $lock['type']);
        $this->assertCount(2, $this->transport->gets);
        $this->assertSame('/_peep/locks/' . $lock['content']['name'], $this->transport->gets[0]);
    }

    public function testPauseStopThrows(): void
    {
        $this->transport->getResponses = [new TransportResponse(200, '{"active":false,"stop":true}')];

        $this->expectException(StopExecutionException::class);

        $this->peep()->pause();
    }

    public function testPauseContinuesWhenTheLockIsUnknownOrTheViewerGoesAway(): void
    {
        $this->peep()->pause();
        $this->assertCount(1, $this->transport->gets, '404: unknown lock, continue.');

        $this->transport->failWith = new TransportException('gone');
        $this->peep()->pause();
        $this->assertCount(1, $this->transport->gets);
    }
}
