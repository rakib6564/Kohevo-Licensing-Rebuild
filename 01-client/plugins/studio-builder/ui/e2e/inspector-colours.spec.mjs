// The Inspector shows the colours a block really has, and one control edits them: a button styled through its `button`
// part (as the FightxLife pages are) shows that part's colours in Normal and Hover, and an edit goes where it will show.
import { test, expect } from '@playwright/test';
import { openBuilder, openSection, settled, layerCount, restoreLayers } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked Inspector (desktop)');
});

test('a plain button names its variant; a part sets Normal and Hover; a theme colour works in Hover', async ({ page }, info) => {
  test.setTimeout(120_000);
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await page.getByRole('tab', { name: /^Add$/ }).click();
    const blocks = page.locator('#sbx-leftpanel-blocks');
    await blocks.getByRole('tab', { name: 'Elements' }).click();
    await blocks.locator('[data-block-type="core.button"]').first().click();
    const setup = page.getByRole('dialog').filter({ hasText: /Set up/ });
    if (await setup.isVisible({ timeout: 2500 }).catch(() => false)) {
      const fields = setup.locator('input[type="text"], input:not([type]), input[type="url"]');
      for (let k = 0; k < await fields.count(); k++) await fields.nth(k).fill(k === 1 ? '/book' : 'Book');
      await setup.getByRole('button', { name: /^Add$/ }).click();
    }
    await settled(page);
    await page.locator('[id^="sbx-blk-"][id$="-tab-style"]').click();

    // Nothing set: the variant's colours, marked as inherited, and one control per colour.
    const style = await openSection(page, 'button_style');
    const hoverTab = style.getByRole('tab', { name: 'Hover' }).first();
    const row = style.getByTestId('normal-hover');
    const text = row.locator('input[id$="-text-text"]');
    await expect(text).toHaveValue('');
    await expect(text).not.toHaveAttribute('placeholder', 'Inherit');
    await expect(row.locator('.sbx-color[data-state="inherited"]').first()).toBeVisible();
    await expect(row.getByTestId('colour-source').first()).toContainText('button variant');
    await expect(style.getByText('Theme colour')).toHaveCount(0);

    // The part holds the colours, as on the contact page.
    const parts = await openSection(page, 'parts');
    await parts.locator('input[id$="-color-text"]').fill('#0c0905');
    await parts.locator('input[id$="-bg-text"]').fill('#fafafa');
    await parts.locator('input[id$="-bg-text"]').blur();
    await settled(page);
    await parts.getByRole('radio', { name: 'Hover' }).or(parts.getByRole('button', { name: 'Hover' })).first().click();
    await parts.locator('input[id$="-color-text"]').fill('#e9008a');
    await parts.locator('input[id$="-color-text"]').blur();
    await settled(page);

    const link = page.frameLocator('iframe.sbx-canvas__frame').locator('.sb-button').last();
    await expect.poll(() => link.evaluate((el) => getComputedStyle(el).backgroundColor), { timeout: 15_000 }).toBe('rgb(250, 250, 250)');

    await openSection(page, 'button_style');
    await expect(text).toHaveValue('#0c0905');
    await expect(row.getByTestId('colour-source').first()).toContainText('Set in Parts');

    await hoverTab.click();
    await expect(row.locator('input[id$="-text"]').first()).toHaveValue('#e9008a');
    const hoverBg = row.locator('.sbx-field').filter({ hasText: 'Background' }).first();
    await expect(hoverBg.locator('input[type="text"]')).toHaveAttribute('placeholder', '#fafafa');
    await expect(hoverBg.getByTestId('colour-source')).toContainText('Same as Normal');
    await page.screenshot({ path: info.outputPath('hover.png') });

    // The same picker in Hover: a theme colour is accepted, saved and written as the theme property.
    await hoverBg.locator('button.sbx-color__swatch').click();
    await expect(hoverBg.locator('.sbx-cp')).toBeVisible();
    const panel = await hoverBg.locator('.sbx-cp').boundingBox();
    const left = await page.locator('.sbx-left').boundingBox();
    expect(panel.x).toBeGreaterThanOrEqual(left.x);
    expect(panel.x + panel.width).toBeLessThanOrEqual(left.x + left.width);
    await page.screenshot({ path: info.outputPath('picker.png') });
    await hoverBg.locator('.sbx-cp .sbx-cp__opt').nth(1).click();
    await settled(page);
    await expect.poll(() => page.frameLocator('iframe.sbx-canvas__frame').locator('html').evaluate(
      (el) => Array.from(el.ownerDocument.styleSheets).flatMap((s) => { try { return Array.from(s.cssRules).map((r) => r.cssText); } catch { return []; } }).join('\n'),
    ), { timeout: 15_000 }).toMatch(/sb-button:hover[^}]*background-color:\s*var\(--sb-/);
  } finally {
    await restoreLayers(page, before);
  }
});
