// The Add panel's Elements tab: each group folds under its heading, new elements carry a badge, and no two tiles share a name.
import { test, expect } from '@playwright/test';
import { openBuilder } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked left panel (desktop)');
});

async function openElements(page) {
  await openBuilder(page);
  await page.getByRole('tab', { name: /^Add$/ }).click();
  const panel = page.locator('#sbx-leftpanel-blocks');
  await panel.getByRole('tab', { name: 'Elements' }).click();
  return panel;
}

test('a group folds away under its heading and opens again', async ({ page }) => {
  const panel = await openElements(page);
  const fold = panel.locator('[data-fold="layout"]');
  const tile = panel.locator('[data-block-type="core.container"]').first();
  await expect(fold).toHaveAttribute('aria-expanded', 'true');
  await expect(tile).toBeVisible();

  await fold.click();
  await expect(fold).toHaveAttribute('aria-expanded', 'false');
  await expect(tile).toBeHidden();
  await expect(panel.locator('[data-fold="content"]')).toHaveAttribute('aria-expanded', 'true'); // the others stay open

  await fold.click();
  await expect(fold).toHaveAttribute('aria-expanded', 'true');
  await expect(tile).toBeVisible();
});

test('recently added elements carry a New badge, and the two containers are told apart', async ({ page }) => {
  const panel = await openElements(page);
  await expect(panel.locator('[data-block-type="core.divider"] [data-testid="new-badge"]')).toHaveText('New');
  await expect(panel.locator('[data-block-type="core.heading"] [data-testid="new-badge"]')).toHaveCount(0);

  await panel.locator('[data-chip="layout"]').click();
  await expect(panel.locator('[data-block-type="layout.container"] .sbx-tile__label')).toHaveText('Width container');
  await expect(panel.locator('[data-block-type="core.container"] .sbx-tile__label').first()).toHaveText('Container');
});
