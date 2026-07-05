<?php

namespace Daun\StatamicCacheDirectives;

use Statamic\Support\Str;

class ExpressionEvaluator
{
    /** @param array<string, \Closure|mixed> $variables */
    public function __construct(
        protected array $variables = [],
    ) {}

    public function evaluate(string $expression, string $operator = 'if'): bool
    {
        if ($operator === 'unless') {
            return ! $this->evaluate($expression, 'if');
        }

        $expression = trim($expression);
        $this->assertBalancedParentheses($expression);
        $expression = $this->unwrapParentheses($expression);

        // Handle OR groups (|)
        if ($parts = $this->splitTopLevel($expression, '|')) {
            return collect($parts)
                ->map(fn ($exp) => $this->evaluate($exp))
                ->some(fn ($result) => (bool) $result);
        }

        // Handle AND groups (&)
        if ($parts = $this->splitTopLevel($expression, '&')) {
            return collect($parts)
                ->map(fn ($exp) => $this->evaluate($exp))
                ->every(fn ($result) => (bool) $result);
        }

        // Handle negated expressions (!)
        if (Str::startsWith($expression, ['!', 'not '])) {
            return ! $this->evaluate(Str::chopStart($expression, ['!', 'not ']));
        }

        return (bool) $this->getVariableValue($expression);
    }

    public function echo(string $expression, bool $escape = true): string
    {
        $expression = trim($expression);
        $value = $this->getVariableValue($expression);

        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value) || $value instanceof \Stringable) {
            $string = (string) $value;

            return $escape ? e($string) : $string;
        }

        throw new \InvalidArgumentException("Cannot echo non-scalar variable in cache directive: {$expression}");
    }

    private function getVariableValue(string $expression): mixed
    {
        if (! isset($this->variables[$expression])) {
            throw new \InvalidArgumentException("Unknown variable in cache directive: {$expression}");
        }

        $value = $this->variables[$expression] ?? null;

        return $value instanceof \Closure ? $value() : $value;
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
