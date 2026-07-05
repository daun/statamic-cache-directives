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

        // Handle OR groups (|)
        if (Str::contains($expression, '|')) {
            return collect(explode('|', $expression))
                ->map(fn ($exp) => $this->evaluateExpression($exp))
                ->some(fn ($result) => (bool) $result);
        }

        // Handle AND groups (&)
        if (Str::contains($expression, '&')) {
            return collect(explode('&', $expression))
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
}
