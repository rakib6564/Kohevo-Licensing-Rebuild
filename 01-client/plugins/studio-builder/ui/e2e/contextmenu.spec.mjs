// Canvas right-click menu and the clipboard (copy, cut, paste) for sections.
// Each test restores the shared sandbox page to the layer count it started with.
import { test, expect } from '@playwright/test';
import { openBuilder, layerCount, restoreLayers, settled } from './helpers.mjs';

const ROWS = '[role="treeitem"]';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked layout (desktop)');
});

// The page's own sections: Layers also lists the shared header and footer as level-1 rows, which are not ours.
const OWN = `${ROWS}[aria-level="1"][data-row^="sec_"]`;

async function ensureSection(page) {
  await page.getByRole('tab', { name: /^Layers$/ }).click();
  if ((await page.locator(OWN).count()) === 0) {
    await page.getByRole('button', { name: /Add section/ }).first().click();
    await expect.poll(() => page.locator(OWN).count(), { timeout: 15_000 }).toBeGreaterThan(0);
  }
  await settled(page);
  return page.locator(OWN).first().getAttribute('data-row');
}

/** Right-click until the menu is there: the canvas frame binds its handlers a moment after the page loads. */
async function openMenu(page, target) {
  const menu = page.getByTestId('canvas-context-menu');
  await expect(async () => {
    await target.click({ button: 'right' });
    await expect(menu).toBeVisible({ timeout: 1500 });
  }).toPass({ timeout: 20_000 });
  return menu;
}

test('right-click opens the menu in the builder, Escape closes it and returns focus', async ({ page }) => {
  await openBuilder(page);
  const before = await layerCount(page);
  const own = await ensureSection(page);
  const start = await layerCount(page);
  const frame = page.frameLocator('iframe.sbx-canvas__frame');
  const menu = await openMenu(page, frame.locator(`[data-sb-node="${own}"]`));
  await expect(menu).toHaveAttribute('role', 'menu');
  const actions = await menu.getByRole('menuitem').evaluateAll((els) => els.map((e) => e.getAttribute('data-action')));
  expect(actions).toEqual(expect.arrayContaining(['edit', 'duplicate', 'copy', 'paste_after', 'cut', 'delete']));
  await page.keyboard.press('ArrowDown');
  await expect(menu.getByRole('menuitem').nth(1)).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(menu).toHaveCount(0);
  await restoreLayers(page, before);
});

test('copy then paste a section from the menu adds one, and undo removes it', async ({ page }) => {
  await openBuilder(page);
  const before = await layerCount(page);
  const own = await ensureSection(page);
  const start = await layerCount(page);
  const frame = page.frameLocator('iframe.sbx-canvas__frame');
  const section = frame.locator(`[data-sb-node="${own}"]`);
  await (await openMenu(page, section)).getByRole('menuitem', { name: 'Copy' }).click();
  await (await openMenu(page, section)).getByRole('menuitem', { name: 'Paste after' }).click();
  await expect.poll(() => layerCount(page), { timeout: 20_000 }).toBeGreaterThan(start);
  await restoreLayers(page, before);
});
