// Free-form style controls validate like the server does (B2-P3a): an invalid draft shows an inline error and is
// never committed; a valid one commits and reaches the canvas.
import { test, expect } from '@playwright/test';
import { openBuilder, frameDocument, frameEval, settled, sandboxPageId, openSection } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked Inspector (desktop)');
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
    await expect(undo).toBeEnabled({ timeout: 15_000 });
    await undo.click();
    await page.waitForTimeout(500);
  }
  throw new Error('could not restore the shared sandbox page');
}

test('a hostile or malformed value shows an inline error and is not applied; a valid one is', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await page.getByRole('tab', { name: /^(Add|Ajouter)$/ }).click();
    const panel = page.locator('#sbx-leftpanel-blocks');
    await panel.getByRole('tab', { name: 'Elements' }).click();
    await panel.locator('[data-chip="content"]').click();
    await panel.locator('[data-block-type="core.quote"]').click();
    await settled(page);
    await page.locator('[id^="sbx-blk-"][id$="-tab-style"]').click();

    const size = page.getByLabel('Font Size', { exact: true });
    await size.fill('url(https://evil.test/x.png)');
    await size.blur();
    await expect(page.getByRole('alert').filter({ hasText: /Not a valid value/ })).toBeVisible();
    await expect(size).toHaveAttribute('aria-invalid', 'true');
    const frame = await frameDocument(page);
    expect(await frame.locator('body').innerHTML()).not.toContain('evil.test');

    await size.fill('1.5rem');
    await size.blur();
    await expect(page.getByRole('alert').filter({ hasText: /Not a valid value/ })).toHaveCount(0);
    await settled(page);
    await expect(size).toHaveValue('1.5rem');

    const color = page.locator('input[id$="-tcolor-text"]');
    await color.fill('url(x)');
    await expect(page.getByRole('alert').filter({ hasText: /Not a valid value/ })).toBeVisible();
    await color.fill('#e8734a');
    await expect(page.getByRole('alert').filter({ hasText: /Not a valid value/ })).toHaveCount(0);
  } finally {
    await restore(page, before);
  }
});

test('the Z-Index field cannot be set above the ceiling that keeps the platform signature on top', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await page.getByRole('tab', { name: /^(Add|Ajouter)$/ }).click();
    const panel = page.locator('#sbx-leftpanel-blocks');
    await panel.getByRole('tab', { name: 'Elements' }).click();
    await panel.locator('[data-chip="content"]').click();
    await panel.locator('[data-block-type="core.quote"]').click();
    await settled(page);
    await page.locator('[id^="sbx-blk-"][id$="-tab-advanced"]').click();
    await openSection(page, 'stacking');
    const z = page.getByRole('spinbutton', { name: 'Z-Index', exact: true });
    await expect(z).toHaveAttribute('max', '999');
    await z.fill('9999');
    await expect(z).toHaveValue('999');
  } finally {
    await restore(page, before);
  }
});

test('the Background controls offer only what the server accepts, and what they write saves', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await page.getByRole('tab', { name: /^(Add|Ajouter)$/ }).click();
    const panel = page.locator('#sbx-leftpanel-blocks');
    await panel.getByRole('tab', { name: 'Elements' }).click();
    await panel.locator('[data-chip="content"]').click();
    await panel.locator('[data-block-type="core.quote"]').click();
    await settled(page);
    await page.locator('[id^="sbx-blk-"][id$="-tab-style"]').click();

    await openSection(page, 'background');
    const pills = page.getByRole('radiogroup', { name: 'Background type' }).getByRole('button');
    await expect(pills).toHaveText(['Image', 'Color', 'Gradient']); // no video: the server has no background video

    await page.getByRole('radiogroup', { name: 'Background type' }).getByRole('button', { name: 'Image' }).click();
        await expect(page.getByRole('group', { name: 'Background image' }).getByLabel('Media ID')).toBeVisible(); // a media reference, not a typed URL
    await expect(page.getByLabel('Fit', { exact: true })).toHaveCount(0); // nothing to fit until an image is chosen

    await page.getByRole('radiogroup', { name: 'Background type' }).getByRole('button', { name: 'Gradient' }).click();
    await page.getByLabel('Angle').fill('90');
    await settled(page);
    await expect(page.getByRole('status').filter({ hasText: /saved/i }).first()).toBeVisible({ timeout: 15_000 });
    await expect(page.getByRole('alert')).toHaveCount(0);
  } finally {
    await restore(page, before);
  }
});

test('a style edit reaches the canvas without a manual reload', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await page.getByRole('tab', { name: /^(Add|Ajouter)$/ }).click();
    const panel = page.locator('#sbx-leftpanel-blocks');
    await panel.getByRole('tab', { name: 'Elements' }).click();
    await panel.locator('[data-chip="content"]').click();
    await panel.locator('[data-block-type="core.quote"]').click();
    await settled(page);
    await page.locator('[id^="sbx-blk-"][id$="-tab-style"]').click();
    const size = page.getByLabel('Font Size', { exact: true });
    await size.fill('37px');
    await size.blur();
    await settled(page);

    // The server writes the style on the block's wrapper; the editor no longer paints it itself.
    await expect.poll(async () => frameEval(page, '[data-sb-type="core.quote"]', (el) => getComputedStyle(el).fontSize), { timeout: 20_000 }).toBe('37px');
  } finally {
    await restore(page, before);
  }
});
