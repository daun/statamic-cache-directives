# Statamic Cache Conditions

Parse conditional comments for dynamic islands in cached pages.

A lightweight alternative to the built-in [`nocache`](https://statamic.dev/tags/nocache) tag, not a replacement.
Implemented as a [cache replacer](https://statamic.dev/advanced-topics/static-caching#replacers) class for half-measure caching.

## Installation

Install the package via composer:

```bash
composer require daun/statamic-cache-conditions
```

## Registration

Enable the replacer class in your `config/statamic/static_caching.php` file.

```diff
use Daun\StatamicCacheConditions\CacheExpressionReplacer;

'replacers' => [
    CsrfTokenReplacer::class,
    NoCacheReplacer::class,
+   CacheConditionReplacer::class,
]
```

## Usage

Render dynamic expressions using conditional comments.

```html
<script>window.$app.authenticated = false;</script>
<!--[if logged_in]-->
  <script>window.$app.authenticated = true;</script>
<!--[endif]-->
```

## Conditions

The following conditions are available:

- `logged_in`: User is logged in
- `logged_out`: User is not logged in
- `editor`: User has the `editor` role
- `super`: User is a super admin

You can also pass in your own conditions as an array of functions.

```php
'replacers' => [
    CacheConditionReplacer::class,
    'conditions' => [
        'user' => fn () => auth()->user(),
        'is_admin' => fn () => auth()->user()?->isAdmin(),
    ],
],

## License

[MIT](https://opensource.org/licenses/MIT)
