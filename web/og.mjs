/**
 * Generates public/og.png — the 1200×630 card used for Open Graph and Twitter.
 *
 * It is rendered from markup rather than drawn in a design tool so that it uses
 * the same tokens as the site: the same off-white canvas, the same hairline
 * grid, the same accent, the same editorial face. Re-run it whenever the
 * headline or the palette changes.
 *
 *   node og.mjs
 *
 * Requires a network connection the first time, to fetch the webfont. If the
 * font cannot be reached the card still renders, in the local serif fallback.
 */
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
const out = resolve(here, "public/og.png");

const html = `<!doctype html>
<html>
  <head>
    <meta charset="utf-8" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400..700&family=Instrument+Serif&family=JetBrains+Mono:wght@500&display=swap"
      rel="stylesheet"
    />
    <style>
      :root {
        --canvas: #fbfbfd;
        --ink: #1c1c1e;
        --ink-muted: #55555c;
        --ink-faint: #76767e;
        --rule: #ebebef;
        --accent: #0071e3;
        --grid: rgba(28, 28, 30, 0.045);
      }

      * { margin: 0; padding: 0; box-sizing: border-box; }

      body {
        width: 1200px;
        height: 630px;
        background: var(--canvas);
        font-family: "Inter", -apple-system, sans-serif;
        color: var(--ink);
        position: relative;
        overflow: hidden;
      }

      /* The same drawing-sheet ground as the site hero, at the same pitch. */
      .grid {
        position: absolute;
        inset: 0;
        background-image:
          linear-gradient(to right, var(--grid) 1px, transparent 1px),
          linear-gradient(to bottom, var(--grid) 1px, transparent 1px);
        background-size: 88px 88px;
        -webkit-mask-image: radial-gradient(
          ellipse 78% 70% at 62% 8%, #000 0%, transparent 76%
        );
      }

      .frame {
        position: relative;
        height: 100%;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        padding: 60px 76px 0;
      }

      .mark {
        display: flex;
        align-items: center;
        gap: 14px;
      }

      .wordmark {
        font-size: 27px;
        font-weight: 650;
        letter-spacing: -0.021em;
      }

      h1 {
        font-family: "Instrument Serif", "Iowan Old Style", Georgia, serif;
        font-weight: 400;
        font-size: 61px;
        line-height: 1.04;
        letter-spacing: -0.014em;
        max-width: 20ch;
        margin-top: 40px;
      }

      h1 em { font-style: italic; color: var(--ink-muted); }

      p.sub {
        margin-top: 24px;
        font-size: 20px;
        line-height: 1.5;
        letter-spacing: -0.006em;
        color: var(--ink-muted);
        max-width: 58ch;
      }

      /* A title-block strip, the way the app frames a controlled document. */
      .titleblock {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        border-top: 1px solid var(--rule);
        margin: 0 -76px;
        padding: 0 76px;
      }

      /* Child combinator, not a descendant selector: the cells contain divs of
         their own, and matching those gave every label its own 92px flex box. */
      .titleblock > div {
        height: 92px;
        display: flex;
        flex-direction: column;
        justify-content: center;
      }

      .titleblock > div + div {
        border-left: 1px solid var(--rule);
        padding-left: 28px;
      }

      .label {
        font-size: 12px;
        font-weight: 590;
        color: var(--ink-faint);
      }

      .value {
        margin-top: 6px;
        font-family: "JetBrains Mono", ui-monospace, monospace;
        font-size: 14px;
        font-weight: 500;
        color: var(--ink);
      }
    </style>
  </head>
  <body>
    <div class="grid"></div>

    <div class="frame">
      <div>
        <div class="mark">
          <svg width="38" height="38" viewBox="0 0 32 32">
            <circle cx="16" cy="16" r="12" fill="none" stroke="#1c1c1e" stroke-width="2.25" />
            <circle cx="16" cy="16" r="4.5" fill="#0071e3" />
          </svg>
          <span class="wordmark">Circle</span>
        </div>

        <h1>When it is contested six months from now, <em>the record answers.</em></h1>

        <p class="sub">
          A temporary, cross-company workspace for one consequential project.
          Every claim cites its evidence. Every agent action names the party that
          authorised it.
        </p>
      </div>

      <div class="titleblock">
        <div>
          <div class="label">Origin</div>
          <div class="value">authenticated upload</div>
        </div>
        <div>
          <div class="label">Integrity</div>
          <div class="value">intact</div>
        </div>
        <div>
          <div class="label">Review</div>
          <div class="value">unreviewed</div>
        </div>
      </div>
    </div>
  </body>
</html>`;

const browser = await chromium.launch();
const page = await browser.newPage({
  viewport: { width: 1200, height: 630 },
  deviceScaleFactor: 1,
});

await page.setContent(html, { waitUntil: "networkidle" });
/* Webfonts can resolve a beat after networkidle; waiting on the font loading
   API is the difference between a serif card and a fallback one. */
await page.evaluate(() => document.fonts.ready);

await mkdir(dirname(out), { recursive: true });
await page.screenshot({ path: out });
await browser.close();

console.log(`Wrote ${out}`);
