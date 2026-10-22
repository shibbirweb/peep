<?php

namespace ShibbirWeb\Peep\Tests\Integration;

use ShibbirWeb\Peep\Config\Config;
use ShibbirWeb\Peep\PeepFactory;
use ShibbirWeb\Peep\Tests\Fixture\FakeViewer;
use ShibbirWeb\Peep\Tests\TestCase;
use ShibbirWeb\Peep\Transport\AvailabilityCache;

/**
 * peep() through the real transport to a running HTTP server, and with nothing listening.
 */
final class EndToEndTest extends TestCase
{
    protected function tear_down()
    {
        PeepFactory::setInstance(null);
        AvailabilityCache::reset();

        parent::tear_down();
    }

    public function testValuesReachTheViewer(): void
    {
        $viewer = FakeViewer::start();

        try {
            $this->useFactory($viewer->port());

            $line = __LINE__ + 1;
            peep('hello', ['a' => 1], new \stdClass())->green();

            $requests = $viewer->requests();
            $this->assertCount(2, $requests);

            $log = json_decode($requests[0]['body'], true);
            $this->assertIsArray($log);
            $this->assertSame(1, $log['protocol']);
            $this->assertSame('log', $log['payloads'][0]['type']);
            $this->assertCount(3, $log['payloads'][0]['content']['values']);
            $this->assertSame(__FILE__, $log['payloads'][0]['origin']['file']);
            $this->assertSame($line, $log['payloads'][0]['origin']['line']);

            $color = json_decode($requests[1]['body'], true);
            $this->assertIsArray($color);
            $this->assertSame($log['card'], $color['card']);
            $this->assertSame(['color' => 'green'], $color['payloads'][0]['content']);
        } finally {
            $viewer->stop();
        }
    }

    /**
     * Phase 1 exit criterion: with the viewer closed, 100 peep() calls cost less than 5 ms.
     * The assertion allows 50 ms so slow CI machines do not fail; the measured time is
     * reported when it is above the 5 ms target.
     */
    public function testClosedViewerCostsAlmostNothing(): void
    {
        $this->useFactory(FakeViewer::freePort());

        $start = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            peep(['iteration' => $i]);
        }
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(0.05, $elapsed, sprintf('100 peep() calls took %.2f ms', $elapsed * 1000));

        if ($elapsed > 0.005) {
            fwrite(STDERR, sprintf("\n[peep] 100 calls with the viewer closed took %.2f ms (target 5 ms)\n", $elapsed * 1000));
        }
    }

    private function useFactory(int $port): void
    {
        PeepFactory::setInstance(new PeepFactory(Config::fromArray([
            'enabled' => true,
            'port' => $port,
            'project' => 'end-to-end',
        ]), null, null, new AvailabilityCache(30, false)));
    }
}
