<?php

namespace ShibbirWeb\Peep\Tests\Laravel;

use Illuminate\Support\Facades\Artisan;

final class CleanCommandTest extends LaravelTestCase
{
    public function testListsAndRemovesLeftoverCalls(): void
    {
        $directory = sys_get_temp_dir() . '/peep-clean-' . bin2hex(random_bytes(6));
        mkdir($directory);
        $file = $directory . '/Controller.php';
        file_put_contents($file, "<?php\nfunction show(\$user)\n{\n    peep(\$user)->green();\n\n    return \$user;\n}\n");

        try {
            $this->assertSame(1, Artisan::call('peep:clean', ['paths' => [$directory]]));
            $this->assertTrue(strpos(Artisan::output(), 'Controller.php:4  peep($user)->green();  (removable)') !== false);

            $this->assertSame(0, Artisan::call('peep:clean', ['paths' => [$directory], '--fix' => true]));
            $this->assertSame("<?php\nfunction show(\$user)\n{\n\n    return \$user;\n}\n", file_get_contents($file));
        } finally {
            unlink($file);
            rmdir($directory);
        }
    }
}
