<?php

namespace ShibbirWeb\Peep\Tests\Unit\Config;

use ShibbirWeb\Peep\Config\ConfigLoader;
use ShibbirWeb\Peep\Tests\TestCase;

final class ConfigLoaderTest extends TestCase
{
    /**
     * @var string
     */
    private $root;

    protected function set_up()
    {
        parent::set_up();

        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'peep-config-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/app/public', 0777, true);
    }

    protected function tear_down()
    {
        foreach ([$this->root . '/peep.php', $this->root . '/app/peep.php'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        rmdir($this->root . '/app/public');
        rmdir($this->root . '/app');
        rmdir($this->root);

        parent::tear_down();
    }

    public function testDefaultsWhenNothingIsConfigured(): void
    {
        $config = $this->loader([])->load();

        $this->assertFalse($config->enabled());
        $this->assertSame(23517, $config->port());
    }

    public function testFindsPeepFileInAParentDirectory(): void
    {
        file_put_contents($this->root . '/app/peep.php', "<?php return ['enabled' => true, 'port' => 9001];");

        $loader = $this->loader([]);

        $this->assertSame(realpath($this->root . '/app/peep.php'), $loader->findFile());
        $config = $loader->load();
        $this->assertTrue($config->enabled());
        $this->assertSame(9001, $config->port());
    }

    public function testNearestPeepFileWins(): void
    {
        file_put_contents($this->root . '/peep.php', "<?php return ['port' => 9001];");
        file_put_contents($this->root . '/app/peep.php', "<?php return ['port' => 9002];");

        $this->assertSame(9002, $this->loader([])->load()->port());
    }

    public function testIgnoresPeepFileThatDoesNotReturnAnArray(): void
    {
        file_put_contents($this->root . '/app/peep.php', '<?php return "nope";');

        $this->assertSame(23517, $this->loader([])->load()->port());
    }

    public function testIgnoresPeepFileThatThrows(): void
    {
        file_put_contents($this->root . '/app/peep.php', '<?php throw new RuntimeException("broken");');

        $this->assertSame(23517, $this->loader([])->load()->port());
    }

    public function testEnvironmentWinsOverFileAndOverridesWinOverEnvironment(): void
    {
        file_put_contents($this->root . '/app/peep.php', "<?php return ['port' => 9001, 'project' => 'from-file', 'host' => 'file-host'];");

        $config = $this->loader(['PEEP_PORT' => '9002', 'PEEP_PROJECT' => 'from-env', 'PEEP_ENABLED' => 'on'])
            ->load(['project' => 'from-override']);

        $this->assertSame(9002, $config->port());
        $this->assertSame('from-override', $config->project());
        $this->assertSame('file-host', $config->host());
        $this->assertTrue($config->enabled());
    }

    public function testReadsEnvironmentVariableSources(): void
    {
        $_SERVER['PEEP_TEST_ONLY_VARIABLE'] = 'from-server';

        try {
            $this->assertSame('from-server', ConfigLoader::readEnvironmentVariable('PEEP_TEST_ONLY_VARIABLE'));
            $this->assertNull(ConfigLoader::readEnvironmentVariable('PEEP_TEST_MISSING_VARIABLE'));
        } finally {
            unset($_SERVER['PEEP_TEST_ONLY_VARIABLE']);
        }
    }

    /**
     * @param array<string, string> $environment
     */
    private function loader(array $environment): ConfigLoader
    {
        return new ConfigLoader([$this->root . '/app/public'], function ($name) use ($environment) {
            return isset($environment[$name]) ? $environment[$name] : null;
        });
    }
}
