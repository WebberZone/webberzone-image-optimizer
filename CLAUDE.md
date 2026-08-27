# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Response Rules

- Return only the changed function or section, not the full file
- No explanation unless asked
- No suggestions outside the scope of what was asked
- Skip preamble and trailing summaries

## Attribution

No AI attribution anywhere in this repository or on its GitHub.

- Commit messages: never include a `Co-Authored-By:` line. A commit message ends with its body.
- Pull request bodies, issue comments and review comments: no "Generated with Claude Code" footer or any equivalent.

## Links

- GitHub: <https://github.com/WebberZone/webberzone-image-optimizer>
- Plugin homepage: <https://webberzone.com/plugins/webberzone-image-optimizer/>
- Knowledge Base: <https://webberzone.com/support/product/image-optimizer/>

## Plugin Overview

**WebberZone Image Optimizer** (v1.0.2) converts media library images to WebP and AVIF and serves them via `<picture>`. Namespace: `WebberZone\Image_Optimizer`. Prefix: `wzio`. Constants: `WZIO_VERSION`, `WZIO_PLUGIN_FILE`, `WZIO_PLUGIN_DIR`, `WZIO_PLUGIN_URL`, `WZIO_PLUGIN_BASENAME`. Requires WordPress 6.6+, PHP 7.4+. GPL-2.0-or-later, fully free, no Freemius.

Settings key: `wzio_settings`. Access via `wzio_get_option($key)` / `wzio_get_settings()`.

## Commands

```bash
composer phpcs       # Lint
composer phpcbf      # Auto-fix code style
composer phpstan     # Static analysis (level 5, clean)
composer test        # phpcs + phpcompat + phpstan
composer zip         # Build the distribution zip via build-zip.sh
pnpm run build:assets   # Minify CSS/JS and generate RTL variants
```

After adding or editing any `.css` or `.js` under `includes/admin/`, run `pnpm run build:assets` to regenerate the `.min` and RTL files.

### Unit tests

```bash
bash phpunit/install.sh <db_name> <db_user> <db_pass> 127.0.0.1 latest
vendor/bin/phpunit                 # single site
WP_MULTISITE=1 vendor/bin/phpunit  # multisite
```

`install.sh` uses GNU `sed`, so on macOS its final rewrite of `wp-tests-config.php` fails and the ABSPATH and DB lines have to be filled in by hand. It works unmodified in CI. Because of this, don't run PHPUnit locally — `.github/workflows/unit-tests.yml` runs it on every push, and that's sufficient.

The WordPress test suite does not support PHPUnit 10+ (`PHPUnit\Util\Test::parseTestMethodAnnotations` was removed). Run against PHPUnit 9 locally — this is what `.github/workflows/unit-tests.yml` pins too. Test files are named for their class (`ConverterTest.php` → `ConverterTest`) because PHPUnit 10+ requires the match.

## Two decisions the whole design rests on

1. **Sidecars named via `Helpers::apply_sidecar_naming()`, never by string concatenation.** Default is append (`photo.jpg` → `photo.jpg.webp`); the `sidecar_naming` setting can switch a site to `replace` (`photo.webp`, collision risk explained on the settings screen). Both the filesystem path and the delivered URL must resolve through this one function or they can disagree and a visitor gets a 404. Originals are never modified — that part is a hard invariant.
2. **`<picture>` rewriting, not `Accept`-header rewriting.** An `Accept` rewrite returns different bytes per URL; a cache that ignores `Vary` then serves one visitor's format to everyone. `<picture>` puts the choice in the browser, so the HTML is identical for every visitor. The `Accept` rewrite exists only as *generated, hand-installed* server rules for CSS background images.

Do not revisit either without a concrete reason.

## Non-obvious implementation notes

- **`Capabilities` probes, it does not trust.** `Imagick::queryFormats()` reporting AVIF means a delegate is registered, not that encoding works. Each driver/format pair is verified by actually encoding a bundled 64×64 PNG, and the result is cached in the `wzio_capabilities` option keyed on plugin version.
- **`wp_content_img_tag` often passes `$attachment_id === 0`** — the ID comes from the `wp-image-{ID}` class, which content from other editors and older imports lacks. `Frontend\Resolver` is therefore keyed on URL, not attachment ID, with a request memo plus object cache (24h for hits, 5min for misses).
- **`srcset` descriptors are copied verbatim**, never re-derived. A `<source>` is only emitted when *every* candidate resolved; one missing candidate would let the browser request a file that does not exist.
- **The `-scaled` file is what gets served**, so it is converted; the true original kept aside by the big-image threshold is deliberately skipped.
- **`wp_update_attachment_metadata` is the single conversion trigger.** It fires after upload sub-size generation *and* after edits, restores and regeneration, which is what makes stale `-e{timestamp}` sidecar cleanup work in one place.
- **A sidecar that is not smaller than its source is deleted**, and the skip recorded per file. That is what makes `file_exists()` a self-consistent serving decision.
- **Skip decisions are per file, not per attachment.** Mixed results inside one attachment are normal, especially with transparent PNGs.
- **The queue table is per site** (`{$wpdb->prefix}wzio_queue`). `register_activation_hook` fires once for a network activation, so `Database::install_all()` loops sites and `wp_initialize_site` covers new ones.
- **Nothing is ever encoded during a page render.** A front-end miss queues the attachment on `shutdown` and serves the original.
