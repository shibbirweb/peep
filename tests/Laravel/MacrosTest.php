<?php

namespace ShibbirWeb\Peep\Tests\Laravel;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use ShibbirWeb\Peep\Tests\Laravel\Fixture\User;

final class MacrosTest extends LaravelTestCase
{
    public function testCollectionMacroReturnsTheCollection(): void
    {
        $collection = collect([1, 2, 3]);

        // Same as $collection->peep('Numbers'), spelled out so static analysis sees the macro call.
        $this->assertSame($collection, $collection->__call('peep', ['Numbers']));
        $this->assertSame('3 items', $this->payloadsOfType('log')[0]['content']['values'][0]['summary']);
        $this->assertSame(['label' => 'Numbers'], $this->payloadsOfType('label')[0]['content']);
    }

    public function testStringableMacro(): void
    {
        if (! class_exists('Illuminate\\Support\\Stringable')) {
            $this->markTestSkipped('Stringable needs Laravel 7.');
        }

        $str = 'Illuminate\\Support\\Str';
        $str::of('Hello')->append(' world')->__call('peep', []);

        $this->assertSame('Hello world', $this->payloadsOfType('log')[0]['content']['values'][0]['value']);
    }

    public function testQueryBuilderMacroShowsTheSql(): void
    {
        $builder = DB::table('users')->where('active', 1);

        $this->assertSame($builder, $builder->__call('peep', []));

        $query = $this->payloadsOfType('query')[0]['content'];
        $this->assertTrue(strpos($query['sql'], 'where') !== false);
        $this->assertSame([1], $query['bindings']);
    }

    public function testModelsAreShownWithoutAccessorsOrQueries(): void
    {
        peep()->model(new User(['name' => 'Ada', 'email' => 'ada@example.com']));

        $node = $this->payloadsOfType('log')[0]['content']['values'][0];
        $this->assertSame(User::class, $node['class']);
        $this->assertSame('not saved', $node['summary']);
        $this->assertSame('attributes', $node['children'][0]['key']);
        $this->assertSame('Ada', $node['children'][0]['node']['children'][0]['node']['value']);
    }

    public function testMarkdown(): void
    {
        peep()->markdown('# Title');

        $this->assertTrue(strpos($this->payloadsOfType('html')[0]['content']['html'], '<h1>Title</h1>') !== false);
    }

    public function testBladeDirectives(): void
    {
        $this->assertTrue(strpos(Blade::compileString('@peep($user)'), 'peep($user)') !== false);
        $this->assertTrue(strpos(Blade::compileString('@peepAll'), "->label('View data')") !== false);
        $this->assertTrue(strpos(Blade::compileString('@peepMeasure'), 'peep()->measure()') !== false);
    }
}
