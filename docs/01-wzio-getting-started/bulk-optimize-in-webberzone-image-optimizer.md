---
slug: bulk-optimize-in-webberzone-image-optimizer
title: "Bulk Optimize in WebberZone Image Optimizer"
products: [image-optimizer]
sections: ["01-wzio-getting-started"]
tags: [bulk, queue, webberzone-image-optimizer]
status: publish
order: 2
toc: true
---

[toc]

The Bulk Optimize screen in [WebberZone Image Optimizer](https://webberzone.com/plugins/webberzone-image-optimizer/) converts your existing media library. Find it at **Media → Bulk Optimize**.

## How it works

The screen works through a database-backed queue one batch at a time rather than in a single long request, so a large library cannot trip the PHP time limit. Building that queue is time-bounded in the same way: **Start optimizing** scans the library in repeated passes of about ten seconds each, each pass picking up from the attachment ID the previous one stopped at, so the status stays on "Building the queue…" for a moment on a large library rather than timing out. Closing the tab loses nothing — the queue lives in the database, and if **Process the queue in the background** is enabled on the Advanced settings tab, a background worker carries on and reopening the screen resumes exactly where it stopped. See [How the Queue Works](https://webberzone.com/support/knowledgebase/how-the-queue-works-in-webberzone-image-optimizer/) for the full mechanics.

## The screen

Four cards summarize your library:

- **Images in the library** — total convertible attachments.
- **Already optimized** — attachments with at least one generated file.
- **Waiting in the queue** — attachments still pending.
- **Bandwidth saved** — total bytes saved. Once at least one image has been converted, shows the percentage saved of the total original size: "Bandwidth saved of X originally (Y%)".

**Images in the library** and **Already optimized** are counted across the whole library, so both are cached rather than recounted after every batch — the library total for an hour, the optimized total for a minute. Uploading or deleting an image clears both immediately, as does starting a scan or clearing the queue. **Waiting in the queue** and **Bandwidth saved** are read from the queue itself and are always current. If **Already optimized** looks a minute behind during a long run, that is the cache, not a stalled queue.

**Start optimizing** builds the queue (if it is empty) and begins working through it. **Pause** stops the current run without losing progress. **Clear queue** removes attachments that are still waiting or in progress — completed rows are kept so the **Bandwidth saved** totals survive the reset. The **Re-optimize images that are already done** checkbox forces every image to be re-encoded on the next run, even ones with an up-to-date copy.

A normal run never re-encodes an image that already has a usable copy, whether this plugin wrote it or another optimizer did. Existing copies are kept and recorded, and only the missing formats and sizes are encoded, which is what makes a run on a library moving over from another plugin so much faster than the first run on a fresh library. See [Migrating from Another Image Optimizer](https://webberzone.com/support/knowledgebase/migrating-from-another-image-optimizer/).

## Failures

An image that fails is retried a few times and then listed under **Images that could not be optimized**, with the reason for each failure. Common reasons include a server that cannot encode the requested format, or every candidate size failing the minimum-saving check.

## Configuring the run

**Images per batch** and **Process the queue in the background** are set on the Advanced tab of the settings screen — see [Image Optimizer Settings](https://webberzone.com/support/knowledgebase/image-optimizer-settings/).
