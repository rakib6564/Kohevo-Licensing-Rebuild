// The flex container: a Container block is a flex box whose direction, wrap, alignment and gaps are set from its Container
// section, differ per device, and show on the canvas at once; its children carry their own flex item settings.
import { test, expect } from '@playwright/test';
import { openBuilder, settled, sandboxPageId, openSection, undoReady } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked Inspector (desktop)');
});

test.afterEach(async ({ page }) => {
  await page.goto(`/plugins/studio-builder/admin/builder.php?page=${sandboxPageId()}&lang=en`);
});

const canvas = (page) => page.frameLocator('iframe.sbx-canvas__frame');

async function layerCount(page) {
  await page.getByRole('tab', { name: /^Layers$/ }).click();
  return page.locator('[role="treeitem"]').count();
}

async function restore(page, layers) {
  for (let i = 0; i < 16; i++) {
    await settled(page);
    if ((await layerCount(page)) <= layers) return;
    const undo = page.getByTestId('undo');
    if (!(await undoReady(undo))) return; // nothing left to undo: stop quietly so the test's own error is the one reported
    await undo.click();
    await page.waitForTimeout(500);
  }
  throw new Error('could not restore the shared sandbox page');
}

async function addContainer(page) {
  await page.getByRole('tab', { name: /^(Add|Ajouter)$/ }).click();
  const panel = page.locator('#sbx-leftpanel-blocks');
  await panel.getByRole('tab', { name: 'Elements' }).click();
  await panel.locator('[data-chip="layout"]').click();
  await panel.locator('[data-block-type="core.container"]').click();
  await settled(page);
}

const css = (loc, prop) => loc.evaluate((el, p) => getComputedStyle(el)[p], prop);

test('a Container is a flex box: its direction, wrap and alignment are set and differ on a phone', async ({ page }) => {
  test.setTimeout(90_000);
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await addContainer(page);
    const box = canvas(page).locator('[data-sb-type="core.container"].sbx-selected');
    await expect(box).toHaveCount(1);
    expect(await css(box, 'display')).toBe('flex');

    const body = await openSection(page, 'container');
    const group = (name) => body.getByRole('group', { name, exact: true });
    await group('Direction').getByRole('button', { name: 'Row', exact: true }).click();
    await group('Wrap').getByRole('button', { name: 'Wrap', exact: true }).click();
    await group('Justify').getByRole('button', { name: 'Space between', exact: true }).click();
    await group('Align items').getByRole('button', { name: 'Center', exact: true }).click();
    await body.getByLabel('Align content', { exact: true }).selectOption('between');
    const gap = body.getByRole('textbox', { name: 'Column', exact: true }); // linked with Row
    await gap.fill('3rem');
    await gap.press('Enter');
    await settled(page);
    await expect.poll(() => css(box, 'flexDirection'), { timeout: 15_000 }).toBe('row');
    await expect.poll(() => css(box, 'columnGap'), { timeout: 15_000 }).toBe('48px'); // beats the Container's own gap class
    expect(await css(box, 'flexWrap')).toBe('wrap');
    expect(await css(box, 'justifyContent')).toBe('space-between');
    expect(await css(box, 'alignItems')).toBe('center');
    expect(await css(box, 'alignContent')).toBe('space-between');

    // the phone gets its own direction: pick Mobile in the layout's device bar, then Column
    await body.getByRole('group', { name: /^Items/ }).getByRole('button', { name: 'Mobile', exact: true }).click();
    await group('Direction').getByRole('button', { name: 'Column', exact: true }).click();
    await settled(page);
    await expect.poll(() => css(box, 'flexDirection'), { timeout: 15_000 }).toBe('column');
    expect(await css(box, 'justifyContent')).toBe('space-between'); // everything not overridden stays

    // back on desktop the row is still there
    await body.getByRole('group', { name: /^Items/ }).getByRole('button', { name: 'Desktop', exact: true }).click();
    await expect.poll(() => css(box, 'flexDirection'), { timeout: 15_000 }).toBe('row');
  } finally {
    await restore(page, before).catch(() => {});
  }
});
