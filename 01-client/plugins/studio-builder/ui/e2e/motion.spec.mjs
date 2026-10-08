// Motion (B2-P3d): the move/reveal presets, and a section's own motion.
import { test, expect } from '@playwright/test';
import { openBuilder, frameDocument, settled, sandboxPageId, openSection } from './helpers.mjs';

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

async function insertBlock(page, type, chip = 'content') {
  await page.getByRole('tab', { name: /^(Add|Ajouter)$/ }).click();
  const panel = page.locator('#sbx-leftpanel-blocks');
  await panel.getByRole('tab', { name: 'Elements' }).click();
  await panel.locator(`[data-chip="${chip}"]`).click();
  await panel.locator(`[data-block-type="${type}"]`).click();
  await settled(page);
}




test('a block takes a reveal preset and a section takes its own motion; both reach the page as classes', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await insertBlock(page, 'core.heading');
    await page.locator('[id^="sbx-blk-"][id$="-tab-style"]').click();
    await openSection(page, 'motion');
    const presets = page.locator('[data-section="motion"]').getByRole('radiogroup', { name: 'Entrance' });
    await presets.getByRole('radio', { name: /Reveal up/ }).click();
    await settled(page);
    const frame = await frameDocument(page);
    await expect(frame.locator('[data-sb-type="core.heading"].sb-animate-reveal-up')).toHaveCount(1, { timeout: 20_000 });

    await page.getByRole('tab', { name: /^Layers$/ }).click();
    await page.locator('[role="treeitem"]').first().click();
    await openSection(page, 'motion');
    const sectionPresets = page.getByRole('radiogroup', { name: 'Entrance' });
    await sectionPresets.getByRole('radio', { name: /Move left/ }).click();
    await page.locator('select[id$="-trigger"]').selectOption('viewport-enter');
    await settled(page);
    const section = frame.locator('section.sb-section.sb-animate-move-left.sb-interaction-viewport-enter');
    await expect(section).toHaveCount(1, { timeout: 20_000 });
    await expect(section).toHaveAttribute('data-sb-interaction-trigger', 'viewport-enter');
    await expect(page.getByRole('alert')).toHaveCount(0);
  } finally {
    await restore(page, before);
  }
});
