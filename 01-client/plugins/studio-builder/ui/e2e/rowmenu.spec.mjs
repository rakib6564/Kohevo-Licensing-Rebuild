// Layers row menu (B2-P2c): every row action in one ⋯ menu that works from the keyboard and on touch,
// plus the page's header and footer shown as references.
import { test, expect } from '@playwright/test';
import { openBuilder, settled, sandboxPageId } from './helpers.mjs';

const ROWS = '[role="treeitem"]';

test.afterEach(async ({ page }) => {
  await page.goto(`/plugins/studio-builder/admin/builder.php?page=${sandboxPageId()}&lang=en`);
});

async function ensureSections(page, n) {
  await page.getByRole('tab', { name: /^(Layers|Calques)$/ }).click();
  for (let guard = 0; guard < n + 2; guard++) {
    const count = await page.locator(`${ROWS}[aria-level="1"]`).count();
    if (count >= n) break;
    await page.getByRole('button', { name: /Add section/ }).first().click();
    await expect.poll(() => page.locator(`${ROWS}[aria-level="1"]`).count(), { timeout: 15_000 }).toBeGreaterThan(count);
  }
  await settled(page);
}

// Creating a section selects it, which moves the left panel to the Inspector: come back to Layers before reading rows.
async function topIds(page) {
  await page.getByRole('tab', { name: /^(Layers|Calques)$/ }).click();
  return page.locator(`${ROWS}[aria-level="1"]`).evaluateAll((els) => els.map((e) => e.getAttribute('data-row')));
}
/** Select a row (which moves the left panel to the Inspector) and come back to Layers. */
async function selectRow(page, id) {
  await page.locator(`${ROWS}[data-row="${id}"]`).click();
  await page.getByRole('tab', { name: /^(Layers|Calques)$/ }).click();
}
const menuOf = (page, id) => page.getByTestId(`row-menu-${id}`);
const item = (page, action) => page.getByRole('menuitem').and(page.locator(`[data-action="${action}"]`));

test.describe('desktop', () => {
  test.beforeEach(async ({}, info) => { test.skip(info.project.name !== 'desktop', 'docked Layers panel'); });

  test('the ⋯ button opens a menu of every row action; Escape closes it and returns focus', async ({ page }) => {
    await openBuilder(page);
    await ensureSections(page, 1);
    const [id] = await topIds(page);
    await selectRow(page, id);
    const btn = menuOf(page, id);
    await expect(btn).toHaveAttribute('aria-haspopup', 'menu');
    await expect(btn).toHaveAttribute('aria-expanded', 'false');
    await btn.click();
    await expect(btn).toHaveAttribute('aria-expanded', 'true');
    const menu = page.getByRole('menu');
    await expect(menu).toBeVisible();
    const actions = await menu.getByRole('menuitem').evaluateAll((els) => els.map((e) => e.getAttribute('data-action')));
    expect(actions).toEqual(expect.arrayContaining(['rename', 'duplicate', 'lock', 'hide', 'move_up', 'move_down', 'delete']));
    expect(actions[actions.length - 1]).toBe('delete');
    await expect(menu.locator('[data-index="0"]')).toBeFocused();
    await page.keyboard.press('ArrowDown');
    await expect(menu.locator('[data-index="1"]')).toBeFocused();
    await page.keyboard.press('End');
    await expect(menu.locator('[data-action="delete"]')).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(menu).toHaveCount(0);
    await expect(btn).toBeFocused();
    // clicking elsewhere also closes it
    await btn.click();
    await expect(page.getByRole('menu')).toBeVisible();
    await page.getByTestId('pages-panel').or(page.locator('header.sbx-topbar')).first().click({ position: { x: 5, y: 5 } });
    await expect(page.getByRole('menu')).toHaveCount(0);
  });

  test('Duplicate and Delete from the menu add and remove a section; Hide and Show toggle it', async ({ page }) => {
    await openBuilder(page);
    await ensureSections(page, 1);
    const before = await topIds(page);
    const id = before[0];
    await selectRow(page, id);

    await menuOf(page, id).click();
    await item(page, 'duplicate').click();
    await expect.poll(async () => (await topIds(page)).length, { timeout: 15_000 }).toBe(before.length + 1);
    await settled(page);
    const copyId = (await topIds(page)).find((x) => !before.includes(x));
    await selectRow(page, copyId);

    await menuOf(page, copyId).click();
    await item(page, 'hide').click();
    await expect(page.locator(`${ROWS}[data-row="${copyId}"]`)).toHaveClass(/is-hidden/);
    await menuOf(page, copyId).click();
    await expect(item(page, 'show')).toBeVisible();
    await item(page, 'show').click();
    await expect(page.locator(`${ROWS}[data-row="${copyId}"]`)).not.toHaveClass(/is-hidden/);

    page.once('dialog', (d) => d.accept());
    await menuOf(page, copyId).click();
    await item(page, 'delete').click();
    await expect.poll(async () => (await topIds(page)).length, { timeout: 15_000 }).toBe(before.length);
  });

  test('Move down and Move up reorder sections; the ends are disabled', async ({ page }) => {
    await openBuilder(page);
    await ensureSections(page, 2);
    const [a, b] = await topIds(page);
    await selectRow(page, a);
    await menuOf(page, a).click();
    await expect(item(page, 'move_up')).toHaveAttribute('aria-disabled', 'true');
    await item(page, 'move_down').click();
    await expect.poll(async () => (await topIds(page)).slice(0, 2), { timeout: 15_000 }).toEqual([b, a]);
    // the shared page may hold more sections: the last one can never move down
    const ids = await topIds(page);
    await selectRow(page, ids[ids.length - 1]);
    await menuOf(page, ids[ids.length - 1]).click();
    await expect(item(page, 'move_down')).toHaveAttribute('aria-disabled', 'true');
    await page.keyboard.press('Escape');
    await settled(page);
    await selectRow(page, a);
    await menuOf(page, a).click();
    await expect(item(page, 'move_up')).not.toHaveAttribute('aria-disabled', 'true'); // no longer first
    await item(page, 'move_up').click();
    await expect.poll(async () => (await topIds(page)).slice(0, 2), { timeout: 15_000 }).toEqual([a, b]);
  });

  test('Rename from the menu opens the inline field; Save to library opens the template dialog on this node', async ({ page }) => {
    await openBuilder(page);
    await ensureSections(page, 1);
    const [id] = await topIds(page);
    await selectRow(page, id);
    await menuOf(page, id).click();
    await item(page, 'rename').click();
    await expect(page.locator(`${ROWS}[data-row="${id}"] .sbx-tree__rename-input`)).toBeFocused();
    await page.keyboard.press('Escape');

    await selectRow(page, id);
    await menuOf(page, id).click();
    await item(page, 'save_library').click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('#sbx-tpl-scope option:checked')).toHaveText(/section/i); // the row's own node is the default scope
    await dialog.getByRole('button', { name: 'Cancel' }).click();
  });

  test('Shift+F10 on a focused row opens its menu', async ({ page }) => {
    await openBuilder(page);
    await ensureSections(page, 1);
    const [id] = await topIds(page);
    const row = page.locator(`${ROWS}[data-row="${id}"]`);
    await row.focus();
    await page.keyboard.press('Shift+F10');
    await expect(page.getByRole('menu')).toBeVisible();
    await page.keyboard.press('Escape');
  });

  test('the header and footer appear as references, not layers', async ({ page }) => {
    await openBuilder(page);
    await ensureSections(page, 1);
    const header = page.getByTestId('partial-header');
    const footer = page.getByTestId('partial-footer');
    await expect(header).toBeVisible();
    await expect(footer).toBeVisible();
    await expect(header).toContainText('Header');
    await expect(header).toHaveAttribute('data-partial-kind', 'builtin');
    await expect(header).toContainText('Built-in');
    expect(await header.evaluate((n) => !!n.closest('[role="tree"]'))).toBe(false); // outside the tree: no move, lock or delete
    expect(await page.locator(`${ROWS}`).evaluateAll((els) => els.some((e) => /header|footer/i.test(e.getAttribute('aria-label') || '')))).toBe(false);
  });

  test('the menu and the references are French in French', async ({ page }) => {
    await page.goto(`/plugins/studio-builder/admin/builder.php?page=${sandboxPageId()}&lang=fr`);
    await page.locator('header.sbx-topbar').waitFor({ state: 'visible' });
    await ensureSections(page, 1);
    const [id] = await topIds(page);
    await page.locator(`${ROWS}[data-row="${id}"]`).click();
    await page.getByRole('tab', { name: /^Calques$/ }).click();
    await menuOf(page, id).click();
    await expect(item(page, 'rename')).toHaveText('Renommer');
    await expect(item(page, 'duplicate')).toHaveText('Dupliquer');
    await expect(item(page, 'move_up')).toHaveText('Monter');
    await expect(item(page, 'move_down')).toHaveText('Descendre');
    await page.keyboard.press('Escape');
    await expect(page.getByTestId('partial-header')).toContainText('Intégré');
  });
});

test.describe('phone', () => {
  test.beforeEach(async ({}, info) => { test.skip(info.project.name !== 'mobile', 'phone shell'); });

  test('the ⋯ button is always there and touch-sized, and the menu fits the screen', async ({ page }) => {
    await openBuilder(page);
    await page.getByTestId('mobile-dock-blocks').click();
    const sheet = page.locator('.sbx-left.is-mobile-open');
    await sheet.getByRole('tab', { name: /^Layers$/ }).click();
    if (!(await sheet.locator(ROWS).count())) {
      await sheet.getByRole('button', { name: /Add section/ }).first().click();
      await expect.poll(() => sheet.locator(ROWS).count(), { timeout: 15_000 }).toBeGreaterThan(0);
    }
    await settled(page);
    const id = await sheet.locator(`${ROWS}[aria-level="1"]`).first().getAttribute('data-row');
    const btn = sheet.getByTestId(`row-menu-${id}`);
    await expect(btn).toBeVisible(); // no hover on a phone: it must not depend on it
    const box = await btn.boundingBox();
    expect(box.width).toBeGreaterThanOrEqual(38);
    expect(box.height).toBeGreaterThanOrEqual(38);
    await btn.click();
    const menu = page.getByRole('menu');
    await expect(menu).toBeVisible();
    const m = await menu.boundingBox();
    const vp = page.viewportSize();
    expect(m.x).toBeGreaterThanOrEqual(0);
    expect(m.y).toBeGreaterThanOrEqual(0);
    expect(m.x + m.width).toBeLessThanOrEqual(vp.width + 1);
    expect(m.y + m.height).toBeLessThanOrEqual(vp.height + 1);
    for (const h of await menu.getByRole('menuitem').evaluateAll((els) => els.map((e) => e.getBoundingClientRect().height))) expect(h).toBeGreaterThanOrEqual(40);
    await page.keyboard.press('Escape');
  });
});
