---
slug: webberzone-image-optimizer-abilities
title: "Using WebberZone Image Optimizer with AI Assistants (Abilities API)"
products: [image-optimizer]
sections: ["02-wzio-advanced"]
tags: [developer, webberzone-image-optimizer, abilities-api, ai]
status: publish
order: 4
---

[kbtoc]

From version 1.3.0, [WebberZone Image Optimizer](https://webberzone.com/plugins/webberzone-image-optimizer/) registers six abilities with the WordPress [Abilities API](https://developer.wordpress.org/apis/abilities-api/). An AI assistant or any other client that can call abilities can use them to check progress, run Bulk Optimize, optimize a single image and change settings, without anyone opening the admin screens.

The Bulk Optimize screen doesn't need to stay open. When the background queue is on, queued images are converted by WP-Cron, the same as when you start a run from **Media → Bulk Optimize** and close the tab.

## Requirements

- WordPress 6.9 or later. The Abilities API ships with 6.9; on earlier versions the plugin registers nothing and nothing changes.
- A client that can discover and call abilities. MCP clients, such as Claude Desktop or Claude Code, connect through the [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter). Every Image Optimizer ability is marked public for MCP, so the adapter can expose it to your client.
- A user account with the right capability. Every ability except `wzio/optimize-attachment` requires `manage_options`, which administrators have. Optimizing one image requires `upload_files` and permission to edit that attachment.

The abilities are also available over REST at `/wp-json/wp-abilities/v1/abilities`, in the **Image Optimizer** (`wzio`) category.

## `wzio/get-status`

Reports how much of the media library has WebP or AVIF copies. Read-only.

It takes no input. The result includes:

- `candidates`: attachments the plugin can convert, and `optimized`: those with a conversion record.
- `remaining` and `queue`: images waiting in the queue, with `pending`, `processing`, `done`, `failed` and `skipped` counts.
- `bytes`: source bytes, bytes saved and the percentage, with a per-format breakdown of the space optimized copies use.
- `formats`: the formats you configured and the formats this server can encode.
- `background`: whether the background queue is on, and its status: `healthy`, `disabled` (WP-Cron is turned off) or `stalled` (queued work hasn't been picked up for a while).
- `failures`: the five most recent failures, each with the attachment ID, the error, the number of attempts and when it last ran.

Example prompt:

```text
How much of my media library is optimized, and are any images failing?
```

## `wzio/queue-bulk`

Adds images that need WebP or AVIF copies to the queue, the same as starting Bulk Optimize. Queued images are converted in the background.

| Input | Type | Default | What it does |
|---|---|---|---|
| `force` | boolean | `false` | Re-encode every image, including ones already optimized. |
| `outdated` | boolean | `false` | Queue only images whose copies were made with older settings, for example after changing quality. |
| `retry` | boolean | `false` | Give failed and retryable skipped images another try. Applies to the first pass only. |
| `after_id` | integer | `0` | Cursor returned by the previous pass. Use `0` to start a new scan. |

Large libraries are scanned in time-limited passes. The result returns `queued` (images added in this pass), `after_id`, `done` and `remaining`. While `done` is `false`, the client calls again with the returned `after_id`. Assistants handle this loop themselves.

If the background queue is off, or WP-Cron isn't running, the images stay queued until `wzio/run-batch` works through them.

Example prompts:

```text
Start Bulk Optimize for the whole media library.
```

```text
I changed the WebP quality. Re-optimize only the images made with the old settings.
```

```text
Retry the images that failed last time.
```

## `wzio/run-batch`

Converts the next batch of queued images now, instead of waiting for the background queue. Useful when WP-Cron is disabled or stalled.

| Input | Type | Default | What it does |
|---|---|---|---|
| `batch_size` | integer, 1 to 200 | **Images per batch** setting | Images to process in this call. |

Each call is limited to about 20 seconds. The result reports `processed`, `converted`, `failed`, `skipped`, `deferred`, `saved` (bytes saved by this batch) and `remaining`. The client calls it repeatedly until `remaining` is `0`.

If `locked` is `true`, another worker, such as the background queue, is already processing. The client waits a few seconds and tries again.

Example prompt:

```text
WP-Cron is off on this site. Work through the image queue until it's empty.
```

## `wzio/optimize-attachment`

Creates WebP or AVIF copies of one media library image now.

| Input | Type | Default | What it does |
|---|---|---|---|
| `attachment_id` | integer | Required | The attachment ID. |
| `force` | boolean | `false` | Re-encode files that already have an up-to-date copy. |

The result has a `status` of `done`, `skipped`, `failed`, or `pending` when the work was deferred, plus `saved` (bytes) and `error`. If `locked` is `true`, another worker is busy; try again shortly. An attachment that isn't a JPEG, PNG, GIF or WebP image returns an error.

Example prompt:

```text
Optimize the image with attachment ID 7214 again, even if it already has copies.
```

## `wzio/get-settings`

Returns the settings that `wzio/update-settings` can change, with their current values. Read-only, no input.

Example prompt:

```text
What quality and formats is Image Optimizer using right now?
```

## `wzio/update-settings`

Changes one or more settings. Settings you leave out keep their values. At least one setting is required.

The input accepts these settings, named as on the settings screen at **Media → Image Optimizer**:

| Setting | Label | Type | Default |
|---|---|---|---|
| `formats` | Formats to generate | List of `webp`, `avif`; at least one | `webp` |
| `convert_on_upload` | Convert new uploads | Boolean | On |
| `convert_sizes` | Image sizes to convert | List of the image sizes registered on your site | Empty (all sizes) |
| `maximum_image_dimension` | Maximum image dimension | Integer, 0 or more | `0` (WordPress default) |
| `disable_image_scaling` | Disable upload scaling | Boolean | Off |
| `min_saving` | Minimum saving (%) | Integer, 0 to 90 | `5` |
| `quality_webp` | WebP quality | Integer, 1 to 100 | `82` |
| `quality_avif` | AVIF quality | Integer, 1 to 100 | `50` |
| `effort_webp` | WebP encoder effort | Integer, 0 to 6 | `6` |
| `effort_avif` | AVIF encoder effort | Integer, 0 to 6 | `4` |
| `strip_metadata` | Strip metadata | Boolean | On |
| `lossless_png` | Lossless for PNG sources | Boolean | On |
| `png_lossy_quality` | PNG lossy fallback quality | Integer, 0 to 100 | `95` |
| `enable_delivery` | Serve optimized images | Boolean | On |
| `rewrite_content` | Post content images | Boolean | On |
| `rewrite_template` | Theme and block images | Boolean | On |
| `rewrite_buffer` | Whole page (buffered) | Boolean | Off |
| `rewrite_backgrounds` | Block background images | Boolean | On |
| `batch_size` | Images per batch | Integer, 1 to 200 | `10` |
| `background_queue` | Process the queue in the background | Boolean | On |
| `lazy_convert` | Queue images on first view | Boolean | On |
| `exclude_paths` | Exclude paths | Text, one path fragment per line, such as `2019/07` or `/logos/` | Empty |

Values are checked against these types and ranges before anything is saved. An out-of-range value, such as a quality of 150, an unknown setting name or an empty request is rejected with an error, and nothing changes. A setting that is disabled on the settings screen is left out of the input.

A list or text setting is replaced as a whole, so to add an excluded path or an image size, the client reads the current value with `wzio/get-settings` and sends the full new list.

Requesting a format the server can't encode drops it. If none of the requested formats can be encoded, the ability returns an error and leaves the setting alone. Check `formats.supported` in `wzio/get-status` first.

New quality and format settings apply to images converted from then on. To redo existing images, follow up with `wzio/queue-bulk` and `outdated` set to `true`.

Changing **Lossless for PNG sources** or **PNG lossy fallback quality** also queues the PNG images skipped under the old settings, so they get another try.

The result lists the settings you sent in `updated`, and returns all current values in `settings`.

Some settings are deliberately not available here. Compressing originals, the optimized file naming setting and the uninstall clean-up options change or delete files, so they stay on the settings screen.

Example prompts:

```text
Turn on AVIF as well as WebP.
```

```text
Set WebP quality to 78 and AVIF quality to 45, then re-optimize the images made with the old settings.
```

```text
Add /private/ to the excluded paths, keeping the ones already there.
```
