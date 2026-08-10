---
slug: webberzone-image-optimizer-wp-cli
title: "WebberZone Image Optimizer WP-CLI"
products: [webberzone-image-optimizer]
sections: [02-wzio-advanced]
tags: [webberzone-image-optimizer, wp-cli, developer]
status: publish
order: 0
---

[kbtoc]

[WebberZone Image Optimizer](https://webberzone.github.io/webberzone-image-optimizer/) registers WP-CLI commands under `wp wzio`, useful for converting a library from a script, cron job, or deployment step without opening the admin screens.

## `wp wzio status`

Shows which drivers and formats this server can encode, the currently configured formats, and how much of the library is converted.

```bash
wp wzio status
```

## `wp wzio convert`

Converts one or more attachments immediately.

```bash
wp wzio convert 7214
wp wzio convert --formats=webp,avif --force
```

* `[<id>...]` — attachment IDs to convert. Omit to convert everything not yet handled.
* `[--force]` — re-encode even when an up-to-date optimized copy already exists.
* `[--formats=<formats>]` — comma-separated list of formats to generate, overriding the settings.
* `[--dry-run]` — report what would be converted without writing anything.

## `wp wzio queue`

Adds every unconverted attachment to the background queue, the same queue the Bulk Optimize screen uses.

```bash
wp wzio queue
```

* `[--force]` — requeue attachments that already have a conversion record.

## `wp wzio run`

Works through the queue in batches until it is empty (or a batch limit is reached).

```bash
wp wzio run
```

* `[--batch=<size>]` — attachments per batch. Defaults to the configured **Images per batch** setting.
* `[--max-batches=<count>]` — stop after this many batches. Defaults to running until the queue is empty.

If another worker holds the queue lock, the command reports an error and exits rather than processing concurrently.

## `wp wzio clean`

Deletes the generated WebP/AVIF files for one or more attachments. Original images are never touched.

```bash
wp wzio clean 7214
```

* `[<id>...]` — attachment IDs. Omit to clean every attachment that has a conversion record (prompts for confirmation unless `--yes` is passed).
* `[--yes]` — skip the confirmation prompt.

## See also

* [Bulk Optimize in WebberZone Image Optimizer](https://webberzone.github.io/webberzone-image-optimizer/docs/01-wzio-getting-started/bulk-optimize-in-webberzone-image-optimizer/)
* [How the Queue Works](https://webberzone.github.io/webberzone-image-optimizer/docs/02-wzio-advanced/how-the-queue-works-in-webberzone-image-optimizer/)
* [WebberZone Image Optimizer Developer Reference](https://webberzone.github.io/webberzone-image-optimizer/docs/03-wzio-developer-docs/webberzone-image-optimizer-developer-reference/)
