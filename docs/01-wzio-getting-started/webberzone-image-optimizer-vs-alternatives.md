---
slug: webberzone-image-optimizer-vs-alternatives
title: "WebberZone Image Optimizer vs. Modern Image Formats, WebP Express, and Converter for Media"
products: [webberzone-image-optimizer]
sections: [01-wzio-getting-started]
tags: [webberzone-image-optimizer, comparison, webp, avif]
status: publish
order: 5
---

[kbtoc]

[WebberZone Image Optimizer](https://webberzone.com/plugins/webberzone-image-optimizer/) is one of several free WordPress plugins that convert images to WebP and AVIF locally, without uploading anything to a third-party service. This article compares it against three plugins that take the same no-account, on-server approach: **Modern Image Formats** (the WordPress Performance Team's `webp-uploads`), **WebP Express**, and **Converter for Media** (`webp-converter-for-media`). Cloud-based optimizers such as ShortPixel, Imagify, and Smush Pro are not covered — uploading images to an external service is a different model with different trade-offs.

Every claim below was checked directly against each plugin's own source rather than its marketing copy: WebberZone Image Optimizer's own `includes/` directory, the WordPress Performance Team's [`performance`](https://github.com/WordPress/performance) repository, the [`rosell-dk/webp-express`](https://github.com/rosell-dk/webp-express) GitHub repository, and the `webp-converter-for-media` trunk from the WordPress.org plugin SVN. This comparison was compiled by Claude Sonnet 5 on 2026-08-11 and reflects each plugin's code as of that date — check each project's changelog if you're reading this later, since settings and defaults do change between releases.

## Quality and encoder control

**WebberZone Image Optimizer** exposes independent numeric controls per format on the Quality tab: **WebP quality** (1–100, default `82`), **AVIF quality** (1–100, default `50`), **WebP encoder effort** (0–6, default `6`), **AVIF encoder effort** (0–6, default `4`), and a **Lossless for PNG sources** toggle (default on). See [Image Optimizer Settings](https://webberzone.com/support/knowledgebase/image-optimizer-settings/) for the full reference.

**Modern Image Formats** has no quality setting anywhere in its admin screen. WebP quality is hardcoded in `hooks.php`:

```php
function webp_uploads_modify_webp_quality( int $quality, string $mime_type ): int {
    // For WebP images, always return 82 (other MIME types were already using 82 by default anyway).
    if ( 'image/webp' === $mime_type ) {
        return 82;
    }
    return $quality;
}
```

AVIF quality isn't touched by the plugin at all — it inherits whatever WordPress core's default image quality happens to be. There is no encoder-effort setting and no lossless-PNG option; a search of the plugin's PHP source for "effort" or "lossless" returns nothing.

**WebP Express** has the most granular quality controls of the three alternatives: separate JPEG→WebP and PNG→WebP quality fields (0–100), a "max quality" cap, near-lossless quality, alpha-channel quality, and — uniquely — automatic detection of the source JPEG's own quality via Imagick, so the WebP output can match it rather than use a fixed number. It also exposes a cwebp "Method (0–6)" field, the same concept as WZIO's encoder-effort setting, though it only applies when the cwebp binary converter is in use, not the GD or Imagick backends.

**Converter for Media** offers a single "Conversion strategy" dropdown with five fixed presets — 75%, 80%, 85%, 90%, or 95% — rather than a free-form 1–100 value, and that one setting applies to both output formats at once rather than WebP and AVIF independently:

```php
public function get_available_values( array $settings ): array {
    $levels = apply_filters( 'webpc_option_quality_levels', [ 75, 80, 85, 90, 95 ] );
    // ...
}
```

No encoder-effort or lossless-PNG setting exists in its source.

<figure class="wp-block-table"><table class="has-fixed-layout">
<thead>
<tr><th>Plugin</th><th>Quality range</th><th>Per-format quality</th><th>Encoder effort</th><th>Lossless PNG</th></tr>
</thead>
<tbody>
<tr><td>WebberZone Image Optimizer</td><td>1–100</td><td>Yes (WebP and AVIF separate)</td><td>Yes (0–6, both formats)</td><td>Yes</td></tr>
<tr><td>Modern Image Formats</td><td>Fixed (WebP always 82)</td><td>No</td><td>No</td><td>No</td></tr>
<tr><td>WebP Express</td><td>0–100</td><td>Yes (by source type, WebP only)</td><td>Yes (0–6, cwebp only)</td><td>Near-lossless only</td></tr>
<tr><td>Converter for Media</td><td>5 fixed presets</td><td>No (shared value)</td><td>No</td><td>No</td></tr>
</tbody>
</table></figure>

## AVIF support

WebberZone Image Optimizer generates AVIF for free, alongside WebP, selectable independently on the **Formats to generate** setting.

Modern Image Formats supports AVIF, but only one modern format at a time — its **Image output format** dropdown is WebP *or* AVIF, never both together:

```php
// settings.php
<select name="perflab_modern_image_format" id="perflab_modern_image_format" ...>
    <option value="webp" ...>WebP</option>
    <option value="avif" ...>AVIF</option>
</select>
```

WebP Express has no AVIF support at all — it is a WebP-only converter, which is reflected in its name.

Converter for Media's free version is WebP-only as well. AVIF is gated behind a paid upgrade, stated directly in its own readme: "Now in the PRO version you can use AVIF as the output format for your images." A plugin whose WordPress.org listing markets "Convert WebP & AVIF" does not generate AVIF at all until you pay.

## Converting the existing media library

WebberZone Image Optimizer ships a database-backed, resumable bulk-conversion queue (**Media → Bulk Optimize**), described in [How the Queue Works](https://webberzone.com/support/knowledgebase/how-the-queue-works-in-webberzone-image-optimizer/), plus a **Queue images on first view** option that converts an image the moment it's seen on the front end without ever encoding during the page render itself.

Modern Image Formats converts **new uploads only**. Its own readme is explicit about this:

> "Modern images will be generated only for new uploads, pre-existing images will only converted to a modern format if images are regenerated. Images can be regenerated with a plugin like Regenerate Thumbnails or via WP-CLI with the `wp media regenerate` command."

There is no bulk-conversion screen, queue, or plugin-native CLI command in its source — it relies on WordPress core's generic `wp media regenerate`, a command that re-runs the entire thumbnail pipeline rather than being purpose-built for format conversion.

WebP Express and Converter for Media both ship their own bulk-conversion screens and their own WP-CLI commands, so on this specific point all three non-Performance-Team plugins are comparable to WebberZone Image Optimizer.

## Command-line tooling

WebberZone Image Optimizer registers five commands under `wp wzio`: `status`, `convert`, `queue`, `run`, and `clean` — documented in [WebberZone Image Optimizer WP-CLI](https://webberzone.com/support/knowledgebase/webberzone-image-optimizer-wp-cli/).

WebP Express registers `wp webp-express convert`, with flags for `--reconvert`, `--only-png`, `--only-jpeg`, `--quality`, `--near-lossless`, `--alpha-quality`, `--encoding`, and `--converter` — comparable in depth to WZIO's CLI, if organized as a single command with many flags rather than several purpose-built subcommands.

Converter for Media includes a `WpCliManager` service class, confirming CLI support exists, though its command surface is smaller than WebP Express's or WZIO's.

Modern Image Formats has no CLI commands of its own.

## Delivery mechanism

WebberZone Image Optimizer wraps images in a `<picture>` element by default, so the browser — not the server — chooses the format. This avoids the correctness problem of `Accept`-header-based negotiation, where a single URL returns different bytes depending on the visitor's browser and a CDN or page cache that ignores `Vary: Accept` can serve the wrong format to the wrong visitor. WZIO's own `Accept`-based rewriting is limited to generated, hand-installed server rules for CSS background images, where a `<picture>` element isn't an option.

Modern Image Formats also supports a `<picture>`-based delivery mode, but it's marked **experimental** in its own settings screen and depends on the JPEG-fallback and "use picture element" options both being enabled; its default delivery still swaps `src`/`srcset` URLs directly.

WebP Express and Converter for Media both default to server-level rewriting: `.htaccess`/nginx rules that check the `Accept` header and return different bytes from the same URL (WebP Express calls this its "varied-image-responses" mode; Converter for Media's default "Image loading mode" is `via .htaccess / Nginx`). WebP Express also ships an opt-in `<picture>`-rewriting mode, disabled by default, that only activates if you switch on HTML alteration in its settings. Converter for Media has no `<picture>` mode at all — its own source contains no reference to the element.

## Free-tier summary

<figure class="wp-block-table"><table class="has-fixed-layout">
<thead>
<tr><th>Capability</th><th>WebberZone Image Optimizer</th><th>Modern Image Formats</th><th>WebP Express</th><th>Converter for Media</th></tr>
</thead>
<tbody>
<tr><td>WebP + AVIF together, free</td><td>Yes</td><td>No (one format at a time)</td><td>No AVIF</td><td>No (AVIF is paid)</td></tr>
<tr><td>Granular quality (1–100)</td><td>Yes, per format</td><td>No</td><td>Yes, per source type</td><td>No (5 presets)</td></tr>
<tr><td>Encoder effort control</td><td>Yes, both formats</td><td>No</td><td>Partial (cwebp only)</td><td>No</td></tr>
<tr><td>Lossless PNG</td><td>Yes</td><td>No</td><td>Near-lossless only</td><td>No</td></tr>
<tr><td>Bulk-converts existing library</td><td>Yes, resumable queue</td><td>No (core regenerate only)</td><td>Yes</td><td>Yes</td></tr>
<tr><td>Native WP-CLI</td><td>Yes (5 commands)</td><td>No</td><td>Yes</td><td>Yes</td></tr>
<tr><td>&lt;picture&gt; delivery by default</td><td>Yes</td><td>No (experimental, opt-in)</td><td>No</td><td>No</td></tr>
</tbody>
</table></figure>

WebP Express is the closest competitor on quality-control depth, with genuinely sophisticated per-source-type logic, but it never generates AVIF. Converter for Media matches WZIO on bulk conversion and CLI support but locks AVIF behind a paywall and offers only coarse quality presets. Modern Image Formats, despite being the WordPress Performance Team's own plugin, has the shallowest feature set of the four on every axis in this comparison except being bundled with an authoritative source — it has no quality control, no bulk conversion, and no CLI.
