<?php

namespace Daun\StatamicCacheDirectives;

use Illuminate\Http\Response;
use Statamic\StaticCaching\Replacer;
use Statamic\Support\Str;
use Statamic\Support\Traits\Hookable;

class CacheDirectiveReplacer implements Replacer
{
    use Hookable;

    private const CHECK = '<!--[';

    private const IGNORE = ['<!--[if mso]>', '<!--[conditional-comments-ignore]-->'];

    private const PATTERNS = [
        '/<!--\[(if|unless)\s+([^\]]+)\]-->(.*?)<!--\[end\1\]-->/is',
        '/<!--\[(if|unless)\s+([^\]]+)\]>(.*?)<!\[end\1\]-->/is',
    ];

    /** @var array<string, \Closure|mixed> */
    protected array $variables = [];

    public function __construct()
    {
        $this->variables = $this->getVariables();
    }

    protected function getVariables(): array
    {
        $variables = [
            'logged_in' => fn () => $this->auth()->check(),
            'logged_out' => fn () => ! $this->auth()->check(),
            'super' => fn () => $this->auth()->user()?->isSuper() ?? false,
        ];

        return $this->runHooks('variables', $variables);
    }

    public function prepareResponseToCache(Response $cachedResponse, Response $response)
    {
        if ($content = $response->getContent()) {
            $response->setContent($this->parse($content));
        }
    }

    public function replaceInCachedResponse(Response $response)
    {
        if ($content = $response->getContent()) {
            $response->setContent($this->parse($content));
        }
    }

    protected function auth()
    {
        return auth(config('statamic.users.guards.cp'));
    }

    public function parse(string $content): string
    {
        if (Str::contains($content, self::IGNORE)) {
            return $content;
        }

        if (! Str::contains($content, self::CHECK)) {
            return $content;
        }

        foreach (self::PATTERNS as $pattern) {
            $content = preg_replace_callback($pattern, function (array $matches) {
                [, $operator, $expression, $content] = $matches;

                return $this->evaluateExpression($expression, operator: $operator)
                    ? $content
                    : '';
            }, $content) ?? $content;
        }

        return $content;
    }

    public function evaluateExpression(string $expression, string $operator = 'if'): bool
    {
        if ($operator === 'unless') {
            return ! $this->evaluateExpression($expression, 'if');
        }

        $expression = trim($expression);
        $this->assertBalancedParentheses($expression);
        $expression = $this->unwrapParentheses($expression);

        // Handle OR groups (|)
        if ($parts = $this->splitTopLevel($expression, '|')) {
            return collect($parts)
                ->map(fn ($exp) => $this->evaluateExpression($exp))
                ->some(fn ($result) => (bool) $result);
        }

        // Handle AND groups (&)
        if ($parts = $this->splitTopLevel($expression, '&')) {
            return collect($parts)
                ->map(fn ($exp) => $this->evaluateExpression($exp))
                ->every(fn ($result) => (bool) $result);
        }

        // Handle negated expressions (!)
        if (Str::startsWith($expression, ['!', 'not '])) {
            return ! $this->evaluateExpression(Str::chopStart($expression, ['!', 'not ']));
        }

        // Evaluate existing variables
        if (! isset($this->variables[$expression])) {
            throw new \InvalidArgumentException("Unknown variable in cache directive: {$expression}");
        }

        $value = $this->variables[$expression] ?? null;
        if ($value instanceof \Closure) {
            $value = $value();
        }

        return (bool) $value;
    }

    /** @return null|array<int, string> */
    private function splitTopLevel(string $expression, string $operator): ?array
    {
        $parts = [];
        $depth = 0;
        $start = 0;
        $length = strlen($expression);

        for ($i = 0; $i < $length; $i++) {
            if ($expression[$i] === '(') {
                $depth++;
            } elseif ($expression[$i] === ')') {
                $depth--;
            } elseif ($expression[$i] === $operator && $depth === 0) {
                $parts[] = substr($expression, $start, $i - $start);
                $start = $i + 1;
            }
        }

        if ($parts === []) {
            return null;
        }

        $parts[] = substr($expression, $start);

        return $parts;
    }

    private function unwrapParentheses(string $expression): string
    {
        while ($this->isWrappedInParentheses($expression)) {
            $expression = trim(substr($expression, 1, -1));
        }

        return $expression;
    }

    private function isWrappedInParentheses(string $expression): bool
    {
        if (! str_starts_with($expression, '(')) {
            return false;
        }

        $depth = 0;
        $last = strlen($expression) - 1;

        for ($i = 0; $i <= $last; $i++) {
            if ($expression[$i] === '(') {
                $depth++;
            } elseif ($expression[$i] === ')') {
                $depth--;

                if ($depth === 0) {
                    return $i === $last;
                }
            }
        }

        return false;
    }

    private function assertBalancedParentheses(string $expression): void
    {
        $depth = 0;

        for ($i = 0; $i < strlen($expression); $i++) {
            if ($expression[$i] === '(') {
                $depth++;
            } elseif ($expression[$i] === ')') {
                $depth--;
            }

            if ($depth < 0) {
                throw new \InvalidArgumentException("Unmatched parentheses in cache directive: {$expression}");
            }
        }

        if ($depth !== 0) {
            throw new \InvalidArgumentException("Unmatched parentheses in cache directive: {$expression}");
        }
    }
}
