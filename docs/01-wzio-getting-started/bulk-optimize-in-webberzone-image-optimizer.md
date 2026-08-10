---
slug: bulk-optimize-in-webberzone-image-optimizer
title: "Bulk Optimize in WebberZone Image Optimizer"
products: [webberzone-image-optimizer]
sections: [01-wzio-getting-started]
tags: [webberzone-image-optimizer, bulk, queue]
status: publish
order: 2
---

[kbtoc]

The Bulk Optimize screen in [WebberZone Image Optimizer](https://webberzone.github.io/webberzone-image-optimizer/) converts your existing media library. Find it at **Media → Bulk Optimize**.

## How it works

The screen works through a database-backed queue one batch at a time rather than in a single long request, so a large library cannot trip the PHP time limit. Closing the tab loses nothing — the queue lives in the database, and if **Process the queue in the background** is enabled on the Advanced settings tab, a background worker carries on and reopening the screen resumes exactly where it stopped. See [How the Queue Works](https://webberzone.github.io/webberzone-image-optimizer/docs/02-wzio-advanced/how-the-queue-works-in-webberzone-image-optimizer/) for the full mechanics.

## The screen

Four cards summarize your library:

* **Images in the library** — total convertible attachments.
* **Already optimized** — attachments with at least one generated file.
* **Waiting in the queue** — attachments still pending.
* **Bandwidth saved** — total bytes saved. Once at least one image has been converted, shows the percentage saved of the total original size: "Bandwidth saved of X originally (Y%)".

**Start optimizing** builds the queue (if it is empty) and begins working through it. **Pause** stops the current run without losing progress. **Clear queue** removes attachments that are still waiting or in progress — completed rows are kept so the **Bandwidth saved** totals survive the reset. The **Re-optimize images that are already done** checkbox forces every image to be re-encoded on the next run, even ones with an up-to-date copy.

## Failures

An image that fails is retried a few times and then listed under **Images that could not be optimized**, with the reason for each failure. Common reasons include a server that cannot encode the requested format, or every candidate size failing the minimum-saving check.

## Configuring the run

**Images per batch** and **Process the queue in the background** are set on the Advanced tab of the settings screen — see [Image Optimizer Settings](https://webberzone.github.io/webberzone-image-optimizer/docs/01-wzio-getting-started/image-optimizer-settings/).

## See also

* [Image Optimizer Settings](https://webberzone.github.io/webberzone-image-optimizer/docs/01-wzio-getting-started/image-optimizer-settings/)
* [How the Queue Works](https://webberzone.github.io/webberzone-image-optimizer/docs/02-wzio-advanced/how-the-queue-works-in-webberzone-image-optimizer/)
* [WebberZone Image Optimizer WP-CLI](https://webberzone.github.io/webberzone-image-optimizer/docs/02-wzio-advanced/webberzone-image-optimizer-wp-cli/)
