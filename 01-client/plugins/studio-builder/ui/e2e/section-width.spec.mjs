// A section's Layout › Width shows on the canvas the moment it is picked (not after the save and the repaint), the
// presets are labelled with this site's sizes, and Custom takes a size per device.
import { test, expect } from '@playwright/test';
import { openBuilder, frameEval, settled, layerCount, restoreLayers } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked Inspector (desktop)');
});

const innerWidth = (page) => frameEval(page, '.sb-section__inner', (el) => Math.round(el.getBoundingClientRect().width));

test('a section width is painted at once, and a custom width is checked and kept per device', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  // The autosave is held: whatever the canvas shows meanwhile, it did not get from the server.
  let release;
  const gate = new Promise((resolve) => { release = resolve; });
  try {
    await page.getByRole('tab', { name: /^(Add|Ajouter)$/ }).click();
    const panel = page.locator('#sbx-leftpanel-blocks');
    await panel.getByRole('tab', { name: 'Elements' }).click();
    await panel.locator('[data-chip="content"]').click();
    await panel.locator('[data-block-type="core.heading"]').click();
    await settled(page);

    // Select the new section by its top edge (its padding, not the heading).
    const frame = page.frameLocator('iframe.sbx-canvas__frame');
    await frame.locator('section.sb-section').last().click({ position: { x: 4, y: 4 } });
    const width = page.locator('select[id$="-width"]');
    await expect(width).toHaveValue('default');
    await expect(width.locator('option')).toHaveText(['Default · 1280px', 'Narrow · 672px', 'Normal · 1024px', 'Wide · 1280px', 'Full width · no limit', 'Custom']);

    await page.route('**/admin/api.php?action=operations*', async (route) => { await gate; await route.continue(); });
    await width.selectOption('narrow');
    await expect.poll(() => innerWidth(page), { timeout: 2_000 }).toBe(672);
    await width.selectOption('normal');
    await expect.poll(() => innerWidth(page), { timeout: 2_000 }).toBe(1024);
    release();
    await page.unrouteAll({ behavior: 'ignoreErrors' });
    await settled(page);

    await width.selectOption('custom');
    const size = page.getByTestId('responsive-size').getByRole('textbox');
    await size.fill('12;color:red');
    await size.blur();
    await expect(page.getByTestId('responsive-size').getByRole('alert')).toBeVisible();
    await size.fill('900');
    await size.blur();
    await expect(page.getByTestId('responsive-size').getByRole('alert')).toHaveCount(0);
    await expect(size).toHaveValue('900px');
    await expect.poll(() => innerWidth(page), { timeout: 2_000 }).toBe(900);
    // The server's render agrees once the save lands.
    await expect.poll(() => frameEval(page, '.sb-section__inner', (el) => el.getAttribute('style')), { timeout: 20_000 }).toBe('--sb-cw-lg:900px');
  } finally {
    release();
    await page.unrouteAll({ behavior: 'ignoreErrors' });
    await restoreLayers(page, before);
  }
});
