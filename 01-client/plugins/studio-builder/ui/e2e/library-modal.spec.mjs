// The template library as a large window: opened from the top bar, tabs per kind, search, Esc to close.
import { test, expect } from '@playwright/test';
import { openBuilder, settled, sandboxPageId } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'top bar button (desktop)');
});

test.afterEach(async ({ page }) => {
  await page.goto(`/plugins/studio-builder/admin/builder.php?page=${sandboxPageId()}&lang=en`);
});

test('the top bar opens the library window with a tab per kind, a search box, and Esc closes it', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const modal = page.getByTestId('library-modal');
  await expect(modal).toHaveCount(0);
  await page.getByTestId('open-library').click();
  await expect(modal).toBeVisible();
  await expect(modal.getByRole('tab')).not.toHaveCount(0);
  await expect(modal.locator('[data-lib-tab="components"]')).toBeVisible();
  await modal.locator('[data-lib-tab="components"]').click();
  await expect(modal.locator('[data-lib-tab="components"]')).toHaveAttribute('aria-selected', 'true');
  await modal.getByTestId('library-search').fill('zzzz-no-such-template');
  await expect(modal.locator('.sbx-hint')).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(modal).toHaveCount(0);
});

test('the empty container button opens the same window', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  await page.getByRole('tab', { name: /^Add$/ }).click();
  const panel = page.locator('#sbx-leftpanel-blocks');
  await panel.getByRole('tab', { name: 'Elements' }).click();
  await panel.locator('[data-chip="layout"]').click();
  await panel.locator('[data-block-type="core.container"]').click();
  await settled(page);
  await page.getByTestId('chooser-close').click();
  await page.getByTestId('zone-templates').click();
  await expect(page.getByTestId('library-modal')).toBeVisible();
  await page.keyboard.press('Escape');
  await page.getByTestId('undo').click(); // nothing a test adds stays on the shared page
  const undo = page.getByTestId('undo');
  for (let i = 0; i < 6 && (await undo.isEnabled()); i++) { await undo.click(); await page.waitForTimeout(400); }
});
