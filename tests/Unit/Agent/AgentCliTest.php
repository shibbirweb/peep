<?php

namespace ShibbirWeb\Peep\Tests\Unit\Agent;

use ShibbirWeb\Peep\Agent\AgentCli;
use ShibbirWeb\Peep\Agent\Skill;
use ShibbirWeb\Peep\Agent\ViewerApi;
use ShibbirWeb\Peep\Tests\Fixture\RecordingTransport;
use ShibbirWeb\Peep\Tests\TestCase;
use ShibbirWeb\Peep\Transport\TransportResponse;

final class AgentCliTest extends TestCase
{
    /**
     * @var RecordingTransport
     */
    private $transport;

    /**
     * @var string
     */
    private $directory;

    protected function set_up()
    {
        parent::set_up();

        $this->transport = new RecordingTransport();
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'peep-agent-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tear_down()
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir((string) $file);
            } else {
                unlink((string) $file);
            }
        }
        rmdir($this->directory);

        parent::tear_down();
    }

    public function testCommandsPrintWhatTheViewerSays(): void
    {
        $this->transport->getResponses[] = new TransportResponse(200, "[card1]  2s ago  POST /orders\n");

        $result = $this->command(['cards', '--search=order', '--json']);

        $this->assertSame([0, "[card1]  2s ago  POST /orders\n", ''], $result);
        $this->assertSame(['/_peep/agent/cards?search=order&format=json'], $this->transport->gets);
    }

    public function testFailuresGoToStandardErrorWithExitCodeOne(): void
    {
        $result = $this->command(['status']);

        $this->assertSame(1, $result[0]);
        $this->assertSame('', $result[1]);
        $this->assertStringContainsString('has no agent endpoints', $result[2]);
    }

    public function testReportCanBeSaved(): void
    {
        $this->transport->getResponses[] = new TransportResponse(200, "# Peep debug report\n");
        $file = $this->directory . DIRECTORY_SEPARATOR . 'peep-report.md';

        $result = $this->command(['report', '--output=' . $file]);

        $this->assertSame(0, $result[0]);
        $this->assertSame("# Peep debug report\n", file_get_contents($file));
        $this->assertStringContainsString('Saved to ' . $file, $result[1]);
    }

    public function testUsageErrors(): void
    {
        $this->assertSame(2, $this->command(['dance'])[0]);
        $this->assertSame(2, $this->command(['cards', 'shop'])[0], 'Options need two dashes.');
        $this->assertSame([], $this->transport->gets);
    }

    public function testTheSkillCanBePrintedAndInstalled(): void
    {
        $printed = $this->command(['skill']);
        $this->assertSame(0, $printed[0]);
        $this->assertStringStartsWith("---\nname: peep\n", $printed[1]);
        $this->assertSame(Skill::contents(), $printed[1]);

        $target = $this->directory . DIRECTORY_SEPARATOR . 'skills' . DIRECTORY_SEPARATOR . 'peep';
        $installed = $this->command(['skill', '--install=' . $target]);
        $this->assertSame(0, $installed[0]);
        $this->assertSame(Skill::contents(), file_get_contents($target . DIRECTORY_SEPARATOR . 'SKILL.md'));
    }

    /**
     * Runs a command against the recording transport.
     *
     * @param list<string> $arguments
     * @return array{0: int, 1: string, 2: string} Exit code, output, errors.
     */
    private function command(array $arguments): array
    {
        $input = fopen('php://memory', 'r');
        $output = fopen('php://memory', 'w+');
        $errors = fopen('php://memory', 'w+');
        $this->assertIsResource($input);
        $this->assertIsResource($output);
        $this->assertIsResource($errors);

        $code = AgentCli::run($arguments, $input, $output, $errors, new ViewerApi($this->transport, '127.0.0.1:23517'));

        rewind($output);
        rewind($errors);

        return [$code, (string) stream_get_contents($output), (string) stream_get_contents($errors)];
    }
}
