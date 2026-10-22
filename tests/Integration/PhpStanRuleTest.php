<?php

namespace ShibbirWeb\Peep\Tests\Integration;

use ShibbirWeb\Peep\Tests\TestCase;

/**
 * The leftover-call rule, run by the PHPStan the tools package installs.
 */
final class PhpStanRuleTest extends TestCase
{
    public function testTheRuleReportsEveryLeftoverCall(): void
    {
        $root = dirname(__DIR__, 2);
        $phpstan = $root . '/tools/vendor/bin/phpstan';

        if (PHP_VERSION_ID < 70400 || ! is_file($phpstan)) {
            $this->markTestSkipped('PHPStan is installed with "composer tools:install" (PHP 7.4+).');
        }

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phpstan) . ' analyse --no-progress --error-format=json --memory-limit=1G'
            . ' --configuration ' . escapeshellarg($root . '/tests/Fixture/PHPStan/phpstan.neon')
            . ' ' . escapeshellarg($root . '/tests/Fixture/PHPStan/leftover.php') . ' 2>/dev/null';

        $report = json_decode((string) shell_exec($command), true);
        $this->assertIsArray($report);

        $messages = [];
        foreach ($report['files'] as $file) {
            foreach ($file['messages'] as $message) {
                $messages[] = [$message['line'], $message['identifier'], $message['message']];
            }
        }

        $this->assertSame([
            [7, 'peep.leftoverCall', 'Leftover debug call peep(). Remove it, or run vendor/bin/peep-clean --fix.'],
            [8, 'peep.leftoverCall', 'Leftover debug call ->peep(). Remove it, or run vendor/bin/peep-clean --fix.'],
            [9, 'peep.leftoverCall', 'Leftover debug call pd(). Remove it, or run vendor/bin/peep-clean --fix.'],
        ], $messages);
    }
}
