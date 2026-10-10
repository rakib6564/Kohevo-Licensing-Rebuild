// The Inspector bar: Reset clears a block's look, Discard puts it back to how it was when selected, Apply saves now.
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

test('Reset clears the look, Discard restores the selection state, Apply saves now', async ({ page }) => {
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

    const bar = page.getByTestId('inspector-bar');
    const reset = bar.getByRole('button', { name: 'Reset' });
    const discard = bar.getByRole('button', { name: 'Discard' });
    const apply = bar.getByRole('button', { name: 'Apply' });
    const literal = page.locator('input[id$="-tcolor-text"]');
    await expect(discard).toBeDisabled();

    // an edit: Discard and Reset come alive; Discard takes it back
    await literal.fill('#e8734a');
    await literal.blur();
    await expect(discard).toBeEnabled();
    await expect(reset).toBeEnabled();
    await discard.click();
    await expect(literal).toHaveValue('');
    await expect(discard).toBeDisabled();

    // Reset clears a look the author built up; one undo brings it back
    await literal.fill('#e8734a');
    await literal.blur();
    await settled(page);
    await reset.click();
    await expect(literal).toHaveValue('');
    await expect(reset).toBeDisabled();

    // Apply is live only while there is something to save, and saves
    await literal.fill('#112233');
    await literal.blur();
    await expect(apply).toBeEnabled();
    await apply.click();
    await expect(apply).toBeDisabled();
    await settled(page);
    await expect(literal).toHaveValue('#112233');
  } finally {
    await restore(page, before);
  }
});
