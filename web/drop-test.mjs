/**
 * Drives the drop gesture itself, in a real browser.
 *
 * The screenshot walk proves the job screen renders; this proves the thing the
 * screen is for. A file is dropped onto a row of the plan and onto the job's
 * own panel, and both are checked the only way that means anything — by asking
 * the API afterwards what is actually filed against that node.
 *
 *   node drop-test.mjs
 */
import { chromium } from "playwright";

const WEB = process.env.WEB ?? "http://localhost:4321";
const API = process.env.API ?? "http://localhost:8000/api";

const errors = [];
let failures = 0;

const ok = (m) => console.log(`  \x1b[32mPASS\x1b[0m ${m}`);
const bad = (m) => {
  failures++;
  console.log(`  \x1b[31mFAIL\x1b[0m ${m}`);
};

const browser = await chromium.launch();
const context = await browser.newContext({ viewport: { width: 1500, height: 1000 } });
const page = await context.newPage();
page.on("pageerror", (e) => errors.push(e.message));
page.on("console", (m) => m.type() === "error" && errors.push(m.text()));

await page.goto(`${WEB}/login`);
await page.fill('input[type="email"]', "gm@jwamats.test");
await page.fill('input[type="password"]', "correct-horse-battery");
await page.click('button[type="submit"]');
await page.waitForURL("**/circles", { timeout: 20_000 });

const token = await page.evaluate(() => localStorage.getItem("circle.token"));

const call = (path) =>
  fetch(API + path, {
    headers: { Authorization: `Bearer ${token}`, Accept: "application/json" },
  }).then((r) => r.json());

// A Circle with a plan to drop onto.
const circles = await call("/circles");
let circle = null;
let goals = [];

for (const c of circles.data.filter((c) => !c.is_closed)) {
  const tree = await call(`/circles/${c.id}/goals`);
  if ((tree.data ?? []).length > 0) {
    circle = c;
    goals = tree.data;
    break;
  }
}

if (!circle) {
  console.log("No open Circle with a plan — seed one first.");
  process.exit(1);
}

console.log(`Using Circle ${circle.id} (${circle.name})`);

/**
 * Build a synthetic drop carrying one file, the way a desktop drag does.
 *
 * The target is resolved to a handle once rather than re-queried per event.
 * A drop zone that says "Release to file it here" the moment a drag enters it
 * is the whole point of the feature, and re-matching on its text between
 * dragenter and drop finds nothing.
 */
async function dropFile(selector, filename, body) {
  const target = await page.waitForSelector(selector, { timeout: 15_000 });

  const transfer = await page.evaluateHandle(
    ([name, text]) => {
      const dt = new DataTransfer();
      dt.items.add(new File([text], name, { type: "text/plain" }));
      return dt;
    },
    [filename, body],
  );

  await target.dispatchEvent("dragenter", { dataTransfer: transfer });
  await target.dispatchEvent("dragover", { dataTransfer: transfer });
  await target.dispatchEvent("drop", { dataTransfer: transfer });
}

/** What the API says is filed against a node. */
const filedOn = async (goalId) => (await call(`/goals/${goalId}/evidence`)).data ?? [];

// ------------------------------------------------ 1. the job's own panel
const job = goals[0];
const stamp = Date.now();
const onPanel = `note-on-panel-${stamp}.txt`;

await page.goto(`${WEB}/circles/${circle.id}/jobs/${job.id}`, { waitUntil: "networkidle" });
await page.waitForSelector("text=Drop files or a folder onto this job", { timeout: 15_000 });

const before = await filedOn(job.id);

await dropFile('div[role="button"]:has-text("Drop files or a folder onto this job")', onPanel, "hello");

await page
  .waitForSelector(`text=${onPanel}`, { timeout: 25_000 })
  .then(() => ok(`the dropped file appears on the job screen (${onPanel})`))
  .catch(() => bad("the dropped file never appeared on the job screen"));

const after = await filedOn(job.id);

if (after.length === before.length + 1) {
  ok(`the API agrees: ${before.length} filed before, ${after.length} after`);
} else {
  bad(`the API disagrees: ${before.length} before, ${after.length} after`);
}

const landed = after.find((f) => f.current_version?.original_filename === onPanel);
if (landed) {
  ok(`it is filed by name and hashed (${landed.origin_status}, filed by ${landed.filed.name})`);
} else {
  bad("the file is not in the job's list by name");
}

// ------------------------------------------------- 2. unfiling it again
await page.click(`li:has-text("${onPanel}") >> button:has-text("Unfile")`);
await page.waitForTimeout(1500);

const afterUnfile = await filedOn(job.id);
if (afterUnfile.length === before.length) {
  ok("unfiling removes it from the job");
} else {
  bad(`unfiling left ${afterUnfile.length} filed, expected ${before.length}`);
}

const vault = await call(`/circles/${circle.id}/evidence`);
if ((vault.data ?? []).some((i) => i.current_version?.original_filename === onPanel)) {
  ok("and leaves it in the Circle's vault, as promised");
} else {
  bad("unfiling appears to have removed it from the vault too");
}

// ------------------------------------------- 3. dropping onto a tree row
const onRow = `note-on-row-${stamp}.txt`;

await page.evaluate(
  ([id]) => localStorage.setItem(`circle.main.shape.${id}`, "tree"),
  [circle.id],
);
await page.goto(`${WEB}/circles/${circle.id}`, { waitUntil: "networkidle" });
await page.waitForSelector("[role=tree]", { timeout: 15_000 });

const rowBefore = await filedOn(job.id);

await dropFile(`[role=treeitem][id="${job.id}"] > div`, onRow, "dropped on a row");

// The row shows no list, so this one is checked against the API alone.
let rowAfter = rowBefore;
for (let i = 0; i < 25 && rowAfter.length === rowBefore.length; i++) {
  await page.waitForTimeout(1000);
  rowAfter = await filedOn(job.id);
}

if (rowAfter.length === rowBefore.length + 1) {
  ok(`dropping on a row in the plan files it against that job (${onRow})`);
} else {
  bad("dropping on a tree row filed nothing");
}

/*
  Unfile what this run put there, so running it twice does not leave a job
  carrying a column of test notes. The items stay in the vault either way —
  nothing in this product destroys evidence, this script included.
*/
for (const stray of rowAfter.filter((f) => f.current_version?.original_filename === onRow)) {
  await fetch(`${API}/goals/${job.id}/evidence/${stray.id}`, {
    method: "DELETE",
    headers: { Authorization: `Bearer ${token}`, Accept: "application/json" },
  });
}

await browser.close();

const real = errors.filter((e) => !/favicon|React DevTools|\[vite\]/i.test(e));
if (real.length) {
  console.log(`\n\x1b[31m${real.length} browser error(s):\x1b[0m`);
  for (const e of new Set(real)) console.log(`  · ${e}`);
}

if (failures || real.length) process.exit(1);
console.log("\n\x1b[32mDropping files onto a job works end to end.\x1b[0m");
