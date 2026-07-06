# Statamic Cache Directives

Parse conditional HTML comment directives after Statamic has rendered a page, so cached pages can still include small dynamic fragments.

This package is a lightweight alternative to Statamic's [`nocache`](https://statamic.dev/tags/nocache) tag. It is useful for tiny auth-, role-, or request-dependent islands where you only need to keep or remove existing markup without the overhead of the nocache data/session pipeline.

It is implemented as a [static caching replacer](https://statamic.dev/advanced-topics/static-caching#replacers) for Statamic's half-measure static cache.

## Installation

Install the package via composer:

```bash
composer require daun/statamic-cache-directives
```

## Registration

Enable the replacer in `config/statamic/static_caching.php`.

```diff
+ use Daun\StatamicCacheDirectives\CacheDirectiveReplacer;

  'replacers' => [
      CsrfTokenReplacer::class,
      NoCacheReplacer::class,
+     CacheDirectiveReplacer::class,
  ],
```

## Usage

Wrap markup in conditional comments. Matching blocks are kept when their expression evaluates to `true`; otherwise they are removed from the response.

```html
<!--[if logged_in]-->
  <a href="/account">Account</a>
<!--[endif]-->

<!--[if logged_out]>
  <a href="/login">Log in</a>
<![endif]-->
```

The second format follows the [downlevel-hidden syntax](https://learn.microsoft.com/en-us/previous-versions/windows/internet-explorer/ie-developer/compatibility/ms537512(v=vs.85)#syntax-of-conditional-comments) for conditional comments.

## Syntax

Expressions use [Symfony Expression Language](https://symfony.com/doc/current/reference/formats/expression_language.html) syntax.

### If

```html
<!--[if logged_in]-->
  <p>Visible to signed-in users.</p>
<!--[endif]-->

<!--[if logged_in]>
  <p>Visible to signed-in users, hidden if unprocessed.</p>
<![endif]-->
```

### Unless

```html
<!--[unless logged_in]-->
  <p>Visible to guests.</p>
<!--[endunless]-->

<!--[unless logged_in]>
  <p>Visible to guests, hidden if unprocessed.</p>
<![endunless]-->
```

### Not

Use either `!` or `not`.

```html
<!--[if !logged_in]-->
  <a href="/login">Log in</a>
<!--[endif]-->

<!--[if not super]-->
  <p>Regular user content.</p>
<!--[endif]-->
```

### And

Use `and` or `&&`.

```html
<!--[if logged_in and super]-->
  <a href="/cp">Control Panel</a>
<!--[endif]-->
```

### Or

Use `or` or `||`.

```html
<!--[if logged_out or super]-->
  <script src="/js/public-preview.js"></script>
<!--[endif]-->
```

### Echo

Use `echo` to print a variable value. Echo directives can be standalone or block-style. Output is escaped for html contexts.

```html
<a href="/account" data-cache="<!--[echo cache_status]-->">
  <!--[echo account_label]-->
</a>

<!--[echo cache_status]>Unknown<![endecho]-->
```

### Raw

Use `raw` to print a variable value without escaping. Use this only for values you fully control as printing untrusted data with `raw` is an XSS vector.

```html
<!--[raw safe_svg_icon]-->

<!--[raw safe_svg_icon]>Fallback<![endraw]-->
```

### Combined expressions

Use parentheses to group subexpressions or override precedence.

```html
<!--[if logged_out or (logged_in and super)]-->
  <a href="/preview">Preview tools</a>
<!--[endif]-->

<!--[if (logged_out or super) and has_preview]-->
  <a href="/preview">Preview tools</a>
<!--[endif]-->

<!--[if !(logged_in and super)]-->
  <p>Visible unless signed in as a super admin.</p>
<!--[endif]-->
```

### Nested data and object methods

Use `[]` for array keys and `.` for object properties or methods.

```html
<!--[if cart["totals"]["items"] > 0 and customer.canCheckout()]-->
  <a href="/checkout">Checkout (<!--[echo cart["totals"]["items"]]-->)</a>
<!--[endif]-->
```

Unknown variable names throw an `InvalidArgumentException`, so typos fail loudly.

## Built-in variables

These variables are available by default and can be used in expressions:

- `logged_in`: Current Statamic user is authenticated.
- `logged_out`: Current Statamic user is not authenticated.
- `cp_access`: Current Statamic user has permission to access the control panel.
- `super`: Current Statamic user is a super admin.

Authentication uses Statamic's configured control-panel guard: `config('statamic.users.guards.cp')`.

## Real-world examples

### Seed frontend auth state

```html
<script>
  window.app = window.app || {};
  window.app.authenticated = false;
</script>

<!--[if logged_in]-->
  <script>window.app.authenticated = true;</script>
<!--[endif]-->
```

### Swap account navigation without `nocache`

```html
<nav>
  <!--[if logged_in]-->
    <a href="/account">Account</a>
    <form method="POST" action="/logout">
      <button type="submit">Log out</button>
    </form>
  <!--[endif]-->

  <!--[if logged_out]-->
    <a href="/login">Log in</a>
    <a href="/register" class="button">Create account</a>
  <!--[endif]-->
</nav>
```

### Show edit links to super admins

```html
<!--[if super]-->
  <aside class="admin-tools">
    <a href="{{ edit_url }}">Edit this page</a>
    <a href="/cp/collections/pages">Pages</a>
  </aside>
<!--[endif]-->
```

### Hide conversion prompts from signed-in users

```html
<!--[unless logged_in]-->
  <section class="cta">
    <h2>Save your favourites</h2>
    <p>Create an account to keep this list across devices.</p>
    <a href="/register" class="button">Sign up</a>
  </section>
<!--[endunless]-->
```

### Load control-panel JavaScript

```html
<!--[if cp_access]-->
  <script type="module" src="/build/admin-bar.js"></script>
<!--[endif]-->
```

## Custom variables

Add custom variables during application boot using `variable()` or `variables()`. Values can be scalar values, arrays,
objects or closures. Closures are evaluated lazily when the variable is first encountered.

Use closures for request-dependent values such as auth, session, request data, or GeoIP lookups. In long-lived worker runtimes
such as FrankenPHP, Swoole, RoadRunner, or Laravel Octane, service providers can boot once per worker instead of once per request.
Passing a direct value would capture the value at boot; passing a closure resolves it for the current request.

```php
use Daun\StatamicCacheDirectives\CacheDirectiveReplacer;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        CacheDirectiveReplacer::variable('editor', fn () => auth()->user()?->hasRole('editor') ?? false);
        CacheDirectiveReplacer::variable('member', fn () => auth()->user()?->isInGroup('members') ?? false);
    }
}
```

For bulk registration, use `variables()`.

```php
use Daun\StatamicCacheDirectives\CacheDirectiveReplacer;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        CacheDirectiveReplacer::variables([
            'in_uk' => fn () => app(GeoIp::class)->countryCode(request()->ip()) === 'UK',
            'has_cart' => fn () => (bool) session('cart.items'),
        ]);
    }
}
```

Then use those variables in comments:

```html
<!--[if editor]-->
  <a href="{{ edit_url }}">Edit</a>
<!--[endif]-->

<!--[if logged_out and has_cart]-->
  <p>Create an account before checkout to save your order history.</p>
<!--[endif]-->

<!--[if member]-->
  <a href="/members/downloads">Member downloads</a>
<!--[endif]-->

<!--[unless in_uk]-->
  <p>This feature is only available in the UK.</p>
<!--[endunless]-->
```

## Disabling a response

If a response contains this marker anywhere, directive parsing is skipped for the **whole response**. Use it as a kill switch on routes that must never be processed (for example email or preview endpoints).

```html
<!--[cache-directives-disable]-->
```

## Ignoring a range

Wrap a section in ignore markers to leave it **verbatim** while the rest of the response is still parsed. Directives inside the range are not processed, and the wrapper comments are removed from the output. This is useful for fragments that legitimately contain conditional comments (for example Outlook `<!--[if mso]>` markup) or documentation showing directive syntax.

```html
<!--[cache-directives-ignore]-->
  <!--[if mso]><table><tr><td>Outlook</td></tr></table><![endif]-->
<!--[cache-directives-endignore]-->
```

> [!WARNING]
> Both markers control directive processing, so they must **never** originate from untrusted, user-controlled content. See [Security](#security) below.

## Security

This replacer scans the **entire** cached HTML response for directive comments. Cached pages frequently contain user-generated content (comments, reviews, usernames, profile fields, reflected search queries, form echoes). If any of that content can contain the strings below, it can subvert directive processing:

- **`<!--[cache-directives-disable]-->`** disables all directive processing for the page. An attacker who injects it can prevent auth-gated blocks such as `<!--[if super]-->...<!--[endif]-->` from being stripped, exposing that markup to every visitor.
- **`<!--[cache-directives-ignore]-->` … `<!--[cache-directives-endignore]-->`** leaves an arbitrary range unprocessed, which can likewise expose auth-gated markup wrapped inside it.
- **`<!--[if ...]-->` / `<!--[echo ...]-->`** markers let injected content pair with, hide, or reveal surrounding directive blocks.

To stay safe, **strip or neutralize directive comments from user-controlled content before it is rendered into a cacheable page**. For example, remove `<!--[` sequences from user input, or escape them:

```php
// When rendering untrusted values into a page, neutralize directive markers.
$safe = str_replace('<!--[', '<!--&#91;', $userContent);
```

Failure handling:

- A directive that fails to evaluate (for example an unknown variable) is **removed** (fails closed) rather than throwing, so a single malformed or injected directive cannot break the whole page. The error is reported to your logger.
- When `app.debug` is enabled (local/dev), the failure is **rethrown** instead, so template typos surface loudly during development.

## License

[MIT](https://opensource.org/licenses/MIT)
