---
title: WebberZone Image Optimizer
description: Convert your WordPress media library to WebP and AVIF, and serve the best format each browser supports. Free, open source, no account, no upload limits.
permalink: /
---

<div class="hero">
  <div class="eyebrow">Free &middot; Open Source &middot; No Account</div>
  <h1>WordPress images, automatically <em>smaller</em></h1>
  <p class="lead">WebberZone Image Optimizer converts the images already in your media library to WebP and AVIF, and serves each visitor the smallest file their browser can read. Typically 40&ndash;60% smaller, with no visible difference.</p>
  <div class="hero-ctas">
    <a href="{{ '/docs/' | relative_url }}" class="btn-primary">Read the Docs</a>
    <a href="https://github.com/WebberZone/webberzone-image-optimizer/releases/latest" target="_blank" class="btn-outline">Download Latest Release</a>
    <a href="https://github.com/WebberZone/webberzone-image-optimizer" target="_blank" class="btn-outline">View on GitHub</a>
  </div>
</div>

<div class="home-section">
  <div class="eyebrow">Why it's different</div>
  <h2 class="section-title" style="margin-bottom:8px;">Runs entirely on your server</h2>
  <p style="color:var(--wz-warm-grey); max-width:64ch;">Your images are never uploaded anywhere. The plugin makes no outbound network requests of any kind &mdash; no external service, no API key, no monthly image quota, no account to create, and nothing that can stop working because a company shut down or changed its pricing.</p>

  <div class="feature-grid">
    <div class="feature-card">
      <h3>WebP &amp; AVIF</h3>
      <p>Generated together or separately, with AVIF used wherever your server supports it.</p>
    </div>
    <div class="feature-card">
      <h3>Originals never touched</h3>
      <p>Each optimized copy is written alongside the original as a sidecar file. Nothing overwrites, replaces, or re-saves the source.</p>
    </div>
    <div class="feature-card">
      <h3>Delivery survives caching</h3>
      <p>Images are wrapped in a <code>&lt;picture&gt;</code> element, so the browser picks the format &mdash; not a cache-breaking <code>Accept</code>-header rewrite.</p>
    </div>
    <div class="feature-card">
      <h3>Resumable bulk conversion</h3>
      <p>A database-backed queue works through your library in batches. Close the tab and nothing is lost.</p>
    </div>
    <div class="feature-card">
      <h3>Multisite aware</h3>
      <p>Per-site queues and settings, with tables created automatically for new sites on a network.</p>
    </div>
    <div class="feature-card">
      <h3>WP-CLI included</h3>
      <p><code>wp wzio status</code>, <code>convert</code>, <code>queue</code>, <code>run</code>, and <code>clean</code> for scripting and deployments.</p>
    </div>
  </div>
</div>

<div class="home-section" style="padding-top:0;">
  <div class="eyebrow">Get started</div>
  <h2 class="section-title" style="margin-bottom:16px;">Documentation</h2>
  <div class="card-grid">
    <a class="doc-card" href="{{ '/docs/01-wzio-getting-started/getting-started-with-webberzone-image-optimizer/' | relative_url }}">
      <h3>Getting Started</h3>
      <p>Install the plugin and understand what it needs to run.</p>
    </a>
    <a class="doc-card" href="{{ '/docs/01-wzio-getting-started/image-optimizer-settings/' | relative_url }}">
      <h3>Settings</h3>
      <p>Configure formats, quality, and optimization behavior.</p>
    </a>
    <a class="doc-card" href="{{ '/docs/02-wzio-advanced/how-the-queue-works-in-webberzone-image-optimizer/' | relative_url }}">
      <h3>Advanced</h3>
      <p>How the optimization queue, multisite, and WP-CLI support work.</p>
    </a>
    <a class="doc-card" href="{{ '/docs/03-wzio-developer-docs/webberzone-image-optimizer-developer-reference/' | relative_url }}">
      <h3>Developer Reference</h3>
      <p>Hooks, filters, and functions for extending the plugin.</p>
    </a>
  </div>
</div>
