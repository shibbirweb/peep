<?php

namespace ShibbirWeb\Peep\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Reports peep(), pd() and ->peep() calls, so none reach production unnoticed. Only loaded
 * by PHPStan; enable it with "includes: - vendor/shibbirweb/peep/phpstan/rules.neon".
 *
 * @implements Rule<Node\Expr>
 */
final class NoPeepCallsRule implements Rule
{
    public function getNodeType(): string
    {
        return Node\Expr::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $call = null;

        if ($node instanceof FuncCall && $node->name instanceof Name) {
            $name = strtolower($node->name->toString());
            $call = in_array($name, ['peep', 'pd', 'shibbirweb\\peep\\peep'], true) ? $name . '()' : null;
        } elseif (($node instanceof MethodCall || $node instanceof NullsafeMethodCall) && $node->name instanceof Identifier && $node->name->toLowerString() === 'peep') {
            $call = '->peep()';
        }

        if ($call === null) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf('Leftover debug call %s. Remove it, or run vendor/bin/peep-clean --fix.', $call))
                ->identifier('peep.leftoverCall')
                ->build(),
        ];
    }
}
