// Chapter nav (scrollspy), copy to clipboard, circular text and the picker look against the exact markup the renderer
// emits (tests/fixtures/extras.html and block-sets.html, pinned by the PHP suite) and the real public runtime.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect } from '@playwright/test';

const here = path.dirname(fileURLToPath(import.meta.url));
const markup = fs.readFileSync(path.join(here, '..', 'tests', 'fixtures', 'extras.html'), 'utf8');
const runtimePath = path.join(here, '..', '..', 'assets', 'public', 'studio-runtime.js');
const html = `<!doctype html><html><head><style>body{margin:0;font:18px/1.4 sans-serif}section{padding:40px}`
  + 'section:first-of-type{position:sticky;top:0;background:#fff;z-index:5}#story,#result{min-height:1200px}.is-current{outline:3px solid red}'
  + `</style></head><body class="sb-body">${markup}</body></html>`;

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'one viewport is enough; run once');
});

async function load(page, { reduced = false } = {}) {
  await page.emulateMedia({ reducedMotion: reduced ? 'reduce' : 'no-preference' });
  await page.setContent(html);
  await page.addScriptTag({ path: runtimePath });
}

test('a chapter nav marks the link of the section being read, and none before the first', async ({ page }) => {
  await load(page);
  const story = page.locator('[data-sb-spy] a[href="#story"]');
  const result = page.locator('[data-sb-spy] a[href="#result"]');
  await expect(story).not.toHaveAttribute('aria-current', /.*/);
  const top = (id) => page.locator(`#${id}`).evaluate((el) => el.getBoundingClientRect().top + scrollY);
  await page.evaluate((y) => scrollTo(0, y), await top('story'));
  await expect(story).toHaveAttribute('aria-current', 'location');
  await expect(story).toHaveClass(/is-current/);
  await expect(result).not.toHaveAttribute('aria-current', /.*/);
  await page.evaluate((y) => scrollTo(0, y), await top('result'));
  await expect(result).toHaveAttribute('aria-current', 'location');
  await expect(story).not.toHaveAttribute('aria-current', /.*/);
});

test('a copy button copies its text, says so for a moment and announces it, without following its link', async ({ page, context }) => {
  // The clipboard API exists only on a secure page: serve the fixture from localhost rather than about:blank.
  await context.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: 'http://localhost' });
  await page.route('http://localhost/extras', (route) => route.fulfill({ contentType: 'text/html', body: html }));
  await page.route('http://localhost/runtime.js', (route) => route.fulfill({ contentType: 'text/javascript', body: fs.readFileSync(runtimePath, 'utf8') }));
  await page.goto('http://localhost/extras');
  await page.addScriptTag({ url: 'http://localhost/runtime.js' });
  const button = page.locator('[data-sb-copy]');
  await button.click();
  await expect(button).toHaveText('Copied!');
  await expect(page.locator('.sb-sr-status')).toHaveText('Copied!');
  expect(await page.evaluate(() => navigator.clipboard.readText())).toBe('hello@example.test');
  expect(await page.evaluate(() => location.hash)).toBe('');
  await expect(button).toHaveText('Copy email', { timeout: 3000 });
});

test('circular text turns, with its words read once, and stands still for reduced motion', async ({ page }) => {
  await load(page);
  const ring = page.locator('.sb-circle-text');
  await expect(ring).toHaveAttribute('role', 'img');
  await expect(ring).toHaveAttribute('aria-label', 'Free website audit •');
  const angle = () => ring.locator('.sb-circle-text__ring').evaluate((el) => getComputedStyle(el).rotate);
  const a = await angle();
  await page.waitForTimeout(500);
  expect(await angle()).not.toBe(a);
  expect(await ring.evaluate((el) => el.getBoundingClientRect().width)).toBe(120);

  await load(page, { reduced: true });
  expect(await page.locator('.sb-circle-text__ring').evaluate((el) => getComputedStyle(el).animationName)).toBe('none');
});

test('the picker look numbers its answers and turns the chosen one\'s arrow', async ({ page }) => {
  const sets = fs.readFileSync(path.join(here, '..', 'tests', 'fixtures', 'block-sets.html'), 'utf8')
    .replace('sb-tabset sb-tabset--top', 'sb-tabset sb-tabset--left sb-tabset--list');
  await page.setContent(`<!doctype html><html><body class="sb-body">${sets}</body></html>`);
  await page.addScriptTag({ path: runtimePath });
  const tab = page.locator('.sb-tabset--list [role="tab"]');
  // The number is a counter (a browser does not report its text): check the rule and that each tab counts.
  expect(await tab.first().evaluate((el) => getComputedStyle(el, '::before').content)).toContain('counter(sb-pick');
  expect(await tab.first().evaluate((el) => getComputedStyle(el).counterIncrement)).toContain('sb-pick');
  await tab.nth(1).click();
  await expect.poll(() => tab.nth(1).evaluate((el) => getComputedStyle(el, '::after').transform)).not.toBe('none');
  await expect(page.locator('.sb-tabset--list [role="tabpanel"]:visible')).toContainText('Staging panel');
});
