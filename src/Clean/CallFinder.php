<?php

namespace ShibbirWeb\Peep\Clean;

/**
 * Finds peep(), pd() and ->peep() calls in PHP source with the tokenizer (not a regex),
 * so strings, comments and other functions named peep are never touched.
 *
 * Removable: a peep() or pd() call that is a statement on its own ("peep($x)->red();"),
 * and a ->peep() step in a chain ("collect($rows)->peep()->sum()"), which returns the
 * collection it was called on. Anything else, such as "$user = peep($user);", is only
 * reported.
 */
final class CallFinder
{
    private const FUNCTIONS = ['peep', 'pd'];

    private const STATEMENT_STARTS = [';', '{', '}'];

    /**
     * @var list<array{0: int|null, 1: string, 2: int}> [token id or null, text, line]
     */
    private $tokens = [];

    /**
     * @return list<array{line: int, code: string, removable: bool, start: int, end: int}>
     */
    public function find(string $code): array
    {
        $this->tokens = self::tokenize($code);
        $calls = [];

        foreach ($this->tokens as $index => $token) {
            $call = $this->callAt($index);
            if ($call !== null) {
                $calls[] = $call;
            }
        }

        return $calls;
    }

    /**
     * Removes the removable calls.
     *
     * @return array{code: string, removed: int}
     */
    public function fix(string $code): array
    {
        $calls = array_values(array_filter($this->find($code), function (array $call) {
            return $call['removable'];
        }));

        if ($calls === []) {
            return ['code' => $code, 'removed' => 0];
        }

        $remove = [];
        foreach ($calls as $call) {
            for ($index = $call['start']; $index <= $call['end']; $index++) {
                $remove[$index] = true;
            }

            // A statement on its own line takes its line with it.
            if ($this->tokens[$call['start']][1] !== '->' && $this->tokens[$call['start']][1] !== '?->') {
                $this->removeLine($call['start'], $call['end'], $remove);
            }
        }

        $result = '';
        foreach ($this->tokens as $index => $token) {
            if (isset($remove[$index])) {
                if ($remove[$index] !== true) {
                    $result .= $remove[$index];
                }

                continue;
            }

            $result .= $token[1];
        }

        return ['code' => $result, 'removed' => count($calls)];
    }

    /**
     * @return array{line: int, code: string, removable: bool, start: int, end: int}|null
     */
    private function callAt(int $index): ?array
    {
        $token = $this->tokens[$index];
        $name = strtolower(ltrim($token[1], '\\'));

        if (! in_array($token[0], [T_STRING, self::fullyQualified()], true) || ! in_array($name, self::FUNCTIONS, true)) {
            return null;
        }

        $open = $this->next($index);
        if ($open === null || $this->tokens[$open][1] !== '(') {
            return null;
        }

        $previous = $this->previous($index);
        $before = $previous !== null ? $this->tokens[$previous] : null;

        // ->peep(): the collection / string / builder macro.
        if ($before !== null && ($before[1] === '->' || $before[1] === '?->')) {
            if ($name !== 'peep') {
                return null;
            }

            $close = $this->matching($open);

            return $close === null ? null : $this->call($previous, $close, true);
        }

        // PHP 7 reads "\peep" as "\" and "peep": a leading backslash is still our function,
        // "Other\peep" is not.
        $start = $index;
        if ($before !== null && $before[0] === T_NS_SEPARATOR) {
            $namespace = $this->previous($previous);
            if ($namespace !== null && in_array($this->tokens[$namespace][0], [T_STRING, T_NAMESPACE], true)) {
                return null;
            }

            $start = $previous;
            $previous = $namespace;
            $before = $namespace !== null ? $this->tokens[$namespace] : null;
        }

        // Method and function declarations, static calls.
        if ($before !== null && (in_array($before[0], [T_FUNCTION, T_NEW, T_CONST, T_DOUBLE_COLON], true) || $before[1] === '&')) {
            return null;
        }

        $close = $this->matching($open);
        if ($close === null) {
            return null;
        }

        $end = $this->chainEnd($close);
        $after = $this->next($end);
        $isStatement = ($before === null || in_array($before[1], self::STATEMENT_STARTS, true) || $before[0] === T_OPEN_TAG)
            && $after !== null && ($this->tokens[$after][1] === ';' || $this->tokens[$after][0] === T_CLOSE_TAG);

        if ($isStatement && $this->tokens[$after][1] === ';') {
            return $this->call($start, $after, true);
        }

        return $this->call($start, $end, $isStatement);
    }

    /**
     * @return array{line: int, code: string, removable: bool, start: int, end: int}
     */
    private function call(int $start, int $end, bool $removable): array
    {
        $code = '';
        for ($index = $start; $index <= $end; $index++) {
            $code .= $this->tokens[$index][1];
        }

        $code = trim((string) preg_replace('/\s+/', ' ', $code));

        return [
            'line' => $this->tokens[$start][2],
            'code' => strlen($code) > 80 ? substr($code, 0, 77) . '...' : $code,
            'removable' => $removable,
            'start' => $start,
            'end' => $end,
        ];
    }

    /**
     * The last token of "peep(...)->red()->label('x')".
     */
    private function chainEnd(int $close): int
    {
        $end = $close;

        while (true) {
            $arrow = $this->next($end);
            if ($arrow === null || ($this->tokens[$arrow][1] !== '->' && $this->tokens[$arrow][1] !== '?->')) {
                return $end;
            }

            $method = $this->next($arrow);
            $open = $method !== null ? $this->next($method) : null;
            if ($open === null || $this->tokens[$open][1] !== '(') {
                return $end;
            }

            $matched = $this->matching($open);
            if ($matched === null) {
                return $end;
            }

            $end = $matched;
        }
    }

    private function matching(int $open): ?int
    {
        $depth = 0;
        $count = count($this->tokens);

        for ($index = $open; $index < $count; $index++) {
            $text = $this->tokens[$index][1];

            if ($text === '(' || $this->tokens[$index][0] === T_CURLY_OPEN || $this->tokens[$index][0] === T_DOLLAR_OPEN_CURLY_BRACES || $text === '{' || $text === '[') {
                $depth++;
            } elseif ($text === ')' || $text === '}' || $text === ']') {
                $depth--;
                if ($depth === 0) {
                    return $this->tokens[$open][1] === '(' && $text === ')' ? $index : null;
                }
            }
        }

        return null;
    }

    private function next(int $index): ?int
    {
        $count = count($this->tokens);

        for ($next = $index + 1; $next < $count; $next++) {
            if (! self::isTrivia($this->tokens[$next][0])) {
                return $next;
            }
        }

        return null;
    }

    private function previous(int $index): ?int
    {
        for ($previous = $index - 1; $previous >= 0; $previous--) {
            if (! self::isTrivia($this->tokens[$previous][0])) {
                return $previous;
            }
        }

        return null;
    }

    /**
     * Marks the indentation before a removed statement and the line break after it, so no
     * blank line is left behind.
     *
     * @param array<int, true|string> $remove Token index => true (drop) or replacement text.
     */
    private function removeLine(int $start, int $end, array &$remove): void
    {
        $before = $start - 1;
        $after = $end + 1;

        if (! isset($this->tokens[$before], $this->tokens[$after]) || $this->tokens[$before][0] !== T_WHITESPACE || $this->tokens[$after][0] !== T_WHITESPACE) {
            return;
        }

        $leading = $this->tokens[$before][1];
        $trailing = $this->tokens[$after][1];
        $lineBreak = strrpos($leading, "\n");

        if ($lineBreak === false || strpos($trailing, "\n") === false) {
            return;
        }

        // Keep the line break that ends the previous line, drop this line's indentation and its own
        // break. Whitespace between two removed lines is dropped entirely.
        $remove[$before] = isset($remove[$before]) ? '' : substr($leading, 0, $lineBreak + 1);
        $remove[$after] = (string) substr($trailing, strpos($trailing, "\n") + 1);
    }

    /**
     * @return list<array{0: int|null, 1: string, 2: int}>
     */
    private static function tokenize(string $code): array
    {
        $tokens = [];
        $line = 1;

        foreach (token_get_all($code) as $token) {
            if (is_array($token)) {
                $tokens[] = [$token[0], $token[1], $token[2]];
                $line = $token[2] + substr_count($token[1], "\n");
            } else {
                $tokens[] = [null, $token, $line];
            }
        }

        return $tokens;
    }

    /**
     * @param int|null $type
     */
    private static function isTrivia($type): bool
    {
        return $type === T_WHITESPACE || $type === T_COMMENT || $type === T_DOC_COMMENT;
    }

    /**
     * PHP 8 reads "\peep" as one token; PHP 7 as "\" and "peep".
     */
    private static function fullyQualified(): int
    {
        return defined('T_NAME_FULLY_QUALIFIED') ? (int) constant('T_NAME_FULLY_QUALIFIED') : -1;
    }
}
