// The countdown element's runtime behaviour (B2-P2b), against the exact markup the renderer emits
// (tests/fixtures/countdown.html, pinned by the PHP suite) and the real public runtime.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect } from '@playwright/test';

const here = path.dirname(fileURLToPath(import.meta.url));
const markup = fs.readFileSync(path.join(here, '..', 'tests', 'fixtures', 'countdown.html'), 'utf8');
const runtimePath = path.join(here, '..', '..', 'assets', 'public', 'studio-runtime.js');

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'no layout involved; run once');
});

async function load(page, nowIso) {
  await page.clock.install({ time: new Date(nowIso) });
  await page.setContent(`<!doctype html><html><body>${markup}</body></html>`);
  await page.addScriptTag({ path: runtimePath });
  await page.evaluate(() => window.StudioRuntime && window.StudioRuntime.mount && 0);
}

const num = (page, k) => page.locator(`[data-sb-cd="${k}"]`).textContent();

test('counts down to the target, marks itself live, and finishes with the done state', async ({ page }) => {
  await load(page, '2029-12-31T21:58:29.500Z'); // 1 h 1 min 30.5 s before the target (the half second absorbs load time)
  const el = page.locator('.sb-countdown');
  await expect(el).toHaveAttribute('data-sb-live', '');
  expect([await num(page, 'd'), await num(page, 'h'), await num(page, 'm'), await num(page, 's')]).toEqual(['00', '01', '01', '30']);

  await page.clock.runFor(1000);
  expect(await num(page, 's')).toBe('29');

  await page.clock.fastForward(61 * 60 * 1000 + 30 * 1000); // jump past the target; the next tick finishes it
  await page.clock.runFor(1000);
  await expect(el).toHaveAttribute('data-sb-finished', '');
  expect([await num(page, 'd'), await num(page, 'h'), await num(page, 'm'), await num(page, 's')]).toEqual(['00', '00', '00', '00']);
});

test('an invalid target leaves the static date line alone', async ({ page }) => {
  await page.clock.install({ time: new Date('2029-12-31T21:58:30Z') });
  await page.setContent(`<!doctype html><html><body>${markup.replace('data-sb-countdown="2029-12-31T23:00:00Z"', 'data-sb-countdown="nonsense"')}</body></html>`);
  await page.addScriptTag({ path: runtimePath });
  await expect(page.locator('.sb-countdown')).not.toHaveAttribute('data-sb-live', '');
  await expect(page.locator('.sb-countdown__date')).toContainText('2029-12-31 23:00 UTC');
});

test('disabling the runtime puts the server markup back', async ({ page }) => {
  await load(page, '2029-12-31T21:58:30Z');
  await expect(page.locator('.sb-countdown')).toHaveAttribute('data-sb-live', '');
  const ok = await page.evaluate(() => { if (!window.StudioRuntime || !window.StudioRuntime.disable) return false; window.StudioRuntime.disable(); return true; });
  expect(ok).toBe(true);
  await expect(page.locator('.sb-countdown')).not.toHaveAttribute('data-sb-live', '');
  expect(await num(page, 'h')).toBe('00');
  expect(await page.locator('.sb-countdown').evaluate((n) => n.outerHTML.trim())).toBe(markup.trim());
});
