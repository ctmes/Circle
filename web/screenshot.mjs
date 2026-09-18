/**
 * Drives the real UI in a headless browser: signs in, walks every Circle view,
 * and captures a screenshot of each.
 *
 * This is the check the contract test cannot make — that the islands actually
 * hydrate, the fonts load, and nothing throws in the browser.
 *
 *   node screenshot.mjs
 */
import { chromium } from "playwright";
import { mkdirSync } from "node:fs";

const WEB = process.env.WEB ?? "http://localhost:4321";
const API = process.env.API ?? "http://localhost:8000/api";
const OUT = "shots";

mkdirSync(OUT, { recursive: true });

const consoleErrors = [];
const pageErrors = [];

const browser = await chromium.launch();
const context = await browser.newContext({
  viewport: { width: 1500, height: 1000 },
  deviceScaleFactor: 2,
});
const page = await context.newPage();

page.on("console", (msg) => {
  if (msg.type() === "error") consoleErrors.push(msg.text());
});
page.on("pageerror", (e) => pageErrors.push(e.message));

async function shot(name, waitFor) {
  if (waitFor) {
    await page.waitForSelector(waitFor, { timeout: 15_000 }).catch(() => {
      console.log(`  ! "${waitFor}" never appeared on ${name}`);
    });
  }
  // Let fonts settle so the capture matches what a person sees.
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(450);
  await page.screenshot({ path: `${OUT}/${name}.png`, fullPage: true });
  console.log(`  captured ${name}`);
}

// Sign in through the real form.
await page.goto(`${WEB}/login`, { waitUntil: "networkidle" });
await shot("01-login", "form");

await page.fill('input[type="email"]', "gm@jwamats.test");
await page.fill('input[type="password"]', "correct-horse-battery");
await page.click('button[type="submit"]');
await page.waitForURL("**/circles", { timeout: 20_000 });
await shot("02-circles", "h1");

// Open the newest open Circle.
const token = await page.evaluate(() => localStorage.getItem("circle.token"));
const circles = await fetch(`${API}/circles`, {
  headers: { Authorization: `Bearer ${token}`, Accept: "application/json" },
}).then((r) => r.json());

const circle = circles.data.find((c) => !c.is_closed) ?? circles.data[0];
console.log(`  using Circle ${circle.id} (${circle.is_closed ? "closed" : "open"})`);

// A job has a screen of its own now, and it is only reachable by id — so the
// walk has to ask the plan which one to open rather than guessing a path.
const tree = await fetch(`${API}/circles/${circle.id}/goals`, {
  headers: { Authorization: `Bearer ${token}`, Accept: "application/json" },
}).then((r) => r.json());

const job = tree.data?.[0] ?? null;
console.log(job ? `  using job ${job.id} (${job.title})` : "  no jobs in this Circle");

const views = [
  // The main screen is the branch diagram now, so wait on the drawing rather
  // than on a heading.
  ["03-plan", "", "svg"],
  ["03b-tree", "/tree", "h1"],
  // Main drawn as the tree rather than the diagram. The switch remembers its
  // answer per Circle in localStorage, so the shape is set before navigating.
  ["03c-main-as-tree", "", "[role=tree]", () => ({ shape: "tree" })],
  ["03d-main-as-diagram", "", "svg", () => ({ shape: "diagram" })],
  ["04-context", "/context", "table, ul"],
  ["05-record", "/record", "section"],
  // The three routes Record replaced. They still resolve, each opening on its
  // own segment, and walking them here is what keeps that true: every link
  // already written into the product points at one of them.
  ["06-record-claims", "/claims", "section"],
  ["07-record-decisions", "/decisions", "section"],
  ["08-record-commitments", "/commitments", "section"],
  ["09-people", "/people", "table"],
  ["10-history", "/history", "section"],
  ["11-export", "/export", "section"],
];

for (const [name, path, waitFor, prefs] of views) {
  if (prefs) {
    await page.evaluate(
      ([id, p]) => localStorage.setItem(`circle.main.shape.${id}`, p.shape),
      [circle.id, prefs()],
    );
  }
  await page.goto(`${WEB}/circles/${circle.id}${path}`, { waitUntil: "networkidle" });
  await shot(name, waitFor);
}

// The job screen, and each of its four sections.
if (job) {
  await page.goto(`${WEB}/circles/${circle.id}/jobs/${job.id}`, { waitUntil: "networkidle" });
  await shot("13-job-files", "h1");

  for (const [section, name] of [
    ["Discussion", "14-job-discussion"],
    ["Record", "15-job-record"],
    ["Changes", "16-job-changes"],
  ]) {
    await page.getByRole("button", { name: new RegExp(`^${section}`) }).first().click();
    await shot(name, "section");
  }
}

// Dark theme, since the palette supports it.
await context.close();
const dark = await browser.newContext({
  viewport: { width: 1500, height: 1000 },
  deviceScaleFactor: 2,
  colorScheme: "dark",
});
const darkPage = await dark.newPage();
darkPage.on("pageerror", (e) => pageErrors.push(e.message));
await darkPage.goto(`${WEB}/login`);
// The app reads its theme from localStorage alone and never consults
// prefers-color-scheme, so the context's colorScheme did nothing here and
// this shot had been quietly capturing the light palette.
await darkPage.evaluate((t) => {
  localStorage.setItem("circle.token", t);
  localStorage.setItem("circle-theme", "dark");
}, token);
await darkPage.goto(`${WEB}/circles/${circle.id}/context`, { waitUntil: "networkidle" });
await darkPage.evaluate(() => document.fonts.ready);
await darkPage.waitForTimeout(600);
await darkPage.screenshot({ path: `${OUT}/12-context-dark.png`, fullPage: true });
console.log("  captured 12-context-dark");

await browser.close();

// Ignore the noise a dev server legitimately produces.
const realErrors = [...pageErrors, ...consoleErrors].filter(
  (e) => !/favicon|Download the React DevTools|\[vite\]/i.test(e),
);

if (realErrors.length) {
  console.log(`\n\x1b[31m${realErrors.length} browser error(s):\x1b[0m`);
  for (const e of new Set(realErrors)) console.log(`  · ${e}`);
  process.exit(1);
}

console.log("\n\x1b[32mAll views rendered with no browser errors.\x1b[0m");
