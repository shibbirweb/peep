<?php

namespace ShibbirWeb\Peep\Tests\Unit\Install;

use ShibbirWeb\Peep\Install\Installer;
use ShibbirWeb\Peep\Tests\TestCase;
use ShibbirWeb\Peep\Version;

final class InstallerTest extends TestCase
{
    /**
     * @var string
     */
    private $root;

    /**
     * @var string
     */
    private $home;

    /**
     * @var string
     */
    private $iniDirectory;

    /**
     * @var string
     */
    private $phar;

    protected function set_up()
    {
        parent::set_up();

        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'peep-installer-' . bin2hex(random_bytes(6));
        $this->home = $this->root . DIRECTORY_SEPARATOR . 'home';
        $this->iniDirectory = $this->root . DIRECTORY_SEPARATOR . 'conf.d';
        $this->phar = $this->root . DIRECTORY_SEPARATOR . 'download' . DIRECTORY_SEPARATOR . 'peep.phar';

        mkdir($this->home, 0755, true);
        mkdir($this->iniDirectory, 0755, true);
        mkdir(dirname($this->phar), 0755, true);
        file_put_contents($this->phar, 'phar bytes');
    }

    protected function tear_down()
    {
        $this->removeDirectory($this->root);

        parent::tear_down();
    }

    public function testInstallCopiesThePharAndWritesTheIniFile(): void
    {
        $result = Installer::install($this->phar, $this->home, $this->iniDirectory, '', false, false);

        $this->assertSame(0, $result['code']);
        $this->assertSame('phar bytes', file_get_contents($this->installedPhar()));

        $ini = (string) file_get_contents($this->iniFile());
        $this->assertStringContainsString('auto_prepend_file = "' . $this->installedPhar() . '"', $ini);
        $this->assertStringStartsWith('; Added by "php peep.phar install".', $ini);
        $this->assertStringContainsString('Wrote ' . $this->iniFile(), implode("\n", $result['lines']));
    }

    public function testDryRunChangesNothing(): void
    {
        $result = Installer::install($this->phar, $this->home, $this->iniDirectory, '', false, true);

        $this->assertSame(0, $result['code']);
        $this->assertFileDoesNotExist($this->installedPhar());
        $this->assertFileDoesNotExist($this->iniFile());
        $this->assertStringContainsString('Would write ' . $this->iniFile(), implode("\n", $result['lines']));
    }

    public function testAnotherAutoPrependFileIsNotReplacedWithoutForce(): void
    {
        $herd = $this->root . DIRECTORY_SEPARATOR . 'herd-prepend.php';
        file_put_contents($herd, '<?php');

        $result = Installer::install($this->phar, $this->home, $this->iniDirectory, $herd, false, false);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('auto_prepend_file is already set to ' . $herd, $result['lines'][0]);
        $this->assertFileDoesNotExist($this->iniFile());

        $forced = Installer::install($this->phar, $this->home, $this->iniDirectory, $herd, true, false);

        $this->assertSame(0, $forced['code']);
        $this->assertFileExists($this->iniFile());
    }

    public function testInstallingAgainOverItselfIsFine(): void
    {
        Installer::install($this->phar, $this->home, $this->iniDirectory, '', false, false);

        $again = Installer::install($this->installedPhar(), $this->home, $this->iniDirectory, $this->installedPhar(), false, false);

        $this->assertSame(0, $again['code']);
        $this->assertSame('phar bytes', file_get_contents($this->installedPhar()));
    }

    public function testWithoutAScanDirectoryItExplainsTheLineToAdd(): void
    {
        $result = Installer::install($this->phar, $this->home, null, '', false, false);

        $this->assertSame(0, $result['code']);
        $this->assertFileExists($this->installedPhar());
        $this->assertContains('    auto_prepend_file = "' . $this->installedPhar() . '"', $result['lines']);
    }

    public function testInstallNeedsThePharAndAHome(): void
    {
        $this->assertSame(1, Installer::install('', $this->home, $this->iniDirectory, '', false, false)['code']);
        $this->assertSame(1, Installer::install($this->phar, null, $this->iniDirectory, '', false, false)['code']);
    }

    public function testUninstallRemovesOnlyWhatPeepWrote(): void
    {
        Installer::install($this->phar, $this->home, $this->iniDirectory, '', false, false);

        $result = Installer::uninstall($this->home, $this->iniDirectory, false);

        $this->assertSame(0, $result['code']);
        $this->assertFileDoesNotExist($this->iniFile());
        $this->assertFileDoesNotExist($this->installedPhar());

        file_put_contents($this->iniFile(), "auto_prepend_file = \"/somewhere/else.php\"\n");
        $foreign = Installer::uninstall($this->home, $this->iniDirectory, false);

        $this->assertFileExists($this->iniFile());
        $this->assertStringContainsString('was not written by Peep', $foreign['lines'][0]);
    }

    public function testUninstallWhenNothingIsInstalled(): void
    {
        $result = Installer::uninstall($this->home, $this->iniDirectory, false);

        $this->assertSame(['Peep is not installed globally.'], $result['lines']);
    }

    public function testStatusReportsWhetherTheGlobalInstallIsActive(): void
    {
        Installer::install($this->phar, $this->home, $this->iniDirectory, '', false, false);

        $active = Installer::status($this->home, $this->iniDirectory, $this->installedPhar());
        $inactive = Installer::status($this->home, $this->iniDirectory, '');

        $this->assertContains('Global install: active', $active['lines']);
        $this->assertContains('Global install: not active', $inactive['lines']);
        $this->assertContains('auto_prepend_file: (not set)', $inactive['lines']);
    }

    public function testRunPrintsTheVersionAndUsage(): void
    {
        $output = fopen('php://memory', 'w+');
        $errors = fopen('php://memory', 'w+');
        if ($output === false || $errors === false) {
            $this->fail('Cannot open memory streams.');
        }

        $this->assertSame(0, Installer::run(['version'], $output, $errors));
        $this->assertSame(2, Installer::run(['nonsense'], $output, $errors));

        rewind($output);
        rewind($errors);
        $this->assertSame('Peep client ' . Version::VERSION . "\n", stream_get_contents($output));
        $this->assertStringContainsString('Usage: php peep.phar', (string) stream_get_contents($errors));
    }

    private function installedPhar(): string
    {
        return $this->home . DIRECTORY_SEPARATOR . '.peep' . DIRECTORY_SEPARATOR . 'peep.phar';
    }

    private function iniFile(): string
    {
        return $this->iniDirectory . DIRECTORY_SEPARATOR . Installer::INI_FILE;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach ((array) scandir($directory) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
