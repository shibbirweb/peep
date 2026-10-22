<?php

namespace ShibbirWeb\Peep\Tests\Unit\Clean;

use ShibbirWeb\Peep\Clean\CallFinder;
use ShibbirWeb\Peep\Tests\TestCase;

final class CallFinderTest extends TestCase
{
    private const CODE = <<<'PHP'
<?php
namespace App;

class Debug
{
    public function peep($value)
    {
        return $value;
    }
}

function work($user, $rows)
{
    peep($user)->green()->label('User');
    $total = collect($rows)->peep()->sum('price');
    $copy = peep($user);
    // peep('in a comment');
    $text = "peep('in a string')";
    \peep('fully qualified');
    pd($rows);
    Other::peep(1);
    \Vendor\peep(2);

    return $total ? peep(1) : 0;
}
PHP;

    public function testFindsCallsButNotLookAlikes(): void
    {
        $found = array_map(function (array $call) {
            return [$call['line'], $call['removable'], $call['code']];
        }, (new CallFinder())->find(self::CODE));

        $this->assertSame([
            [14, true, "peep(\$user)->green()->label('User');"],
            [15, true, '->peep()'],
            [16, false, 'peep($user)'],
            [19, true, "\\peep('fully qualified');"],
            [20, true, 'pd($rows);'],
            [24, false, 'peep(1)'],
        ], $found);
    }

    public function testFixRemovesOnlyWhatIsSafeAndLeavesNoBlankLines(): void
    {
        $result = (new CallFinder())->fix(self::CODE);

        $this->assertSame(4, $result['removed']);
        $this->assertSame(<<<'PHP'
<?php
namespace App;

class Debug
{
    public function peep($value)
    {
        return $value;
    }
}

function work($user, $rows)
{
    $total = collect($rows)->sum('price');
    $copy = peep($user);
    // peep('in a comment');
    $text = "peep('in a string')";
    Other::peep(1);
    \Vendor\peep(2);

    return $total ? peep(1) : 0;
}
PHP
            , $result['code']);
        $this->assertSame([], array_filter((new CallFinder())->find($result['code']), function (array $call) {
            return $call['removable'];
        }), 'Nothing removable is left.');
    }

    public function testFileWithoutCallsIsUnchanged(): void
    {
        $code = "<?php\necho 'hello';\n";

        $this->assertSame(['code' => $code, 'removed' => 0], (new CallFinder())->fix($code));
    }
}
