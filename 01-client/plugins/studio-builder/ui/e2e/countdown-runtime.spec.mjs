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

const evergreen = (extra = '') => markup
  .replace('data-sb-countdown="2029-12-31T23:00:00Z"', `data-sb-countdown="" data-sb-countdown-evergreen="3660" data-sb-countdown-key="blk_e" ${extra}`);

async function loadHtml(page, html, nowIso = '2029-12-31T12:00:00Z') {
  await page.clock.install({ time: new Date(nowIso) });
  await page.goto('https://sb.test/start');
  await page.setContent(`<!doctype html><html><body>${html}</body></html>`);
  await page.addScriptTag({ path: runtimePath });
}

test.describe('countdown options', () => {
  test.beforeEach(async ({ page }) => {
    await page.route('https://sb.test/**', (route) => route.fulfill({ contentType: 'text/html', body: '<!doctype html><title>page</title>' }));
  });

  test('an evergreen timer starts on the first view and carries on after a reload', async ({ page }) => {
    await loadHtml(page, evergreen());
    expect([await num(page, 'h'), await num(page, 'm')]).toEqual(['01', '00']);
    await page.clock.runFor(30 * 60 * 1000);
    await page.setContent(`<!doctype html><html><body>${evergreen()}</body></html>`);
    await page.addScriptTag({ path: runtimePath });
    expect([await num(page, 'h'), await num(page, 'm')]).toEqual(['00', '30']);
  });

  test('a new evergreen length restarts the timer', async ({ page }) => {
    await loadHtml(page, evergreen());
    await page.clock.runFor(30 * 60 * 1000);
    const longer = evergreen().replace('"3660"', '"7260"');
    await page.setContent(`<!doctype html><html><body>${longer}</body></html>`);
    await page.addScriptTag({ path: runtimePath });
    expect([await num(page, 'h'), await num(page, 'm')]).toEqual(['02', '01']);
  });

  test('a hidden unit passes its time down', async ({ page }) => {
    const html = markup.replace(/<div class="sb-countdown__unit"><span class="sb-countdown__num" data-sb-cd="d">.*?<\/div>/, '');
    await loadHtml(page, html, '2029-12-29T22:58:30Z');
    expect(await num(page, 'h')).toBe('48');
  });

  test('hide removes the countdown when it ends', async ({ page }) => {
    const html = markup.replace('data-sb-countdown="', 'data-sb-countdown-expire="hide" data-sb-countdown="');
    await loadHtml(page, html, '2030-01-01T00:00:00Z');
    await expect(page.locator('.sb-countdown')).toBeHidden();
  });

  test('redirect goes to the page set, and never to the page it is on', async ({ page }) => {
    const html = (to) => markup.replace('data-sb-countdown="', `data-sb-countdown-expire="redirect" data-sb-href="${to}" data-sb-countdown="`);
    await loadHtml(page, html('/start'), '2030-01-01T00:00:00Z');
    await expect(page.locator('.sb-countdown')).toHaveAttribute('data-sb-finished', '');
    expect(page.url()).toBe('https://sb.test/start');

    await page.setContent(`<!doctype html><html><body>${html('/merci/')}</body></html>`);
    await page.addScriptTag({ path: runtimePath });
    await page.waitForURL('https://sb.test/merci/');
  });
});
