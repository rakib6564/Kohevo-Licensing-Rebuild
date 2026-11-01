// A top-level Container's Inspector, the only Inspector a page's outer level has: a compact header with its tools, padding
// boxes wide enough to type in, and what a Section used to offer (Background Overlay, Shape Divider, boxed widths, Vertical
// spacing) in the same panel as any Container.
import { test, expect } from '@playwright/test';
import { openBuilder, settled, layerCount, restoreLayers } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked panel (desktop)');
});

async function addTopContainer(page) {
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  await page.getByRole('button', { name: /Add Container/ }).first().click();
  await settled(page);
  await expect(page.getByTestId('container-pane')).toBeVisible();
  return before;
}

test('the header is compact and carries the top-level tools, and the four padding boxes are usable', async ({ page }) => {
  test.setTimeout(90_000);
  const before = await addTopContainer(page);
  try {
    const head = page.locator('.sbx-inspector .sbx-ihead').first();
    await expect(head).toBeVisible();
    const tools = page.locator('.sbx-inspector__tools').first();
    const h = await head.boundingBox();
    const t = await tools.boundingBox();
    expect(t.y + t.height - h.y, 'header and tool buttons together').toBeLessThan(90);
    // Only a top-level Container adds a sibling, saves as a template or becomes a component; it cannot be indented.
    for (const name of ['Move up', 'Move down', 'Add Container', 'Delete']) await expect(tools.getByRole('button', { name, exact: true })).toHaveCount(1);
    await expect(tools.getByRole('button', { name: 'Move into container above', exact: true })).toHaveCount(0);

    await page.getByRole('tab', { name: 'Advanced' }).click();
    const boxes = page.getByRole('group', { name: 'Padding', exact: true }).locator('.sbx-spacing__grid input');
    await expect(boxes).toHaveCount(4);
    for (const w of await boxes.evaluateAll((els) => els.map((e) => e.getBoundingClientRect().width))) {
      expect(w, 'a padding box is wide enough to read a number').toBeGreaterThanOrEqual(44);
    }
  } finally {
    await restoreLayers(page, before);
  }
});

test('it has Background Overlay, Shape Divider, boxed widths and Vertical spacing', async ({ page }) => {
  test.setTimeout(90_000);
  const before = await addTopContainer(page);
  try {
    const pane = page.getByTestId('container-pane');
    await expect(pane.getByLabel('Container layout')).toHaveValue('flex');
    await expect(pane.getByLabel('Content width')).toHaveValue('boxed'); // a new top-level Container keeps to the site's width
    await expect(pane.locator('select[id$="-box-width"] option')).toHaveText(['Default · 1280px', 'Narrow · 672px', 'Normal · 1024px', 'Wide · 1280px', 'Full width · no limit', 'Custom']);

    const spacing = pane.locator('select[id$="-padding-y"]');
    await spacing.selectOption('lg');
    const frame = page.frameLocator('iframe.sbx-canvas__frame');
    const top = frame.locator('.sb-top[data-sb-node]').last();
    await expect.poll(() => top.evaluate((el) => parseFloat(getComputedStyle(el).paddingTop) > 0 && getComputedStyle(el).paddingTop === getComputedStyle(el).paddingBottom)).toBe(true);
    await settled(page);

    await page.locator('[id$="-tab-style"]').click();
    const panel = page.locator('[role="tabpanel"]');
    await expect(panel.locator('[data-section="overlay"]')).toHaveCount(1);
    await expect(panel.locator('[data-section="shape_divider"]')).toHaveCount(1);
    await panel.locator('[data-section="shape_divider"] .sbx-isec__toggle').click();
    await expect(page.getByTestId('shape-picker').getByRole('radio').first()).toBeVisible();
  } finally {
    await restoreLayers(page, before);
  }
});
