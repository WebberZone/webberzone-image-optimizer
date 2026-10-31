=== WebberZone Image Optimizer – WebP & AVIF Converter, Compress Images ===
Tags: webp, avif, image optimization, compress images, optimize images
Contributors: webberzone, ajay
Donate link: https://wzn.io/donate-wz
Stable tag: 1.3.0
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: http://www.gnu.org/licenses/gpl-2.0.txt

Free WebP and AVIF converter. Compress and optimize images on your own server and serve the best format. No API key, no account, no limits.

== Description ==

WebberZone Image Optimizer is a free WebP converter and AVIF converter for WordPress. It converts the JPEG, PNG, GIF and WebP images already in your media library to WebP and AVIF, optimizes new uploads automatically, and serves each visitor the smallest file their browser can read. Images are typically 40–60% smaller with no visible difference, so pages load faster and score better on PageSpeed Insights and Core Web Vitals.

Everything happens on your own server. Your images are never uploaded anywhere. The plugin makes no outbound network requests of any kind — there is no external service, no API key, no monthly image quota, no account to create and nothing that can stop working because a company shut down or changed its pricing. AVIF is not a paid upgrade: every feature is free.

= What your server needs =

Almost certainly nothing you do not already have. WordPress cannot crop or resize an uploaded image without either the **Imagick** or the **GD** extension, so every working WordPress install already has one of them, and both have been able to write WebP for years.

* **WebP** works on virtually any current host. GD has supported it since PHP 5.5, Imagick since well before that.
* **AVIF** is newer and less universal. It needs either PHP 8.1+ with GD built against libavif, or Imagick with an AVIF delegate. Plenty of hosts have it; plenty do not yet.

You do not have to guess. The plugin tests your server by actually encoding a small image with each backend at activation, rather than trusting what the extension claims to support, and the settings screen shows you exactly which formats came back working. If AVIF is unavailable the option is simply marked as such and WebP carries on normally.

= Compress and resize JPEG and PNG originals =

By default, each optimized copy is written alongside the original with the new extension appended, so `photo.jpg` gains `photo.jpg.webp`, and the original is left unchanged. The *File naming* setting can switch this to replacing the extension instead (`photo.webp`) — see the settings screen for the trade-off before turning it on.

You can also compress the original JPEG and PNG files themselves. JPEG compression uses Imagick or GD at the quality you choose; PNG compression uses a local oxipng or pngquant tool when your server has one. A maximum image dimension resizes oversized uploads and, optionally, existing originals. Every change is backed up first, and Restore originals reverses it. Both features are off by default. Deactivation stops optimized delivery but does not restore compressed originals.

= Serve WebP and AVIF with the picture element, safe behind caches and CDNs =

Images are wrapped in a `<picture>` element, so the *browser* chooses the format. That matters more than it sounds: the common alternative is to vary the response on the `Accept` header, which returns different bytes for a single URL. Any cache in front of that — a page cache plugin, a CDN — which ignores `Vary` will happily hand a WebP file to a browser that cannot display it. A `<picture>` element has no such failure mode.

Responsive images are handled properly. Each format's `<source>` lists only the optimized `srcset` candidates that exist, with their descriptors preserved exactly. Missing intermediate copies are omitted, while a missing smallest, widest or highest-density copy withholds that format so the optimized set always covers the same range as the original set. The original `<img>` remains as the fallback.

Background images set in block styles, such as Group and Cover block backgrounds, get a CSS `image-set()` alternative, which does the same job as `<picture>` in CSS. For images referenced from a stylesheet file, where the browser is never offered a choice, the Delivery tab generates ready-to-paste Apache and nginx rules, complete with the `Vary: Accept` header those rules require.

= Bulk optimize your existing media library =

The bulk screen works through a database-backed queue one batch at a time. Close the tab and nothing is lost; a background worker carries on, and reopening the screen resumes exactly where it stopped. Failures are retried a few times and then listed with the reason.

= Features =

* Convert JPEG, PNG and GIF to WebP and AVIF, together or separately (AVIF where the server supports it), and WebP uploads to AVIF
* Bulk conversion of an existing media library, resumable and interruptible
* Automatic conversion of new uploads
* Optional JPEG and PNG compression of the originals, with backups and Restore originals
* Maximum image dimension to resize oversized uploads
* Reuses WebP and AVIF files already created by another image optimizer instead of converting again
* Lazy conversion: an image seen on the front end but not yet converted is queued, never encoded during the page render
* Per-format quality, encoder effort, and lossless mode for PNG sources
* A lossy optimized copy that misses the minimum saving is retried once at a lower quality before being discarded, per file
* Metadata stripped from the copies while the color profile is kept, so colors do not shift
* Animated GIFs keep their animation when ImageMagick is available
* Memory guard that skips an image rather than crashing a batch
* Per-image status and actions in the Media library, with a filter for optimized, not-yet-optimized, skipped and failed images
* Bulk Optimize warns when images are queued but the background worker has stopped running, and gives the commands that recover it
* WebP and AVIF for background images set in block styles, using CSS `image-set()`
* Site Health tests and an Info section covering encoders, the queue and delivery settings
* Abilities API support on WordPress 6.9+, so AI assistants and MCP clients can check status, run Bulk Optimize and change settings
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

= Do I need an API key or an account? =

No. Conversion and JPEG compression run on your own server with Imagick or GD, the image libraries WordPress already uses; PNG compression also needs oxipng or pngquant on the server. There is no sign-up, no monthly quota and no limit on how many images you convert, and it works the same on staging and local sites.

= Is AVIF conversion free? =

Yes. There is no paid version; WebP, AVIF, bulk conversion, compression and WP-CLI are all included. AVIF only needs a server that can encode it, and the settings screen tells you whether yours can.

= Will this improve my PageSpeed Insights score? =

Smaller images mean fewer bytes to download, which is what PageSpeed Insights measures in its image delivery audit and what most often slows Largest Contentful Paint. The plugin does not lazy load images or change their dimensions, so keep whatever handles those today.

= I am switching from another image optimizer. Do I have to convert everything again? =

No. WebP and AVIF files left next to your originals by plugins such as ShortPixel, Imagify or EWWW Image Optimizer are served as they are. The plugin detects which naming those files use and offers to match it, and the bulk run only creates the formats that are missing. Do not use the old plugin's restore tool before switching, as that deletes its optimized copies. See [Migrating from another image optimizer](https://webberzone.com/support/knowledgebase/migrating-from-another-image-optimizer/).

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

= 1.3.0 =

Release date: 1 November 2026

**Added**

* Block background images setting, on by default, which serves WebP and AVIF for background images set in block styles using CSS `image-set()`.
* WebP uploads as a source for AVIF copies.
* Abilities API support on WordPress 6.9 and later, so AI assistants and other MCP clients can check status, run Bulk Optimize, optimize one image and change settings.
* Site Health tests for missing encoders, a missing queue table and a stalled background queue, and an Image Optimizer section in Site Health Info.

**Changed**

* Bulk Optimize now retries a request up to five times when the server returns an error page or cannot be reached, instead of stopping.

**Fixed**

* Bulk Optimize stopped with an "is not valid JSON" error when PHP ran out of memory or time, or a server or proxy returned an error page.
* An image that crashed PHP during conversion stayed claimed for 10 minutes with no reason recorded. The PHP error now appears in the failures table.
* The Media Library Optimize link reported PHP errors as an interrupted connection.
* The .htaccess Install and Remove buttons showed a JSON parse error when the server returned an error page.
* Compress PNG originals could be turned on with WP-CLI or a direct option update when no PNG tool was installed, although the settings screen blocked it.
* Already optimized on the Bulk Optimize screen could exceed Images in the library, and the size totals were inflated, when conversion records were left behind by attachments that no longer exist.

= Earlier versions =

For the changelog of earlier versions, please refer to the [releases page on GitHub](https://github.com/WebberZone/webberzone-image-optimizer/releases).

== Upgrade Notice ==

= 1.3.0 =
Serves WebP and AVIF for block background images, converts WebP uploads to AVIF, and lets AI assistants run Bulk Optimize on WordPress 6.9+. Keeps Bulk Optimize running through server errors.
