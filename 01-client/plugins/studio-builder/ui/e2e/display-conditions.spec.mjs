// Theme Builder display conditions: a site header says where it applies, in a dialog, and the rules persist.
import { test, expect } from '@playwright/test';
import { settled } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked left panel (desktop)');
});

test('a header partial gets include and exclude rules from the dialog, validates them, and keeps them', async ({ page }) => {
  const title = `Cond header ${Date.now()}`;
  await page.goto('/plugins/studio-builder/admin/pages.php');
  await page.getByLabel('Title').fill(title);
  await page.locator('#sb-type').selectOption('header_partial');
  await page.getByRole('button', { name: /Create & Open Canvas Builder/ }).click();
  await page.waitForURL(/builder\.php\?page=\d+/);
  const builderUrl = page.url();
  await page.locator('iframe.sbx-canvas__frame').waitFor();
  await settled(page);

  await page.getByRole('tab', { name: /^Settings$/ }).click();
  const panel = page.locator('#sbx-leftpanel-settings');
  await expect(panel.getByTestId('cond-summary')).toContainText('No conditions');
  await panel.getByTestId('cond-edit').click();

  const dialog = page.getByTestId('condition-dialog');
  await expect(dialog).toBeVisible();
  await expect(dialog.getByTestId('cond-empty')).toBeVisible();
  await dialog.getByTestId('cond-add').click();
  const first = dialog.getByTestId('cond-row').first();
  await first.locator('[data-cond="kind"]').selectOption('singular');
  await first.locator('[data-cond="value"]').selectOption('post');

  // An exclusion alone selects nothing, so Save waits for an Include rule; here the first row is one.
  await dialog.getByTestId('cond-add').click();
  const second = dialog.getByTestId('cond-row').nth(1);
  await second.locator('[data-cond="action"]').selectOption('exclude');
  await second.locator('[data-cond="kind"]').selectOption('category');
  await expect(page.getByTestId('cond-save')).toBeDisabled(); // a category rule needs its name
  await second.locator('[data-cond="value"]').fill('News');
  await expect(page.getByTestId('cond-save')).toBeEnabled();

  // Only exclusions: refused until an Include rule exists.
  await first.locator('[data-cond="action"]').selectOption('exclude');
  await expect(page.getByTestId('cond-save')).toBeDisabled();
  await first.locator('[data-cond="action"]').selectOption('include');

  await page.getByTestId('cond-save').click();
  await expect(page.getByTestId('condition-dialog')).toHaveCount(0);
  await expect(panel.getByTestId('cond-summary')).toContainText('2 condition');
  await settled(page);
  await expect(page.getByRole('alert')).toHaveCount(0);
  const saveDraft = page.getByRole('button', { name: 'Save draft' });
  await expect(saveDraft).toBeEnabled();
  await saveDraft.click();
  await expect(saveDraft).toBeDisabled({ timeout: 15_000 }); // nothing left to save

  // The rules are in the draft: they are still there after a reload, and the dialog shows them as rows.
  await page.goto(builderUrl);
  await page.locator('iframe.sbx-canvas__frame').waitFor();
  await settled(page);
  await page.getByRole('tab', { name: /^Settings$/ }).click();
  await expect(page.locator('#sbx-leftpanel-settings').getByTestId('cond-summary')).toContainText('2 condition');
  await page.locator('#sbx-leftpanel-settings').getByTestId('cond-edit').click();
  const rows = page.getByTestId('condition-dialog').getByTestId('cond-row');
  await expect(rows).toHaveCount(2);
  await expect(rows.nth(0).locator('[data-cond="kind"]')).toHaveValue('singular');
  await expect(rows.nth(1).locator('[data-cond="value"]')).toHaveValue('News');
});

test('an ordinary page has no display conditions section', async ({ page }) => {
  await page.goto(`/plugins/studio-builder/admin/builder.php?page=${(await import('./helpers.mjs')).sandboxPageId()}&lang=en`);
  await page.locator('iframe.sbx-canvas__frame').waitFor();
  await settled(page);
  await page.getByRole('tab', { name: /^Settings$/ }).click();
  await expect(page.locator('#sbx-leftpanel-settings').getByTestId('cond-edit')).toHaveCount(0);
});
