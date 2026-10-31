// Motion (B2-P3d): the move/reveal presets, and a Container's own motion.
import { test, expect } from '@playwright/test';
import { openBuilder, frameDocument, settled, sandboxPageId, openSection, showEdit, undoReady } from './helpers.mjs';

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
    if (!(await undoReady(undo))) return; // nothing left to undo: stop quietly so the test's own error is the one reported
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




test('a block takes a reveal preset and a Container takes its own motion; both reach the page as classes', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await insertBlock(page, 'core.heading');
    await page.locator('[id^="sbx-blk-"][id$="-tab-style"]').click();
    await openSection(page, 'motion');
    await page.getByTestId('motion-more').locator('summary').click();
    const presets = page.locator('[data-section="motion"]').getByRole('radiogroup', { name: 'Entrance' });
    await presets.getByRole('radio', { name: /Reveal up/ }).click();
    await settled(page);
    const frame = await frameDocument(page);
    await expect(frame.locator('[data-sb-type="core.heading"].sb-animate-reveal-up')).toHaveCount(1, { timeout: 20_000 });

    await page.getByRole('tab', { name: /^Layers$/ }).click();
    await page.locator('[role="treeitem"]').first().click();
    await showEdit(page);
    await openSection(page, 'motion');
    await page.getByTestId('motion-more').locator('summary').click();
    const containerPresets = page.getByRole('radiogroup', { name: 'Entrance' });
    await containerPresets.getByRole('radio', { name: /Move left/ }).click();
    await page.locator('select[id$="-trigger"]').selectOption('viewport-enter');
    await settled(page);
    const top = frame.locator('section.sb-top.sb-animate-move-left.sb-interaction-viewport-enter');
    await expect(top).toHaveCount(1, { timeout: 20_000 });
    await expect(top).toHaveAttribute('data-sb-interaction-trigger', 'viewport-enter');
    await expect(page.getByRole('alert')).toHaveCount(0);
  } finally {
    await restore(page, before);
  }
});

test('a block\'s interaction and its animation reach the canvas without a reload', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await insertBlock(page, 'core.heading');
    await page.locator('[id^="sbx-blk-"][id$="-tab-style"]').click();
    await openSection(page, 'motion');
    await page.getByTestId('motion-more').locator('summary').click();
    await page.locator('select[id^="sbx-motion-"][id$="-trigger"]').selectOption('hover');
    await page.locator('select[id^="sbx-motion-"][id$="-trigger-anim"]').selectOption('fade_in');
    await settled(page);
    // Painted by the server (the live patch cannot write motion classes), and merged into the open canvas.
    const heading = (await frameDocument(page)).locator('[data-sb-type="core.heading"].sb-interaction-hover.sb-animate-fade-in');
    await expect(heading).toHaveCount(1, { timeout: 20_000 });
    await expect(heading).toHaveAttribute('data-sb-interaction-trigger', 'hover');
    await expect(page.getByRole('alert')).toHaveCount(0);
  } finally {
    await restore(page, before);
  }
});
