// The Background image pane tells the truth about what the page draws: the preview carries the overlay, the overlay is
// named under it with a way to remove it, the tabs mark which kinds of background are set, and the Color tab says an
// image covers it.
import { test, expect } from '@playwright/test';
import { chooseImage, openBuilder, settled, openSection, sandboxPageId, undoReady } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked Edit panel (desktop)');
});

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
    if (!(await undoReady(undo))) return; // nothing left to undo: stop quietly so the test's own error is the one reported
    await undo.click();
    await page.waitForTimeout(500);
  }
  throw new Error('could not restore the shared sandbox page');
}

test('an overlay shows on the image preview, is named under it, and can be removed from there', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await page.getByRole('tab', { name: /^Add$/ }).click();
    const panel = page.locator('#sbx-leftpanel-blocks');
    await panel.getByRole('tab', { name: 'Elements' }).click();
    await panel.locator('[data-chip="layout"]').click();
    await panel.locator('[data-block-type="core.container"]').click();
    await settled(page);
    await page.getByTestId('chooser-close').click().catch(() => {});
    await page.getByRole('tab', { name: 'Style' }).click();
    const bg = await openSection(page, 'background');
    const modes = bg.getByRole('radiogroup', { name: 'Background type' });
    await modes.getByRole('button', { name: 'Image' }).click();
    await expect(bg.getByTestId('bg-overlay-note')).toHaveCount(0);
    await chooseImage(page, bg);
    await expect(modes.getByRole('button', { name: 'Image' })).toHaveClass(/has-value/);
    await expect(modes.getByRole('button', { name: 'Gradient' })).not.toHaveClass(/has-value/);
    await expect(bg.getByTestId('bg-preview-tint')).toHaveCount(0);

    await bg.getByRole('button', { name: 'Edit image options' }).click();
    const colour = bg.locator('input[id$="-overlay-text"]');
    await colour.fill('#2f5d2f');
    await colour.blur();
    await bg.getByLabel('Overlay opacity').fill('90');
    await expect(bg.getByTestId('bg-preview-tint')).toBeVisible();
    await expect(bg.getByTestId('bg-overlay-note')).toContainText('Overlay 90%');

    // The Color tab does not hide that an image paints over it.
    await modes.getByRole('button', { name: 'Color' }).click();
    await expect(bg.getByTestId('bg-covered-hint')).toBeVisible();
    await modes.getByRole('button', { name: 'Image' }).click();

    await bg.getByTestId('bg-overlay-note').getByRole('button', { name: 'Remove overlay' }).click();
    await expect(bg.getByTestId('bg-overlay-note')).toHaveCount(0);
    await expect(bg.getByTestId('bg-preview-tint')).toHaveCount(0);
  } finally {
    await restore(page, before);
  }
});
