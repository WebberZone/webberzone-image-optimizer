---
slug: compressing-and-restoring-original-images
title: "Compressing and Restoring Original Images"
products: [image-optimizer]
sections: ["01-wzio-getting-started"]
tags: [compression, backups, resizing, webberzone-image-optimizer]
status: publish
order: 7
---

[kbtoc]

WebberZone Image Optimizer can optionally compress the served JPEG and PNG files as well as generate modern-format copies. This reduces the files used by social previews, direct links and other requests that do not use the plugin's `<picture>` delivery.

## Enable original compression

In **Media → Image Optimizer → General**, enable **Compress original images** and choose **JPEG quality for originals**. JPEG encoding uses Imagick when it works, with GD as a fallback. PNG compression requires a verified local `oxipng` or `pngquant` executable and PHP process execution. The plugin checks `/usr/bin`, `/usr/local/bin` and `/opt/homebrew/bin`; it does not download tools. Unsupported originals stay unchanged. PNGs with an embedded ICC profile require oxipng; they are kept unchanged when only pngquant is available, to avoid applying the wrong profile after palette conversion.

Original compression uses the same selected image sizes and exclusions as sidecar conversion. WordPress's unserved `original_image` file is never compressed. The served `-scaled` file is eligible.

Each file is backed up before its first change. The pipeline then resizes the main file if requested, compresses it and generates sidecars from the resulting source. A replacement must meet **Minimum saving (%)** before it can replace the served file. Repeated runs with the same settings keep the result; changed settings encode from the first backup.

Changing settings does not start a library-wide job. Use **Bulk Optimize → Re-optimize images that are already done** or `wp wzio compress` to process existing attachments.

## Resize existing main files

Set a positive **Maximum image dimension** and enable **Resize existing originals**. Both dimensions fit within that cap, with the aspect ratio preserved. This step only affects the main attached file. Smaller files are not enlarged. PNGs require **Compress PNG originals** and a working PNG tool.

Existing thumbnail files are retained so embedded URLs continue to work. Oversized thumbnails are omitted from responsive `srcset` candidates generated through WordPress while the main file is resized. A directly embedded thumbnail URL still serves that thumbnail, so this setting is not a cap on every previously published image URL.

Stored post content is not rewritten. Existing width/height attributes retain their old values. Since the aspect ratio is preserved, responsive layouts generally keep the same proportions, but fixed-size markup can display the resized file larger than its new intrinsic resolution. Review layouts that use fixed full-size dimensions.

## Backups and protection

Backups live in `uploads/wzio-originals/` using the source's relative path. They are mandatory and never replaced by a compressed version. Allow enough disk space for a full copy of every selected source. Bulk Optimize and `wp wzio status` show backup storage separately from original and sidecar savings.

The plugin writes an index file plus Apache/LiteSpeed and IIS deny rules. nginx does not read `.htaccess`; add an equivalent rule to the server configuration and reload nginx:

```nginx
location ~ /wzio-originals/ {
    deny all;
}
```

Custom web-server configurations must also deny direct access to this directory. Include the backup directory and WordPress database in site backups; both are needed for automatic restoration.

## Restore originals

Use **Restore originals** from an attachment's row actions, its Save box or the Media Library bulk actions. The command-line equivalent is:

```bash
wp wzio restore-originals --ids=123,456
wp wzio restore-originals --all --dry-run
wp wzio restore-originals --all
```

Restore verifies backup hashes, replaces the files and restores dimensions and file sizes. The backup is removed only after restoration is recorded. Failed restores retain recovery information for another attempt. The attachment is queued for sidecar regeneration, with automatic original compression suppressed so restoration is not immediately undone. An explicit per-image **Optimize** action or `wp wzio compress` can enable processing of that attachment again.

**Delete optimized copies** and `wp wzio clean` delete sidecars only. They preserve original backups. Neither deactivating nor uninstalling the plugin restores originals: compressed images stay compressed, so you can uninstall after a bulk run and keep the savings. To get the untouched originals back, restore them before uninstalling. By default uninstall keeps the backup folder and its restore records, so a reinstall can still restore them. Enable **Delete original backups on uninstall** to permanently remove the backups; the originals of compressed images are then lost for good.

## Regeneration, other optimizers and caching

The plugin's adapters for WordPress's GD and Imagick image editors read the verified backup of a compressed main file while keeping the normal output destination. This avoids creating new thumbnails from already compressed pixels. When WordPress has an untouched unscaled original, it continues to use it. Third-party editors that bypass WordPress's standard editor selection are outside this integration.

If another tool changes a source file after optimization, the plugin refuses further compression of that file rather than silently treating the change as its own output. Review or restore the backup before trying again. Avoid enabling original compression in TinyPNG, ShortPixel, Imagify, EWWW or Smush at the same time.

Original URLs remain unchanged. A CDN or browser may continue serving the previous bytes until its cache expires or is purged. The plugin does not automatically purge third-party caches.
