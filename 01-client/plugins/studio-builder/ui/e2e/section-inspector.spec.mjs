// A section's Inspector: a compact header, and padding boxes wide enough to type in.
import { test, expect } from '@playwright/test';
import { openBuilder, settled, layerCount, restoreLayers } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked panel (desktop)');
});

test('the section header is compact, and the four padding boxes are usable', async ({ page }) => {
  test.setTimeout(90_000);
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await page.getByRole('button', { name: /Add section/ }).first().click();
    await settled(page);
    const head = page.locator('.sbx-inspector .sbx-ihead').first();
    await expect(head).toBeVisible();
    const tools = page.locator('.sbx-inspector__tools').first();
    const h = await head.boundingBox();
    const t = await tools.boundingBox();
    expect(t.y + t.height - h.y, 'header and tool buttons together').toBeLessThan(90);

    await page.getByRole('tab', { name: 'Advanced' }).click();
    const boxes = page.locator('.sbx-spacing--box .sbx-spacing__grid input');
    await expect(boxes).toHaveCount(4);
    for (const w of await boxes.evaluateAll((els) => els.map((e) => e.getBoundingClientRect().width))) {
      expect(w, 'a padding box is wide enough to read a number').toBeGreaterThanOrEqual(44);
    }
  } finally {
    await restoreLayers(page, before);
  }
});
