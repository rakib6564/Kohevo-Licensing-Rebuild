// Part styles (BlockParts): a Tabs block's tab titles take their own size from Style › Parts, the active tab
// its own border, and the canvas shows them on those elements only.
import { test, expect } from '@playwright/test';
import { openBuilder, frameEval, settled, openSection, layerCount, restoreLayers } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked Inspector (desktop)');
});

test('tab titles take their own size, and the active tab its own border', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await page.getByRole('tab', { name: /^(Add|Ajouter)$/ }).click();
    const panel = page.locator('#sbx-leftpanel-blocks');
    await panel.getByRole('searchbox').fill('tabs');
    await panel.locator('[data-block-type="core.tabs"]').first().click();
    await settled(page);

    const body = await openSection(page, 'parts');
    const parts = body.locator('[data-part-styles]');
    await expect(parts.getByLabel('Part', { exact: true })).toHaveValue('tab');
    await parts.getByLabel('Font Size', { exact: true }).fill('19px');
    await parts.getByLabel('Font Size', { exact: true }).blur();
    await settled(page);
    await expect.poll(() => frameEval(page, '.sb-tabs__tab', (el) => getComputedStyle(el).fontSize), { timeout: 20_000 }).toBe('19px');

    await parts.getByLabel('Part', { exact: true }).selectOption('tab_active');
    await parts.getByLabel('Border Width', { exact: true }).fill('3px');
    await parts.getByLabel('Border Width', { exact: true }).blur();
    await settled(page);
    await parts.getByLabel('Border Style', { exact: true }).selectOption('solid');
    await settled(page);
    await expect.poll(() => frameEval(page, '.sb-tabs__tab[aria-selected="true"]', (el) => getComputedStyle(el).borderTopWidth), { timeout: 20_000 }).toBe('3px');
    expect(await frameEval(page, '.sb-tabs__tab[aria-selected="false"]', (el) => getComputedStyle(el).borderTopWidth)).not.toBe('3px');
    // The picker marks the parts that have a style.
    await expect(parts.getByLabel('Part', { exact: true }).locator('option[value="tab"]')).toHaveText(/•/);
    await expect(page.getByRole('alert')).toHaveCount(0);
  } finally {
    await restoreLayers(page, before);
  }
});
