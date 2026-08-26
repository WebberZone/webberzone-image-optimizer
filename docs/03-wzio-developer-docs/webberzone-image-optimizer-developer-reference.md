---
slug: webberzone-image-optimizer-developer-reference
title: "WebberZone Image Optimizer Developer Reference"
products: [image-optimizer]
sections: ["03-wzio-developer-docs"]
tags: [developer, filters, hooks, webberzone-image-optimizer]
status: publish
---

The full developer reference for [WebberZone Image Optimizer](https://webberzone.com/plugins/webberzone-image-optimizer/) lives at [webberzone.dev](https://webberzone.dev/webberzone-image-optimizer/), a site generated directly from the plugin source. It documents every action and filter hook, every function, and every class and public method in the plugin, with parameters, return types and `@since` versions — and it is always regenerated from the current release, so it never drifts.

## The reference site

- [Hooks](https://webberzone.dev/webberzone-image-optimizer/#hooks) — all `wzio_` action and filter hooks, such as `wzio_conversion_args` and `wzio_delivery_enabled`.
- [Functions](https://webberzone.dev/webberzone-image-optimizer/#functions) — the `wzio_get_option()`, `wzio_update_settings()` and other wrapper functions.
- [Classes](https://webberzone.dev/webberzone-image-optimizer/#classes) — every class in the `WebberZoneImage_Optimizer` namespace, with its public methods.

## Conventions

The plugin uses the namespace `WebberZoneImage_Optimizer` and the prefix `wzio` throughout. Two design decisions matter when extending the plugin:

**Sidecar files, named via `Helpers::apply_sidecar_naming()` — never by string concatenation.** Default is `photo.jpg` → `photo.jpg.webp`; the **Optimized file naming** setting can switch a site to `photo.webp` instead, and code that assumes append-only will 404 there. The optional third argument forces a strategy rather than reading the setting, which is how the plugin looks for copies under the convention a site is not using. Originals are never modified.

**`<picture>` rewriting, not `Accept`-header rewriting.** The HTML is identical for every visitor; the browser picks the source. Do not rely on server-side content negotiation when extending delivery — it is deliberately not how this plugin works.
