// The carousel's runtime against the renderer's markup and the stylesheet's carousel rules
// (tests/fixtures/carousel.html, pinned by the PHP suite): every step brings one whole slide exactly into view.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect } from '@playwright/test';

const here = path.dirname(fileURLToPath(import.meta.url));
const markup = fs.readFileSync(path.join(here, '..', 'tests', 'fixtures', 'carousel.html'), 'utf8');
const runtimePath = path.join(here, '..', '..', 'assets', 'public', 'studio-runtime.js');

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'sizes are set per test; run once');
});

async function load(page, width, html = markup) {
  await page.setViewportSize({ width, height: 800 });
  await page.emulateMedia({ reducedMotion: 'reduce' }); // no slide transition, so positions settle at once
  // No reset stylesheet: <figure> and <blockquote> keep the browser's own 40px side margins, as on a real theme.
  await page.setContent(`<!doctype html><html><body style="margin:0;padding:0 20px">${html}</body></html>`);
  await page.addScriptTag({ path: runtimePath });
  await expect(page.locator('[data-sb-carousel]')).toHaveAttribute('data-sb-car-ready', '');
}

/** Where each slide sits relative to the carousel's visible box. */
const layout = (page) => page.evaluate(() => {
  const box = document.querySelector('[data-sb-carousel]').getBoundingClientRect();
  return [...document.querySelectorAll('[data-sb-slide]')].map((s) => {
    const r = s.getBoundingClientRect();
    return { left: Math.round(r.left - box.left), width: Math.round(r.width), box: Math.round(box.width) };
  });
});

async function expectShown(page, i, perView = 1) {
  await expect.poll(async () => (await layout(page))[i].left).toBe(0);
  const slides = await layout(page);
  const share = (slides[0].box - 16 * (perView - 1)) / perView;
  for (let k = 0; k < slides.length; k++) {
    expect(Math.abs(slides[k].width - share)).toBeLessThanOrEqual(1);
    const inside = slides[k].left >= -1 && slides[k].left + slides[k].width <= slides[k].box + 1;
    const outside = slides[k].left + slides[k].width <= 1 || slides[k].left >= slides[k].box - 1;
    expect(inside || outside, `slide ${k + 1} is either fully shown or fully hidden: ${JSON.stringify(slides[k])}`).toBe(true);
    expect(inside, `slide ${k + 1} shown`).toBe(k >= i && k < i + perView);
  }
}

for (const width of [375, 1280]) {
  test(`at ${width}px every slide lands exactly in view, through the loop and back`, async ({ page }) => {
    await load(page, width);
    await expectShown(page, 0);
    const next = page.locator('[data-sb-car-next]');
    await next.click(); await expectShown(page, 1);
    await next.click(); await expectShown(page, 2);
    await next.click(); await expectShown(page, 0); // loops round
    await page.locator('[data-sb-car-prev]').click(); await expectShown(page, 2);
    await page.locator('[data-sb-car-dot="1"]').click(); await expectShown(page, 1);
    await expect(page.locator('[data-sb-car-dot="1"]')).toHaveAttribute('aria-current', 'true');
  });
}

test('the shown slide stays in place when the window changes size', async ({ page }) => {
  await load(page, 1280);
  await page.locator('[data-sb-car-dot="2"]').click();
  await expectShown(page, 2);
  await page.setViewportSize({ width: 390, height: 800 });
  await expectShown(page, 2);
});

test('two per view: each slide takes half less the gap, and the last step shows the last two', async ({ page }) => {
  await load(page, 1280, markup.replace('data-sb-per-view="1"', 'data-sb-per-view="2"'));
  await expect(page.locator('[data-sb-car-dot]')).toHaveCount(2);
  await expectShown(page, 0, 2);
  await page.locator('[data-sb-car-next]').click();
  await expectShown(page, 1, 2);
});

test('the dots are spaced apart, not touching', async ({ page }) => {
  await load(page, 1280);
  const gaps = await page.evaluate(() => {
    const d = [...document.querySelectorAll('[data-sb-car-dot]')].map((e) => e.getBoundingClientRect());
    return d.slice(1).map((r, i) => Math.round(r.left - d[i].right));
  });
  for (const g of gaps) expect(g).toBeGreaterThanOrEqual(6);
});
