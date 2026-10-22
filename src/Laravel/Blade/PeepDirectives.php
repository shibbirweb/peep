<?php

namespace ShibbirWeb\Peep\Laravel\Blade;

/**
 * Blade directives:
 *
 * - `@peep($user, $order)` shows values;
 * - `@peepAll` shows every variable of the view;
 * - `@peepMeasure` ... `@endpeepMeasure` times a part of the view.
 */
final class PeepDirectives
{
    private function __construct()
    {
    }

    /**
     * @param \Illuminate\View\Compilers\BladeCompiler $compiler
     */
    public static function register($compiler): void
    {
        $compiler->directive('peep', function ($expression) {
            return '<?php peep(' . $expression . '); ?>';
        });

        $compiler->directive('peepAll', function () {
            return "<?php peep(isset(\$__data) ? \\Illuminate\\Support\\Arr::except(\$__data, ['__env', 'app', 'errors']) : get_defined_vars())->label('View data'); ?>";
        });

        $compiler->directive('peepMeasure', function () {
            return '<?php peep()->measure(); ?>';
        });

        $compiler->directive('endpeepMeasure', function () {
            return '<?php peep()->measure(); ?>';
        });
    }
}
