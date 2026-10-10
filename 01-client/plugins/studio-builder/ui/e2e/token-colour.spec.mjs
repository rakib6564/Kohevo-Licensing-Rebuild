// A colour field offers the theme palette; a theme colour replaces the literal one and shows on the canvas at once.
import { test, expect } from '@playwright/test';
import { openBuilder, frameDocument, settled, sandboxPageId } from './helpers.mjs';

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

test('a theme colour is picked from the palette, replaces a literal colour on the canvas, and "Custom" takes it back', async ({ page }) => {
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

    const literal = page.locator('input[id$="-tcolor-text"]');
    await literal.fill('#e8734a');
    await literal.blur();
    await settled(page);
    const frame = await frameDocument(page);
    const quote = frame.locator('[data-sb-type="core.quote"]').last();

    const picker = page.locator('[id$="-tcolor-token"]');
    await picker.click();
    await page.getByRole('listbox').getByRole('option').nth(1).click();
    await expect(literal).toHaveValue('');
    await expect(quote).toHaveClass(/sb-fg--/);
    await settled(page);
    await expect(quote).toHaveClass(/sb-fg--/);
    await expect(picker).not.toContainText('Custom');

    await picker.click();
    await page.getByRole('listbox').getByRole('option').first().click();
    await expect(quote).not.toHaveClass(/sb-fg--/);
    await expect(picker).toContainText('Custom');
  } finally {
    await restore(page, before);
  }
});
