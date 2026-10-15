// A fresh editor opens on Add > Elements, and the Layers list starts with every layer collapsed.
import { test, expect } from '@playwright/test';
import { openBuilder, settled, layerCount } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked panel (desktop)');
});

test('the editor opens on Add > Elements, and Layers starts with every layer collapsed', async ({ page }) => {
  test.setTimeout(90_000);
  await openBuilder(page);
  await settled(page);
  await expect(page.getByRole('tab', { name: /^Add$/ })).toHaveAttribute('aria-selected', 'true');
  const blocks = page.locator('#sbx-leftpanel-blocks');
  await expect(blocks.getByRole('tab', { name: 'Elements' })).toHaveAttribute('aria-selected', 'true');

  await layerCount(page); // opens Layers
  const before = await page.locator('[role="treeitem"][aria-level="1"]').count();
  try {
    // Put a container on the page and reload: the list must come back collapsed.
    await page.getByRole('tab', { name: /^Add$/ }).click();
    await blocks.locator('[data-chip="layout"]').click();
    await blocks.locator('[data-block-type="core.container"]').click();
    await settled(page);
    await page.reload();
    await openBuilder(page);
    await settled(page);
    await expect(page.getByRole('tab', { name: /^Add$/ })).toHaveAttribute('aria-selected', 'true');
    await page.getByRole('tab', { name: /^Layers$/ }).click();
    const rows = page.locator('[role="treeitem"]');
    await expect(rows.first()).toBeVisible();
    await expect(page.locator('[role="treeitem"][aria-expanded="true"]')).toHaveCount(0);
    await expect(page.locator('[role="treeitem"][aria-level="2"]')).toHaveCount(0);
  } finally {
    // The reload cleared undo, so take the container off the way an author would: select its section and delete it.
    await page.getByRole('tab', { name: /^Layers$/ }).click();
    const sections = page.locator('[role="treeitem"][aria-level="1"]');
    if ((await sections.count()) > before) {
      page.once('dialog', (d) => d.accept()); // "Delete this section and all of its blocks?"
      await sections.last().click();
      await page.getByRole('button', { name: 'Remove item', exact: true }).first().click();
      await settled(page);
      await expect(sections).toHaveCount(before);
    }
  }
});
