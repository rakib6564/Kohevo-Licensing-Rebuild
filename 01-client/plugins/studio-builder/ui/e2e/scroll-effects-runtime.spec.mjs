// Sticky and parallax (ScrollEffects) against the exact markup and CSS the renderer emits
// (tests/fixtures/scroll-effects.html, pinned by the PHP suite) and the real public runtime.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect } from '@playwright/test';

const here = path.dirname(fileURLToPath(import.meta.url));
const markup = fs.readFileSync(path.join(here, '..', 'tests', 'fixtures', 'scroll-effects.html'), 'utf8');
const runtimePath = path.join(here, '..', '..', 'assets', 'public', 'studio-runtime.js');
// Room to scroll: the parallax section is tall, and the page below it longer still.
const page_ = `<!doctype html><html><head><style>body{margin:0}.sb-section{min-height:120px;background:#fff}main .sb-section{min-height:900px}main{padding-bottom:2000px}</style></head><body>${markup}</body></html>`;

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'one viewport is enough; run once');
});

async function load(page) {
  await page.emulateMedia({ reducedMotion: 'no-preference' });
  await page.setContent(page_);
  await page.addScriptTag({ path: runtimePath });
}

const scrollTo = async (page, y) => {
  await page.evaluate((y) => window.scrollTo(0, y), y);
  await page.waitForTimeout(120); // one rAF and the stuck-state transition start
};

test('a sticky header section sticks to the page, marks itself stuck, hides scrolling down and returns scrolling up', async ({ page }) => {
  await load(page);
  const header = page.locator('section.sb-sticky');
  await expect(header).toHaveCSS('position', 'sticky');
  await expect(header).not.toHaveClass(/is-stuck/);

  await scrollTo(page, 600);
  await scrollTo(page, 900);
  expect(await header.evaluate((el) => Math.round(el.getBoundingClientRect().top) <= 0)).toBe(true); // stuck at the top, not scrolled away inside its wrapper
  await expect(header).toHaveClass(/is-stuck/);
  await expect(header).toHaveClass(/is-hidden/);

  await scrollTo(page, 700);
  await expect(header).toHaveClass(/is-stuck/);
  await expect(header).not.toHaveClass(/is-hidden/);
  expect(await header.evaluate((el) => getComputedStyle(el).boxShadow)).not.toBe('none');

  await scrollTo(page, 0);
  await expect(header).not.toHaveClass(/is-stuck/);
});

test('a parallax background layer moves as the page scrolls, and stays still for reduced motion', async ({ page }) => {
  await load(page);
  const tall = page.locator('section.sb-parallax-bg');
  const y = () => tall.evaluate((el) => el.style.getPropertyValue('--sb-parallax-y'));
  await scrollTo(page, 100);
  const a = await y();
  await scrollTo(page, 500);
  const b = await y();
  expect(a).not.toBe('');
  expect(parseFloat(b)).toBeGreaterThan(parseFloat(a));

  await page.emulateMedia({ reducedMotion: 'reduce' });
  await page.setContent(page_);
  await page.addScriptTag({ path: runtimePath });
  await scrollTo(page, 500);
  expect(await page.locator('section.sb-parallax-bg').evaluate((el) => el.style.getPropertyValue('--sb-parallax-y'))).toBe('');
});
