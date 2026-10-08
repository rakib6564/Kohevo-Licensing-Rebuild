// The three-region workspace (reference D01/D02): Add and Layers on the left, the canvas in the middle, and the
// Inspector docked on the right. Selecting a node fills the right panel and leaves the left panel where it was.
import { test, expect } from '@playwright/test';
import { openBuilder, frameDocument, settled, sandboxPageId } from './helpers.mjs';

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
    await expect(undo).toBeEnabled({ timeout: 5_000 });
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

test('on a desktop the Inspector is docked on the right, and the left panel has no Style tab', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop', 'docked layout');
  await openBuilder(page);
  await expect(page.getByTestId('inspector-panel')).toBeVisible();
  await expect(page.getByRole('tablist', { name: 'Structure' }).getByRole('tab')).toHaveText(['Add', 'Layers', 'Library', 'Settings']);
  const left = await page.locator('aside.sbx-left').boundingBox();
  const right = await page.getByTestId('inspector-panel').boundingBox();
  const canvas = await page.locator('main, .sbx-canvas').first().boundingBox();
  expect(right.x).toBeGreaterThan(left.x + left.width - 1); // right of the left panel
  expect(right.x + right.width).toBeGreaterThan(page.viewportSize().width - 2); // flush with the window's right edge
  expect(canvas.width).toBeGreaterThan(300);
});

test('selecting a block fills the right panel and leaves the left panel on its tab', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop', 'docked layout');
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await page.getByRole('tab', { name: /^(Add|Ajouter)$/ }).click();
    await insertHeading(page);
    await expect(page.getByTestId('inspector-panel').locator('.sbx-ihead .sbx-inspector__title')).toHaveText('Heading');
    await expect(page.getByRole('tab', { name: /^Add$/ })).toHaveAttribute('aria-selected', 'true'); // not pulled to another tab
    await expect(page.locator('#sbx-leftpanel-blocks')).toBeVisible();
    await expect(page.locator('[data-section="content"]')).toHaveCount(1); // one Inspector, not two
  } finally {
    await restore(page, before);
  }
});

test('the toggle hides the Inspector for a wider canvas and shows it again with the selection intact', async ({ page }, info) => {
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
    await expect(page.getByTestId('inspector-panel')).toHaveCount(0);
    await expect(toggle).toHaveAttribute('aria-pressed', 'false');
    await expect.poll(async () => (await page.locator('iframe.sbx-canvas__frame').boundingBox()).width).toBeGreaterThan(open);
    await toggle.click();
    await expect(page.getByTestId('inspector-panel').locator('.sbx-ihead .sbx-inspector__title')).toHaveText('Heading');
  } finally {
    await restore(page, before);
  }
});

test('with nothing selected the right panel says so, and its shortcuts open the left tabs', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop', 'docked layout');
  await openBuilder(page);
  const panel = page.getByTestId('inspector-panel');
  await expect(panel.getByText(/Select a block or section/)).toBeVisible();
  await panel.getByRole('button', { name: 'Add elements' }).click();
  await expect(page.getByRole('tab', { name: /^Add$/ })).toHaveAttribute('aria-selected', 'true');
  await panel.getByRole('button', { name: 'View Layers' }).click();
  await expect(page.getByRole('tab', { name: /^Layers$/ })).toHaveAttribute('aria-selected', 'true');
});

test('a phone has no right panel: the Inspector stays in the sheet', async ({ page }, info) => {
  test.skip(info.project.name === 'desktop', 'phone shell');
  await openBuilder(page);
  await expect(page.getByTestId('inspector-panel')).toHaveCount(0);
});
