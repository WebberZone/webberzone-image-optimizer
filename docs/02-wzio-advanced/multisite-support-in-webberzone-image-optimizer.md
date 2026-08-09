---
slug: multisite-support-in-webberzone-image-optimizer
title: "Multisite Support in WebberZone Image Optimizer"
products: [webberzone-image-optimizer]
sections: [02-wzio-advanced]
tags: [webberzone-image-optimizer, multisite]
status: publish
order: 1
---

[kbtoc]

[WebberZone Image Optimizer](https://webberzone.com/plugins/webberzone-image-optimizer/) is multisite-aware: settings, the conversion queue and per-image conversion records are all per site, exactly as if each site had its own separate installation.

## Per-site queues

The plugin's queue table is created per site rather than shared across the network. Each site works through its own queue independently, so a bulk run or background worker on one site never touches another site's images.

## Activation on a network

Network-activating the plugin creates the queue table on every existing site in the network, not just the site where you clicked Activate. This matters because WordPress only fires the activation hook once for a network activation.

Sites created **after** the plugin is network-activated get their table automatically as well — there is no manual step for new sites.

## Settings

Each site has its own **General**, **Quality**, **Delivery** and **Advanced** settings — see [Image Optimizer Settings](../01-wzio-getting-started/image-optimizer-settings.md). Formats, quality and delivery choices set on one site have no effect on any other site in the network.

## See also

* [Bulk Optimize in WebberZone Image Optimizer](../01-wzio-getting-started/bulk-optimize-in-webberzone-image-optimizer.md)
* [Image Optimizer Settings](../01-wzio-getting-started/image-optimizer-settings.md)
