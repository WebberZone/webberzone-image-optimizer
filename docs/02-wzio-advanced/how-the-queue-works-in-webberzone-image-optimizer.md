---
slug: how-the-queue-works-in-webberzone-image-optimizer
title: "How the Queue Works in WebberZone Image Optimizer"
products: [webberzone-image-optimizer]
sections: [02-wzio-advanced]
tags: [webberzone-image-optimizer, queue, bulk]
status: publish
order: 2
---

[kbtoc]

[WebberZone Image Optimizer](https://webberzone.github.io/webberzone-image-optimizer/) converts images through a database-backed queue rather than during a page render. This is what makes the Bulk Optimize screen resumable and keeps front-end visitors from ever waiting on an encode.

## One row per attachment

The queue holds one row per attachment, not per file — the converter always processes an attachment's scaled original and every sub-size together, so a per-file queue would only add rows without adding any real resolution.

Each row moves through these statuses:

* **pending** — waiting to be processed.
* **processing** — claimed by a worker.
* **done** — finished successfully.
* **skipped** — nothing to do (already optimized, or excluded).
* **failed** — finished with an error, after retries are exhausted.

## What adds attachments to the queue

* Running a scan from the **Bulk Optimize** screen, or `wp wzio queue` / `wp wzio convert` from the command line.
* A front-end view of an image that has not been converted yet, when **Queue images on first view** is enabled on the Advanced settings tab — the original is served immediately and the attachment is queued on `shutdown`, so nothing is ever encoded during the page render itself.

## How a batch runs

`Processor::run_batch()` is the single routine behind the Bulk Optimize screen, the background cron worker and `wp wzio run` — all three share one definition of a unit of work.

1. Claims up to **Images per batch** pending rows (Advanced settings tab), oldest first.
2. Converts each claimed attachment.
3. Records the outcome: `done` with bytes saved, `skipped` when nothing was convertible, or `failed` with the error message.
4. A `failed` attachment is put back to `pending` and retried, up to 3 attempts total, before it is left as `failed` for good and listed on the Bulk Optimize screen.

Claiming uses a status guard (`UPDATE ... WHERE status = 'pending'`) that InnoDB serializes, so two workers claiming at the same time never process the same row twice.

## Locking and the background worker

Only one worker runs a batch at a time, enforced with a MySQL advisory lock (`GET_LOCK`/`RELEASE_LOCK`). If a second worker — the cron job firing while you're also running the bulk screen, for example — tries to run a batch while the lock is held, it backs off immediately rather than processing concurrently.

When **Process the queue in the background** is enabled (Advanced settings tab), a batch that finishes with work still remaining schedules a WP-Cron event roughly a minute later to continue automatically, even with the Bulk Optimize screen closed. The schedule is removed once the queue is empty, and re-created the next time something is queued.

A row stuck in `processing` for more than 10 minutes — a worker killed by a fatal error or a timeout — is automatically released back to `pending` the next time a batch runs, so it can never stall the queue permanently.

## Clearing the queue

**Clear queue** on the Bulk Optimize screen removes pending and in-progress rows. Completed rows are kept so the **Bandwidth saved** totals on the bulk screen survive the reset — the queue can be cleared without losing the record of what was already saved. Images that already have optimized copies stay optimized; clearing the queue only discards rows that have not finished, it does not delete any generated files.

Running `wp wzio clean` without attachment IDs empties the entire queue table (all rows) and deletes every generated sidecar file — a full reset. `wp wzio clean 7214` removes the row for a single attachment and its generated files.

## See also

* [Image Optimizer Settings](../01-wzio-getting-started/image-optimizer-settings.md)
* [Bulk Optimize in WebberZone Image Optimizer](../01-wzio-getting-started/bulk-optimize-in-webberzone-image-optimizer.md)
* [WebberZone Image Optimizer WP-CLI](webberzone-image-optimizer-wp-cli.md)
