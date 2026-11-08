// The message widget, the local time, wave text, and the Query Loop's filter chips and item dialog, against the exact
// markup the renderer emits (tests/fixtures/widgets.html, pinned by the PHP suite) and the real public runtime.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect } from '@playwright/test';

const here = path.dirname(fileURLToPath(import.meta.url));
const markup = fs.readFileSync(path.join(here, '..', 'tests', 'fixtures', 'widgets.html'), 'utf8');
const runtime = fs.readFileSync(path.join(here, '..', '..', 'assets', 'public', 'studio-runtime.js'), 'utf8');
const html = `<!doctype html><html><head><meta charset="utf-8"><style>body{margin:0;font:18px/1.4 sans-serif}section{padding:40px}.spacer{height:2400px}</style></head>`
  + `<body class="sb-body">${markup}<div class="spacer"></div></body></html>`;
const item = (name) => `<!doctype html><html><head><meta charset="utf-8"><title>${name}</title><style>.item-${name}{color:rgb(1, 2, 3)}</style></head>`
  + `<body><header>Site header</header><main id="main"><h1>${name}</h1><p class="item-${name}">Body of ${name}</p></main><footer>Site footer</footer></body></html>`;

test.beforeEach(async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop', 'one viewport is enough; run once');
  await page.route('http://localhost/widgets*', (route) => route.fulfill({ contentType: 'text/html', body: html }));
  await page.route('http://localhost/runtime.js', (route) => route.fulfill({ contentType: 'text/javascript', body: runtime }));
  await page.route('http://localhost/work/*', (route) => route.fulfill({ contentType: 'text/html', body: item(new URL(route.request().url()).pathname.split('/').pop()) }));
});

async function load(page, { reduced = false, url = 'http://localhost/widgets' } = {}) {
  await page.emulateMedia({ reducedMotion: reduced ? 'reduce' : 'no-preference' });
  await page.goto(url);
  await page.addScriptTag({ url: 'http://localhost/runtime.js' });
}

test('the message widget opens from its button, focuses the form, and closes with Escape back to the button', async ({ page }) => {
  await load(page);
  const panel = page.locator('.sb-msg__panel');
  const launcher = page.locator('[data-sb-msg-open]');
  await expect(panel).toBeHidden();
  await expect(page.locator('[data-sb-msg]')).toHaveClass(/is-online/);
  await expect(page.locator('[data-sb-msg-status-text]')).toHaveText('Rakib · Online now');
  await launcher.click();
  await expect(panel).toBeVisible();
  await expect(launcher).toHaveAttribute('aria-expanded', 'true');
  await expect(page.locator('.sb-msg__form input[name="name"]')).toBeFocused();
  await page.locator('.sb-msg__chip', { hasText: 'SEO' }).click();
  await expect(page.locator('input[name="topic"][value="SEO"]')).toBeChecked();
  await page.keyboard.press('Escape');
  await expect(panel).toBeHidden();
  await expect(launcher).toBeFocused();
  expect(await page.evaluate(() => location.hash)).toBe('');
});

test('the message widget opens by itself halfway down the page, once, and not again for a visitor who closed it', async ({ page }) => {
  await load(page);
  const panel = page.locator('.sb-msg__panel');
  await page.evaluate(() => scrollTo(0, document.documentElement.scrollHeight));
  await expect(panel).toBeVisible();
  await page.locator('[data-sb-msg-close]').click();
  await expect(panel).toBeHidden();
  await page.evaluate(() => { scrollTo(0, 0); scrollTo(0, document.documentElement.scrollHeight); });
  await page.waitForTimeout(200);
  await expect(panel).toBeHidden();
  await load(page);
  await page.evaluate(() => scrollTo(0, document.documentElement.scrollHeight));
  await page.waitForTimeout(300);
  await expect(panel).toBeHidden();
});

test('the local time shows the time in its zone and ticks', async ({ page }) => {
  await load(page);
  const clock = page.locator('[data-sb-clock]');
  const first = await clock.textContent();
  expect(first).toMatch(/^\d\d:\d\d:\d\d$/);
  const utc = await page.evaluate(() => new Date().toISOString().slice(11, 16));
  expect(first.slice(0, 5)).toBe(utc);
  await expect(clock).not.toHaveText(first, { timeout: 2500 });
});

test('wave text keeps its heading, reads as words, and thickens the letters near the pointer', async ({ page }) => {
  await load(page);
  const heading = page.locator('[data-sb-wave] h2');
  await expect(heading).toHaveCount(1);
  await expect(page.locator('[data-sb-wave-on]')).toHaveCount(1);
  const letters = page.locator('.sb-wave__l');
  await expect(letters).toHaveCount(10);
  expect(await heading.evaluate((h) => h.innerText.replace(/\s+/g, ' ').trim())).toContain('Rakib');
  const first = await letters.first().boundingBox();
  await page.mouse.move(first.x + first.width / 2, first.y + first.height / 2);
  await expect.poll(async () => Number(await letters.first().evaluate((l) => l.style.fontWeight))).toBeGreaterThan(800);
  const far = Number(await letters.last().evaluate((l) => l.style.fontWeight || '0'));
  expect(far).toBeLessThan(600);
  await page.mouse.move(1270, 790);
  await expect.poll(async () => letters.first().evaluate((l) => l.style.fontWeight)).toBe('');
});

test('wave text is left as plain text for reduced motion', async ({ page }) => {
  await load(page, { reduced: true });
  await expect(page.locator('.sb-wave__l')).toHaveCount(0);
});

test('filter chips show their term\'s items in place, and the address follows', async ({ page }) => {
  await load(page);
  const cards = page.locator('.sb-query-loop [data-sb-item-url]');
  await page.locator('[data-sb-filter-term="Brand"]').click();
  await expect(cards.nth(0)).toBeHidden();
  await expect(cards.nth(1)).toBeVisible();
  await expect(cards.nth(2)).toBeHidden();
  await expect(page.locator('[data-sb-filter-term="Brand"]')).toHaveAttribute('aria-current', 'true');
  expect(await page.evaluate(() => location.search)).toBe('?filter=Brand');
  await page.locator('[data-sb-filter-term=""]').click();
  await expect(cards).toHaveCount(3);
  for (let i = 0; i < 3; i++) await expect(cards.nth(i)).toBeVisible();
  expect(await page.evaluate(() => location.search)).toBe('');
});

test('an item opens in a dialog with its page\'s content and styles, walks to the next, and Back closes it', async ({ page }) => {
  await load(page);
  await page.locator('.sb-post-card__title a', { hasText: 'One' }).click();
  const dialog = page.locator('dialog.sb-loop-dialog');
  await expect(dialog).toBeVisible();
  await expect(dialog.locator('h1')).toHaveText('one');
  await expect(dialog.locator('.item-one')).toHaveCSS('color', 'rgb(1, 2, 3)');
  await expect(dialog).not.toContainText('Site header');
  expect(await page.evaluate(() => location.pathname)).toBe('/work/one');
  await expect(dialog.locator('.sb-loop-dialog__prev')).toBeDisabled();
  await dialog.locator('.sb-loop-dialog__next').click();
  await expect(dialog.locator('h1')).toHaveText('two');
  expect(await page.evaluate(() => location.pathname)).toBe('/work/two');
  await page.goBack();
  await expect(dialog).toBeHidden();
  expect(await page.evaluate(() => location.pathname)).toBe('/widgets');
});

test('the dialog walks only the items a chip left showing, and closing it returns to the list', async ({ page }) => {
  await load(page);
  await page.locator('[data-sb-filter-term="Web"]').click();
  await page.locator('.sb-post-card__title a', { hasText: 'One' }).click();
  const dialog = page.locator('dialog.sb-loop-dialog');
  await dialog.locator('.sb-loop-dialog__next').click();
  await expect(dialog.locator('h1')).toHaveText('three');
  await expect(dialog.locator('.sb-loop-dialog__next')).toBeDisabled();
  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();
  await expect.poll(() => page.evaluate(() => location.pathname + location.search)).toBe('/widgets?filter=Web');
  await expect(page.locator('.sb-post-card__title a', { hasText: 'One' })).toBeFocused();
});

test('the testimonial grid shows a page at a time, pages by number and arrow, and opens a clamped review in a dialog', async ({ page }) => {
  await load(page);
  const grid = page.locator('[data-sb-tgrid]');
  const shown = grid.locator('.sb-tgrid__card:not([hidden])');
  await expect(shown).toHaveCount(2);
  await expect(grid.locator('.sb-tgrid__count')).toHaveText('01–02 of 7 reviews');
  await expect(grid.locator('.sb-tgrid__nav')).toBeVisible();
  await expect(grid.locator('[data-sb-tg-page]')).toHaveText(['01', '02', '04']);
  await expect(grid.locator('.sb-tgrid__gap')).toHaveCount(1);
  await expect(grid.locator('[data-sb-tg-prev]')).toBeDisabled();
  await expect(shown.first()).toHaveClass(/is-first/);

  await grid.locator('[data-sb-tg-next]').click();
  await expect(grid.locator('.sb-tgrid__count')).toHaveText('03–04 of 7 reviews');
  await expect(shown.first()).toContainText('Client 3');
  await expect(shown.first()).toHaveClass(/is-first/);
  await grid.locator('[data-sb-tg-page="3"]').click();
  await expect(grid.locator('.sb-tgrid__count')).toHaveText('07–07 of 7 reviews');
  await expect(shown).toHaveCount(1);
  await expect(grid.locator('[data-sb-tg-next]')).toBeDisabled();
  await grid.locator('[data-sb-tg-page="0"]').click();

  // Only the clamped review offers its full text.
  const more = grid.locator('[data-sb-tg-more]:not([hidden])');
  await expect(more).toHaveCount(1);
  await more.click();
  const dialog = grid.locator('.sb-tgrid__dialog');
  await expect(dialog).toBeVisible();
  await expect(dialog.locator('[data-sb-tg-full]')).toContainText('runs on and on');
  await expect(dialog.locator('[data-sb-tg-who]')).toHaveText('— Client 1 · on Fiverr');
  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();
  await expect(more).toBeFocused();

  // Teardown leaves every review showing, as without the runtime.
  await page.evaluate(() => window.StudioRuntime.disable());
  await expect(grid.locator('.sb-tgrid__card:not([hidden])')).toHaveCount(7);
  await expect(grid.locator('.sb-tgrid__nav')).toBeHidden();
  await expect(grid.locator('.sb-tgrid__count')).toHaveText('01–07 of 7 reviews');
});

test('the testimonial grid shows its page size for a phone, and every review reads in full without the runtime', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 800 });
  await page.goto('http://localhost/widgets');
  const grid = page.locator('[data-sb-tgrid]');
  await expect(grid.locator('.sb-tgrid__card:not([hidden])')).toHaveCount(7);
  await page.addScriptTag({ url: 'http://localhost/runtime.js' });
  await expect(grid.locator('.sb-tgrid__card:not([hidden])')).toHaveCount(1);
  await expect(grid.locator('.sb-tgrid__count')).toHaveText('01–01 of 7 reviews');
});
