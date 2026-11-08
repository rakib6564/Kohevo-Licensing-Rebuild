// A Query Loop alone in a marquee Container lends the row its items, and gets them back on teardown.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect } from '@playwright/test';

const here = path.dirname(fileURLToPath(import.meta.url));
const runtime = fs.readFileSync(path.join(here, '..', '..', 'assets', 'public', 'studio-runtime.js'), 'utf8');
const items = Array.from({ length: 4 }, (_, i) => `<div class="sb-query-loop__item"><article style="width:200px;height:80px">Item ${i + 1}</article></div>`).join('');
const html = `<!doctype html><html><head><meta charset="utf-8"></head><body class="sb-body">`
  + `<div class="sb-block" data-sb-ticker="3 left" style="display:flex;gap:16px;width:600px">`
  + `<div class="sb-block sb-block--core-query-loop"><div class="sb-query-loop"><div class="sb-query-loop__grid">${items}</div></div></div>`
  + `</div></body></html>`;

test.beforeEach(async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop', 'one viewport is enough');
  await page.route('http://localhost/loop', (route) => route.fulfill({ contentType: 'text/html', body: html }));
  await page.route('http://localhost/runtime.js', (route) => route.fulfill({ contentType: 'text/javascript', body: runtime }));
});

test('the loop\'s items scroll in the row, and go back into the loop when the runtime stops', async ({ page }) => {
  await page.emulateMedia({ reducedMotion: 'no-preference' });
  await page.goto('http://localhost/loop');
  await page.addScriptTag({ url: 'http://localhost/runtime.js' });
  const track = page.locator('[data-sb-ticker] > .sb-ticker-track');
  await expect(track).toHaveCount(1);
  await expect(track.locator('.sb-query-loop__item:not([data-sb-copy])')).toHaveCount(4);
  await expect(page.locator('.sb-query-loop__grid > .sb-query-loop__item')).toHaveCount(0);
  await expect(page.locator('.sb-block--core-query-loop')).toBeHidden();
  // The row sits where the loop was, first in the Container.
  expect(await page.evaluate(() => document.querySelector('[data-sb-ticker]').firstElementChild.className)).toBe('sb-ticker-track');
  await page.evaluate(() => window.StudioRuntime.disable());
  await expect(page.locator('.sb-query-loop__grid > .sb-query-loop__item')).toHaveCount(4);
  await expect(page.locator('.sb-ticker-track')).toHaveCount(0);
  await expect(page.locator('.sb-block--core-query-loop')).toBeVisible();
  await expect(page.locator('[data-sb-copy]')).toHaveCount(0);
});

test('a widget\'s own list scrolls: its items move into the track and come back on teardown', async ({ page }) => {
  const list = '<ul class="sb-field-chips sb-field-chips--ticker" data-sb-ticker="5 left" style="display:flex;gap:20px;width:400px;overflow:hidden;list-style:none;margin:0;padding:0">'
    + ['WordPress', 'Elementor Pro', 'Crocoblock', 'Gravity Forms'].map((t) => `<li style="flex:none;white-space:nowrap">${t}</li>`).join('') + '</ul>';
  await page.route('http://localhost/chips', (route) => route.fulfill({ contentType: 'text/html', body: `<!doctype html><html><head><meta charset="utf-8"></head><body class="sb-body"><div class="sb-block sb-block--theme-field-value">${list}</div></body></html>` }));
  await page.emulateMedia({ reducedMotion: 'no-preference' });
  await page.goto('http://localhost/chips');
  await page.addScriptTag({ url: 'http://localhost/runtime.js' });
  const track = page.locator('[data-sb-ticker] > .sb-ticker-track');
  await expect(track.locator('li:not([data-sb-copy])')).toHaveCount(4);
  // The items sit in the track, so the track is the list and the <ul> only its frame: no <li> outside a list.
  await expect(track).toHaveAttribute('role', 'list');
  await expect(page.locator('ul[data-sb-ticker]')).toHaveAttribute('role', 'presentation');
  const x0 = await track.evaluate((t) => t.getBoundingClientRect().x);
  await page.waitForTimeout(700);
  const x1 = await track.evaluate((t) => t.getBoundingClientRect().x);
  expect(x1).toBeLessThan(x0);
  await page.evaluate(() => window.StudioRuntime.disable());
  await expect(page.locator('[data-sb-ticker] > li')).toHaveCount(4);
  await expect(page.locator('.sb-ticker-track')).toHaveCount(0);
  await expect(page.locator('ul[data-sb-ticker]')).not.toHaveAttribute('role', /./);
});
