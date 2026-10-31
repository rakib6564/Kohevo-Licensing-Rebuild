// The Nav menu's menu button, submenus and pointers: the real renderer's markup and stylesheet (nav-widget-page.php) with
// the real runtime, at phone and desktop widths.
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect } from '@playwright/test';

const here = path.dirname(fileURLToPath(import.meta.url));
const runtimePath = path.join(here, '..', '..', 'assets', 'public', 'studio-runtime.js');
const PHONE = { width: 390, height: 800 };
const DESKTOP = { width: 1280, height: 800 };

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'sizes are set per test; run once');
});

/** Serves the page at `url`, with or without the runtime. */
async function load(page, viewport, props, { url = 'http://nav.test/', runtime = true, header = '' } = {}) {
  const html = execFileSync('php', [path.join(here, 'nav-widget-page.php'), JSON.stringify(props)], { encoding: 'utf8' }).replace('<header class="sb-site-header">', `<header class="sb-site-header${header}">`);
  await page.setViewportSize(viewport);
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await page.route('http://nav.test/**', (route) => route.fulfill({ contentType: 'text/html', body: html }));
  await page.goto(url);
  if (runtime) {
    await page.addScriptTag({ path: runtimePath });
    await expect(page.locator('html')).toHaveAttribute('data-sb-ready', '');
  }
}

const burger = (page) => page.locator('.sb-nav__burger');
const list = (page) => page.locator('.sb-nav__list');
const link = (page, name) => page.getByRole('link', { name, exact: true });

test('phone: the hamburger opens a full-width dropdown under the header; Escape and a link close it', async ({ page }) => {
  await load(page, PHONE, { toggle_breakpoint: 'mobile' });
  await expect(page.locator('nav')).toHaveCount(1);
  await expect(burger(page)).toBeVisible();
  await expect(list(page)).toBeHidden();
  await expect(burger(page)).toHaveAttribute('aria-expanded', 'false');

  await burger(page).click();
  await expect(list(page)).toBeVisible();
  await expect(burger(page)).toHaveAttribute('aria-expanded', 'true');
  const box = await list(page).boundingBox();
  expect(Math.round(box.x)).toBe(0);
  expect(Math.round(box.width)).toBe(PHONE.width);

  await page.keyboard.press('Escape');
  await expect(list(page)).toBeHidden();
  await expect(burger(page)).toBeFocused();

  await burger(page).click();
  await link(page, 'Contact').click();
  await expect(list(page)).toBeHidden();
});

test('phone: a submenu opens by a tap on its arrow, stays folded otherwise, and growing past the breakpoint closes the menu', async ({ page }) => {
  await load(page, PHONE, { toggle_breakpoint: 'mobile' });
  await burger(page).click();
  await expect(link(page, 'Yoga')).toBeHidden();
  const arrow = page.locator('.sb-nav__toggle');
  await arrow.click();
  await expect(link(page, 'Yoga')).toBeVisible();
  await expect(arrow).toHaveAttribute('aria-expanded', 'true');
  await arrow.click();
  await expect(link(page, 'Yoga')).toBeHidden();

  await page.setViewportSize(DESKTOP);
  await expect(burger(page)).toBeHidden();
  await expect(burger(page)).toHaveAttribute('aria-expanded', 'false');
  await expect(list(page)).toHaveCSS('flex-direction', 'row');
});

test('off-canvas: focus moves in and stays in, the backdrop closes it, the page behind stays put', async ({ page }) => {
  await load(page, PHONE, { toggle_breakpoint: 'always', toggle_mode: 'offcanvas' });
  await burger(page).click();
  await expect(link(page, 'Home')).toBeFocused();
  await expect(page.locator('html')).toHaveClass(/sb-scroll-lock/);
  const box = await list(page).boundingBox();
  expect(Math.round(box.x + box.width)).toBe(PHONE.width);
  for (let i = 0; i < 8; i++) {
    await page.keyboard.press('Tab');
    expect(await page.evaluate(() => !!document.activeElement.closest('nav')), 'focus stays in the menu').toBe(true);
  }
  await page.mouse.click(10, 400);
  await expect(list(page)).toBeHidden();
  await expect(burger(page)).toBeFocused();
  await expect(page.locator('html')).not.toHaveClass(/sb-scroll-lock/);
});

test('without scripts the checkbox opens and closes the menu, and every submenu link shows', async ({ page }) => {
  await load(page, PHONE, { toggle_breakpoint: 'mobile' }, { runtime: false });
  await expect(list(page)).toBeHidden();
  await page.locator('.sb-nav__check').click();
  await expect(list(page)).toBeVisible();
  await expect(link(page, 'Yoga')).toBeVisible();
  await page.locator('.sb-nav__check').click();
  await expect(list(page)).toBeHidden();
});

test('desktop: the row shows, a dropdown opens on hover and keyboard focus, and the pointer lights up', async ({ page }) => {
  await load(page, DESKTOP, { toggle_breakpoint: 'mobile', pointer: 'underline', pointer_animation: 'none' });
  await expect(burger(page)).toBeHidden();
  await expect(list(page)).toHaveCSS('flex-direction', 'row');
  const opacity = () => link(page, 'Contact').evaluate((el) => getComputedStyle(el, '::after').opacity);
  expect(await opacity()).toBe('0');
  await link(page, 'Contact').hover();
  await expect.poll(opacity).toBe('1');

  await expect(link(page, 'Yoga')).toBeHidden();
  await link(page, 'Services').hover();
  await expect(link(page, 'Yoga')).toBeVisible();
  await page.mouse.move(600, 600);
  await expect(link(page, 'Yoga')).toBeHidden();
  await link(page, 'Services').focus();
  await expect(link(page, 'Yoga')).toBeVisible();
});

test('the link of the page being viewed is marked, and so is its parent', async ({ page }) => {
  await load(page, DESKTOP, {}, { url: 'http://nav.test/yoga/' });
  await expect(page.locator('a[aria-current="page"]')).toHaveText('Yoga');
  await expect(page.locator('a.is-current')).toHaveCount(1);
  await expect(page.locator('.sb-nav__item.is-current-parent')).toHaveCount(1);
});

test('a sticky header with Shrink tightens after a little scroll and comes back at the top', async ({ page }) => {
  await load(page, DESKTOP, {}, { header: ' sb-sticky sb-sticky--top" data-sb-sticky="top" data-sb-sticky-effect="shrink' });
  const header = page.locator('header.sb-site-header').first();
  await page.evaluate(() => window.scrollTo(0, 200));
  await expect(header).toHaveClass(/is-shrunk/);
  await expect(page.locator('header.sb-site-header .sb-top').first()).toHaveCSS('padding-top', '5.6px');
  await page.evaluate(() => window.scrollTo(0, 0));
  await expect(header).not.toHaveClass(/is-shrunk/);
});
