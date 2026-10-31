// One control per setting: a Text's alignment and size live only in Style, and both paint on the canvas.
import { test, expect } from '@playwright/test';
import { openBuilder, openSection, settled, layerCount, restoreLayers, frameDocument } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked Inspector (desktop)');
});

test('a Text has one alignment and one size control, both in Style', async ({ page }) => {
  test.setTimeout(120_000);
  page.setDefaultTimeout(10_000);
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await page.getByRole('tab', { name: /^Add$/ }).click();
    const blocks = page.locator('#sbx-leftpanel-blocks');
    await blocks.getByRole('tab', { name: 'Elements' }).click();
    await blocks.locator('[data-chip="content"]').click();
    await blocks.locator('[data-block-type="core.text"]').first().click();
    await settled(page);

    const styleTab = page.locator('[id^="sbx-blk-"][id$="-tab-style"]');
    const content = page.locator('[id^="sbx-blk-"][id$="-panel-content"]');
    const text = (await frameDocument(page)).locator('.sb-text').last();

    await expect(content.getByRole('button', { name: 'center', exact: true })).toHaveCount(0);
    await expect(content.getByText('Alignment', { exact: true })).toHaveCount(0);
    await expect(content.getByLabel('Size', { exact: true })).toHaveCount(0);
    await expect(content.getByLabel('Color Token')).toHaveCount(0);

    await styleTab.click();
    const own = await openSection(page, 'text_style');
    await own.getByRole('button', { name: 'center', exact: true }).click();
    await settled(page);
    await expect(text).toHaveCSS('text-align', 'center');

    await own.getByLabel('Font Size', { exact: true }).first().fill('19px');
    await own.getByLabel('Font Size', { exact: true }).first().press('Tab');
    await settled(page);
    await expect(text).toHaveCSS('font-size', '19px');
  } finally {
    await restoreLayers(page, before);
  }
});
