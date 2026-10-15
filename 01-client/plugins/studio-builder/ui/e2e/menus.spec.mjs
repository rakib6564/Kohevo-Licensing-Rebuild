// Site menus: created, edited and deleted on their own screens, and chosen in a Nav menu block in the builder.
import { test, expect } from '@playwright/test';
import { openBuilder, settled, frameDocument, layerCount, restoreLayers } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'admin screens and the docked panel (desktop)');
});

const ADMIN = '/plugins/studio-builder/admin';

test('create a menu, refuse an unsafe link, edit it, use it in a Nav menu block, then delete it', async ({ page }) => {
  test.setTimeout(150_000);
  const stamp = Date.now();
  const key = `e2e-menu-${stamp}`;
  const name = `E2E menu ${stamp}`;

  // List -> New menu on its own screen.
  await page.goto(`${ADMIN}/menus.php`);
  await page.getByTestId('new-menu').click();
  await expect(page).toHaveURL(/menu-new\.php$/);
  await page.locator('#sb-menu-name').fill(name);
  await page.locator('#sb-menu-key').fill(key);
  const rows = page.getByTestId('menu-item');
  await rows.nth(0).locator('input').nth(0).fill('Alpha link');
  await rows.nth(0).locator('input').nth(1).fill('/alpha');
  await page.getByTestId('menu-add-link').click();
  await rows.nth(1).locator('input').nth(0).fill('Bad link');
  await rows.nth(1).locator('input').nth(1).fill('javascript:alert(1)');
  await page.getByRole('button', { name: 'Create menu' }).click();
  await expect(page.locator('.alert-error')).toContainText('Bad link'); // refused, nothing saved
  await expect(page).toHaveURL(/menu-new\.php$/);

  await rows.nth(1).locator('input').nth(1).fill('https://example.com/beta');
  await rows.nth(1).locator('input').nth(0).fill('Beta link');
  await page.getByRole('button', { name: 'Create menu' }).click();
  await expect(page).toHaveURL(/menus\.php\?saved=1/);
  await expect(page.locator('.alert-success')).toContainText('Menu saved');
  await expect(page.locator('.data-row', { hasText: name })).toHaveCount(1);

  // Edit: move Beta above Alpha, save, and the order sticks.
  await page.goto(`${ADMIN}/menu-edit.php?key=${key}`);
  await rows.nth(1).locator('[data-move="up"]').click();
  await page.getByRole('button', { name: 'Save changes' }).click();
  await expect(page.locator('.alert-success')).toContainText('Menu saved');
  await expect(rows.nth(0).locator('input').nth(0)).toHaveValue('Beta link');

  // The builder offers it in a Nav menu block, and the canvas shows its links.
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await page.getByRole('tab', { name: /^Add$/ }).click();
    const blocks = page.locator('#sbx-leftpanel-blocks');
    await blocks.getByRole('tab', { name: 'Elements' }).click();
    await blocks.locator('[data-chip="layout"]').click();
    await blocks.locator('[data-block-type="core.nav_menu"]').click();
    await settled(page);
    const select = page.locator('#sbx-leftpanel-inspector').getByLabel('Menu', { exact: true });
    await expect(select).toBeVisible();
    await expect(select.locator('option', { hasText: name })).toHaveCount(1);
    await select.selectOption(key);
    await expect.poll(async () => (await frameDocument(page)).locator('.sb-nav__link', { hasText: 'Beta link' }).count(), { timeout: 20_000 }).toBe(1);
    await expect((await frameDocument(page)).locator('.sb-nav__link', { hasText: 'Home' })).toHaveCount(0); // not its own default links
  } finally {
    await restoreLayers(page, before);
  }

  // Delete asks first; the menu leaves the list.
  await page.goto(`${ADMIN}/menu-edit.php?key=${key}`);
  page.once('dialog', (d) => d.accept());
  await page.getByRole('button', { name: 'Delete menu' }).click();
  await expect(page).toHaveURL(/menus\.php\?deleted=1/);
  await expect(page.locator('.data-row', { hasText: name })).toHaveCount(0);
});

test('a menu that does not exist says so', async ({ page }) => {
  await page.goto(`${ADMIN}/menu-edit.php?key=nope-${Date.now()}`);
  await expect(page.locator('.alert-error')).toContainText('does not exist');
});
