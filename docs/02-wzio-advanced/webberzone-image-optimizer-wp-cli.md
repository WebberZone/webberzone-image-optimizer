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

- `[[<id>...]]` — attachment IDs to convert. Omit to convert everything not yet handled.
- `[[--force]]` — re-encode even when an up-to-date optimized copy already exists. Without it, an existing copy that is newer than its source and meets the minimum saving is kept and recorded, including one written by another plugin.
- `[[--formats=<formats>]]` — comma-separated list of formats to generate, overriding the settings.
- `[[--dry-run]]` — report what would be converted without writing anything.

## `wp wzio queue`

Adds every unconverted attachment to the background queue, the same queue the Bulk Optimize screen uses.

```bash
wp wzio queue
```

- `[[--force]]` — requeue attachments that already have a conversion record.
