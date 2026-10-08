// Mobile shell (B2-P1): five-tab dock, two-row top bar with a status chip, selection that does
// not cover the canvas, draggable sheets, the Responsive-view sheet and keyboard handling.
import { test, expect } from '@playwright/test';
import { openBuilder, frameDocument } from './helpers.mjs';

const dock = (page, key) => page.getByTestId(`mobile-dock-${key}`);

async function ensureSection(page) {
  const frame = await frameDocument(page);
  if (await frame.locator('[data-sb-node]').count()) return;
  await dock(page, 'blocks').click();
  await page.getByRole('tab', { name: /Layers/ }).click();
  await page.getByRole('button', { name: /Add section/ }).first().click();
  await expect.poll(async () => (await frameDocument(page)).locator('[data-sb-node]').count(), { timeout: 15_000 }).toBeGreaterThan(0);
  await page.getByRole('button', { name: 'Close panel' }).click();
}

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name === 'desktop', 'phone shell');
});

test('the dock is Blocks · Edit · Theme · Preview · More', async ({ page }) => {
  await openBuilder(page);
  const labels = await page.locator('nav.sbx-mobile-dock .sbx-dock-btn__label').allTextContents();
  expect(labels).toEqual(['Blocks', 'Edit', 'Theme', 'Preview', 'More']);
});

test('the top bar has two rows with a status chip and the Responsive-view button', async ({ page }) => {
  await openBuilder(page);
  const header = await page.locator('header.sbx-topbar').boundingBox();
  const left = await page.locator('.sbx-topbar__left').boundingBox();
  const center = await page.locator('.sbx-topbar__center').boundingBox();
  expect(header.height).toBeGreaterThan(70);
  expect(center.y).toBeGreaterThanOrEqual(left.y + left.height - 1);
  await expect(page.getByTestId('status-chip')).toBeVisible();
  expect(['Unsaved', 'Saved', 'Published']).toContain((await page.getByTestId('status-chip').textContent()).trim());
  await expect(page.getByTestId('open-responsive-view')).toBeVisible();
  await expect(page.getByTestId('undo')).toBeVisible();
});

test('a phone starts on the Mobile device and the canvas fills the width instead of shrinking', async ({ page }, info) => {
  test.skip(info.project.name !== 'mobile', 'default device is only asserted at phone width');
  await openBuilder(page);
  await expect(page.getByRole('button', { name: /^Mobile/ }).first()).toHaveAttribute('aria-pressed', 'true');
  const frame = await page.locator('iframe.sbx-canvas__frame').boundingBox();
  expect(frame.width).toBeGreaterThan(360);
});

test('selecting a node does not open the sheet; the contextual bar shows and Edit opens the sheet', async ({ page }) => {
  await openBuilder(page);
  await ensureSection(page);
  const frame = await frameDocument(page);
  await frame.locator('[data-sb-node]').first().click({ position: { x: 6, y: 6 } });

  await expect(page.getByTestId('overlay-toolbar')).toBeVisible();
  await expect(page.locator('.sbx-left.is-mobile-open')).toHaveCount(0);
  await expect(dock(page, 'inspector')).toBeVisible();

  await dock(page, 'inspector').click();
  await expect(page.locator('.sbx-left.is-mobile-open')).toHaveCount(1);
  await expect(page.getByRole('tab', { name: /Style/ })).toHaveAttribute('aria-selected', 'true');
});

test('the Blocks sheet can be dragged and pulled down to close', async ({ page }) => {
  await openBuilder(page);
  await dock(page, 'blocks').click();
  const sheet = page.locator('.sbx-left.is-mobile-open');
  await expect(sheet).toHaveCount(1);
  // wait for the slide-up animation to finish so the starting height is stable
  let last = -1;
  await expect.poll(async () => { const h = (await sheet.boundingBox()).height; const same = h === last; last = h; return same; }).toBe(true);
  const handle = await page.getByTestId('left-sheet-handle').boundingBox();
  const before = (await sheet.boundingBox()).height;

  // drag up a little: the sheet grows to a snap point
  await page.mouse.move(handle.x + handle.width / 2, handle.y + 10);
  await page.mouse.down();
  await page.mouse.move(handle.x + handle.width / 2, handle.y - 120, { steps: 6 });
  await page.mouse.up();
  const grown = (await sheet.boundingBox()).height;
  expect(grown).toBeGreaterThan(before - 1);

  // pull well below the lowest snap: it closes
  const h2 = await page.getByTestId('left-sheet-handle').boundingBox();
  await page.mouse.move(h2.x + h2.width / 2, h2.y + 10);
  await page.mouse.down();
  await page.mouse.move(h2.x + h2.width / 2, h2.y + 700, { steps: 12 });
  await page.mouse.up();
  await expect(page.locator('.sbx-left.is-mobile-open')).toHaveCount(0);
});

test('the More sheet is a modal dialog: Tab stays inside, Escape closes and focus returns', async ({ page }) => {
  await openBuilder(page);
  await dock(page, 'more').click();
  const sheet = page.getByTestId('sheet-more');
  await expect(sheet).toHaveAttribute('role', 'dialog');
  await expect(sheet).toHaveAttribute('aria-modal', 'true');
  for (let i = 0; i < 40; i++) {
    await page.keyboard.press('Tab');
    expect(await sheet.evaluate((el) => el.contains(document.activeElement)), `focus escaped on Tab ${i + 1}`).toBe(true);
  }
  await page.keyboard.press('Escape');
  await expect(sheet).toHaveCount(0);
  await expect(dock(page, 'more')).toBeFocused();
});

test('tapping the scrim closes a sheet', async ({ page }) => {
  await openBuilder(page);
  await dock(page, 'theme').click();
  await expect(page.getByTestId('sheet-theme')).toBeVisible();
  await page.getByTestId('sheet-theme-scrim').click({ position: { x: 20, y: 20 } });
  await expect(page.getByTestId('sheet-theme')).toHaveCount(0);
});

test('Responsive view: device preset, zoom and layout aids drive the canvas', async ({ page }) => {
  await openBuilder(page);
  await ensureSection(page);
  await page.getByTestId('open-responsive-view').click();
  const sheet = page.getByTestId('sheet-responsive');
  await expect(sheet).toBeVisible();

  await page.getByTestId('rv-device-tablet').click();
  await expect(page.getByTestId('rv-device-tablet')).toHaveAttribute('aria-pressed', 'true');

  const before = parseInt(await page.getByTestId('rv-zoom-percent').textContent(), 10);
  await page.getByTestId('rv-zoom-out').click();
  expect(parseInt(await page.getByTestId('rv-zoom-percent').textContent(), 10)).toBeLessThan(before);

  await page.getByTestId('rv-labels').check();
  await page.getByTestId('rv-outlines').check();
  await page.getByTestId('sheet-responsive-close').click();
  await expect(page.getByTestId('overlay-section-label').first()).toBeVisible();
  expect(await page.locator('.sbx-overlay__outline').count()).toBeGreaterThan(0);

  // dark preview and spacing guides are listed but disabled, never faked
  await page.getByTestId('open-responsive-view').click();
  await expect(page.getByTestId('rv-dark')).toBeDisabled();
  await expect(page.getByTestId('rv-spacing')).toBeDisabled();
});

test('with the on-screen keyboard open the sheet sits above it and the dock steps aside', async ({ page }) => {
  await openBuilder(page);
  await dock(page, 'more').click();
  await page.evaluate(() => {
    const shell = document.querySelector('.sbx-shell');
    shell.style.setProperty('--sbx-kb-inset', '300px');
    shell.dataset.keyboard = 'open';
  });
  const viewport = page.viewportSize();
  // the sheet slides up when it mounts; wait for it to settle above the keyboard
  await expect.poll(async () => {
    const box = await page.getByTestId('sheet-more').boundingBox();
    return box.y + box.height;
  }).toBeLessThanOrEqual(viewport.height - 300 + 1);
  await expect(page.locator('nav.sbx-mobile-dock')).toBeHidden();
});
