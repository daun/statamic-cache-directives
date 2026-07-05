<?php

use Daun\StatamicCacheDirectives\CacheDirectiveReplacer;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Mockery\MockInterface;
use Statamic\StaticCaching\Replacer;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    app()->instance('statamic.hooks', collect());
});

afterEach(function () {
    app()->instance('statamic.hooks', collect());
});

function replacerWithVariables(array $variables = []): CacheDirectiveReplacer
{
    app()->instance('statamic.hooks', collect());

    if ($variables !== []) {
        CacheDirectiveReplacer::hook('variables', function (array $existing, Closure $next) use ($variables) {
            return $next(array_merge($existing, $variables));
        });
    }

    return new CacheDirectiveReplacer;
}

function mockCpGuard(bool $check = false, mixed $user = null): MockInterface
{
    config(['statamic.users.guards.cp' => 'cp']);

    $guard = Mockery::mock();
    $guard->shouldReceive('check')->andReturn($check)->byDefault();
    $guard->shouldReceive('user')->andReturn($user)->byDefault();

    Auth::shouldReceive('guard')->with('cp')->andReturn($guard)->byDefault();

    return $guard;
}

it('keeps matching if directive content and removes directive comments', function () {
    $replacer = replacerWithVariables(['feature_enabled' => true]);

    $content = 'Before <!--[if feature_enabled]-->Visible<!--[endif]--> After';

    expect($replacer->parse($content))->toBe('Before Visible After');
});

it('removes non matching if directive content', function () {
    $replacer = replacerWithVariables(['feature_enabled' => false]);

    $content = 'Before <!--[if feature_enabled]-->Hidden<!--[endif]--> After';

    expect($replacer->parse($content))->toBe('Before  After');
});

it('inverts conditions for unless directives', function () {
    $replacer = replacerWithVariables([
        'enabled' => true,
        'disabled' => false,
    ]);

    expect($replacer->parse('<!--[unless disabled]-->Visible<!--[endunless]-->'))->toBe('Visible');
    expect($replacer->parse('<!--[unless enabled]-->Hidden<!--[endunless]-->'))->toBe('');
});

it('keeps matching hidden conditional comments and removes comment wrappers', function () {
    $replacer = replacerWithVariables(['feature_enabled' => true]);

    $content = 'Before <!--[if feature_enabled]>Visible<![endif]--> After';

    expect($replacer->parse($content))->toBe('Before Visible After');
});

it('removes non matching hidden conditional comments', function () {
    $replacer = replacerWithVariables(['feature_enabled' => false]);

    $content = 'Before <!--[if feature_enabled]>Hidden<![endif]--> After';

    expect($replacer->parse($content))->toBe('Before  After');
});

it('supports unless hidden conditional comments', function () {
    $replacer = replacerWithVariables([
        'enabled' => true,
        'disabled' => false,
    ]);

    expect($replacer->parse('<!--[unless disabled]>Visible<![endunless]-->'))->toBe('Visible');
    expect($replacer->parse('<!--[unless enabled]>Hidden<![endunless]-->'))->toBe('');
});

it('evaluates directive expressions with or and and operators', function (string $expression, bool $expected) {
    $replacer = replacerWithVariables([
        'truthy' => true,
        'falsy' => false,
    ]);

    expect($replacer->evaluateExpression($expression))->toBe($expected);
})->with([
    'pipe or true' => ['truthy | falsy', true],
    'or false' => ['falsy | falsy', false],
    'ampersand and true' => ['truthy & truthy', true],
    'ampersand and false' => ['truthy & falsy', false],
]);

it('does not support double boolean operators', function (string $expression) {
    $replacer = replacerWithVariables([
        'truthy' => true,
        'falsy' => false,
    ]);

    $replacer->evaluateExpression($expression);
})->with([
    'double pipe' => ['falsy || truthy'],
    'double ampersand' => ['truthy && falsy'],
])->throws(InvalidArgumentException::class, 'Unknown variable in cache directive: ');

it('evaluates negated directive expressions', function (string $expression, bool $expected) {
    $replacer = replacerWithVariables([
        'truthy' => true,
        'falsy' => false,
    ]);

    expect($replacer->evaluateExpression($expression))->toBe($expected);
})->with([
    'bang false' => ['!falsy', true],
    'bang true' => ['!truthy', false],
    'not false' => ['not falsy', true],
    'not true' => ['not truthy', false],
]);

it('evaluates parenthesized directive subexpressions', function (string $expression, bool $expected) {
    $replacer = replacerWithVariables([
        'truthy' => true,
        'falsy' => false,
    ]);

    expect($replacer->evaluateExpression($expression))->toBe($expected);
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

it('parses directives with parenthesized subexpressions', function () {
    $replacer = replacerWithVariables([
        'truthy' => true,
        'falsy' => false,
    ]);

    expect($replacer->parse('<!--[if (truthy | falsy) & truthy]-->Visible<!--[endif]-->'))->toBe('Visible')
        ->and($replacer->parse('<!--[if (truthy | falsy) & falsy]-->Hidden<!--[endif]-->'))->toBe('');
});

it('throws for unbalanced directive subexpressions', function (string $expression) {
    $replacer = replacerWithVariables([
        'truthy' => true,
    ]);

    $replacer->evaluateExpression($expression);
})->with([
    'missing closing parenthesis' => ['(truthy'],
    'missing opening parenthesis' => ['truthy)'],
])->throws(InvalidArgumentException::class, 'Unmatched parentheses in cache directive:');

it('calls closure backed condition variables', function () {
    $called = false;

    $replacer = replacerWithVariables([
        'computed' => function () use (&$called) {
            $called = true;

            return true;
        },
    ]);

    expect($replacer->parse('<!--[if computed]-->Visible<!--[endif]-->'))->toBe('Visible')
        ->and($called)->toBeTrue();
});

it('throws for unknown condition variables', function () {
    $replacer = replacerWithVariables();

    $replacer->evaluateExpression('missing');
})->throws(InvalidArgumentException::class, 'Unknown variable in cache directive: missing');

it('supports built in logged in and logged out conditions', function () {
    mockCpGuard(check: true);

    $replacer = new CacheDirectiveReplacer;

    expect($replacer->parse('<!--[if logged_in]-->In<!--[endif]-->'))->toBe('In');
    expect($replacer->parse('<!--[if logged_out]-->Out<!--[endif]-->'))->toBe('');
});

it('supports the built in super condition', function () {
    $user = new class
    {
        public function isSuper(): bool
        {
            return true;
        }
    };

    mockCpGuard(user: $user);

    $replacer = new CacheDirectiveReplacer;

    expect($replacer->parse('<!--[if super]-->Super<!--[endif]-->'))->toBe('Super');
});

it('treats the super condition as false without an authenticated user', function () {
    mockCpGuard(user: null);

    $replacer = new CacheDirectiveReplacer;

    expect($replacer->parse('<!--[if super]-->Super<!--[endif]-->'))->toBe('');
});

it('leaves content unchanged when it has no directives', function () {
    $replacer = replacerWithVariables(['enabled' => true]);

    expect($replacer->parse('<p>No directives here.</p>'))->toBe('<p>No directives here.</p>');
});

it('leaves ignored conditional comments unchanged', function () {
    $replacer = replacerWithVariables(['enabled' => true]);

    $mso = '<!--[if mso]><table><tr><td>Outlook</td></tr></table><![endif]-->';
    $ignored = '<!--[conditional-comments-ignore]--><!--[if enabled]-->Visible<!--[endif]-->';

    expect($replacer->parse($mso))->toBe($mso);
    expect($replacer->parse($ignored))->toBe($ignored);
});

it('replaces directives in cached responses', function () {
    $replacer = replacerWithVariables(['enabled' => true]);
    $response = new Response('Before <!--[if enabled]-->Visible<!--[endif]--> After');

    $replacer->replaceInCachedResponse($response);

    expect($response->getContent())->toBe('Before Visible After');
});

it('conforms to Statamic static cache replacer contract', function () {
    $replacer = new CacheDirectiveReplacer;
    $class = new ReflectionClass($replacer);

    expect($replacer)->toBeInstanceOf(Replacer::class);

    $prepare = $class->getMethod('prepareResponseToCache');
    expect($prepare->getNumberOfParameters())->toBe(2);
    expect($prepare->getParameters()[0]->getType()?->getName())->toBe(Response::class);
    expect($prepare->getParameters()[1]->getType()?->getName())->toBe(Response::class);

    $replace = $class->getMethod('replaceInCachedResponse');
    expect($replace->getNumberOfParameters())->toBe(1);
    expect($replace->getParameters()[0]->getType()?->getName())->toBe(Response::class);
});
