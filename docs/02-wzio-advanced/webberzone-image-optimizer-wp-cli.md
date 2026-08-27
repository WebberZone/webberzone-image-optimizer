---
slug: webberzone-image-optimizer-wp-cli
title: "WebberZone Image Optimizer WP-CLI"
products: [image-optimizer]
sections: ["02-wzio-advanced"]
tags: [developer, webberzone-image-optimizer, wp-cli]
status: publish
order: 3
toc: true
---

[toc]

[WebberZone Image Optimizer](https://webberzone.com/plugins/webberzone-image-optimizer/) registers WP-CLI commands under `wp wzio`, useful for converting a library from a script, cron job, or deployment step without opening the admin screens.

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

- `<id>...` — attachment IDs to convert. Omit to convert everything not yet handled.
- `--force` — re-encode even when an up-to-date optimized copy already exists. Without it, it keeps and records an existing copy that is newer than its source and meets the minimum saving, including one written by another plugin.
- `--formats=<formats>` — comma-separated list of formats to generate, overriding the settings.
- `--dry-run` — report what would be converted without writing anything.

## `wp wzio queue`

Adds every unconverted attachment to the background queue, the same queue the Bulk Optimize screen uses.

```bash
wp wzio queue
```

- `--force` — re-queue attachments that already have a conversion record.

The scan walks the library in pages of 500 attachments with no time limit, unlike the Bulk Optimize screen, which has to build the queue across several time-bounded passes to stay inside the PHP request limit. Queuing also schedules the background worker, so the queue starts draining on its own if **Process the queue in the background** is enabled. See [How the Queue Works](https://webberzone.com/support/knowledgebase/how-the-queue-works-in-webberzone-image-optimizer/).

## `wp wzio run`

Works through the queue in the current process, batch after batch, until the queue is empty. Useful in a deployment step or a system cron job on a site where WP-Cron is disabled.

```bash
wp wzio run
wp wzio run --batch=25 --max-batches=10
```

- `--batch=<size>` — attachments per batch. Defaults to the **Images per batch** setting on the Advanced tab.
- `--max-batches=<count>` — stop after this many batches. Defaults to running until the queue is empty.

Each batch reports how many were converted, skipped and failed, and how many remain. Only one worker may hold the queue lock at a time, so if a background batch is already running, the command exits with "Another worker holds the queue lock" rather than processing the same rows twice — wait a moment and run it again.

## `wp wzio clean`

Deletes the generated WebP and AVIF files. Your original images are never touched.

```bash
wp wzio clean 7214
wp wzio clean --yes
```

- `<id>...` — attachment IDs to clean. Omit to clean every attachment that has a conversion record.
- `--yes` — skip the confirmation prompt.

With IDs, only those attachments' generated files are deleted and their queue rows removed. Without IDs, every generated file on the site is deleted and the entire queue table is emptied, including the completed rows — a full reset, which also discards the **Bandwidth saved** totals on the Bulk Optimize screen.
