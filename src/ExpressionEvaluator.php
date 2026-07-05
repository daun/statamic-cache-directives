<?php

namespace Daun\StatamicCacheDirectives;

use Symfony\Component\ExpressionLanguage\ExpressionLanguage as SymfonyExpressionLanguage;
use Symfony\Component\ExpressionLanguage\Node\NameNode;
use Symfony\Component\ExpressionLanguage\Node\Node;
use Symfony\Component\ExpressionLanguage\SyntaxError;

class ExpressionEvaluator
{
    protected SymfonyExpressionLanguage $language;

    /** @param array<string, \Closure|mixed> $variables */
    public function __construct(
        protected array $variables = [],
    ) {
        $this->language = new SymfonyExpressionLanguage;
    }

    public function evaluate(string $expression, string $operator = 'if'): bool
    {
        if ($operator === 'unless') {
            return ! $this->evaluate($expression, 'if');
        }

        return (bool) $this->evaluateValue($expression);
    }

    public function echo(string $expression, bool $escape = true): string
    {
        $value = $this->evaluateValue($expression);

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

        throw new \InvalidArgumentException("Cannot echo non-scalar expression in cache directive: {$expression}");
    }

    private function evaluateValue(string $expression): mixed
    {
        $expression = trim($expression);

        try {
            $parsed = $this->language->parse($expression, array_keys($this->variables));

            return $this->language->evaluate($parsed, $this->resolvedVariables($this->variableNames($parsed->getNodes())));
        } catch (SyntaxError $e) {
            throw new \InvalidArgumentException($this->messageForSyntaxError($e, $expression), previous: $e);
        } catch (\RuntimeException $e) {
            throw new \InvalidArgumentException("Invalid expression in cache directive: {$expression}", previous: $e);
        }
    }

    /**
     * @param  array<int, string>  $names
     * @return array<string, mixed>
     */
    private function resolvedVariables(array $names): array
    {
        $variables = [];

        foreach ($names as $name) {
            $value = $this->variables[$name] ?? null;
            $variables[$name] = $value instanceof \Closure ? $value() : $value;
        }

        return $variables;
    }

    /** @return array<int, string> */
    private function variableNames(Node $node): array
    {
        $names = $node instanceof NameNode ? [$node->attributes['name']] : [];

        foreach ($node->nodes as $child) {
            $names = [...$names, ...$this->variableNames($child)];
        }

        return array_values(array_unique($names));
    }

    private function messageForSyntaxError(SyntaxError $error, string $expression): string
    {
        if (preg_match('/Variable "([^"]+)" is not valid/', $error->getMessage(), $matches) === 1) {
            return "Unknown variable in cache directive: {$matches[1]}";
        }

        return "Invalid expression in cache directive: {$expression}";
    }
}
