<?php

namespace ShibbirWeb\Peep\Tests\Unit\Api;

use InvalidArgumentException;
use LogicException;
use RuntimeException;

final class InspectionTest extends ApiTestCase
{
    public function testTraceStartsAtTheCall(): void
    {
        $line = __LINE__ + 1;
        $this->peep()->trace();

        $frames = $this->onlyPayload()['content']['frames'];
        $this->assertSame(__FILE__, $frames[0]['file']);
        $this->assertSame($line, $frames[0]['line']);
        $this->assertSame('trace', $frames[0]['function']);
        $this->assertSame(__FUNCTION__, $frames[1]['function']);
    }

    public function testCallerIsWhereTheEnclosingFunctionWasCalled(): void
    {
        $line = __LINE__ + 1;
        $this->helperThatAsksForItsCaller();

        $frames = $this->onlyPayload()['content']['frames'];
        $this->assertCount(1, $frames);
        $this->assertSame(__FILE__, $frames[0]['file']);
        $this->assertSame($line, $frames[0]['line']);
        $this->assertSame('helperThatAsksForItsCaller', $frames[0]['function']);
    }

    public function testExceptionWithSnippetTraceAndPrevious(): void
    {
        $line = __LINE__ + 1;
        $exception = new RuntimeException('Outer', 7, new LogicException('Inner'));

        $this->peep()->exception($exception);

        $payloads = $this->payloads();
        $this->assertSame(['exception', 'color'], $this->types());

        $content = $payloads[0]['content'];
        $this->assertSame(RuntimeException::class, $content['class']);
        $this->assertSame('Outer', $content['message']);
        $this->assertSame(7, $content['code']);
        $this->assertSame(__FILE__, $content['file']);
        $this->assertSame($line, $content['line']);
        $this->assertSame($line, $content['snippet']['highlight']);
        $this->assertStringContainsString("new RuntimeException('Outer'", implode("\n", $content['snippet']['lines']));
        $this->assertNotEmpty($content['frames']);
        $this->assertSame('Inner', $content['previous'][0]['message']);
        $this->assertSame(['color' => 'red'], $payloads[1]['content']);
    }

    public function testMeasureStartsThenReportsLaps(): void
    {
        $this->peep()->measure();
        usleep(2000);
        $this->peep()->measure();

        $payloads = $this->payloads();
        $this->assertTrue($payloads[0]['content']['started']);
        $this->assertFalse($payloads[1]['content']['started']);
        $this->assertGreaterThanOrEqual(1.0, $payloads[1]['content']['total_ms']);
        $this->assertGreaterThan(0, $payloads[1]['content']['memory']);
    }

    public function testMeasureACallable(): void
    {
        $ran = false;
        $this->peep()->measure(function () use (&$ran) {
            usleep(2000);
            $ran = true;
        });

        $this->assertTrue($ran);
        $content = $this->onlyPayload()['content'];
        $this->assertTrue($content['callable']);
        $this->assertGreaterThanOrEqual(1.0, $content['total_ms']);
    }

    public function testCountUpdatesOneCardPerCounter(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->peep()->count();
            $this->peep()->count('named');
        }

        $this->assertSame(3, $this->peep()->counterValue('named'));

        $cards = ['line' => [], 'named' => []];
        $counts = ['line' => [], 'named' => []];
        foreach ($this->transport->requests as $request) {
            $payload = $request['body']['payloads'][0];
            $key = $payload['content']['name'] === null ? 'line' : 'named';
            $cards[$key][] = $request['body']['card'];
            $counts[$key][] = $payload['content']['count'];
        }

        $this->assertSame([1, 2, 3], $counts['line']);
        $this->assertSame([1, 2, 3], $counts['named']);
        $this->assertCount(1, array_unique($cards['line']));
        $this->assertCount(1, array_unique($cards['named']));
        $this->assertNotSame($cards['line'][0], $cards['named'][0]);
    }

    public function testCatchShowsMatchingExceptionsAndRethrowsOthers(): void
    {
        $this->assertSame('result', $this->peep()->catch(function () {
            return 'result';
        }));

        $this->assertNull($this->peep()->catch(function () {
            throw new RuntimeException('caught');
        }));

        $this->assertNull($this->peep()->catch(function () {
            throw new InvalidArgumentException('by class');
        }, InvalidArgumentException::class));

        $this->assertNull($this->peep()->catch(function () {
            throw new LogicException('by list');
        }, [InvalidArgumentException::class, LogicException::class]));

        $handled = null;
        $this->assertNull($this->peep()->catch(function () {
            throw new RuntimeException('by closure');
        }, function (RuntimeException $exception) use (&$handled) {
            $handled = $exception->getMessage();
        }));
        $this->assertSame('by closure', $handled);

        try {
            $this->peep()->catch(function () {
                throw new LogicException('not matching');
            }, function (RuntimeException $exception) {
            });
            $this->fail('Expected the exception to be rethrown.');
        } catch (LogicException $exception) {
            $this->assertSame('not matching', $exception->getMessage());
        }

        $this->assertSame(['exception', 'color', 'exception', 'color', 'exception', 'color'], $this->types());
    }

    private function helperThatAsksForItsCaller(): void
    {
        $this->peep()->caller();
    }
}
