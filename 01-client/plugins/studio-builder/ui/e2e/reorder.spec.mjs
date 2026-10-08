// Reordering Layers rows with the drag handle (mouse and touch) and the Pages quick tools (B2-P2c).
import { test, expect } from '@playwright/test';
import { openBuilder, settled, sandboxPageId } from './helpers.mjs';

const ROWS = '[role="treeitem"]';

test.afterEach(async ({ page }) => {
  await page.goto(`/plugins/studio-builder/admin/builder.php?page=${sandboxPageId()}&lang=en`);
});

async function ensureSections(page, n, scope = page) {
  await scope.getByRole('tab', { name: /^Layers$/ }).click();
  for (let guard = 0; guard < n + 2; guard++) {
    const count = await scope.locator(`${ROWS}[aria-level="1"]`).count();
    if (count >= n) break;
    await scope.getByRole('button', { name: /Add section/ }).first().click();
    await expect.poll(() => scope.locator(`${ROWS}[aria-level="1"]`).count(), { timeout: 15_000 }).toBeGreaterThan(count);
  }
  await settled(page);
}

async function topIds(page, scope = page) {
  await scope.getByRole('tab', { name: /^Layers$/ }).click();
  return scope.locator(`${ROWS}[aria-level="1"]`).evaluateAll((els) => els.map((e) => e.getAttribute('data-row')));
}

test.describe('desktop', () => {
  test.beforeEach(async ({}, info) => { test.skip(info.project.name !== 'desktop', 'mouse drag on the docked panel'); });

  test('dragging a row\'s handle onto another row reorders; Escape cancels; locked rows have no handle', async ({ page }) => {
    await openBuilder(page);
    await ensureSections(page, 2);
    const [a, b] = await topIds(page);
    const rowB = page.locator(`${ROWS}[data-row="${b}"]`);
    await page.locator(`${ROWS}[data-row="${a}"]`).hover();
    const handle = page.getByTestId(`row-handle-${a}`);
    await expect(handle).toBeVisible();
    await expect(handle).toHaveAttribute('aria-label', /Drag to reorder/);

    // Escape in the middle of a drag drops nothing
    let h = await handle.boundingBox();
    await page.mouse.move(h.x + h.width / 2, h.y + h.height / 2);
    await page.mouse.down();
    const box = await rowB.boundingBox();
    await page.mouse.move(box.x + 30, box.y + box.height * 0.8, { steps: 8 });
    await expect(rowB).toHaveClass(/is-drop-after/);
    await expect(page.locator(`${ROWS}[data-row="${a}"]`)).toHaveClass(/is-dragging/);
    await page.keyboard.press('Escape');
    await page.mouse.up();
    await expect(rowB).not.toHaveClass(/is-drop-/);
    expect((await topIds(page)).slice(0, 2)).toEqual([a, b]);

    // a real drop below B
    await page.locator(`${ROWS}[data-row="${a}"]`).hover();
    h = await handle.boundingBox();
    await page.mouse.move(h.x + h.width / 2, h.y + h.height / 2);
    await page.mouse.down();
    const box2 = await rowB.boundingBox();
    await page.mouse.move(box2.x + 30, box2.y + box2.height * 0.8, { steps: 8 });
    await page.mouse.up();
    await expect.poll(async () => (await topIds(page)).slice(0, 2), { timeout: 15_000 }).toEqual([b, a]);
    await settled(page);

    // put it back (and prove the keyboard route: arrows on the handle)
    await page.locator(`${ROWS}[data-row="${a}"]`).click();
    await page.getByRole('tab', { name: /^Layers$/ }).click();
    await page.getByTestId(`row-handle-${a}`).focus();
    await page.keyboard.press('ArrowUp');
    await expect.poll(async () => (await topIds(page)).slice(0, 2), { timeout: 15_000 }).toEqual([a, b]);
  });

  test('a locked row cannot be dragged: its handle is disabled until it is unlocked', async ({ page }) => {
    await openBuilder(page);
    await ensureSections(page, 1);
    const [a] = await topIds(page);
    await page.locator(`${ROWS}[data-row="${a}"]`).click();
    await page.getByRole('tab', { name: /^Layers$/ }).click();
    await page.getByTestId(`row-menu-${a}`).click();
    await page.getByRole('menuitem').and(page.locator('[data-action="lock"]')).click();
    try {
      await expect(page.getByTestId(`row-handle-${a}`)).toBeDisabled();
    } finally {
      await page.getByTestId(`row-menu-${a}`).click();
      await page.getByRole('menuitem').and(page.locator('[data-action="unlock"]')).click();
    }
    await expect(page.getByTestId(`row-handle-${a}`)).toBeEnabled();
  });

  test('a block can be dragged into another section', async ({ page }) => {
    await openBuilder(page);
    await ensureSections(page, 2);
    const [a, b] = await topIds(page);
    await page.locator(`${ROWS}[data-row="${a}"]`).click();
    await page.getByRole('tab', { name: /^Add$/ }).click();
    await page.locator('#sbx-leftpanel-blocks').getByRole('tab', { name: 'Elements' }).click();
    await page.locator('#sbx-leftpanel-blocks [data-chip="content"]').click();
    await page.locator('#sbx-leftpanel-blocks [data-block-type="core.quote"]').click();
    await settled(page);
    await page.getByRole('tab', { name: /^Layers$/ }).click();
    const parentOf = (id) => page.locator(ROWS).evaluateAll((els, rowId) => {
      let owner = null;
      for (const el of els) {
        if (el.getAttribute('aria-level') === '1') owner = el.getAttribute('data-row');
        if (el.getAttribute('data-row') === rowId) return owner;
      }
      return null;
    }, id);
    const block = await page.locator(`${ROWS}[aria-level="2"]`).first().getAttribute('data-row');
    try {
      expect(await parentOf(block)).toBe(a);
      await page.locator(`${ROWS}[data-row="${block}"]`).hover();
      const h = await page.getByTestId(`row-handle-${block}`).boundingBox();
      await page.mouse.move(h.x + h.width / 2, h.y + h.height / 2);
      await page.mouse.down();
      const target = await page.locator(`${ROWS}[data-row="${b}"]`).boundingBox();
      await page.mouse.move(target.x + 40, target.y + target.height / 2, { steps: 8 });
      await expect(page.locator(`${ROWS}[data-row="${b}"]`)).toHaveClass(/is-drop-inside/);
      await page.mouse.up();
      await expect.poll(() => parentOf(block), { timeout: 15_000 }).toBe(b);
    } finally {
      await settled(page);
      await page.getByRole('tab', { name: /^Layers$/ }).click();
      page.once('dialog', (d) => d.accept());
      await page.getByTestId(`row-menu-${block}`).click({ force: true }).catch(() => {});
      await page.getByRole('menuitem').and(page.locator('[data-action="delete"]')).click().catch(() => {});
    }
  });

  test('the Pages quick tools open the dialogs and panels that already exist', async ({ page }) => {
    await openBuilder(page);
    await page.getByRole('tab', { name: /^Layers$/ }).click();
    await page.locator('[data-nav="pages"]').click();
    const tools = page.getByTestId('page-tools');
    await expect(tools).toBeVisible();
    for (const k of ['settings', 'history', 'packages', 'theme']) await expect(tools.locator(`[data-tool="${k}"]`)).toBeVisible();

    await tools.locator('[data-tool="packages"]').click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).toHaveCount(0);

    await tools.locator('[data-tool="theme"]').click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await page.keyboard.press('Escape');

    await tools.locator('[data-tool="history"]').click();
    await expect(page.getByTestId('history-panel').or(page.getByRole('dialog')).first()).toBeVisible();
    await page.keyboard.press('Escape');

    await tools.locator('[data-tool="settings"]').click();
    await expect(page.getByRole('tab', { name: /^Settings$/ })).toHaveAttribute('aria-selected', 'true');
  });

  test('the tools are French in French', async ({ page }) => {
    await page.goto(`/plugins/studio-builder/admin/builder.php?page=${sandboxPageId()}&lang=fr`);
    await page.locator('header.sbx-topbar').waitFor({ state: 'visible' });
    await page.getByRole('tab', { name: /^Calques$/ }).click();
    await page.locator('[data-nav="pages"]').click();
    const tools = page.getByTestId('page-tools');
    await expect(tools.locator('[data-tool="settings"]')).toHaveText('Réglages');
    await expect(tools.locator('[data-tool="history"]')).toBeVisible();
  });
});

test.describe('phone', () => {
  test.beforeEach(async ({}, info) => { test.skip(info.project.name !== 'mobile', 'touch'); });

  test('a finger on the handle reorders rows in the Layers sheet without panning the sheet', async ({ page }) => {
    await openBuilder(page);
    await page.getByTestId('mobile-dock-blocks').click();
    const sheet = page.locator('.sbx-left.is-mobile-open');
    await ensureSections(page, 2, sheet);
    const [a, b] = await topIds(page, sheet);
    const handle = sheet.getByTestId(`row-handle-${a}`);
    await expect(handle).toBeVisible();
    const hb = await handle.boundingBox();
    expect(hb.width).toBeGreaterThanOrEqual(38);
    expect(hb.height).toBeGreaterThanOrEqual(38);

    const client = await page.context().newCDPSession(page);
    const touch = (type, x, y) => client.send('Input.dispatchTouchEvent', { type, touchPoints: type === 'touchEnd' ? [] : [{ x, y, id: 1 }] });
    const rowB = sheet.locator(`${ROWS}[data-row="${b}"]`);
    const rb = await rowB.boundingBox();
    const sx = hb.x + hb.width / 2;
    const sy = hb.y + hb.height / 2;
    const tx = rb.x + 40;
    const ty = rb.y + rb.height * 0.8;
    await touch('touchStart', sx, sy);
    for (let i = 1; i <= 10; i++) await touch('touchMove', sx + ((tx - sx) * i) / 10, sy + ((ty - sy) * i) / 10);
    await expect(rowB).toHaveClass(/is-drop-after/);
    await touch('touchEnd', tx, ty);
    await expect.poll(async () => (await topIds(page, sheet)).slice(0, 2), { timeout: 15_000 }).toEqual([b, a]);
    await settled(page);

    // and back, from the menu (the touch route that needs no dragging at all)
    await sheet.locator(`${ROWS}[data-row="${a}"]`).click();
    await sheet.getByRole('tab', { name: /^Layers$/ }).click();
    await sheet.getByTestId(`row-menu-${a}`).click();
    await page.getByRole('menuitem').and(page.locator('[data-action="move_up"]')).click();
    await expect.poll(async () => (await topIds(page, sheet)).slice(0, 2), { timeout: 15_000 }).toEqual([a, b]);
  });
});
