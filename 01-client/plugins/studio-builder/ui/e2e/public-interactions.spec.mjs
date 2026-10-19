// Interaction triggers on a public page, against the real public runtime: a click replays the block's animation,
// load plays one as the page opens, scroll ties the block to the scroll position, and reduced motion turns them off.
// The CSS below is the stylesheet's own interaction rules (StudioStylesheet), copied for a page without a server.
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect } from '@playwright/test';

const here = path.dirname(fileURLToPath(import.meta.url));
const runtimePath = path.join(here, '..', '..', 'assets', 'public', 'studio-runtime.js');

const CSS = [
  '.sb-animate-fade-in{animation:sb-fade-in .3s ease 0s both}',
  '@keyframes sb-fade-in{from{opacity:0}to{opacity:1}}',
  '.sb-interaction-click{transition:transform .15s ease}',
  '.sb-interaction-click:not([class*="sb-animate-"]):active{transform:scale(.97)}',
  '.sb-interaction-click[class*="sb-animate-"]:not([data-sb-play]){animation:none}',
  '.sb-interaction-load:not([class*="sb-animate-"]){animation:sb-fade-in .3s ease 0s both}',
  '@keyframes sb-scroll-drift{from{transform:translateY(32px)}to{transform:translateY(-32px)}}',
  '@supports (animation-timeline:view()){',
  '.sb-interaction-scroll{animation-timeline:view();animation-range:cover 0% cover 100%;animation-fill-mode:both}',
  '.sb-interaction-scroll:not([class*="sb-animate-"]){animation:sb-scroll-drift linear both;animation-timeline:view();animation-range:cover 0% cover 100%}',
  '}',
  '@media (prefers-reduced-motion:reduce){[class*="sb-animate-"],[class*="sb-interaction-"]{animation:none!important;transition:none!important;transform:none!important}}',
].join('');

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'no layout involved; run once');
});

async function load(page, markup) {
  await page.setContent(`<!doctype html><html><head><style>${CSS}</style></head><body>${markup}</body></html>`);
  await page.addScriptTag({ path: runtimePath });
}

const animationName = (locator) => locator.evaluate((el) => getComputedStyle(el).animationName);

test('a click trigger plays the block\'s animation on each click, not on load', async ({ page }) => {
  await load(page, '<button id="b" class="sb-interaction-click sb-animate-fade-in" data-sb-interaction-trigger="click">Tap</button>');
  const b = page.locator('#b');
  expect(await animationName(b)).toBe('none');
  await b.click();
  await expect(b).toHaveAttribute('data-sb-play', '');
  expect(await animationName(b)).toBe('sb-fade-in');
  await expect(b).not.toHaveAttribute('data-sb-play', /.*/, { timeout: 3000 });
  await b.click();
  await expect(b).toHaveAttribute('data-sb-play', '');
});

test('a load trigger with no animation of its own fades the block in', async ({ page }) => {
  await load(page, '<div id="l" class="sb-interaction-load" data-sb-interaction-trigger="load">Hello</div>');
  expect(await animationName(page.locator('#l'))).toBe('sb-fade-in');
});

test('a scroll trigger ties the block to the scroll position', async ({ page }) => {
  await load(page, '<div style="height:150vh"></div><div id="s" class="sb-interaction-scroll" data-sb-interaction-trigger="scroll">Drift</div><div style="height:150vh"></div>');
  const s = page.locator('#s');
  const supported = await page.evaluate(() => CSS.supports('animation-timeline: view()'));
  if (supported) {
    expect(await animationName(s)).toBe('sb-scroll-drift');
  }
  const shiftAt = async (y) => {
    await page.evaluate((top) => window.scrollTo(0, top), y);
    await page.waitForTimeout(120);
    return s.evaluate((el) => new DOMMatrixReadOnly(getComputedStyle(el).transform).m42);
  };
  const box = await s.boundingBox();
  const vh = page.viewportSize().height;
  const entering = await shiftAt(box.y - vh + 10);
  const leaving = await shiftAt(box.y + box.height - 10);
  expect(entering).toBeGreaterThan(leaving);
});

test('with reduced motion, a click plays nothing', async ({ page }) => {
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await load(page, '<button id="b" class="sb-interaction-click sb-animate-fade-in" data-sb-interaction-trigger="click">Tap</button>');
  await page.locator('#b').click();
  await expect(page.locator('#b')).not.toHaveAttribute('data-sb-play', /.*/);
});
