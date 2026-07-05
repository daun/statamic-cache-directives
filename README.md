# Statamic Cache Conditions

Parse conditional html comments after Statamic has rendered a page, so cached pages can still include small dynamic fragments.

This package is a lightweight alternative to Statamic's [`nocache`](https://statamic.dev/tags/nocache) tag. It is useful for tiny auth-, role-, or request-dependent islands where you only need to keep or remove existing markup without the overhead of the nocache data/session pipeline.

It is implemented as a [static caching replacer](https://statamic.dev/advanced-topics/static-caching#replacers) for Statamic's half-measure static cache.

## Installation

Install the package via composer:

```bash
composer require daun/statamic-cache-conditions
```

## Registration

Enable the replacer in `config/statamic/static_caching.php`.

```diff
+ use Daun\StatamicCacheConditions\CacheConditionReplacer;
  use Statamic\StaticCaching\Replacers\CsrfTokenReplacer;
  use Statamic\StaticCaching\Replacers\NoCacheReplacer;

  'replacers' => [
      CsrfTokenReplacer::class,
      NoCacheReplacer::class,
+     CacheConditionReplacer::class,
  ],
```

## Usage

Wrap markup in conditional comments. Matching blocks are kept when their expression evaluates to `true`; otherwise they are removed from the response.

```html
<!--[if logged_in]-->
  <a href="/account">Account</a>
<!--[endif]-->

<!--[if logged_out]-->
  <a href="/login">Log in</a>
<!--[endif]-->
```

## Syntax

### If

```html
<!--[if logged_in]-->
  <p>Visible to signed-in users.</p>
<!--[endif]-->
```

### Unless

```html
<!--[unless logged_in]-->
  <p>Visible to guests.</p>
<!--[endunless]-->
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

Use `&` or `&&`.

```html
<!--[if logged_in & super]-->
  <a href="/cp">Control Panel</a>
<!--[endif]-->
```

### Or

Use `|` or `||`.

```html
<!--[if logged_out | super]-->
  <script src="/js/public-preview.js"></script>
<!--[endif]-->
```

### Combined expressions

`&`/`&&` groups are evaluated inside `|`/`||` groups. Parentheses are not supported.

```html
<!--[if logged_out | logged_in & super]-->
  <a href="/preview">Preview tools</a>
<!--[endif]-->
```

Unknown condition names throw an `InvalidArgumentException`, so typos fail loudly.

## Built-in conditions

- `logged_in`: Current Statamic user is authenticated.
- `logged_out`: Current Statamic user is not authenticated.
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

### Load admin-only JavaScript

```html
<!--[if logged_in && super]-->
  <script type="module" src="/build/admin-overlay.js"></script>
<!--[endif]-->
```

## Custom conditions

Add your own condition names with the `conditions` hook. Register the hook during application boot, for example in `app/Providers/AppServiceProvider.php`.
Values can be scalar values or closures that are called when the condition is evaluated.

```php
use Daun\StatamicCacheConditions\CacheConditionReplacer;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        CacheConditionReplacer::hook('conditions', function (array $conditions, Closure $next) {
            $conditions['editor'] = fn () => auth()->user()?->hasRole('editor') ?? false;
            $conditions['member'] = fn () => auth()->user()?->groups()->has('members') ?? false;
            $conditions['has_cart'] = fn () => (bool) session('cart.items');
            $conditions['in_uk'] = fn () => new \GeoIp2\Database\Reader()->city(request()->getClientIp())->country->isoCode === 'UK';

            return $next($conditions);
        });
    }
}
```

Then use those names in comments:

```html
<!--[if editorZ]-->
  <a href="{{ edit_url }}">Edit</a>
<!--[endif]-->

<!--[if logged_out & has_cart]-->
  <p>Create an account before checkout to save your order history.</p>
<!--[endif]-->

<!--[if member]-->
  <a href="/members/downloads">Member downloads</a>
<!--[endif]-->

<!--[unless in_uk]-->
  <p>This feature is only available in the UK.</p>
<!--[endunless]-->
```

## Ignoring a response

If a response contains Outlook conditional comments (`<!--[if mso]>`) or this marker, parsing is skipped for the whole response.
This is useful for emails rendered by Statamic, where conditional comments are part of the final html.

```html
<!--[conditional-comments-ignore]-->
```

## License

[MIT](https://opensource.org/licenses/MIT)
