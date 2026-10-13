// The two-region workspace: one left panel (Add, Layers, Edit, Library, Settings) and the canvas filling the rest.
// Picking on the canvas, or adding an element, opens the panel's Edit tab; picking a Layers row leaves it on Layers.
import { test, expect } from '@playwright/test';
import { openBuilder, frameDocument, settled, sandboxPageId, showEdit } from './helpers.mjs';

test.afterEach(async ({ page }) => {
  await page.goto(`/plugins/studio-builder/admin/builder.php?page=${sandboxPageId()}&lang=en`);
});

async function layerCount(page) {
  await page.getByRole('tab', { name: /^Layers$/ }).click();
  return page.locator('[role="treeitem"]').count();
}

async function restore(page, layers) {
  for (let i = 0; i < 16; i++) {
    await settled(page);
    if ((await layerCount(page)) <= layers) return;
    const undo = page.getByTestId('undo');
    await expect(undo).toBeEnabled({ timeout: 15_000 });
    await undo.click();
    await page.waitForTimeout(500);
  }
  throw new Error('could not restore the shared sandbox page');
}

async function insertHeading(page) {
  await page.getByRole('tab', { name: /^(Add|Ajouter)$/ }).click();
  const panel = page.locator('#sbx-leftpanel-blocks');
  await panel.getByRole('tab', { name: 'Elements' }).click();
  await panel.locator('[data-chip="content"]').click();
  await panel.locator('[data-block-type="core.heading"]').click();
  await settled(page);
}

test('on a desktop one left panel holds every tab and the canvas fills the rest of the window', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop', 'docked layout');
  await openBuilder(page);
  await expect(page.getByRole('tablist', { name: 'Structure' }).getByRole('tab')).toHaveText(['Add', 'Layers', 'Edit', 'Library', 'Settings']);
  await expect(page.locator('.sbx-right')).toHaveCount(0); // no second, docked Inspector
  const left = await page.locator('aside.sbx-left').boundingBox();
  const canvas = await page.locator('iframe.sbx-canvas__frame').boundingBox();
  expect(left.width).toBeLessThan(341);
  expect(canvas.x).toBeGreaterThan(left.x + left.width - 1); // right of the left panel
  expect(canvas.x + canvas.width).toBeGreaterThan(page.viewportSize().width - 24); // and out to the window's edge
});

test('adding an element or picking it on the canvas opens Edit; the other tabs stay one click away', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop', 'docked layout');
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await insertHeading(page);
    const edit = page.getByRole('tab', { name: /^Edit$/ });
    await expect(edit).toHaveAttribute('aria-selected', 'true'); // a new element opens its controls
    await expect(page.getByTestId('inspector-panel').locator('.sbx-ihead .sbx-inspector__title')).toHaveText('Heading');
    await expect(page.locator('[data-section="content"]')).toHaveCount(1); // one Inspector, not two

    await page.getByRole('tab', { name: /^Add$/ }).click();
    await expect(page.locator('#sbx-leftpanel-blocks')).toBeVisible();
    await expect(page.getByTestId('inspector-panel')).toBeHidden();

    await (await frameDocument(page)).locator('[data-sb-type="core.heading"]').first().click({ force: true });
    await expect(edit).toHaveAttribute('aria-selected', 'true'); // a canvas pick brings the controls back
    await expect(page.getByTestId('inspector-panel')).toBeVisible();

    await page.getByRole('tab', { name: /^Layers$/ }).click();
    await page.locator('[role="treeitem"]').first().click();
    await expect(page.getByRole('tab', { name: /^Layers$/ })).toHaveAttribute('aria-selected', 'true'); // a Layers pick keeps the tree
    await showEdit(page);
  } finally {
    await restore(page, before);
  }
});

test('the toggle collapses the panel for a wider canvas and brings Edit back with the selection intact', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop', 'docked layout');
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await insertHeading(page);
    const toggle = page.getByTestId('inspector-toggle');
    await expect(toggle).toHaveAttribute('aria-pressed', 'true');
    const open = (await page.locator('iframe.sbx-canvas__frame').boundingBox()).width;
    await toggle.click();
    await expect(toggle).toHaveAttribute('aria-pressed', 'false');
    await expect.poll(async () => (await page.locator('iframe.sbx-canvas__frame').boundingBox()).width).toBeGreaterThan(open);
    await toggle.click();
    await expect(toggle).toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByTestId('inspector-panel').locator('.sbx-ihead .sbx-inspector__title')).toHaveText('Heading');
  } finally {
    await restore(page, before);
  }
});

test('with nothing selected Edit says so, and its shortcuts open the other tabs', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop', 'docked layout');
  await openBuilder(page);
  await showEdit(page);
  const panel = page.getByTestId('inspector-panel');
  await expect(panel.getByText(/Select a block or section/)).toBeVisible();
  await panel.getByRole('button', { name: 'Add elements' }).click();
  await expect(page.getByRole('tab', { name: /^Add$/ })).toHaveAttribute('aria-selected', 'true');
  await showEdit(page);
  await panel.getByRole('button', { name: 'View Layers' }).click();
  await expect(page.getByRole('tab', { name: /^Layers$/ })).toHaveAttribute('aria-selected', 'true');
});

test('a phone keeps the Inspector in the sheet: its panel is not on screen until the sheet opens', async ({ page }, info) => {
  test.skip(info.project.name === 'desktop', 'phone shell');
  await openBuilder(page);
  await expect(page.getByTestId('inspector-panel')).toBeHidden();
});
