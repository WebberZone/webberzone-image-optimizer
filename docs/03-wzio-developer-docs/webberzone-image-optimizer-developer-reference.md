---
slug: webberzone-image-optimizer-developer-reference
title: "WebberZone Image Optimizer Developer Reference"
products: [webberzone-image-optimizer]
sections: [03-wzio-developer-docs]
tags: [webberzone-image-optimizer, developer, filters, hooks]
status: publish
order: 0
---

[kbtoc]

Reference for developers extending [WebberZone Image Optimizer](https://webberzone.github.io/webberzone-image-optimizer/). The plugin uses the namespace `WebberZone\Image_Optimizer` and the prefix `wzio` throughout.

## Reading settings

```php
$value    = wzio_get_option( 'quality_webp', 82 );
$settings = wzio_get_settings();
```

`wzio_get_option( $key, $default_value )` returns a single setting, falling back to `$default_value` (or the registered default) when it is not set. `wzio_get_settings()` returns the full settings array, merged with defaults.

## Filters

### `wzio_get_settings`

Filters the full settings array returned by `wzio_get_settings()`.

```php
apply_filters( 'wzio_get_settings', array $settings )
```

### `wzio_get_option`

Filters the value of any option fetched via `wzio_get_option()`.

```php
apply_filters( 'wzio_get_option', mixed $value, string $key, mixed $default_value )
```

### `wzio_get_option_{$key}`

Key-specific variant of the above — fires after `wzio_get_option`, only for the named key. For example, `wzio_get_option_quality_webp`.

```php
apply_filters( "wzio_get_option_{$key}", mixed $value, string $key, mixed $default_value )
```

### `wzio_update_option`

Filters the value about to be saved by `wzio_update_option()`.

```php
apply_filters( 'wzio_update_option', mixed $value, string $key )
```

### `wzio_conversion_args`

Filters the resolved arguments (formats, quality, effort, strip, lossless, minimum saving) used for a single conversion run.

```php
apply_filters( 'wzio_conversion_args', array $args, array $overrides )
```

### `wzio_attachment_files`

Filters the list of source files (basename → absolute path) that will be converted for an attachment, after MIME-type and exclusion filtering.

```php
apply_filters( 'wzio_attachment_files', array $files, int $attachment_id )
```

### `wzio_is_excluded`

Filters whether a given file path is excluded from conversion. Runs after the **Exclude paths** setting has been checked.

```php
apply_filters( 'wzio_is_excluded', bool $excluded, string $path )
```

### `wzio_delivery_enabled`

Filters whether images are rewritten to `<picture>` markup on the current request. Already `false` on admin, preview, customizer-preview, JSON/REST and cron requests, or when **Serve optimized images** is off.

```php
apply_filters( 'wzio_delivery_enabled', bool $enabled )
```

### `wzio_driver_classes`

Filters the ordered list of encoder driver classes tried when converting an image. Defaults to `Imagick_Driver::class, GD_Driver::class`.

```php
apply_filters( 'wzio_driver_classes', array $classes )
```

### `wzio_memory_multiplier`

Filters the multiplier applied to `width * height * 4 bytes` when the memory guard estimates whether an encode is safe to attempt. Default: `2.5`.

```php
apply_filters( 'wzio_memory_multiplier', float $multiplier )
```

## Two design decisions to know before extending the plugin

**Sidecar files, named via `Helpers::apply_sidecar_naming()` — never by string concatenation.** Default is `photo.jpg` → `photo.jpg.webp`; the `sidecar_naming` setting can switch a site to `photo.webp` instead, and code that assumes append-only will 404 there. Originals are never modified — that part is a hard invariant when hooking into conversion.

**`<picture>` rewriting, not `Accept`-header rewriting.** The HTML is identical for every visitor; the browser picks the source. Do not rely on server-side content negotiation when extending delivery — it is deliberately not how this plugin works.

## Public methods

### `Helpers::apply_sidecar_naming( string $path, string $format ): string`

Applies the current **Optimized file naming** setting (`sidecar_naming`) to any path, filename or URL path. In append mode, `photo.jpg` + `webp` → `photo.jpg.webp`; in replace mode → `photo.webp`. Use this anywhere you need to construct or resolve a sidecar path so it agrees with the plugin's own naming — never concatenate an extension by hand.

### `Resolver::invalidate_path( string $path )`

Forgets the positive existence cache for all sidecar formats associated with a source file. Call this after creating or deleting a sidecar — the cache is deliberately long-lived, so leaving it stale could point `<picture>` markup at a file that no longer exists.

## See also

* [Image Optimizer Settings](https://webberzone.github.io/webberzone-image-optimizer/docs/01-wzio-getting-started/image-optimizer-settings/)
* [How the Queue Works](https://webberzone.github.io/webberzone-image-optimizer/docs/02-wzio-advanced/how-the-queue-works-in-webberzone-image-optimizer/)
* [WebberZone Image Optimizer WP-CLI](https://webberzone.github.io/webberzone-image-optimizer/docs/02-wzio-advanced/webberzone-image-optimizer-wp-cli/)
