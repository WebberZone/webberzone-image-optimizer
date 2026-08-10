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

[WebberZone Image Optimizer](https://webberzone.github.io/webberzone-image-optimizer/) adds a status column and per-image actions to the Media Library list view (**Media → Library**, list mode) for every image it can optimize.

## The Optimized column

* **—** — the file is not an image type the plugin can optimize.
* **Not yet** — the image is convertible but has no optimized copy yet.
* **N files, X smaller (Y%)** — the number of generated files, the total bytes saved, and the percentage saved for this attachment.

## Row actions

Hover an image row to reveal:

**Optimize**
Converts this attachment immediately, even if it was skipped or already has an up-to-date copy. Useful after changing quality settings, when you want a single image re-encoded without running a full bulk pass.

**Delete optimized copies**
Deletes the generated WebP/AVIF files for this attachment and reverts it to serving the original. Only shown once an attachment has at least one generated file. The original image is never affected either way.

Both actions require the `edit_post` capability for that attachment and show a confirmation notice ("Optimized copies regenerated" / "Optimized copies deleted") after completing.

## See also

* [Bulk Optimize in WebberZone Image Optimizer](https://webberzone.github.io/webberzone-image-optimizer/docs/01-wzio-getting-started/bulk-optimize-in-webberzone-image-optimizer/)
* [Image Optimizer Settings](https://webberzone.github.io/webberzone-image-optimizer/docs/01-wzio-getting-started/image-optimizer-settings/)
