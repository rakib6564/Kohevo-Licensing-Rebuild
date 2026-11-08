// Marquee of blocks, sideways scroll and hover image against the exact markup and CSS the renderer emits
// (tests/fixtures/scroll-layout.html, pinned by the PHP suite) and the real public runtime.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect } from '@playwright/test';

const here = path.dirname(fileURLToPath(import.meta.url));
const markup = fs.readFileSync(path.join(here, '..', 'tests', 'fixtures', 'scroll-layout.html'), 'utf8');
const runtimePath = path.join(here, '..', '..', 'assets', 'public', 'studio-runtime.js');
// A picture in each hover row, as an Image block renders it (the PHP fixture has no media library to draw from).
const pixel = (c) => `data:image/svg+xml,${encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" width="40" height="30"><rect width="40" height="30" fill="${c}"/></svg>`)}`;
const html = `<!doctype html><html><head><style>body{margin:0;font:18px/1.4 sans-serif}section{padding:40px 0}`
  + '.spacer{height:700px}.sb-stack--vertical{display:flex;flex-direction:column;gap:24px}h3{margin:0;padding:20px;background:#eee}'
  + `</style></head><body class="sb-body"><div class="spacer"></div>${markup}<div class="spacer"></div></body></html>`;

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'one viewport is enough; run once');
});

async function load(page, { reduced = false } = {}) {
  await page.emulateMedia({ reducedMotion: reduced ? 'reduce' : 'no-preference' });
  await page.setContent(html);
  await page.evaluate((srcs) => {
    document.querySelectorAll('[data-sb-hover-image] > .sb-block').forEach((row, i) => {
      row.insertAdjacentHTML('beforeend', `<div class="sb-block sb-block--core-image"><img alt="" src="${srcs[i]}"></div>`);
    });
  }, ['red', 'green', 'blue'].map(pixel));
  await page.addScriptTag({ path: runtimePath });
}
const ticker = (page) => page.locator('[data-sb-ticker]');
const x = (loc) => loc.evaluate((el) => new DOMMatrix(getComputedStyle(el).transform).m41);

test('a marquee of blocks runs its children past in a loop, copies hidden from screen readers, and slows under the pointer', async ({ page }) => {
  await load(page);
  const box = ticker(page);
  await box.scrollIntoViewIfNeeded();
  const track = box.locator('> .sb-ticker-track');
  await expect(track).toHaveCount(1);
  await expect(box).toHaveClass(/is-live/);
  expect(await track.locator('> .sb-block:not([data-sb-copy])').count()).toBe(3);
  const copies = track.locator('> [data-sb-copy]');
  expect(await copies.count()).toBeGreaterThanOrEqual(3);
  expect(await copies.evaluateAll((els) => els.every((el) => el.getAttribute('aria-hidden') === 'true'))).toBe(true);
  expect(await track.evaluate((el) => el.scrollWidth)).toBeGreaterThan(await box.evaluate((el) => el.clientWidth));
  expect(await track.locator('> .sb-block').first().evaluate((el) => el.getBoundingClientRect().width)).toBe(200);

  const a = await x(track);
  await page.waitForTimeout(400);
  const b = await x(track);
  expect(b).toBeLessThan(a); // runs to the left
  const fast = a - b;
  const r = await box.boundingBox();
  await page.mouse.move(r.x + 50, r.y + r.height / 2);
  await page.waitForTimeout(800);
  const c = await x(track);
  await page.waitForTimeout(400);
  const slow = Math.abs(c - (await x(track)));
  expect(slow).toBeLessThan(fast / 2);
});

test('sideways scroll pins the section on a large screen and moves its blocks across as the page scrolls', async ({ page }) => {
  await load(page);
  const sec = page.locator('[data-sb-horizontal]');
  const track = sec.locator('.sb-hs-pin > .sb-hs-track');
  await expect(track).toHaveCount(1);
  expect(await track.locator('> .sb-block').count()).toBe(4);
  const vh = await page.evaluate(() => innerHeight);
  const tall = await sec.evaluate((el) => el.offsetHeight);
  expect(tall).toBeGreaterThan(vh + 500); // grew by the width its four 500px panels overflow

  const top = await sec.evaluate((el) => el.getBoundingClientRect().top + scrollY);
  await page.evaluate((y) => scrollTo(0, y), top);
  await page.waitForTimeout(150);
  expect(Math.round(await x(track))).toBe(0);
  await page.evaluate((y) => scrollTo(0, y), top + (tall - vh) / 2);
  await page.waitForTimeout(150);
  const pinTop = await sec.locator('.sb-hs-pin').evaluate((el) => Math.round(el.getBoundingClientRect().top));
  expect(pinTop).toBe(0); // pinned
  const mid = await x(track);
  expect(mid).toBeLessThan(-100);
  await page.evaluate((y) => scrollTo(0, y), top + tall - vh);
  await page.waitForTimeout(150);
  const end = await x(track);
  expect(end).toBeLessThan(mid);
  // The last panel's right edge reaches the frame's right edge.
  const gap = await sec.evaluate((el) => {
    const pin = el.querySelector('.sb-hs-pin').getBoundingClientRect();
    const last = el.querySelector('.sb-hs-track > .sb-block:last-child').getBoundingClientRect();
    return Math.round(pin.right - last.right);
  });
  expect(Math.abs(gap)).toBeLessThanOrEqual(1);

  await page.setViewportSize({ width: 800, height: 800 }); // below 1024px: back to the author's layout
  await expect(sec.locator('.sb-hs-pin')).toHaveCount(0);
  expect(await sec.evaluate((el) => el.style.height)).toBe('');
});

test('hover image: the rows lose their pictures and the hovered row\'s picture follows the pointer', async ({ page }) => {
  await load(page);
  const list = page.locator('[data-sb-hover-image]');
  await list.scrollIntoViewIfNeeded();
  await expect(list).toHaveClass(/is-live/);
  await expect(list.locator('.sb-block--core-image').first()).toBeHidden();
  const float = page.locator('body > .sb-hover-float');
  await expect(float).toHaveCount(1);
  await expect(float).not.toHaveClass(/is-on/);

  const row = list.locator('> .sb-block').nth(1);
  const r = await row.boundingBox();
  await page.mouse.move(r.x + 300, r.y + r.height / 2);
  await expect(float).toHaveClass(/is-on/);
  expect(await float.locator('img').getAttribute('src')).toContain('green');
  expect(await float.evaluate((el) => el.getBoundingClientRect().width)).toBe(200);
  await page.mouse.move(r.x + 500, r.y + r.height / 2, { steps: 5 });
  await page.waitForTimeout(600);
  const centre = await float.evaluate((el) => { const b = el.getBoundingClientRect(); return b.left + b.width / 2; });
  expect(Math.abs(centre - (r.x + 500))).toBeLessThan(20); // it caught up with the pointer
  await page.mouse.move(5, 5);
  await expect(float).not.toHaveClass(/is-on/);
});

test('reduced motion keeps every layout as the author made it', async ({ page }) => {
  await load(page, { reduced: true });
  await expect(page.locator('.sb-ticker-track, .sb-hs-pin, .sb-hover-float, [data-sb-copy]')).toHaveCount(0);
  await expect(page.locator('[data-sb-hover-image] .sb-block--core-image').first()).toBeVisible();
});

test('disabling the runtime puts every child back where the server rendered it', async ({ page }) => {
  await load(page);
  const before = await page.evaluate(() => [...document.querySelectorAll('[data-sb-ticker], [data-sb-horizontal]')].map((el) => [...el.children].map((c) => c.textContent.trim()).join('|')));
  await page.evaluate(() => window.StudioRuntime.disable());
  await expect(page.locator('.sb-ticker-track, .sb-hs-pin, .sb-hover-float, [data-sb-copy], .is-live')).toHaveCount(0);
  const after = await page.evaluate(() => [...document.querySelectorAll('[data-sb-ticker], [data-sb-horizontal]')].map((el) => [...el.children].map((c) => c.textContent.trim()).join('|')));
  expect(after).toEqual(['Card 1|Card 2|Card 3', 'Panel 1|Panel 2|Panel 3|Panel 4']);
  expect(before.length).toBe(2);
});
