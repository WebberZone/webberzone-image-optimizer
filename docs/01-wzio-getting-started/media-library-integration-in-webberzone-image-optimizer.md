---
slug: media-library-integration-in-webberzone-image-optimizer
title: "Media Library Integration in WebberZone Image Optimizer"
products: [image-optimizer]
sections: ["01-wzio-getting-started"]
tags: [media-library, webberzone-image-optimizer]
status: publish
order: 3
---

[kbtoc]

[WebberZone Image Optimizer](https://webberzone.com/plugins/webberzone-image-optimizer/) adds a status column and per-image actions to the Media Library list view (**Media → Library**, list mode) for every image it can optimize.

## The Optimized column

- **—** — the file is not an image type the plugin can optimize.
- **Not yet** — the image is convertible but has no optimized copy yet.
- **N files, X smaller (Y%)** — the number of generated files, the total bytes saved, and the percentage saved for this attachment.

When any optimized copy had to be encoded below the configured quality to come out smaller than the original, the column adds a note — "N copies needed a lower quality to come out smaller than the original" — so a lower-quality result is never silent. See **Minimum saving (%)** in [Image Optimizer Settings](https://webberzone.com/support/knowledgebase/image-optimizer-settings/) for how that retry works.

## Filtering by status

A status dropdown at the top of the list view filters the library by optimization status:

- **All optimization statuses** — the default, no filtering.
- **Optimized** — has at least one generated WebP or AVIF file.
- **Not yet optimized** — a convertible image with no finished conversion record.
- **Skipped** — nothing to convert, either because the image is already covered or excluded.
- **Failed** — conversion failed after the retries were exhausted.

Only image attachments ever appear under these options — video, audio and document files are left out of every filter. The dropdown is shown only while the **Optimized** column itself is visible: hiding the column in Screen Options hides the filter as well.

## Row actions

Hover an image row to reveal:

**Optimize**
Converts whatever this attachment is still missing, without waiting for a bulk run — a size with no optimized copy yet, or one whose copy is older than its source. A copy that is already up to date is kept as it is, and a format recorded as skipped stays skipped. To re-encode an attachment that is already done, after changing the quality settings for example, use **Re-optimize images that are already done** on [the Bulk Optimize screen](https://webberzone.com/support/knowledgebase/bulk-optimize-in-webberzone-image-optimizer/) or run `wp wzio convert --force`.

**Delete optimized copies**
Deletes the generated WebP/AVIF files for this attachment and reverts it to serving the original. Only shown once an attachment has at least one generated file. The original image is never affected either way.

Both actions require the `edit_post` capability for that attachment and show a confirmation notice ("Optimized copies regenerated" / "Optimized copies deleted") after completing.

## The attachment edit screen

Opening a single attachment (**Media → Library** → click an image) shows the same totals in the Save box: the number of generated files and bytes saved, a per-format breakdown, and the same lower-quality note when any copy needed it. The **Optimize** and **Delete optimized copies** links are there too, so you can convert or restore one image without going back to the list view.
