---
slug: image-optimizer-settings
title: "Image Optimizer Settings"
products: [webberzone-image-optimizer]
sections: [01-wzio-getting-started]
tags: [webberzone-image-optimizer, settings]
status: publish
order: 1
---

[kbtoc]

[WebberZone Image Optimizer](https://webberzone.github.io/webberzone-image-optimizer/) settings live at **Media → Image Optimizer**, across four tabs: General, Quality, Delivery and Advanced.

## General

**Formats to generate**
Choose WebP, AVIF or both. AVIF files are smaller than WebP but take longer to encode and are understood by slightly fewer browsers. Generating both lets each visitor receive the smallest file their browser can read. A format your server cannot encode is listed as unavailable. Default: WebP only.

**Convert new uploads**
Generate the optimized copies as soon as an image is uploaded or its thumbnails are regenerated. Default: on.

**Image sizes to convert**
Leave every box unchecked to convert all sizes, which is what you want unless disk space is tight. A responsive image only switches format when every size in its `srcset` has been converted, so excluding a size that appears in your theme's markup disables the optimization for those images. Default: all sizes (nothing excluded).

**Minimum saving (%)**
Discard an optimized copy unless it is at least this much smaller than the original. Small or already-compressed images frequently grow when re-encoded, and keeping those wastes disk space for no benefit. Default: `5`. Range: `0`–`90`.

**Optimized file naming**
Controls how the generated WebP/AVIF file is named. **Append the new extension** (`photo.jpg.webp`) is the safe default — every file has a unique name and nothing can collide. **Replace the extension** (`photo.webp`) produces shorter filenames but can collide if the same folder contains both `photo.jpg` and `photo.png`, silently overwriting one optimized copy with the other. Only choose Replace if you are sure your uploads never share a filename across extensions. Default: `append`.

## Quality

**WebP quality**
Between 1 and 100. Default: `82`, which is visually indistinguishable from the original for most photographs. Values above 90 grow the file quickly for very little visible gain.

**AVIF quality**
Between 1 and 100. Default: `50`. AVIF and WebP quality numbers are not comparable: AVIF at 50 looks about the same as WebP at 82 while producing a noticeably smaller file.

**Encoder effort**
Between 0 and 6. Default: `6`. Higher values spend more CPU time searching for a smaller file at identical visual quality. Because conversion happens once and the result is served many times, the highest setting is usually the right trade — lower it if bulk runs are timing out.

**Strip metadata**
Remove EXIF, GPS and embedded thumbnails from the optimized copies. The color profile is always kept, so colors will not shift. Your original files are never modified either way. Default: on.

**Lossless for PNG sources**
Encode PNG sources without any quality loss. Right for logos, screenshots and line art; produces much larger files for photographs saved as PNG. Default: on.

## Delivery

**Serve optimized images**
Wrap images in a `<picture>` element so the browser picks the best format it supports. Because the choice is made by the browser rather than the server, this works correctly behind page caches and CDNs. Default: on.

**Post content images**
Rewrite images embedded in post and page content. Default: on.

**Theme and block images**
Rewrite featured images, gallery images and any image rendered by a theme or block through the WordPress image functions. Default: on.

**Whole page (buffered)**
Catch images printed directly by a page builder or a hard-coded template by buffering the entire page and rewriting it before it is sent. This catches the most images but costs a little memory on every request — leave it off unless you can see images the two options above are missing. Default: off.

**CSS background images**
The Delivery tab generates ready-to-paste Apache and nginx rules for images referenced from a stylesheet, where the browser is never offered a choice by the plugin itself. Both blocks send a `Vary: Accept` header — do not remove it, or a CDN or page cache can hand a WebP file to a browser that cannot display it.

* **Apache or LiteSpeed** — if the `.htaccess` file is writable by PHP, use the **Add to .htaccess** / **Remove from .htaccess** buttons to install the rules with one click. If the file is not writable, copy the generated block above the WordPress rules in `.htaccess` by hand.
* **nginx** — add the generated block to your server configuration and reload.

## Advanced

**Images per batch**
How many attachments to process in a single bulk step. Default: `10`. Range: `1`–`200`. Lower this if your server times out during a bulk run; raise it to finish faster on a fast server.

**Process the queue in the background**
Keep working through the queue on a schedule even when the Bulk Optimize screen is closed. Turn this off if you would rather the queue only advance while you watch it. Default: on. See [How the Queue Works](../02-wzio-advanced/how-the-queue-works-in-webberzone-image-optimizer.md) for what runs a batch and how retries are handled.

**Queue images on first view**
When a page references an image that has not been converted yet, serve the original immediately and add the image to the queue. Nothing is ever converted during a page render, so visitors never wait for an encode. Default: on.

**Exclude paths**
One fragment per line. Any image whose path inside the uploads folder contains one of these fragments is left alone — for example `2019/07` or `/logos/`. Default: empty.

**Delete optimized files on uninstall**
Remove every generated WebP and AVIF file when the plugin is deleted. Original images are never touched either way. Leave this off if you may reinstall later and would rather not convert everything again. Default: off.

**Delete settings and records on uninstall**
Remove the settings, the queue table and the per-image conversion records when the plugin is deleted. Default: off.

## See also

* [Getting Started with WebberZone Image Optimizer](getting-started-with-webberzone-image-optimizer.md)
* [Bulk Optimize in WebberZone Image Optimizer](bulk-optimize-in-webberzone-image-optimizer.md)
* [How the Queue Works](../02-wzio-advanced/how-the-queue-works-in-webberzone-image-optimizer.md)
