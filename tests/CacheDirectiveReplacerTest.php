<?php

use Daun\StatamicCacheDirectives\CacheDirectiveReplacer;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Response;
use Mockery\MockInterface;
use Statamic\StaticCaching\Replacer;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    app()->instance('statamic.hooks', collect());
    resetCacheDirectiveReplacerVariables();
});

afterEach(function () {
    app()->instance('statamic.hooks', collect());
    resetCacheDirectiveReplacerVariables();
});

function resetCacheDirectiveReplacerVariables(): void
{
    $property = new ReflectionProperty(CacheDirectiveReplacer::class, 'customVariables');
    $property->setValue(null, []);
}

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

    $guard = Mockery::mock(Guard::class);
    $guard->shouldReceive('check')->andReturn($check)->byDefault();
    $guard->shouldReceive('user')->andReturn($user)->byDefault();

    $factory = Mockery::mock(AuthFactory::class);
    $factory->shouldReceive('guard')->with('cp')->andReturn($guard)->byDefault();
    app()->instance(AuthFactory::class, $factory);

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

it('replaces standalone echo directives', function () {
    $replacer = replacerWithVariables([
        'debug' => 'cache-hit',
        'count' => 3,
        'enabled' => true,
        'disabled' => false,
    ]);

    expect($replacer->parse('<a data-cache="<!--[echo debug]-->"><!--[echo count]--></a>'))
        ->toBe('<a data-cache="cache-hit">3</a>')
        ->and($replacer->parse('<!--[echo enabled]--> <!--[echo disabled]-->'))
        ->toBe('true false');
});

it('replaces block echo directives', function () {
    $replacer = replacerWithVariables([
        'label' => 'Debug link',
        'debug' => 'cache-hit',
    ]);

    expect($replacer->parse('<a><!--[echo label]-->Fallback<!--[endecho]--></a>'))
        ->toBe('<a>Debug link</a>')
        ->and($replacer->parse('<!--[echo debug]>Fallback<![endecho]-->'))
        ->toBe('cache-hit');
});

it('does not evaluate echo directives inside removed conditions', function () {
    $replacer = replacerWithVariables([
        'enabled' => false,
    ]);

    expect($replacer->parse('<!--[if enabled]--><!--[echo missing]--><!--[endif]-->'))->toBe('');
});

it('throws for unknown echo variables in debug mode', function () {
    config(['app.debug' => true]);

    $replacer = replacerWithVariables();

    $replacer->parse('<!--[echo missing]-->');
})->throws(InvalidArgumentException::class, 'Unknown variable in cache directive: missing');

it('removes failing directives instead of throwing outside debug mode', function () {
    config(['app.debug' => false]);

    $replacer = replacerWithVariables(['enabled' => true]);

    expect($replacer->parse('Before <!--[echo missing]--> After'))->toBe('Before  After')
        ->and($replacer->parse('Before <!--[if missing]-->X<!--[endif]--> After'))->toBe('Before  After')
        ->and($replacer->parse('Before <!--[if enabled]-->Kept<!--[endif]--> After'))->toBe('Before Kept After');
});

it('isolates a failing directive from surrounding valid directives', function () {
    config(['app.debug' => false]);

    $replacer = replacerWithVariables(['enabled' => true]);

    // A poisoned directive (e.g. injected via user content) must not abort
    // parsing of the rest of the response.
    $content = '<!--[if enabled]-->A<!--[endif]--><!--[if injected]-->B<!--[endif]--><!--[if enabled]-->C<!--[endif]-->';

    expect($replacer->parse($content))->toBe('AC');
});

it('escapes echo output by default', function () {
    $replacer = replacerWithVariables(['name' => '<script>alert(1)</script>']);

    expect($replacer->parse('<!--[echo name]-->'))
        ->toBe('&lt;script&gt;alert(1)&lt;/script&gt;');
});

it('does not escape raw output', function () {
    $replacer = replacerWithVariables(['snippet' => '<b>hi</b>']);

    expect($replacer->parse('<!--[raw snippet]-->'))->toBe('<b>hi</b>')
        ->and($replacer->parse('<x><!--[raw snippet]-->Fallback<!--[endraw]--></x>'))->toBe('<x><b>hi</b></x>')
        ->and($replacer->parse('<!--[raw snippet]>Fallback<![endraw]-->'))->toBe('<b>hi</b>');
});

it('parses directives with parenthesized subexpressions', function () {
    $replacer = replacerWithVariables([
        'truthy' => true,
        'falsy' => false,
    ]);

    expect($replacer->parse('<!--[if (truthy | falsy) & truthy]-->Visible<!--[endif]-->'))->toBe('Visible')
        ->and($replacer->parse('<!--[if (truthy | falsy) & falsy]-->Hidden<!--[endif]-->'))->toBe('');
});

it('parses nested array keys and object methods', function () {
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

    $replacer = replacerWithVariables([
        'settings' => ['cache' => ['enabled' => true]],
        'user' => $user,
    ]);

    expect($replacer->parse('<!--[if settings["cache"]["enabled"] and user.isSuper()]-->Visible<!--[endif]-->'))
        ->toBe('Visible');
});

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

it('supports custom variables registered with variable', function () {
    CacheDirectiveReplacer::variable('editor', fn () => true);

    $replacer = new CacheDirectiveReplacer;

    expect($replacer->parse('<!--[if editor]-->Edit<!--[endif]-->'))->toBe('Edit');
});

it('passes variables registered with variable through the variables hook', function () {
    CacheDirectiveReplacer::variable('editor', fn () => false);

    CacheDirectiveReplacer::hook('variables', function (array $variables, Closure $next) {
        $variables['editor'] = fn () => true;

        return $next($variables);
    });

    $replacer = new CacheDirectiveReplacer;

    expect($replacer->parse('<!--[if editor]-->Edit<!--[endif]-->'))->toBe('Edit');
});

it('supports built in logged in and logged out conditions', function () {
    mockCpGuard(check: true);

    $replacer = new CacheDirectiveReplacer;

    expect($replacer->parse('<!--[if logged_in]-->In<!--[endif]-->'))->toBe('In');
    expect($replacer->parse('<!--[if logged_out]-->Out<!--[endif]-->'))->toBe('');
});

it('supports the built in super condition', function () {
    $user = new class implements Authenticatable
    {
        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): int
        {
            return 1;
        }

        public function getAuthPasswordName(): string
        {
            return 'password';
        }

        public function getAuthPassword(): string
        {
            return '';
        }

        public function getRememberToken(): ?string
        {
            return null;
        }

        public function setRememberToken($value): void
        {
            //
        }

        public function getRememberTokenName(): string
        {
            return 'remember_token';
        }

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

it('disables all parsing for the whole response when the disable marker is present', function () {
    $replacer = replacerWithVariables(['enabled' => true]);

    $content = '<!--[cache-directives-disable]--><!--[if enabled]-->Visible<!--[endif]-->';

    expect($replacer->parse($content))->toBe($content);
});

it('leaves an ignore range verbatim while still parsing the rest of the response', function () {
    $replacer = replacerWithVariables(['enabled' => true]);

    $content = '<!--[if enabled]-->A<!--[endif]-->'
        .'<!--[cache-directives-ignore]--><!--[if mso]><table></table><![endif]--><!--[cache-directives-endignore]-->'
        .'<!--[if enabled]-->B<!--[endif]-->';

    // Wrapper markers stripped, inner content kept as-is, outer directives processed.
    expect($replacer->parse($content))
        ->toBe('A<!--[if mso]><table></table><![endif]-->B');
});

it('does not process directives inside an ignore range', function () {
    $replacer = replacerWithVariables(['enabled' => true]);

    $content = '<!--[cache-directives-ignore]--><!--[if enabled]-->Kept<!--[endif]--><!--[cache-directives-endignore]-->';

    expect($replacer->parse($content))->toBe('<!--[if enabled]-->Kept<!--[endif]-->');
});

it('a disable marker shown inside an ignore range does not disable the page', function () {
    $replacer = replacerWithVariables(['enabled' => true]);

    $content = '<!--[cache-directives-ignore]--><!--[cache-directives-disable]--><!--[cache-directives-endignore]-->'
        .'<!--[if enabled]-->Visible<!--[endif]-->';

    expect($replacer->parse($content))
        ->toBe('<!--[cache-directives-disable]-->Visible');
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
