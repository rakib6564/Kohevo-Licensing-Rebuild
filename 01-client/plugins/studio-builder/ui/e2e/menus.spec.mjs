// Site menus: created, edited and deleted on their own screens (add panel on the left, menu structure on the right),
// and chosen in a Nav menu block in the builder.
import { test, expect } from '@playwright/test';
import { openBuilder, settled, frameDocument, layerCount, restoreLayers, publishThrowawayPage, archivePage } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'admin screens and the docked panel (desktop)');
});

const ADMIN = '/plugins/studio-builder/admin';

/** Open the Custom links box (closed while other sources exist) and add one link. */
async function addCustomLink(page, url, text) {
  const toggle = page.getByRole('button', { name: 'Custom links' });
  if ((await toggle.getAttribute('aria-expanded')) !== 'true') await toggle.click();
  await page.locator('#sb-menu-custom-url').fill(url);
  await page.locator('#sb-menu-custom-text').fill(text);
  await page.getByTestId('menu-add-link').click();
}

/** Drag a row by its handle: `dx` pixels sideways (a level is 32px), and onto `before` (a row) or straight across. */
async function dragRow(page, row, { dx = 0, onto = null } = {}) {
  const h = await row.locator('[data-handle]').boundingBox();
  const x = h.x + h.width / 2;
  const y = h.y + h.height / 2;
  let ty = y;
  if (onto) {
    const t = await onto.boundingBox();
    ty = t.y + 6; // the top part of the target row: the dragged row goes above it
  }
  await page.mouse.move(x, y);
  await page.mouse.down();
  await page.mouse.move(x + dx / 2, (y + ty) / 2, { steps: 4 });
  await page.mouse.move(x + dx, ty, { steps: 6 });
  await page.mouse.up();
}

const titles = (rows) => rows.locator('[data-title]').allTextContents();
const depths = async (rows) => (await rows.evaluateAll((els) => els.map((el) => el.getAttribute('data-depth')))).map(Number);

test('create a menu, refuse an unsafe link, build it from pages, products and links, nest it, then delete it', async ({ page }) => {
  test.setTimeout(180_000);
  const stamp = Date.now();
  const key = `e2e-menu-${stamp}`;
  const name = `E2E menu ${stamp}`;
  const pageTitle = `Menu source ${stamp}`;

  await openBuilder(page);
  await settled(page);
  const source = await publishThrowawayPage(page, pageTitle, `menu-source-${stamp}`);
  try {
    // List -> New menu on its own screen, two columns.
    await page.goto(`${ADMIN}/menus.php`);
    await page.getByTestId('new-menu').click();
    await expect(page).toHaveURL(/menu-new\.php$/);
    await expect(page.getByRole('heading', { name: 'Add menu items' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Menu structure' })).toBeVisible();
    await page.locator('#sb-menu-name').fill(name);
    await page.locator('#sb-menu-key').fill(key);
    const rows = page.getByTestId('menu-item');
    await expect(rows).toHaveCount(0);
    await expect(page.locator('#sb-menu-empty')).toBeVisible();

    await addCustomLink(page, '/alpha', 'Alpha link');
    await addCustomLink(page, 'javascript:alert(1)', 'Bad link');
    await expect(rows).toHaveCount(2);
    await expect(rows.nth(1).locator('[data-type]')).toHaveText('Custom link');
    await page.getByRole('button', { name: 'Create menu' }).click();
    await expect(page.locator('.alert-error')).toContainText('Bad link'); // refused, nothing saved
    await expect(page).toHaveURL(/menu-new\.php$/);
    await expect(rows).toHaveCount(2); // the posted rows come back

    // Fix it in the item's settings: label and address.
    await rows.nth(1).locator('[data-toggle]').click();
    await rows.nth(1).locator('[data-field="url"]').fill('https://example.com/beta');
    await rows.nth(1).locator('[data-field="label"]').fill('Beta link');
    await expect(rows.nth(1).locator('[data-title]')).toHaveText('Beta link');
    await page.getByRole('button', { name: 'Create menu' }).click();
    await expect(page).toHaveURL(/menus\.php\?saved=1/);
    await expect(page.locator('.alert-success')).toContainText('Menu saved');
    await expect(page.locator('.data-row', { hasText: name })).toHaveCount(1);

    // Edit: add a page (search) and every product (select all) from the add panel.
    await page.goto(`${ADMIN}/menu-edit.php?key=${key}`);
    expect(await titles(rows)).toEqual(['Alpha link', 'Beta link']);
    const pages = page.locator('[data-testid="menu-source"][data-source="page"]');
    await pages.getByRole('tab', { name: 'Search' }).click();
    await pages.getByRole('searchbox').fill(`source ${stamp}`);
    await expect(pages.locator('[data-list] li:not([hidden])')).toHaveCount(1);
    await pages.getByLabel(pageTitle).check();
    await pages.getByRole('button', { name: 'Add to menu' }).click();
    await expect(rows).toHaveCount(3);
    await expect(rows.nth(2).locator('[data-title]')).toHaveText(pageTitle);
    await expect(rows.nth(2).locator('[data-type]')).toHaveText('Page');
    await rows.nth(2).locator('[data-toggle]').click();
    await expect(rows.nth(2).locator('[data-field="url"]')).toHaveValue(source.path);
    await rows.nth(2).locator('[data-close]').click();

    const products = page.locator('[data-testid="menu-source"][data-source="plugin:product"]');
    await products.getByRole('button', { name: 'Products' }).click();
    await products.getByRole('tab', { name: 'View all' }).click();
    const productCount = await products.locator('[data-list] li:not([hidden])').count();
    expect(productCount).toBeGreaterThan(0);
    await products.getByLabel('Select all').check();
    await products.getByRole('button', { name: 'Add to menu' }).click();
    await expect(rows).toHaveCount(3 + productCount);
    await expect(rows.nth(3).locator('[data-type]')).toHaveText('Product');

    // Bulk select: remove the products again.
    await page.getByTestId('menu-bulk').check();
    for (let i = 3; i < 3 + productCount; i++) await rows.nth(i).locator('[data-pick]').check();
    await page.getByTestId('menu-bulk-remove').click();
    await expect(rows).toHaveCount(3);
    await page.getByTestId('menu-bulk').uncheck();

    // Drag: the page above Alpha, then Beta right into Alpha's submenu; the handle's arrow keys nest one more level.
    await dragRow(page, rows.nth(2), { onto: rows.nth(0) });
    expect(await titles(rows)).toEqual([pageTitle, 'Alpha link', 'Beta link']);
    await dragRow(page, rows.nth(2), { dx: 40 });
    expect(await depths(rows)).toEqual([0, 0, 1]);
    await rows.nth(2).locator('[data-handle]').focus();
    await page.keyboard.press('ArrowRight'); // no sibling above Beta inside Alpha's submenu: stays at level 1
    expect(await depths(rows)).toEqual([0, 0, 1]);
    await rows.nth(1).locator('[data-handle]').focus();
    await page.keyboard.press('ArrowRight'); // Alpha (with Beta) under the page: Beta goes down with it
    expect(await depths(rows)).toEqual([0, 1, 2]);

    // Per-item settings: CSS classes and a new tab; the Move links follow the tree.
    await rows.nth(1).locator('[data-toggle]').click();
    await expect(rows.nth(1).locator('[data-move="out"]')).toHaveText(`Out from under ${pageTitle}`);
    await rows.nth(1).locator('[data-field="class"]').fill('menu-cta is-alpha');
    await rows.nth(1).locator('[data-field="new_tab"]').check();
    await expect(page.locator('#sb-menu-dirty')).toBeVisible();
    await page.getByRole('button', { name: 'Save menu' }).click();
    await expect(page.locator('.alert-success')).toContainText('Menu saved');
    expect(await titles(rows)).toEqual([pageTitle, 'Alpha link', 'Beta link']);
    expect(await depths(rows)).toEqual([0, 1, 2]);
    await expect(rows.nth(1).locator('[data-field="class"]')).toHaveValue('menu-cta is-alpha');
    await expect(rows.nth(1).locator('[data-field="new_tab"]')).toBeChecked();

    // Move links: Beta to the top, out of every submenu.
    await rows.nth(2).locator('[data-toggle]').click();
    await rows.nth(2).locator('[data-move="top"]').click();
    expect(await titles(rows)).toEqual(['Beta link', pageTitle, 'Alpha link']);
    expect(await depths(rows)).toEqual([0, 0, 1]);
    await page.getByRole('button', { name: 'Save menu' }).click();
    await expect(page.locator('.alert-success')).toContainText('Menu saved');

    // The builder offers it in a Nav menu block, and the canvas shows its links, nested, with the item's classes.
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
      const doc = await frameDocument(page);
      await expect.poll(async () => (await frameDocument(page)).locator('.sb-nav__link', { hasText: 'Beta link' }).count(), { timeout: 20_000 }).toBe(1);
      await expect((await frameDocument(page)).locator('.sb-nav__sub > .sb-nav__item.menu-cta.is-alpha > .sb-nav__link[target="_blank"]', { hasText: 'Alpha link' })).toHaveCount(1);
      await expect(doc.locator('.sb-nav__link', { hasText: 'Home' })).toHaveCount(0); // not its own default links
    } finally {
      await restoreLayers(page, before);
    }

    // Delete sits by Save menu and asks first; the menu leaves the list.
    await page.goto(`${ADMIN}/menu-edit.php?key=${key}`);
    page.once('dialog', (d) => d.accept());
    await page.getByRole('button', { name: 'Delete menu' }).click();
    await expect(page).toHaveURL(/menus\.php\?deleted=1/);
    await expect(page.locator('.data-row', { hasText: name })).toHaveCount(0);
  } finally {
    page.on('dialog', (d) => d.accept()); // a failed step may leave unsaved changes behind
    await openBuilder(page);
    await archivePage(page, source.id);
  }
});

test('a submenu never goes deeper than two levels, by drag or by keys', async ({ page }) => {
  await page.goto(`${ADMIN}/menu-new.php`);
  for (const n of ['One', 'Two', 'Three', 'Four']) await addCustomLink(page, `/${n.toLowerCase()}`, n);
  const rows = page.getByTestId('menu-item');
  await dragRow(page, rows.nth(1), { dx: 40 });
  await dragRow(page, rows.nth(2), { dx: 80 });
  expect(await depths(rows)).toEqual([0, 1, 2, 0]);
  await dragRow(page, rows.nth(3), { dx: 160 });
  expect(await depths(rows)).toEqual([0, 1, 2, 2]); // as deep as allowed, never deeper
  await rows.nth(3).locator('[data-handle]').focus();
  await page.keyboard.press('ArrowRight');
  expect(await depths(rows)).toEqual([0, 1, 2, 2]);
  await page.keyboard.press('ArrowLeft'); // out from under Two: after Two's submenu, one level up
  expect(await depths(rows)).toEqual([0, 1, 2, 1]);
  // Removing an item keeps its submenu, one level up.
  await rows.nth(1).locator('[data-toggle]').click();
  await rows.nth(1).locator('[data-remove]').click();
  expect(await titles(rows)).toEqual(['One', 'Three', 'Four']);
  expect(await depths(rows)).toEqual([0, 1, 1]);
});

test('a menu that does not exist says so', async ({ page }) => {
  await page.goto(`${ADMIN}/menu-edit.php?key=nope-${Date.now()}`);
  await expect(page.locator('.alert-error')).toContainText('does not exist');
});
