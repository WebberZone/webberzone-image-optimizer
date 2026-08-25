---
slug: getting-started-with-webberzone-image-optimizer
title: "Getting Started with WebberZone Image Optimizer"
products: [webberzone-image-optimizer]
sections: [01-wzio-getting-started]
tags: [webberzone-image-optimizer, getting-started, webp, avif]
status: publish
order: 0
---

[kbtoc]

[WebberZone Image Optimizer](https://webberzone.com/plugins/webberzone-image-optimizer/) converts the images already in your media library to WebP and AVIF, and serves each visitor the smallest file their browser can read. Images are typically 40–60% smaller with no visible difference, and everything happens on your own server — there is no external service, no API key, no account and no upload limit.

## What your server needs

WordPress cannot crop or resize an uploaded image without either the **Imagick** or the **GD** extension, so every working WordPress install already has one of them, and both have been able to write WebP for years.

* **WebP** works on virtually any current host.
* **AVIF** is newer. It needs either PHP 8.1+ with GD built against libavif, or Imagick with an AVIF delegate. Not every host has it yet.

The plugin does not trust what the extension claims to support — it tests your server by actually encoding a small image with each backend at activation, and the settings screen shows exactly which formats came back working. If AVIF is unavailable, the option is marked as such and WebP carries on normally.

## Two things worth knowing before you start

**Your originals are never modified.** By default, each optimized copy is written alongside the original with the new extension appended, so `photo.jpg` gains `photo.jpg.webp`. The **Optimized file naming** setting can switch this to replacing the extension instead (`photo.webp`) — see the settings screen for the trade-off before turning it on. Which of the two you want depends on what is already on disk if another optimizer has been running on this site, so start with [Migrating from Another Image Optimizer](https://webberzone.com/support/knowledgebase/migrating-from-another-image-optimizer/) in that case. Either way, nothing overwrites, replaces or re-saves your original file. Deactivating the plugin returns your site to serving the originals immediately, and no URL ever breaks.

**Delivery survives caching.** Images are wrapped in a `<picture>` element, so the *browser* chooses the format — not the server. The common alternative, varying the response on the `Accept` header, returns different bytes for a single URL; any cache in front of that which ignores `Vary` will happily hand a WebP file to a browser that cannot display it. A `<picture>` element has no such failure mode.

## Installation

1. Download the latest release from [GitHub](https://github.com/WebberZone/webberzone-image-optimizer/releases/latest) and upload the `webberzone-image-optimizer` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins menu.
3. Visit **Media → Image Optimizer** to choose your formats — see [Image Optimizer Settings](https://webberzone.com/support/knowledgebase/image-optimizer-settings/).
4. Visit **Media → Bulk Optimize** and press **Start optimizing** — see [Bulk Optimize in WebberZone Image Optimizer](https://webberzone.com/support/knowledgebase/bulk-optimize-in-webberzone-image-optimizer/).

New uploads are converted automatically from then on, and existing images are optimized the first time they're viewed on the front end even if you never run the bulk screen, as long as **Queue images on first view** is enabled.
