---
slug: media-library-integration-in-webberzone-image-optimizer
title: "Media Library Integration in WebberZone Image Optimizer"
products: [webberzone-image-optimizer]
sections: [01-wzio-getting-started]
tags: [webberzone-image-optimizer, media-library]
status: publish
order: 3
---

[kbtoc]

[WebberZone Image Optimizer](https://webberzone.com/plugins/webberzone-image-optimizer/) adds a status column and per-image actions to the Media Library list view (**Media → Library**, list mode) for every image it can optimize.

## The Optimized column

* **—** — the file is not an image type the plugin can optimize.
* **Not yet** — the image is convertible but has no optimized copy yet.
* **N files, X smaller (Y%)** — the number of generated files, the total bytes saved, and the percentage saved for this attachment.

## Row actions

Hover an image row to reveal:

**Optimize**
Converts whatever this attachment is still missing, without waiting for a bulk run — a size with no optimized copy yet, or one whose copy is older than its source. A copy that is already up to date is kept as it is, and a format recorded as skipped stays skipped. To re-encode an attachment that is already done, after changing the quality settings for example, use **Re-optimize images that are already done** on [the Bulk Optimize screen](https://webberzone.com/support/knowledgebase/bulk-optimize-in-webberzone-image-optimizer/) or run `wp wzio convert --force`.

**Delete optimized copies**
Deletes the generated WebP/AVIF files for this attachment and reverts it to serving the original. Only shown once an attachment has at least one generated file. The original image is never affected either way.

Both actions require the `edit_post` capability for that attachment and show a confirmation notice ("Optimized copies regenerated" / "Optimized copies deleted") after completing.
