<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Webhook\Template;

/**
 * Renders {{ }} templates for one body format, so values are always escaped correctly:
 *
 * - json: the template is JSON; each string leaf is rendered. A leaf that is exactly one
 *   placeholder keeps the value's type ("{{ now.unix_float }}" becomes a number).
 * - form: the template is a JSON object of fields; each value is rendered as text.
 * - xml:  text with every inserted value XML-escaped.
 * - raw:  text, nothing escaped.
 */
final class TemplateRenderer
{
    /**
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
     * Plain text: URL, header values, raw and XML bodies.
     *
     * @throws TemplateException
     */
    public function text(string $template, bool $xml = false): string
    {
        return (string) preg_replace_callback('/\{\{(.*?)\}\}/s', function (array $matches) use ($xml): string {
            $value = Functions::text($this->evaluate($matches[1]));

            return $xml ? htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8') : $value;
        }, self::checked($template));
    }

    /**
     * @throws TemplateException
     */
    public function json(string $template): string
    {
        $tree = json_decode($template, true);

        if ($tree === null && trim($template) !== 'null') {
            throw new TemplateException('The JSON body is not valid JSON: ' . json_last_error_msg() . '.');
        }

        return (string) json_encode($this->leaves($tree), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * @return array<string, string> Field => rendered value, in the template's order.
     *
     * @throws TemplateException
     */
    public function form(string $template): array
    {
        $fields = json_decode($template, true);

        if (! is_array($fields) || array_is_list($fields) && $fields !== []) {
            throw new TemplateException('A form body is a JSON object of fields, e.g. {"MessageStatus": "{{ event }}"}.');
        }

        $rendered = [];
        foreach ($fields as $name => $value) {
            $rendered[(string) $name] = is_string($value) ? $this->text($value) : Functions::text($value);
        }

        return $rendered;
    }

    private function leaves(mixed $node): mixed
    {
        if (is_array($node)) {
            return array_map($this->leaves(...), $node);
        }

        if (! is_string($node)) {
            return $node;
        }

        // Exactly one placeholder: keep the value's own type.
        if (preg_match('/^\{\{(.*?)\}\}$/s', $node, $matches) === 1 && ! str_contains($matches[1], '}}')) {
            return $this->evaluate($matches[1]);
        }

        return $this->text($node);
    }

    private function evaluate(string $source): mixed
    {
        $expression = new Expression($this->variables);
        $value = $expression->evaluate($source);
        array_push($this->warnings, ...$expression->warnings);

        return $value;
    }

    /**
     * @throws TemplateException
     */
    private static function checked(string $template): string
    {
        if (substr_count($template, '{{') !== substr_count($template, '}}')) {
            throw new TemplateException('A {{ has no matching }}.');
        }

        return $template;
    }
}
