<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Webhook\Template;

/**
 * Parses and evaluates one expression inside {{ }}. Nothing is ever run as PHP:
 *
 *     expression := term ("~" term)*                 "~" joins as text
 *     term       := string | number | call | path | "(" expression ")"
 *     call       := name "(" [expression ("," expression)*] ")"
 *     path       := name ("." name)*                 e.g. mail.headers.message-id
 */
final class Expression
{
    /**
     * @var list<array{0: string, 1: string}> [type, text]
     */
    private array $tokens = [];

    private int $position = 0;

    /**
     * Unknown variables and functions met while evaluating.
     *
     * @var list<string>
     */
    public array $warnings = [];

    /**
     * @param array<string, mixed> $variables
     */
    public function __construct(
        private readonly array $variables,
    ) {}

    /**
     * @throws TemplateException
     */
    public function evaluate(string $source): mixed
    {
        $this->tokens = self::tokenize($source);
        $this->position = 0;

        $value = $this->expression();

        if ($this->position < count($this->tokens)) {
            throw new TemplateException('Unexpected "' . $this->tokens[$this->position][1] . '" in {{ ' . trim($source) . ' }}.');
        }

        return $value;
    }

    /**
     * @return list<array{0: string, 1: string}>
     *
     * @throws TemplateException
     */
    private static function tokenize(string $source): array
    {
        $tokens = [];
        $length = strlen($source);
        $index = 0;

        while ($index < $length) {
            $character = $source[$index];

            if (ctype_space($character)) {
                $index++;
            } elseif ($character === '"' || $character === "'") {
                $end = $index + 1;
                $text = '';
                while ($end < $length && $source[$end] !== $character) {
                    if ($source[$end] === '\\' && $end + 1 < $length) {
                        $end++;
                    }
                    $text .= $source[$end];
                    $end++;
                }

                if ($end >= $length) {
                    throw new TemplateException('A string is not closed in {{ ' . trim($source) . ' }}.');
                }

                $tokens[] = ['string', $text];
                $index = $end + 1;
            } elseif (preg_match('/\G-?\d+(\.\d+)?/', $source, $matches, 0, $index) === 1) {
                $tokens[] = ['number', $matches[0]];
                $index += strlen($matches[0]);
            } elseif (preg_match('/\G[A-Za-z_][A-Za-z0-9_-]*(\.[A-Za-z0-9_-]+)*/', $source, $matches, 0, $index) === 1) {
                $tokens[] = ['name', $matches[0]];
                $index += strlen($matches[0]);
            } elseif (str_contains('~(),', $character)) {
                $tokens[] = [$character, $character];
                $index++;
            } else {
                throw new TemplateException('Unexpected "' . $character . '" in {{ ' . trim($source) . ' }}.');
            }
        }

        return $tokens;
    }

    private function expression(): mixed
    {
        $value = $this->term();

        while ($this->peek() === '~') {
            $this->position++;
            $value = Functions::text($value) . Functions::text($this->term());
        }

        return $value;
    }

    private function term(): mixed
    {
        $token = $this->tokens[$this->position] ?? null;
        if ($token === null) {
            throw new TemplateException('An expression is missing a value.');
        }

        $this->position++;

        return match ($token[0]) {
            'string' => $token[1],
            'number' => str_contains($token[1], '.') ? (float) $token[1] : (int) $token[1],
            '(' => $this->group(),
            'name' => $this->peek() === '(' ? $this->call($token[1]) : $this->lookup($token[1]),
            default => throw new TemplateException('Unexpected "' . $token[1] . '".'),
        };
    }

    private function group(): mixed
    {
        $value = $this->expression();
        $this->expect(')');

        return $value;
    }

    private function call(string $name): mixed
    {
        $this->expect('(');
        $arguments = [];

        if ($this->peek() !== ')') {
            $arguments[] = $this->expression();

            while ($this->peek() === ',') {
                $this->position++;
                $arguments[] = $this->expression();
            }
        }

        $this->expect(')');

        if (! Functions::exists($name)) {
            $this->warnings[] = 'Unknown function: ' . $name . '()';

            return '';
        }

        return Functions::call($name, $arguments);
    }

    private function lookup(string $path): mixed
    {
        $value = $this->variables;

        foreach (explode('.', $path) as $key) {
            if (! is_array($value) || ! array_key_exists($key, $value)) {
                $this->warnings[] = 'Unknown variable: ' . $path;

                return '';
            }

            $value = $value[$key];
        }

        // A group with its own value, e.g. "now" (ISO time) next to "now.unix".
        return is_array($value) && array_key_exists('_', $value) ? $value['_'] : $value;
    }

    private function peek(): ?string
    {
        return $this->tokens[$this->position][0] ?? null;
    }

    private function expect(string $type): void
    {
        if ($this->peek() !== $type) {
            throw new TemplateException('Expected "' . $type . '".');
        }

        $this->position++;
    }
}
