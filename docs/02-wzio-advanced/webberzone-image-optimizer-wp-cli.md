---
slug: webberzone-image-optimizer-wp-cli
title: "WebberZone Image Optimizer WP-CLI"
products: [image-optimizer]
sections: ["02-wzio-advanced"]
tags: [developer, webberzone-image-optimizer, wp-cli]
status: publish
order: 3
---

[kbtoc]

[WebberZone Image Optimizer](https://webberzone.com/plugins/webberzone-image-optimizer/) registers WP-CLI commands under `wp wzio`, useful for converting a library from a script, cron job, or deployment step without opening the admin screens.

## `wp wzio status`

Shows which drivers and formats this server can encode, the currently configured formats, and how much of the library is converted. It also reports original encoder availability, original bytes saved, backup disk use and the number of resized main files, plus the disk space used by optimized copies with a per-format breakdown.

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
- `--outdated` — re-encode only attachments whose copies were made with different quality, effort or lossless settings. Copies written before settings were tracked are left alone.
- `--force` — re-encode even when an up-to-date optimized copy already exists. Without it, it keeps and records an existing copy that is newer than its source and meets the minimum saving, including one written by another plugin.
- `--formats=<formats>` — comma-separated list of formats to generate, overriding the settings.
- `--dry-run` — report what would be converted without writing anything.

If any copy needed a lower quality than configured to come out smaller than the original, the command says so at the end: "N optimized copies needed a lower quality than configured to come out smaller than the original." See **Minimum saving (%)** in [Image Optimizer Settings](https://webberzone.com/support/knowledgebase/image-optimizer-settings/) for how that retry works.

## `wp wzio compress`

Compress served JPEG/PNG originals with mandatory backups, then rebuild affected sidecars. PNGs follow the **Compress PNG originals** setting.

```bash
wp wzio compress --ids=123,456 --dry-run
wp wzio compress --ids=123,456
wp wzio compress --resize
```

- `--ids=<ids>` — comma-separated attachment IDs; omit to scan the whole library.
- `--dry-run` — list candidates without changing files, records or the queue. Savings and encoding eligibility are determined during the actual run.
- `--resize` — resize main files to the configured maximum dimension; requires a positive cap and scaling enabled. Without this flag, the command does not request resizing.

## `wp wzio restore-originals`

Restore backed-up files and dimensions, then queue sidecar-only regeneration.

```bash
wp wzio restore-originals --ids=123,456
wp wzio restore-originals --all --dry-run
wp wzio restore-originals --all
```

Specify `--ids` or `--all`. Failed restorations keep recovery data and produce a nonzero exit status. **Delete optimized copies** remains separate from original restoration.

## `wp wzio queue`

Adds every unconverted attachment to the background queue, the same queue the Bulk Optimize screen uses.

```bash
wp wzio queue
```

- `--outdated` — queue only attachments whose copies were made with older settings.
- `--force` — re-queue attachments that already have a conversion record, and re-encode their copies when the queue processes them, the same as **Re-optimize images that are already done** on the Bulk Optimize screen.

The scan walks the library in pages of 500 attachments with no time limit, unlike the Bulk Optimize screen, which has to build the queue across several time-bounded passes to stay inside the PHP request limit. Queuing also schedules the background worker, so the queue starts draining on its own if **Process the queue in the background** is enabled. See [How the Queue Works](https://webberzone.com/support/knowledgebase/how-the-queue-works-in-webberzone-image-optimizer/).

## `wp wzio run`

Works through the queue in the current process, batch after batch, until the queue is empty. Useful in a deployment step or a system cron job on a site where WP-Cron is disabled.

```bash
wp wzio run
wp wzio run --batch=25 --max-batches=10
```

- `--batch=<size>` — attachments per batch. Defaults to the **Images per batch** setting on the Advanced tab.
- `--max-batches=<count>` — stop after this many batches. Defaults to running until the queue is empty.

Each batch reports how many were converted, skipped and failed, and how many remain. Only one worker can hold the queue lock at a time. If a background batch is already running, the command exits with "Another worker holds the queue lock" rather than processing the same rows twice. Wait a moment and run it again.

## `wp wzio clean`

Deletes the generated WebP and AVIF files. Your original images and their backups are never touched; use `wp wzio restore-originals` to restore compressed originals.

```bash
wp wzio clean 7214
wp wzio clean --yes
```

- `<id>...` — attachment IDs to clean. Omit to clean every attachment that has a conversion record.
- `--yes` — skip the confirmation prompt.

With IDs, only those attachments' generated files are deleted and their queue rows removed. Without IDs, every generated file on the site is deleted and the entire queue table is emptied, including the completed rows — a full reset. The records of the deleted copies are removed with the files, so the **Bandwidth saved** and **Optimized copies occupy** totals on the Bulk Optimize screen no longer count them. Original-compression records and backups are kept.
