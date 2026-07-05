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

    private const DISABLE = '<!--[cache-directives-disable]-->';

    private const IGNORE_PATTERN = '/<!--\[cache-directives-ignore\]-->(.*?)<!--\[cache-directives-endignore\]-->/is';

    private const PATTERNS = [
        '/<!--\[(if|unless)\s+([^\]]+)\]-->(.*?)<!--\[end\1\]-->/is',
        '/<!--\[(if|unless)\s+([^\]]+)\]>(.*?)<!\[end\1\]-->/is',
    ];

    private const RAW_PATTERNS = [
        '/<!--\[raw\s+([^\]]+)\]-->(.*?)<!--\[endraw\]-->/is',
        '/<!--\[raw\s+([^\]]+)\]>(.*?)<!\[endraw\]-->/is',
    ];

    private const RAW_INLINE_PATTERN = '/<!--\[raw\s+([^\]]+)\]-->/i';

    private const ECHO_PATTERNS = [
        '/<!--\[echo\s+([^\]]+)\]-->(.*?)<!--\[endecho\]-->/is',
        '/<!--\[echo\s+([^\]]+)\]>(.*?)<!\[endecho\]-->/is',
    ];

    private const ECHO_INLINE_PATTERN = '/<!--\[echo\s+([^\]]+)\]-->/i';

    /** @var array<string, \Closure|mixed> */
    protected array $variables = [];

    protected ExpressionEvaluator $evaluator;

    public function __construct()
    {
        $this->variables = $this->getVariables();
        $this->evaluator = new ExpressionEvaluator($this->variables);
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
        if (! Str::contains($content, self::CHECK)) {
            return $content;
        }

        [$content, $ignored, $token] = $this->extractIgnoreRanges($content);

        if (Str::contains($content, self::DISABLE)) {
            return $this->restoreIgnoreRanges($content, $ignored, $token);
        }

        foreach (self::PATTERNS as $pattern) {
            $content = preg_replace_callback($pattern, function (array $matches) {
                [, $operator, $expression, $inner] = $matches;

                return $this->guard(
                    fn () => $this->evaluator->evaluate($expression, operator: $operator) ? $inner : '',
                    '',
                );
            }, $content) ?? $content;
        }

        foreach (self::RAW_PATTERNS as $pattern) {
            $content = preg_replace_callback($pattern, function (array $matches) {
                return $this->guard(fn () => $this->evaluator->echo($matches[1], escape: false), '');
            }, $content) ?? $content;
        }

        $content = preg_replace_callback(self::RAW_INLINE_PATTERN, function (array $matches) {
            return $this->guard(fn () => $this->evaluator->echo($matches[1], escape: false), '');
        }, $content) ?? $content;

        foreach (self::ECHO_PATTERNS as $pattern) {
            $content = preg_replace_callback($pattern, function (array $matches) {
                return $this->guard(fn () => $this->evaluator->echo($matches[1]), '');
            }, $content) ?? $content;
        }

        $content = preg_replace_callback(self::ECHO_INLINE_PATTERN, function (array $matches) {
            return $this->guard(fn () => $this->evaluator->echo($matches[1]), '');
        }, $content) ?? $content;

        return $this->restoreIgnoreRanges($content, $ignored, $token);
    }

    /** @return array{0: string, 1: array<int, string>, 2: string} */
    private function extractIgnoreRanges(string $content): array
    {
        if (! Str::contains($content, '<!--[cache-directives-ignore]-->')) {
            return [$content, [], ''];
        }

        $ignored = [];
        $token = bin2hex(random_bytes(16));

        $content = preg_replace_callback(self::IGNORE_PATTERN, function (array $matches) use (&$ignored, $token) {
            $placeholder = "<!--cache-directives:{$token}:".count($ignored).'-->';
            $ignored[] = $matches[1];

            return $placeholder;
        }, $content) ?? $content;

        return [$content, $ignored, $token];
    }

    /** @param array<int, string> $ignored */
    private function restoreIgnoreRanges(string $content, array $ignored, string $token): string
    {
        if ($ignored === []) {
            return $content;
        }

        return preg_replace_callback(
            '/<!--cache-directives:'.preg_quote($token, '/').':(\d+)-->/',
            fn (array $matches) => $ignored[(int) $matches[1]] ?? '',
            $content,
        ) ?? $content;
    }

    private function guard(\Closure $callback, string $fallback): string
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            if (config('app.debug')) {
                throw $e;
            }

            report($e);

            return $fallback;
        }
    }

    public function evaluateExpression(string $expression, string $operator = 'if'): bool
    {
        return $this->evaluator->evaluate($expression, $operator);
    }

    public function evaluateEcho(string $expression, bool $escape = true): string
    {
        return $this->evaluator->echo($expression, $escape);
    }
}
