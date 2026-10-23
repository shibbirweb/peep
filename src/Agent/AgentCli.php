<?php

namespace ShibbirWeb\Peep\Agent;

use ShibbirWeb\Peep\Version;

/**
 * The debugging commands of `peep`: read what the viewer caught, as text an AI agent
 * (or a person) can act on, and serve the same as an MCP server.
 */
final class AgentCli
{
    public const COMMANDS = ['status', 'cards', 'report', 'mail', 'sms', 'wait', 'clear', 'mcp', 'skill'];

    public const USAGE = <<<'TEXT'
Debugging with an AI agent (or by hand):
  status                 Is the viewer running? Projects and what they caught
  report                 Markdown debug report: problems first, then each request, mail, SMS
  cards                  The newest cards as text
  mail [--id=ID]         Caught mail (one mail with its body when --id is given)
  sms                    Caught SMS
  wait                   Wait for new cards and show them (--timeout=30, --count=1)
  clear                  Empty the viewer before reproducing (--what=all|cards|mail|sms)
  mcp                    Run as an MCP server on stdin and stdout
  skill                  Print the Peep skill for AI agents (--install to add it to this project)

Filters for report, cards, mail, sms and wait:
  --project=NAME         One project (default: PEEP_PROJECT, else every project)
  --since=5m             Only what came after: an age (30s, 5m, 2h, 1d) or a unix time
  --request=ID           One request (the id shown in cards and reports)
  --card=ID              One card
  --search=TEXT          Only what contains TEXT
  --limit=50             How many of the newest

Other options:
  --json                 JSON instead of text (status, cards, mail, sms)
  --output=FILE          report: also write the report to FILE

The viewer is found the way the app finds it: peep.php, PEEP_HOST, PEEP_PORT, PEEP_TOKEN.
Exit code: 0 when it worked, 1 when the viewer is unreachable or nothing came, 2 for a usage error.
TEXT;

    private function __construct()
    {
    }

    /**
     * @param list<string> $arguments Arguments after the program name; the first is the command.
     * @param resource $input
     * @param resource $output
     * @param resource $errors
     */
    public static function run(array $arguments, $input, $output, $errors, ?ViewerApi $viewer = null): int
    {
        $command = isset($arguments[0]) ? $arguments[0] : 'help';
        $options = self::options(array_slice($arguments, 1));

        if ($options === null) {
            fwrite($errors, 'Options look like --name=value. Run "peep help".' . "\n");

            return 2;
        }

        if (! in_array($command, self::COMMANDS, true)) {
            fwrite($errors, 'Unknown command: ' . $command . "\n\n" . self::USAGE . "\n");

            return 2;
        }

        $base = getcwd();
        $base = is_string($base) ? $base : null;

        if ($command === 'skill') {
            return self::skill($options, $base, $output, $errors);
        }

        $viewer = $viewer !== null ? $viewer : ViewerApi::fromEnvironment($base);
        $json = isset($options['json']);

        switch ($command) {
            case 'mcp':
                (new McpServer($viewer))->serve($input, $output);

                return 0;

            case 'status':
                $result = $viewer->status($json);
                break;

            case 'cards':
                $result = $viewer->cards($options, $json);
                break;

            case 'report':
                $result = $viewer->report($options);
                if ($result['ok'] && isset($options['output']) && $options['output'] !== '') {
                    if (@file_put_contents($options['output'], $result['text']) === false) {
                        fwrite($errors, 'Cannot write ' . $options['output'] . "\n");

                        return 1;
                    }
                    $result['text'] .= "\nSaved to " . $options['output'] . "\n";
                }
                break;

            case 'mail':
                $result = $viewer->mail($options, isset($options['id']) ? $options['id'] : null, $json);
                break;

            case 'sms':
                $result = $viewer->sms($options, $json);
                break;

            case 'wait':
                $result = $viewer->wait(
                    $options,
                    isset($options['timeout']) && ctype_digit($options['timeout']) ? (int) $options['timeout'] : 30,
                    isset($options['count']) && ctype_digit($options['count']) ? (int) $options['count'] : 1
                );
                break;

            default:
                $result = $viewer->clear(isset($options['project']) ? $options['project'] : null, isset($options['what']) ? $options['what'] : 'all');
        }

        fwrite($result['ok'] ? $output : $errors, $result['text']);

        return $result['ok'] ? 0 : 1;
    }

    public static function usage(): string
    {
        return 'Peep ' . Version::VERSION . "\n\nUsage: peep <command> [options]\n\n" . self::USAGE;
    }

    /**
     * @param array<string, string> $options
     * @param resource $output
     * @param resource $errors
     */
    private static function skill(array $options, ?string $base, $output, $errors): int
    {
        $skill = Skill::contents();
        if ($skill === null) {
            fwrite($errors, "The Peep skill file is missing from this installation.\n");

            return 1;
        }

        if (! isset($options['install'])) {
            fwrite($output, $skill);

            return 0;
        }

        $directory = $options['install'] !== '' && $options['install'] !== '1' ? $options['install'] : ($base !== null ? $base . DIRECTORY_SEPARATOR . Skill::PROJECT_DIRECTORY : null);
        if ($directory === null) {
            fwrite($errors, "Cannot tell the project directory; pass --install=DIR.\n");

            return 2;
        }

        $file = Skill::install($directory);
        if ($file === null) {
            fwrite($errors, 'Cannot write the skill into ' . $directory . "\n");

            return 1;
        }

        fwrite($output, 'Installed the Peep skill: ' . $file . "\n" . 'Claude Code finds it there. For other agents, copy it to their skills folder or point AGENTS.md at it.' . "\n");

        return 0;
    }

    /**
     * --name=value and --flag. Null when something else is passed.
     *
     * @param list<string> $arguments
     * @return array<string, string>|null
     */
    private static function options(array $arguments): ?array
    {
        $options = [];

        foreach ($arguments as $argument) {
            if (strpos($argument, '--') !== 0 || strlen($argument) < 3) {
                return null;
            }

            $parts = explode('=', substr($argument, 2), 2);
            $options[$parts[0]] = isset($parts[1]) ? $parts[1] : '1';
        }

        return $options;
    }
}
