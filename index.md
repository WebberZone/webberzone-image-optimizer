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
  <div class="eyebrow">How it works</div>
  <h2 class="section-title" style="margin-bottom:8px;">Three steps, all on your own server</h2>
  <p style="color:var(--wz-warm-grey); max-width:64ch;">There's no dashboard to sign into and no image to upload anywhere else. The plugin runs inside WordPress using the image libraries your host already gave you.</p>

  <div class="steps-grid">
    <div class="step">
      <h3>Test what your server can actually do</h3>
      <p>At activation, the plugin encodes a small bundled image with GD and Imagick rather than trusting what the extension claims to support. It caches which formats really work, so AVIF is only offered when it will succeed.</p>
    </div>
    <div class="step">
      <h3>Convert without touching the original</h3>
      <p>New uploads convert automatically; existing libraries convert through a resumable, database-backed queue. Each result is written next to the source as a sidecar file &mdash; <code>photo.jpg</code> gains <code>photo.jpg.webp</code> &mdash; and a copy that isn't actually smaller is discarded.</p>
    </div>
    <div class="step">
      <h3>Let the browser choose, safely</h3>
      <p>Every image is wrapped in a <code>&lt;picture&gt;</code> element listing the available formats. The browser picks the one it supports, so the HTML is identical for every visitor and every cache layer &mdash; page cache, CDN, proxy &mdash; can cache it without any special configuration.</p>
    </div>
  </div>
</div>

<div class="home-section" style="padding-top:0;">
  <div class="eyebrow">Why it's different</div>
  <h2 class="section-title" style="margin-bottom:8px;">Not a cloud service, and not an Accept-header hack</h2>
  <p style="color:var(--wz-warm-grey); max-width:64ch;">Most image optimization plugins either ship your media to a third-party API, or rewrite delivery based on the browser's <code>Accept</code> header. Both come with trade-offs this plugin avoids.</p>

  <table class="compare-table">
    <thead>
      <tr>
        <th></th>
        <th>Image Optimizer</th>
        <th>Cloud optimization services</th>
        <th>Accept-header rewriting</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>Where images go</td>
        <td class="wz-yes">Never leave your server</td>
        <td>Uploaded to a third-party API</td>
        <td>Never leave your server</td>
      </tr>
      <tr>
        <td>Account / API key</td>
        <td class="wz-yes">None required</td>
        <td>Required, usually metered</td>
        <td>Varies</td>
      </tr>
      <tr>
        <td>Ongoing cost</td>
        <td class="wz-yes">Free, no quota</td>
        <td>Monthly quota or subscription</td>
        <td>Free</td>
      </tr>
      <tr>
        <td>Behind a CDN or page cache</td>
        <td class="wz-yes">Safe &mdash; same HTML for everyone</td>
        <td>Depends on provider</td>
        <td>Risky unless every cache layer honors <code>Vary: Accept</code></td>
      </tr>
      <tr>
        <td>If the service disappears</td>
        <td class="wz-yes">Nothing to lose &mdash; it's not a service</td>
        <td>Optimized images can stop being served</td>
        <td>N/A</td>
      </tr>
    </tbody>
  </table>
</div>

<div class="home-section" style="padding-top:0;">
  <div class="eyebrow">Everything included</div>
  <h2 class="section-title" style="margin-bottom:8px;">Built for real media libraries</h2>

  <div class="feature-grid">
    <div class="feature-card">
      <h3>WebP &amp; AVIF</h3>
      <p>Generated together or separately, with per-format quality and encoder effort settings, and lossless mode for PNG sources.</p>
    </div>
    <div class="feature-card">
      <h3>Originals never touched</h3>
      <p>Each optimized copy is written alongside the original as a sidecar file. Deactivating the plugin returns your site to the originals immediately &mdash; no broken URLs.</p>
    </div>
    <div class="feature-card">
      <h3>Responsive images done properly</h3>
      <p>Every candidate in an image's <code>srcset</code> is mapped to its optimized copy. If even one size is missing, the whole image falls back to the original rather than risk a broken request.</p>
    </div>
    <div class="feature-card">
      <h3>Resumable bulk conversion</h3>
      <p>A database-backed queue works through your library in batches with retries. Close the tab and nothing is lost &mdash; reopen it and it picks up where it stopped.</p>
    </div>
    <div class="feature-card">
      <h3>Lazy conversion on the front end</h3>
      <p>An image seen by a visitor but not yet converted is queued for later and served as-is. Nothing is ever encoded during a page render.</p>
    </div>
    <div class="feature-card">
      <h3>CSS background images</h3>
      <p>For images referenced from a stylesheet, where the browser is never offered a choice, the Delivery tab generates ready-to-paste Apache and nginx rules.</p>
    </div>
    <div class="feature-card">
      <h3>Multisite aware</h3>
      <p>Per-site queues and settings, with tables created automatically for new sites on a network.</p>
    </div>
    <div class="feature-card">
      <h3>WP-CLI included</h3>
      <p><code>wp wzio status</code>, <code>convert</code>, <code>queue</code>, <code>run</code>, and <code>clean</code> for scripting and deployments.</p>
    </div>
    <div class="feature-card">
      <h3>Built to fail safely</h3>
      <p>A memory guard skips an oversized image rather than crashing a batch, and metadata is stripped from copies while the colour profile is kept so colours don't shift.</p>
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
