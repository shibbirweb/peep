<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Tests\Unit\Log;

use PHPUnit\Framework\TestCase;
use ShibbirWeb\PeepServer\Log\LogParser;

final class LogParserTest extends TestCase
{
    public const string LARAVEL_LOG = <<<'LOG'
        [2026-10-20 10:15:01] local.INFO: User signed in {"user_id":18,"roles":["admin"]} []
        [2026-10-20 10:15:02] local.ERROR: Payment failed {"order":18,"exception":"[object] (App\\Exceptions\\PaymentFailed(code: 402): Payment failed at /app/app/Pay.php:12)
        [stacktrace]
        #0 /app/app/Http/Controllers/OrderController.php(42): App\\Pay->charge()
        #1 {main}

        [previous exception] [object] (LogicException(code: 0): Card expired at /app/app/Card.php:3)
        [stacktrace]
        #0 {main}
        "} []
        [2026-10-20 10:15:03] production.WARNING: Multi
        line message
        [2026-10-20T10:15:04.123456+02:00] app.DEBUG: Cache miss {key} {"key":"rates"} {"memory":"12 MB"}

        LOG;

    public function testEntriesWithContextExceptionsAndMoreLines(): void
    {
        $entries = new LogParser('UTC')->parse(self::LARAVEL_LOG, 100);

        $this->assertCount(4, $entries);

        $this->assertSame(100, $entries[0]->offset);
        $this->assertSame('2026-10-20 10:15:01', $entries[0]->time);
        $this->assertSame(1792491301.0, $entries[0]->timestamp);
        $this->assertSame('local', $entries[0]->channel);
        $this->assertSame('info', $entries[0]->level);
        $this->assertSame('User signed in', $entries[0]->message);
        $this->assertSame(['user_id' => 18, 'roles' => ['admin']], $entries[0]->context);
        $this->assertNull($entries[0]->extra);

        $error = $entries[1];
        $this->assertSame('Payment failed', $error->message);
        $this->assertSame(18, is_array($error->context) ? $error->context['order'] : null);
        $this->assertNotNull($error->exception);
        $this->assertSame('App\\Exceptions\\PaymentFailed', $error->exception['class']);
        $this->assertSame('402', $error->exception['code']);
        $this->assertSame('/app/app/Pay.php', $error->exception['file']);
        $this->assertSame(12, $error->exception['line']);
        $this->assertSame([
            ['file' => '/app/app/Http/Controllers/OrderController.php', 'line' => 42, 'call' => 'App\\Pay->charge()'],
            ['file' => null, 'line' => null, 'call' => '{main}'],
        ], $error->exception['frames']);
        $this->assertSame(['LogicException(code: 0): Card expired at /app/app/Card.php:3'], $error->exception['previous']);

        $this->assertSame("Multi\nline message", $entries[2]->message);
        $this->assertSame('warning', $entries[2]->level);
        $this->assertNull($entries[2]->context);

        $this->assertSame('Cache miss {key}', $entries[3]->message, 'Braces in the message are not JSON.');
        $this->assertSame(['key' => 'rates'], $entries[3]->context);
        $this->assertSame(['memory' => '12 MB'], $entries[3]->extra);
        $this->assertSame(1792484104.123456, $entries[3]->timestamp, 'A written offset wins over the time zone.');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function monologVersions(): array
    {
        return [
            'Monolog 1 (Laravel 5.5 to 5.8)' => ['monolog-1.log'],
            'Monolog 2 (Laravel 6 to 9)' => ['monolog-2.log'],
            'Monolog 3 (Laravel 10 and later)' => ['monolog-3.log'],
        ];
    }

    /**
     * Files written by a real Monolog with Laravel's formatter (tests/Fixture/logs/make.php).
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('monologVersions')]
    public function testWhatEachLaravelsMonologWrites(string $fixture): void
    {
        $entries = new LogParser('UTC')->parse((string) file_get_contents(__DIR__ . '/../../Fixture/logs/' . $fixture));

        $this->assertSame(['info', 'debug', 'error', 'warning', 'info', 'critical'], array_map(static fn($entry): string => $entry->level, $entries));
        $this->assertSame(['2026-10-20 10:15:00', '2026-10-20 10:15:05'], [$entries[0]->time, $entries[5]->time]);
        $this->assertSame(1792491300.0, $entries[0]->timestamp);

        $this->assertSame(['User signed in', ['user_id' => 18, 'roles' => ['admin', 'editor']], null], [$entries[0]->message, $entries[0]->context, $entries[0]->extra]);
        $this->assertSame(['Cache miss {key}', null], [$entries[1]->message, $entries[1]->context]);

        $error = $entries[2];
        $this->assertSame('Payment failed', $error->message);
        $this->assertNotNull($error->exception);
        $this->assertSame(['RuntimeException', '402', 'Card declined', '/app/make.php', 41], [
            $error->exception['class'],
            $error->exception['code'],
            $error->exception['message'],
            $error->exception['file'],
            $error->exception['line'],
        ]);
        $this->assertSame(['file' => '/app/make.php', 'line' => 47, 'call' => 'charge()'], $error->exception['frames'][0]);
        $this->assertSame(['LogicException(code: 0): Card expired at /app/make.php:41'], $error->exception['previous']);
        $this->assertSame(18, is_array($error->context) ? $error->context['order'] : null);

        $this->assertSame(["Multi\nline message", ['nested' => ['a' => [1, 2, ['deep' => true]]]]], [$entries[3]->message, $entries[3]->context]);
        $this->assertSame(['Queued job', ['job' => 'SendInvoice'], ['request_id' => 'req-7']], [$entries[4]->message, $entries[4]->context, $entries[4]->extra]);
        $this->assertSame(['Résumé upload failed ✓', ['size' => 1.5]], [$entries[5]->message, $entries[5]->context]);
    }

    public function testTheAppsTimeZoneReadsTimesWithoutAnOffset(): void
    {
        $entry = new LogParser('Asia/Dhaka')->parse("[2026-10-20 10:15:01] local.INFO: Hi\n")[0];

        $this->assertSame(1792469701.0, $entry->timestamp);
    }

    public function testLevels(): void
    {
        $entries = new LogParser()->parse(self::LARAVEL_LOG);

        $this->assertTrue($entries[1]->isAtLeast('error'));
        $this->assertFalse($entries[2]->isAtLeast('error'));
        $this->assertTrue($entries[2]->isAtLeast('debug'));
    }

    public function testTextBeforeTheFirstEntryIsNotAnEntry(): void
    {
        $this->assertSame([], new LogParser()->parse("not a log line\nnor this\n"));
        $this->assertCount(1, new LogParser()->parse("half of an entry\n[2026-10-20 10:15:01] local.INFO: Hi\n"));
    }
}
