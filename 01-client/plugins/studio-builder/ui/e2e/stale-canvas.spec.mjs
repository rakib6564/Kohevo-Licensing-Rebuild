// A saved text edit must reach the canvas even when the edit is made while the render of the previous save is still on its
// way: that render (older than the edit) is merged into the canvas, and the edit has to be painted again on top of it.
import { test, expect } from '@playwright/test';
import { layerCount, openBuilder, restoreLayers, settled } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked Inspector (desktop)');
});

const canvas = (page) => page.frameLocator('iframe.sbx-canvas__frame');

test('a text edit made while the render of the previous save is on its way is still on the canvas afterwards', async ({ page }) => {
  test.setTimeout(90_000);
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    // The canvas render is slow: the repaint that follows the insert's save lands well after the edit.
    let slow = true;
    await page.route('**/canvas.php*', async (route) => {
      if (slow) await new Promise((resolve) => setTimeout(resolve, 1500));
      await route.continue();
    });

    await page.getByRole('tab', { name: /^(Add|Ajouter)$/ }).click();
    const panel = page.locator('#sbx-leftpanel-blocks');
    await panel.getByRole('tab', { name: 'Elements' }).click();
    await panel.locator('[data-chip="content"]').click();
    await panel.locator('[data-block-type="core.heading"]').click();
    await expect(canvas(page).locator('[data-sb-type="core.heading"] .sb-heading')).toHaveCount(1, { timeout: 10_000 });
    await expect(canvas(page).locator('.sbx-pending')).toHaveCount(0, { timeout: 10_000 });

    const text = page.getByLabel('Heading Text', { exact: false }).first();
    await text.fill('Edited fast');
    await text.blur();
    await expect(canvas(page).locator('[data-sb-type="core.heading"] .sb-heading').last()).toHaveText('Edited fast', { timeout: 5_000 }); // painted at once

    slow = false;
    await settled(page);
    // Whatever render lands, and however it is ordered with the save, the canvas ends up showing the saved text.
    await page.waitForTimeout(5_000);
    await expect(canvas(page).locator('[data-sb-type="core.heading"] .sb-heading').last()).toHaveText('Edited fast', { timeout: 20_000 });
  } finally {
    await page.unrouteAll({ behavior: 'ignoreErrors' });
    await restoreLayers(page, before);
  }
});
