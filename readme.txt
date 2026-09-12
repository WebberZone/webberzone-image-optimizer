=== WebberZone Image Optimizer ===
Tags: webp, avif, image optimization, performance, convert
Contributors: webberzone, ajay
Donate link: https://wzn.io/donate-wz
Stable tag: 1.1.0
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

= Your originals are never modified =

By default, each optimized copy is written alongside the original with the new extension appended, so `photo.jpg` gains `photo.jpg.webp`. The *File naming* setting can switch this to replacing the extension instead (`photo.webp`) — see the settings screen for the trade-off before turning it on. Either way, nothing overwrites, replaces or re-saves your original file. Deactivating the plugin returns your site to serving the originals immediately, and no URL ever breaks.

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

No. Originals are never modified, moved or deleted. Every optimized file is a separate file written next to the original.

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

= 1.1.0 =

Release date: 12 September 2026
Release post: https://webberzone.com/announcements/image-optimizer-v1-1/

**Added**

* Media Library filter for optimized, not-yet-optimized, skipped and failed images. Thanks to [muneeb-ashraf](https://github.com/muneeb-ashraf).
* One lower-quality retry for a lossy copy that misses the minimum saving, preserving more optimized candidates before discarding a stubborn size. The step is a share of the configured quality, floored at 40, and filterable with `wzio_conversion_retry_step`. The retry is skipped where the server's encoder ignores the quality setting, because it would produce an identical file at twice the cost.
* A Bulk Optimize warning when images are queued but the background worker has stopped running, naming `DISABLE_WP_CRON` or a blocked loopback request as the likely cause and giving the WP-CLI and system cron commands that recover it.
* A note in the Media Library column, on the attachment screen and in `wp wzio convert` when a copy needed a lower quality than the one configured.

**Changed**

* Optimized `<picture>` sources now omit missing intermediate `srcset` candidates while requiring the smallest and widest or highest-density candidates. Complete format sets are listed before partial sets.
* AVIF encoder effort now maps onto each encoder's own speed scale rather than one shared invented scale, with the default sitting at the measured point where files stop getting meaningfully smaller. This cuts AVIF conversion time by roughly thirty times on ImageMagick servers for a few per cent of file size.
* A source that can carry transparency is never encoded at the fastest AVIF speed, where its file can grow by two fifths for no gain.
* Lossless encoding of PNG sources now applies to WebP only. A lossless AVIF is almost always larger than the PNG it came from, so it was discarded anyway.
* The capability probe encodes at the cheapest effort. It only answers whether a format works, so it no longer runs the slowest encode the plugin is capable of.

**Security**

* Hardened settings textarea sanitization for users without the `unfiltered_html` capability.

**Fixed**

* The AVIF encoder effort setting had no effect on servers that use GD, which never received it and fell back to its own default.
* The AVIF quality setting was ignored for PNG sources on servers that use GD. The encoder was handed its own default quality instead, which was neither the lossless encode the setting implied nor the quality configured on the Quality tab.
* Very large images were automatically pushed towards a faster encoder setting, which could make them substantially larger instead of merely quicker.
* Images were wrapped in a second `<picture>` element when content and template rewriting both ran.
* An image whose conversion stopped the background worker was claimed again indefinitely, because the abandoned attempt was never counted against its retry budget.
* A single attachment could run far past the batch time budget. The worker now stops between files and resumes after the last file it attempted.
* The queue table was reported missing for the rest of the request in which it was created, so a new site silently queued nothing.
* `trim()`, `ltrim()` and `rtrim()` relied on the default character list, which changes in PHP 8.6.

= Earlier versions =

For the changelog of earlier versions, please refer to the [releases page on GitHub](https://github.com/WebberZone/webberzone-image-optimizer/releases).

== Upgrade Notice ==

= 1.1.0 =
Responsive images now use eligible optimized sizes while preserving the original set's smallest and largest coverage. Bulk Optimize detects a stalled worker, and lossy conversions get a lower-quality retry. Includes security hardening.
