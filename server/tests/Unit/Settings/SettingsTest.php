<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use ShibbirWeb\PeepServer\Settings\DisplayMode;
use ShibbirWeb\PeepServer\Settings\InvalidSettingException;
use ShibbirWeb\PeepServer\Settings\Settings;
use ShibbirWeb\PeepServer\Settings\SettingsFile;
use ShibbirWeb\PeepServer\Settings\Theme;

final class SettingsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/peep-settings-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        @unlink($this->directory . '/' . SettingsFile::FILE_NAME);
        @rmdir($this->directory);
    }

    public function testAFileWithMistakesStillLoadsItsValidValues(): void
    {
        $settings = Settings::fromArray([
            'display_mode' => 'window',
            'ide' => 'notepad',
            'port' => '9000',
            'theme' => 'dark',
            'token' => 'short',
            'path_mappings' => [['from' => '/var/www', 'to' => '/Users/me/shop']],
            'unknown' => true,
        ]);

        $this->assertSame(DisplayMode::Window, $settings->displayMode);
        $this->assertSame('phpstorm', $settings->ide);
        $this->assertSame(23517, $settings->port, 'A string port is not a port.');
        $this->assertSame(Theme::Dark, $settings->theme);
        $this->assertNull($settings->token);
        $this->assertSame([['from' => '/var/www', 'to' => '/Users/me/shop']], $settings->pathMappings);
    }

    public function testUpdatesAreAllOrNothingAndReportWhatNeedsARestart(): void
    {
        $settings = new Settings();

        $this->assertSame(['port'], $settings->update(['port' => 9000, 'theme' => 'light']));
        $this->assertSame(9000, $settings->port);
        $this->assertSame(Theme::Light, $settings->theme);

        try {
            $settings->update(['ide' => 'zed', 'max_cards' => 5]);
            $this->fail('An invalid value must be rejected.');
        } catch (InvalidSettingException $exception) {
            $this->assertSame('max_cards', $exception->setting);
        }

        $this->assertSame('phpstorm', $settings->ide, 'Nothing changes when one value is invalid.');
    }

    public function testTheUpdateChannelIsStableOrBeta(): void
    {
        $settings = new Settings();

        $this->assertSame('stable', $settings->updateChannel);
        $settings->update(['update_channel' => 'beta', 'check_updates' => false]);
        $this->assertSame(['beta', false], [$settings->updateChannel, $settings->checkUpdates]);

        $this->expectException(InvalidSettingException::class);
        $settings->update(['update_channel' => 'nightly']);
    }

    public function testTheHooksRejectInvalidValuesSetDirectly(): void
    {
        $settings = new Settings();

        $this->expectException(InvalidSettingException::class);
        $settings->port = 70000;
    }

    public function testSavedSettingsLoadAgainAndTheFileIsPrivate(): void
    {
        $file = new SettingsFile($this->directory);
        $settings = new Settings();
        $settings->update(['token' => str_repeat('a', 32), 'switches' => ['shop' => ['queries' => 'on']]]);

        $file->save($settings);

        $this->assertSame($settings->toArray(), $file->load()->toArray());
        $this->assertSame('0600', substr(sprintf('%o', fileperms($file->path())), -4));
        $this->assertSame([SettingsFile::FILE_NAME], array_values(array_diff((array) scandir($this->directory), ['.', '..'])), 'No temp file left behind.');
    }

    public function testTheDirectoryFollowsTheEnvironment(): void
    {
        $env = static fn(array $values): \Closure => static fn(string $name): string|false => $values[$name] ?? false;

        $this->assertSame('/custom', SettingsFile::inDefaultDirectory($env(['PEEP_CONFIG_DIR' => '/custom/', 'HOME' => '/home/me']))->directory);
        $this->assertSame('/xdg/peep', SettingsFile::inDefaultDirectory($env(['XDG_CONFIG_HOME' => '/xdg', 'HOME' => '/home/me']))->directory);
        $this->assertSame('/home/me/.config/peep', SettingsFile::inDefaultDirectory($env(['HOME' => '/home/me']))->directory);
    }
}
