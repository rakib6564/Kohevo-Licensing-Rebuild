// The page-level motion (page colours, adapt, progress bar, text effects, rotating words, stagger, "scroll into view"
// without scroll timelines, glow, magnet, smooth scrolling) against the exact markup and CSS the renderer emits
// (tests/fixtures/page-motion.html, pinned by the PHP suite) and the real public runtime.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect } from '@playwright/test';

const here = path.dirname(fileURLToPath(import.meta.url));
const markup = fs.readFileSync(path.join(here, '..', 'tests', 'fixtures', 'page-motion.html'), 'utf8');
const runtimePath = path.join(here, '..', '..', 'assets', 'public', 'studio-runtime.js');
// Sections a screen tall, so each one in turn crosses the middle of the screen.
const html = (smooth) => `<!doctype html><html${smooth ? ' data-sb-smooth' : ''}><head><style>body{margin:0;font:20px/1.4 sans-serif}`
  + 'section{min-height:900px;padding:40px}section:first-of-type{min-height:80px;padding:10px 40px}.sb-block--core-card{padding:40px;width:300px}'
  + `</style></head><body class="sb-body">${markup}</body></html>`;

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'one viewport is enough; run once');
});

async function load(page, { reduced = false, smooth = false } = {}) {
  await page.emulateMedia({ reducedMotion: reduced ? 'reduce' : 'no-preference' });
  await page.setContent(html(smooth));
  // A browser without scroll timelines (Firefox, older Safari): the runtime must hold "scroll into view" itself.
  await page.evaluate(() => {
    const supports = CSS.supports.bind(CSS);
    CSS.supports = (...args) => (/animation-timeline/.test(args.join(':')) ? false : supports(...args));
  });
  await page.addScriptTag({ path: runtimePath });
}
const scrollTo = async (page, y) => {
  await page.evaluate((y) => window.scrollTo(0, y), y);
  await page.waitForTimeout(150);
};
const sectionTop = (page, n) => page.locator('section').nth(n).evaluate((el) => el.getBoundingClientRect().top + window.scrollY);
const htmlVar = (page, name) => page.evaluate((n) => document.documentElement.style.getPropertyValue(n), name);

test('page colours follow the section in the middle of the screen, and a header takes the colours beneath it', async ({ page }) => {
  // A screen so short its middle is still in the header: above the first coloured section, the page keeps its own colours.
  await page.setViewportSize({ width: 1280, height: 120 });
  await load(page);
  expect(await htmlVar(page, '--sb-paged-bg')).toBe('');
  await page.setViewportSize({ width: 1280, height: 800 });
  await page.waitForTimeout(150);
  expect(await htmlVar(page, '--sb-paged-bg')).toBe('#0b0b0c'); // now the first section crosses the middle
  const header = page.locator('section').first();

  await scrollTo(page, await sectionTop(page, 1));
  expect(await htmlVar(page, '--sb-paged-bg')).toBe('#0b0b0c');
  expect(await htmlVar(page, '--sb-paged-fg')).toBe('#ede8df');
  await expect(page.locator('body')).toHaveCSS('background-color', 'rgb(11, 11, 12)', { timeout: 2000 });
  await expect(header).toHaveCSS('background-color', 'rgb(11, 11, 12)', { timeout: 2000 });
  // The header carries the theme colours of what is beneath it too, so a logo or link on theme colours follows.
  expect(await header.evaluate((el) => el.style.getPropertyValue('--sb-text-body'))).toBe('#ede8df');

  await scrollTo(page, await sectionTop(page, 2));
  expect(await htmlVar(page, '--sb-paged-bg')).toBe('#ede8df');
  await expect(page.locator('body')).toHaveCSS('color', 'rgb(11, 11, 12)', { timeout: 2000 });

  await scrollTo(page, await sectionTop(page, 4)); // past the last coloured one: it keeps the last colours
  expect(await htmlVar(page, '--sb-paged-bg')).toBe('#ede8df');
  await scrollTo(page, 0);
  expect(await htmlVar(page, '--sb-paged-bg')).toBe('#0b0b0c');
});

test('a coloured section off the screen paints its own colours, and follows the page once scrolled near it', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 800 });
  await load(page);
  const light = page.locator('section').nth(2);
  // Far below the screen: its own background and text, so it reads right whenever it is read.
  await expect(light).not.toHaveAttribute('data-sb-page-on', '');
  await expect(light).toHaveCSS('background-color', 'rgb(237, 232, 223)');
  // Scrolled until it shows at the bottom (the dark section still gives the colours): it lets the page's show.
  await scrollTo(page, (await sectionTop(page, 2)) - 600);
  await expect(light).toHaveAttribute('data-sb-page-on', '', { timeout: 2000 });
  await expect(light).toHaveCSS('background-color', 'rgba(0, 0, 0, 0)');
  // Back far above it: its own colours again.
  await scrollTo(page, 0);
  await expect(light).not.toHaveAttribute('data-sb-page-on', '', { timeout: 2000 });
});

test('a section already in view when the page opens keeps its own colours until it gives the page its colours', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 800 });
  await page.emulateMedia({ reducedMotion: 'no-preference' });
  await page.setContent(html(false));
  // Open the page scrolled so the light section shows at the bottom while the dark one gives the colours.
  await page.evaluate((y) => window.scrollTo(0, y), (await sectionTop(page, 2)) - 600);
  await page.addScriptTag({ path: runtimePath });
  await page.waitForTimeout(300);
  const light = page.locator('section').nth(2);
  expect(await htmlVar(page, '--sb-paged-bg')).toBe('#0b0b0c');
  await expect(light).not.toHaveAttribute('data-sb-page-on', '');
  await expect(light).toHaveCSS('background-color', 'rgb(237, 232, 223)');
  // Once it crosses the middle and the page has blended to its colours, it follows the page.
  await scrollTo(page, await sectionTop(page, 2));
  await expect(light).toHaveAttribute('data-sb-page-on', '', { timeout: 2000 });
});

test('the progress bar fills with the scroll', async ({ page }) => {
  await load(page);
  const bar = page.locator('section').first().locator('> .sb-progress-bar');
  await expect(bar).toHaveCount(1);
  await expect(bar).toHaveAttribute('aria-hidden', 'true');
  expect(Number(await htmlVar(page, '--sb-page-progress'))).toBe(0);
  const max = await page.evaluate(() => document.documentElement.scrollHeight - innerHeight);
  await scrollTo(page, max / 2);
  expect(Number(await htmlVar(page, '--sb-page-progress'))).toBeCloseTo(0.5, 1);
  await scrollTo(page, max);
  expect(Number(await htmlVar(page, '--sb-page-progress'))).toBe(1);
});

test('a heading rises line by line, then its text is put back as it was', async ({ page }) => {
  await load(page);
  const heading = page.locator('[data-sb-text^="lines"]');
  const text = 'Every line of this heading rises into place';
  await expect(heading).toHaveAttribute('data-sb-split', '');
  // It is on screen at load, so it rises at once; the words sit in their masks meanwhile.
  await expect(heading).toHaveAttribute('data-sb-lines-in', '', { timeout: 1000 });
  expect(await heading.locator('.sb-wm > .sb-w').count()).toBe(text.split(' ').length);
  await expect(heading.locator('.sb-wm')).toHaveCount(0, { timeout: 3000 });
  await expect(heading.locator('h1')).toHaveText(text);
});

test('words light up as the text crosses the screen', async ({ page }) => {
  await load(page);
  const words = page.locator('[data-sb-text^="words"] .sb-w');
  const unlit = () => words.evaluateAll((ws) => ws.filter((w) => w.style.opacity === '0.2').length);
  expect(await words.count()).toBeGreaterThan(10);
  expect(await unlit()).toBe(await words.count()); // below the screen: all dim
  const top = await sectionTop(page, 2);
  await scrollTo(page, top - 500);
  const some = await unlit();
  expect(some).toBeGreaterThan(0);
  expect(some).toBeLessThan(await words.count());
  await scrollTo(page, top + 400);
  expect(await unlit()).toBe(0);
});

test('the coloured part of a Dual Color Heading changes to its other words in turn', async ({ page }) => {
  await load(page);
  const part = page.locator('[data-sb-rotate]');
  await expect(part).toHaveText('builders');
  await expect(part).toHaveText('cleaners', { timeout: 2500 });
  await expect(part).toHaveText('hosts', { timeout: 2500 });
});

test('a stagger group plays its entrances in turn once it is seen, and a lone "scroll into view" block waits until seen', async ({ page }) => {
  await load(page);
  const group = page.locator('[data-sb-stagger]');
  const items = group.locator('[class*="sb-animate-"]');
  expect(await items.evaluateAll((els) => els.map((el) => el.style.getPropertyValue('--i')))).toEqual(['0', '1', '2']);
  expect(await items.first().evaluate((el) => getComputedStyle(el).animationPlayState)).toBe('paused');
  const lone = page.locator('section').nth(4).locator('[class*="sb-animate-"]');
  await expect(lone).toHaveAttribute('data-sb-wait', '');

  await scrollTo(page, await sectionTop(page, 3));
  await expect(group).toHaveClass(/is-in/);
  expect(await items.nth(2).evaluate((el) => getComputedStyle(el).animationDelay)).toBe('0.2s');
  await expect(items.nth(2)).toHaveCSS('opacity', '1', { timeout: 2000 });

  await scrollTo(page, await sectionTop(page, 4));
  await expect(lone).not.toHaveAttribute('data-sb-wait', '');
  await expect(lone).toHaveCSS('opacity', '1', { timeout: 2000 });
});

test('the glow follows the pointer over its block, and a magnetic button leans towards the pointer', async ({ page }) => {
  await load(page);
  const card = page.locator('[data-sb-glow]');
  await card.evaluate((el) => el.scrollIntoView({ block: 'center' })); // clear of the sticky header
  await page.waitForTimeout(150);
  await expect(card.locator('> .sb-glow')).toHaveCount(1);
  const box = await card.boundingBox();
  await page.mouse.move(box.x + 30, box.y + 20);
  await expect.poll(() => card.evaluate((el) => el.style.getPropertyValue('--sb-mx'))).toBe('30px');
  await expect(card.locator('> .sb-glow')).toHaveCSS('opacity', '1', { timeout: 1000 });

  const button = page.locator('[data-sb-mouse]');
  const b = await button.boundingBox();
  await page.mouse.move(b.x + b.width + 10, b.y + b.height / 2);
  await expect.poll(() => button.evaluate((el) => parseFloat(el.style.translate) || 0)).toBeGreaterThan(2);
  await page.mouse.move(5, 5);
  await expect.poll(() => button.evaluate((el) => el.style.translate)).toBe('');
});

test('smooth scrolling eases a wheel turn instead of jumping, and only when the site turns it on', async ({ page }) => {
  await load(page, { smooth: true });
  await page.mouse.move(400, 300);
  await page.mouse.wheel(0, 600);
  await page.waitForTimeout(60);
  const early = await page.evaluate(() => window.scrollY);
  expect(early).toBeGreaterThan(0);
  expect(early).toBeLessThan(600);
  await expect.poll(() => page.evaluate(() => window.scrollY), { timeout: 3000 }).toBe(600);

  await load(page);
  await page.mouse.wheel(0, 600);
  await expect.poll(() => page.evaluate(() => window.scrollY)).toBe(600);
});

test('reduced motion: no text effect, no rotation, nothing waits, and the colours still follow', async ({ page }) => {
  await load(page, { reduced: true, smooth: true });
  await expect(page.locator('[data-sb-text]').first()).not.toHaveAttribute('data-sb-split', '');
  await expect(page.locator('.sb-w')).toHaveCount(0);
  await page.waitForTimeout(1300);
  await expect(page.locator('[data-sb-rotate]')).toHaveText('builders');
  await expect(page.locator('[data-sb-wait]')).toHaveCount(0);
  await expect(page.locator('.sb-glow')).toHaveCount(0);
  await scrollTo(page, await sectionTop(page, 1));
  expect(await htmlVar(page, '--sb-paged-bg')).toBe('#0b0b0c');
});

test('disabling the runtime puts the page back as the server rendered it', async ({ page }) => {
  await load(page);
  await page.locator('[data-sb-text^="words"]').scrollIntoViewIfNeeded();
  await page.evaluate(() => window.StudioRuntime.disable());
  for (const sel of ['.sb-w', '.sb-wm', '.sb-progress-bar', '.sb-glow', '[data-sb-split]', '[data-sb-wait]', 'html[data-sb-page-colors]']) {
    await expect(page.locator(sel)).toHaveCount(0);
  }
  await expect(page.locator('[data-sb-rotate]')).toHaveText('builders');
  expect(await htmlVar(page, '--sb-paged-bg')).toBe('');
});
