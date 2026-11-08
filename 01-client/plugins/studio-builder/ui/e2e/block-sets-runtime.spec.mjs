// Tabs with blocks, Accordion with blocks, Timeline and Progress bars against the exact markup and CSS the renderer emits
// (tests/fixtures/block-sets.html, pinned by the PHP suite) and the real public runtime.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect } from '@playwright/test';

const here = path.dirname(fileURLToPath(import.meta.url));
const markup = fs.readFileSync(path.join(here, '..', 'tests', 'fixtures', 'block-sets.html'), 'utf8');
const runtimePath = path.join(here, '..', '..', 'assets', 'public', 'studio-runtime.js');
const html = `<!doctype html><html><head><style>body{margin:0;font:18px/1.4 sans-serif}section{padding:40px;min-height:500px}.spacer{height:900px}</style></head>`
  + `<body class="sb-body">${markup}<div class="spacer"></div></body></html>`;

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'one viewport is enough; run once');
});

async function load(page, { reduced = false } = {}) {
  await page.emulateMedia({ reducedMotion: reduced ? 'reduce' : 'no-preference' });
  await page.setContent(html);
  await page.addScriptTag({ path: runtimePath });
}
const tabs = (page) => page.locator('[data-sb-tabs] [role="tab"]');
const shown = (page) => page.locator('[data-sb-tabs] [role="tabpanel"]:visible');

test('tabs with blocks: one panel at a time, chosen by click or arrow keys', async ({ page }) => {
  await load(page);
  await expect(shown(page)).toHaveCount(1);
  await expect(shown(page)).toContainText('Audit panel');
  await tabs(page).nth(2).click();
  await expect(shown(page)).toContainText('Launch panel');
  await expect(tabs(page).nth(2)).toHaveAttribute('aria-selected', 'true');
  await page.keyboard.press('ArrowRight');
  await expect(tabs(page).nth(0)).toBeFocused();
  await expect(shown(page)).toContainText('Audit panel');
  // The tab block's wrapper goes with its panel.
  expect(await page.locator('.sb-block--layout-tab:visible').count()).toBe(1);
});

test('tabs with blocks turn by themselves while seen, fill each tab\'s bar, and hold under the pointer', async ({ page }) => {
  await load(page);
  await page.waitForTimeout(1000);
  const fill = await tabs(page).nth(0).locator('.sb-tabset__bar > i').evaluate((el) => new DOMMatrix(getComputedStyle(el).transform).a);
  expect(fill).toBeGreaterThan(0.3);
  expect(fill).toBeLessThan(0.8);
  await expect(tabs(page).nth(1)).toHaveAttribute('aria-selected', 'true', { timeout: 2000 });

  const box = await page.locator('[data-sb-tabs]').boundingBox();
  await page.mouse.move(box.x + 20, box.y + 20);
  await page.waitForTimeout(2600);
  await expect(tabs(page).nth(1)).toHaveAttribute('aria-selected', 'true'); // held
  await page.mouse.move(box.x + 20, box.y + box.height + 200);
  await expect(tabs(page).nth(2)).toHaveAttribute('aria-selected', 'true', { timeout: 3000 });
});

test('accordion with blocks: rows start as the server set them and open one at a time', async ({ page }) => {
  await load(page);
  const buttons = page.locator('[data-sb-accordion] [data-sb-accordion-button]');
  const panels = page.locator('[data-sb-accordion] [data-sb-accordion-panel]');
  await expect(buttons.nth(0)).toHaveAttribute('aria-expanded', 'false');
  await expect(buttons.nth(1)).toHaveAttribute('aria-expanded', 'true');
  await expect(panels.nth(1)).toBeVisible();
  await buttons.nth(0).click();
  await expect(panels.nth(0)).toBeVisible();
  await expect(panels.nth(0)).toContainText('One');
  await expect(buttons.nth(1)).toHaveAttribute('aria-expanded', 'false');
  await expect(panels.nth(1)).toBeHidden();
});

test('the timeline fills with the scroll and marks the steps it passes', async ({ page }) => {
  await load(page);
  const tl = page.locator('[data-sb-timeline]');
  const p = () => tl.evaluate((el) => Number(el.style.getPropertyValue('--sb-tl-p')));
  const passed = () => tl.locator('.sb-timeline__steps > .sb-block.is-passed').count();
  const top = await tl.evaluate((el) => el.getBoundingClientRect().top + scrollY);
  const vh = await page.evaluate(() => innerHeight);
  await page.evaluate((y) => scrollTo(0, y), top - vh);
  await page.waitForTimeout(150);
  expect(await p()).toBe(0);
  expect(await passed()).toBe(0);
  await page.evaluate((y) => scrollTo(0, y), top - vh * 0.6);
  await page.waitForTimeout(150);
  expect(await p()).toBeGreaterThan(0);
  expect(await passed()).toBeGreaterThan(0);
  await page.evaluate((y) => scrollTo(0, y), top + 400);
  await page.waitForTimeout(150);
  expect(await p()).toBe(1);
  expect(await passed()).toBe(4);
  await expect(tl.locator('.sb-timeline__steps > .sb-block').first()).toHaveCSS('margin-left', '56px');
});

test('progress bars wait empty until seen, then grow to their values', async ({ page }) => {
  await load(page);
  const bars = page.locator('[data-sb-progress-bars]');
  const scale = () => bars.locator('.sb-progress__bar').first().evaluate((el) => new DOMMatrix(getComputedStyle(el).transform).a);
  await expect(bars).not.toHaveClass(/is-in/);
  expect(await scale()).toBe(0);
  await bars.scrollIntoViewIfNeeded();
  await expect(bars).toHaveClass(/is-in/);
  await expect.poll(scale, { timeout: 3000 }).toBeCloseTo(0.9, 2);
});

test('reduced motion: no autoplay, the timeline full, the bars at their values', async ({ page }) => {
  await load(page, { reduced: true });
  await page.waitForTimeout(2600);
  await expect(tabs(page).nth(0)).toHaveAttribute('aria-selected', 'true');
  expect(await page.locator('[data-sb-timeline]').evaluate((el) => el.style.getPropertyValue('--sb-tl-p'))).toBe('1');
  await expect(page.locator('[data-sb-progress-bars]')).toHaveClass(/is-in/);
});
