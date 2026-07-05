<?php

use Daun\StatamicCacheDirectives\ExpressionEvaluator;

function evaluator(array $variables = []): ExpressionEvaluator
{
    return new ExpressionEvaluator($variables);
}

it('evaluates expressions with or and and operators', function (string $expression, bool $expected) {
    expect(evaluator([
        'truthy' => true,
        'falsy' => false,
    ])->evaluate($expression))->toBe($expected);
})->with([
    'pipe or true' => ['truthy | falsy', true],
    'or false' => ['falsy | falsy', false],
    'ampersand and true' => ['truthy & truthy', true],
    'ampersand and false' => ['truthy & falsy', false],
]);

it('evaluates double boolean operators', function (string $expression, bool $expected) {
    expect(evaluator([
        'truthy' => true,
        'falsy' => false,
    ])->evaluate($expression))->toBe($expected);
})->with([
    'double pipe' => ['falsy || truthy', true],
    'double ampersand' => ['truthy && falsy', false],
]);

it('evaluates negated expressions', function (string $expression, bool $expected) {
    expect(evaluator([
        'truthy' => true,
        'falsy' => false,
    ])->evaluate($expression))->toBe($expected);
})->with([
    'bang false' => ['!falsy', true],
    'bang true' => ['!truthy', false],
    'not false' => ['not falsy', true],
    'not true' => ['not truthy', false],
]);

it('evaluates parenthesized subexpressions', function (string $expression, bool $expected) {
    expect(evaluator([
        'truthy' => true,
        'falsy' => false,
    ])->evaluate($expression))->toBe($expected);
})->with([
    'wrapped truthy' => ['(truthy)', true],
    'wrapped falsy' => ['(falsy)', false],
    'nested wrapped truthy' => ['((truthy))', true],
    'wrapped and false' => ['(truthy & falsy)', false],
    'wrapped or true' => ['(truthy | falsy)', true],
    'negated wrapped false' => ['!(falsy)', true],
    'negated wrapped and false' => ['!(truthy & falsy)', true],
    'negated wrapped or true' => ['!(truthy | falsy)', false],
    'and with wrapped operands' => ['(truthy)&(truthy)', true],
    'or with wrapped operands' => ['(falsy)|(truthy)', true],
    'parentheses override precedence false' => ['(truthy | falsy) & falsy', false],
    'parentheses preserve nested precedence true' => ['truthy | (falsy & falsy)', true],
]);

it('throws for unbalanced subexpressions', function (string $expression) {
    evaluator(['truthy' => true])->evaluate($expression);
})->with([
    'missing closing parenthesis' => ['(truthy'],
    'missing opening parenthesis' => ['truthy)'],
])->throws(InvalidArgumentException::class, 'Invalid expression in cache directive:');

it('throws for unknown variables', function () {
    evaluator()->evaluate('missing');
})->throws(InvalidArgumentException::class, 'Unknown variable in cache directive: missing');

it('evaluates nested array keys', function () {
    expect(evaluator([
        'user' => [
            'profile' => [
                'active' => true,
                'score' => 42,
            ],
        ],
    ])->evaluate('user["profile"]["active"] and user["profile"]["score"] >= 40'))->toBeTrue();
});

it('evaluates object properties and methods', function () {
    $user = new class
    {
        public object $profile;

        public function __construct()
        {
            $this->profile = (object) ['active' => true];
        }

        public function isSuper(): bool
        {
            return true;
        }
    };

    expect(evaluator(['user' => $user])->evaluate('user.profile.active and user.isSuper()'))->toBeTrue();
});

it('inverts conditions for the unless operator', function () {
    $evaluator = evaluator([
        'enabled' => true,
        'disabled' => false,
    ]);

    expect($evaluator->evaluate('disabled', 'unless'))->toBeTrue()
        ->and($evaluator->evaluate('enabled', 'unless'))->toBeFalse();
});
