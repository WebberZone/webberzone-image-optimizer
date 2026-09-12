---
slug: how-avif-encoder-speed-is-chosen
title: "How AVIF Encoder Speed Is Chosen"
products: [image-optimizer]
sections: ["03-wzio-developer-docs"]
tags: [avif, developer, drivers, performance, webberzone-image-optimizer]
status: publish
order: 1
---

The **AVIF encoder effort** setting is a 0–6 dial that belongs to the plugin, not to any encoder. Each driver translates it into its own backend's native speed scale. This page records the measurements behind that translation so the numbers do not have to be re-derived.

## The mapping

Both built-in drivers use the same table today, each holding its own private copy because libheif and libavif are different libraries and may diverge.

| Effort | Encoder speed | Character |
|---|---|---|
| 0–2 | 9 | Fastest |
| 3 | 8 | |
| **4** | **7** | **Default — the measured knee** |
| 5 | 6 | |
| 6 | 4 | Maximum compression |

A source whose format can carry transparency — anything that is not JPEG — is capped at speed 8. The cap therefore does nothing at or above the default and only constrains the fast end of the dial.

Both encoders count the opposite way from the plugin: 0 is the slowest and highest-compression setting, 9 the fastest. libavif nominally reaches 10, but 9 and 10 produce byte-identical output, so 9 is the ceiling.

## Why effort 4 maps to the knee

Measured over a 57-image corpus of photographs, UI screenshots and transparent graphics, at quality 50, CPU time normalized per megapixel:

| Speed | Imagick CPU ms/MP | Imagick bytes/px | GD CPU ms/MP | GD bytes/px |
|---|---|---|---|---|
| 5 | 3,372 | 0.0524 | 1,349 | 0.0713 |
| 6 | 684 | 0.0536 | 297 | 0.0727 |
| **7** | **310** | **0.0544** | **170** | **0.0735** |
| 8 | 145 | 0.0565 | 83 | 0.0751 |
| 9 | 63 | 0.0610 | 32 | 0.0808 |

Each step halves the CPU. Below speed 7 the file grows about 1.5% per step; at speed 8 it grows 3.8%, and at speed 9 nearly 9%. Speed 7 is where the returns stop being worth paying for.

The previous mapping, `6 - effort`, put the default at speed 2. On the same corpus that cost 10,654 ms/MP for 0.0514 bytes/px — roughly **70 times the CPU of speed 7 for 5% smaller files**.

## Why not speed 8

Speed 8 was the original candidate on CPU and size alone. A perceptual pass rejected it. Scoring every encode with SSIMULACRA2 against its source, speed 8 versus speed 7 across 150 comparisons:

| | |
|---|---|
| Best | +0.65 |
| Median | −1.13 |
| 10th percentile | −4.75 |
| Worst | −9.40 |
| Regressing by more than 1 point | 78 of 150 |

Speed 8 also produces *larger* files than speed 7 — 0.0544 to 0.0565 bytes/px on Imagick, 0.0735 to 0.0751 on GD. It is worse on quality and on size, buying only CPU, so there is no rate-distortion argument for it.

The artefact class is fine isolated highlights on dark ground: night skies, bokeh, sparkle, small light text on dark. The worst case in the corpus was a starfield photograph.

## Why transparent sources are capped

Alpha is encoded as a separate plane and compresses far worse at speed. Relative to speed 7, on transparent PNGs:

| Speed | Lossless bytes | Lossy SSIMULACRA2 (alpha channel) |
|---|---|---|
| 7 | — | — |
| 8 | +3.8% | −4.2 |
| 9 | **+42.6%** | **−122.8** |

The cliff is entirely at speed 9, so the cap sits at 8. Both encoders show the same shape independently, which makes it a property of AV1 rather than of one build.

Transparency itself survives at every speed. Comparing decoded alpha against the source pixel by pixel, no pixel became fully opaque or fully clear at any speed; the mean deviation moves from 0.021 to 0.033 out of 255 between speed 2 and speed 7, an artefact of lossy encoding rather than of speed.

## Why there is no megapixel banding

Earlier versions stepped effort *down* for large images to bound encode time, which stepped speed *up*. Once the default sits on the knee, that pushes the largest images — the ones where AVIF pays off most — past it, making them substantially larger rather than merely quicker. The banding was removed rather than re-aimed.

`dims` is still passed to drivers for third-party use; no built-in driver reads it.

Worst-case cost without banding is bounded in practice: a 299-frame animated GIF converts in under four seconds, and WordPress caps uploaded originals at 2560px on the longest edge.

## Encoders that ignore quality

Some ImageMagick builds discard the quality argument for AVIF entirely. On ImageMagick 7.1.2-27, qualities 20, 50, 80 and 95 produce byte-identical output, while `avifenc` on the same machine responds normally — so the fault is in the delegate, not the codec. Nothing the plugin can pass fixes it; `setImageCompressionQuality()`, `heic:quality` and `avif:quality` are all inert.

`Capabilities` therefore encodes its probe image at two qualities and compares the results by content hash, recording the answer per format. `Converter` skips the lower-quality retry where quality is ignored, because the retry would spend a second full encode producing an identical file that fails the same size check.

## Re-measuring

The mapping is a measurement, not a constant. If you re-derive it on other builds, the numbers to reproduce are CPU per megapixel and bytes per pixel across speeds, a perceptual metric (SSIMULACRA2 rather than PSNR — PSNR barely separates these encodes), and alpha behaviour on transparent sources, over a corpus that separates photographs, UI screenshots and transparent graphics. Note that PNG sources take the lossy AVIF path by default, since **Lossless for PNG sources** applies to WebP only.
