=== WebberZone Image Optimizer ===
Tags: webp, avif, image optimization, performance, convert
Contributors: webberzone, ajay
Donate link: https://wzn.io/donate-wz
Stable tag: 1.2.1
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: http://www.gnu.org/licenses/gpl-2.0.txt

Convert your media library to WebP and AVIF, and serve the best format each browser supports. Free, open source, no account, no upload limits.

== Description ==

WebberZone Image Optimizer converts the images already in your media library to WebP and AVIF, and serves each visitor the smallest file their browser can read. Images are typically 40–60% smaller with no visible difference.

Everything happens on your own server. Your images are never uploaded anywhere. The plugin makes no outbound network requests of any kind — there is no external service, no API key, no monthly image quota, no account to create and nothing that can stop working because a company shut down or changed its pricing.

= What your server needs =

Almost certainly nothing you do not already have. WordPress cannot crop or resize an uploaded image without either the **Imagick** or the **GD** extension, so every working WordPress install already has one of them, and both have been able to write WebP for years.

* **WebP** works on virtually any current host. GD has supported it since PHP 5.5, Imagick since well before that.
* **AVIF** is newer and less universal. It needs either PHP 8.1+ with GD built against libavif, or Imagick with an AVIF delegate. Plenty of hosts have it; plenty do not yet.

You do not have to guess. The plugin tests your server by actually encoding a small image with each backend at activation, rather than trusting what the extension claims to support, and the settings screen shows you exactly which formats came back working. If AVIF is unavailable the option is simply marked as such and WebP carries on normally.

= Optional original compression with backups =

By default, each optimized copy is written alongside the original with the new extension appended, so `photo.jpg` gains `photo.jpg.webp`. The *File naming* setting can switch this to replacing the extension instead (`photo.webp`) — see the settings screen for the trade-off before turning it on. Sidecar generation leaves the source unchanged. Optional original compression recompresses served JPEG/PNG files with mandatory backups; optional resizing uses the maximum dimension. Use Restore originals to reverse these changes. Deactivation stops optimized delivery but does not restore compressed originals.

= Delivery that survives caching =

Images are wrapped in a `<picture>` element, so the *browser* chooses the format. That matters more than it sounds: the common alternative is to vary the response on the `Accept` header, which returns different bytes for a single URL. Any cache in front of that — a page cache plugin, a CDN — which ignores `Vary` will happily hand a WebP file to a browser that cannot display it. A `<picture>` element has no such failure mode.

Responsive images are handled properly. Each format's `<source>` lists only the optimized `srcset` candidates that exist, with their descriptors preserved exactly. Missing intermediate copies are omitted, while a missing smallest, widest or highest-density copy withholds that format so the optimized set always covers the same range as the original set. The original `<img>` remains as the fallback.

For images referenced from a stylesheet, where the browser is never offered a choice, the Delivery tab generates ready-to-paste Apache and nginx rules, complete with the `Vary: Accept` header those rules require.

= Bulk conversion that finishes =

The bulk screen works through a database-backed queue one batch at a time. Close the tab and nothing is lost; a background worker carries on, and reopening the screen resumes exactly where it stopped. Failures are retried a few times and then listed with the reason.

= Features =

* WebP and AVIF, generated together or separately (AVIF where the server supports it)
* Bulk conversion of an existing media library, resumable and interruptible
* Automatic conversion of new uploads
* Lazy conversion: an image seen on the front end but not yet converted is queued, never encoded during the page render
* Per-format quality, encoder effort, and lossless mode for PNG sources
* A lossy optimized copy that misses the minimum saving is retried once at a lower quality before being discarded, per file
* Metadata stripped from the copies while the color profile is kept, so colors do not shift
* Animated GIFs keep their animation when ImageMagick is available
* Memory guard that skips an image rather than crashing a batch
* Per-image status and actions in the Media library, with a filter for optimized, not-yet-optimized, skipped and failed images
* Bulk Optimize warns when images are queued but the background worker has stopped running, and gives the commands that recover it
* Multisite aware: per-site queues, tables created for new sites automatically
* WP-CLI: `wp wzio status`, `convert`, `queue`, `run`, `clean`
* Filters throughout for developers

== Installation ==

1. Upload the `webberzone-image-optimizer` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins menu.
3. Visit **Media → Image Optimizer** to choose your formats.
4. Visit **Media → Bulk Optimize** and press Start.

== Frequently Asked Questions ==

= Will this touch my original images? =

By default, originals are unchanged and modern copies are separate files. Enable Compress original images to recompress served JPEG/PNG files with mandatory backups. Restore originals reverses compression and optional resizing. The unscaled upload retained by WordPress is left untouched.

= What happens if I deactivate the plugin? =

Your site immediately goes back to serving the original images. The generated files stay on disk, so reactivating restores the optimization instantly without converting anything again.

= Do I need to change my server configuration? =

No. The `<picture>` rewrite needs nothing beyond activating the plugin. Server rules are only needed for images referenced from CSS, and they are optional.

= Should I enable AVIF? =

AVIF produces noticeably smaller files than WebP and is understood by every current browser, but it takes longer to encode and a small number of older browsers do not support it. Generating both is the safest choice: each visitor gets the smallest file their browser can read, and anyone else gets the original. Start with WebP if your library is very large.

= My server cannot encode WebP or AVIF =

The settings screen marks any format your server cannot produce. Ask your host to enable the Imagick extension, which is the better backend, or GD compiled with WebP support.

= One of my images is still being served as a JPEG or PNG =

There are three common reasons, and the Media library column tells you which one applies.

**The image is not hosted on your site.** Only files inside your own uploads directory can be optimized. Posts imported from another site often keep image URLs pointing back at the original domain, and those are left alone.

**One size in the set could not be made smaller.** A lossy optimized copy that misses the minimum saving is retried once at a lower quality and discarded if it still misses. Lossless copies are simply discarded when they miss. Missing intermediate copies are left out of the optimized `srcset`, so the browser can still use the copies that exist. If the smallest, widest or highest-density copy is missing, that optimized format is withheld to avoid over-downloading or serving an undersized image where the original set offered a better choice.

**Your server cannot produce that format.** Check the settings screen, which marks any format your server cannot encode.

= Does this work with a CDN or a caching plugin? =

Yes. Because the format choice happens in the browser rather than on the server, every visitor receives identical HTML and the page cache stays correct. If you also add the optional server rules for CSS backgrounds, keep the `Vary: Accept` header they include.

== Screenshots ==

1. Bulk Optimize screen with live progress
2. Per-image savings and actions in the Edit Media screen

== Changelog ==

= 1.2.1 =

Release date: 10 October 2026

**Added**

* `wzio_png_tool_paths` filter for custom oxipng or pngquant locations.

**Fixed**

* PNG compression was unavailable, with PHP warnings, when `open_basedir` excluded the tool's folder.
* Images already inside a `<picture>` element were wrapped in a second one.
* The nginx rules served the original instead of WebP when AVIF was accepted but missing.
* Whole-page rewriting wrapped images inside HTML comments and scripts.

= 1.2.0 =

Release date: 7 October 2026
Release post: https://webberzone.com/announcements/image-optimizer-v1-2/

**Added**

* Optional original JPEG compression with configurable quality and PNG compression through local oxipng or pngquant tools, with verified backups and `wp wzio compress` support.
* Restore originals actions in the Media Library and Edit Media screen, bulk restoration and `wp wzio restore-originals` to recover files and dimensions; backups and restore records are retained on uninstall by default.
* Compression from verified backups when settings change, plus backup integration with WordPress's GD and Imagick image editors to avoid regenerating thumbnails from compressed main files.
* Maximum image dimension and Disable upload scaling settings for new uploads.
* Resize existing originals option and `wp wzio compress --resize` to shrink served main files with restorable dimensions; existing thumbnail files are retained, with oversized candidates omitted from WordPress-generated `srcset`.
* Original compression results and resized dimensions in the Media Library, plus original savings, backup usage, resized-image counts and optimized-copy disk usage by format on Bulk Optimize and in `wp wzio status`.
* Regenerate only images made with older settings option on Bulk Optimize and `--outdated` on `wp wzio convert` and `wp wzio queue`; copies made before settings tracking are left alone.

**Fixed**

* Bandwidth savings on Bulk Optimize and `wp wzio status` omitted images converted outside the queue and could be lost when queue records were cleared.
* Image conversion could fail in background or command-line requests when `wp_tempnam()` was unavailable.
* Optimized copies could remain on disk when an image was deleted during conversion.

= Earlier versions =

For the changelog of earlier versions, please refer to the [releases page on GitHub](https://github.com/WebberZone/webberzone-image-optimizer/releases).

== Upgrade Notice ==

= 1.2.1 =
Fixes PNG compression on servers using open_basedir, nested picture elements and the nginx WebP fallback.
