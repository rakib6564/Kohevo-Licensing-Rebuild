// Scroll effects in the editor: a Container made sticky, with a stuck shadow, and given a parallax background reaches the
// canvas as the classes and attributes the public runtime reads.
import { test, expect } from '@playwright/test';
import { openBuilder, frameDocument, settled, openSection, showEdit, layerCount, restoreLayers, undoReady } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked Inspector (desktop)');
});

test('a Container takes sticky and parallax from Motion effects, and both reach the canvas', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  let changes = 0;
  try {
    // A Container of our own (the shared sandbox page may be empty): a heading added from Elements brings one.
    await page.getByRole('tab', { name: /^(Add|Ajouter)$/ }).click();
    const panel = page.locator('#sbx-leftpanel-blocks');
    await panel.getByRole('tab', { name: 'Elements' }).click();
    await panel.locator('[data-chip="content"]').click();
    await panel.locator('[data-block-type="core.heading"]').click();
    await settled(page);
    await page.getByRole('tab', { name: /^Layers$/ }).click();
    await page.locator('[role="treeitem"]').last().click();
    await page.locator('[role="treeitem"]').first().click();
    await showEdit(page);
    const body = await openSection(page, 'motion');
    const effects = body.locator('[data-scroll-effects]');
    await effects.getByRole('group', { name: 'Sticky' }).getByRole('button', { name: 'Top' }).click();
    changes++;
    await settled(page);
    await effects.getByLabel('Add a shadow').check();
    changes++;
    await settled(page);
    await effects.getByRole('group', { name: 'Parallax' }).getByRole('button', { name: 'Background' }).click();
    changes++;
    await settled(page);

    const frame = await frameDocument(page);
    const top = frame.locator('section.sb-top.sb-sticky.sb-sticky--top.sb-parallax-bg');
    await expect(top).toHaveCount(1, { timeout: 20_000 });
    await expect(top).toHaveAttribute('data-sb-sticky-effect', 'shadow');
    await expect(top).toHaveAttribute('data-sb-parallax-bg', '0.3');
    await expect(page.getByRole('alert')).toHaveCount(0);
  } finally {
    const undo = page.getByTestId('undo');
    for (let i = 0; i < changes; i++) {
      if (!(await undoReady(undo))) break;
      await undo.click();
      await settled(page);
    }
    await restoreLayers(page, before);
  }
});
