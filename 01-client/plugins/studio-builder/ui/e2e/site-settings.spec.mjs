// Settings › Site: the design tokens as grouped, compact controls that save through the token endpoint.
import { test, expect } from '@playwright/test';
import { openBuilder, settled, sandboxPageId } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked left panel (desktop)');
});

test.afterEach(async ({ page }) => {
  await page.goto(`/plugins/studio-builder/admin/builder.php?page=${sandboxPageId()}&lang=en`);
});

test('Site settings groups the tokens, saves an override, and resets it', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  await page.getByRole('tab', { name: /^Settings$/ }).click();
  const panel = page.locator('#sbx-leftpanel-settings');
  await panel.locator('[data-settings-view="site"]').click();
  const form = panel.getByTestId('site-settings');
  await expect(form.locator('[data-ss-group]')).toHaveText([/Global colors/, /Global fonts/, /Corners/, /Shadows/, /Spacing/, /Custom CSS/]);
  await expect(form.locator('[data-ss-group="colors"]')).toHaveAttribute('open', '');
  await expect(form.locator('[data-ss-group="shape"]')).not.toHaveAttribute('open', '');

  const save = form.getByTestId('site-settings-save');
  await expect(save).toBeDisabled(); // nothing changed yet
  const row = form.locator('.sbx-ss__row', { hasText: 'Main surface' }).first();
  const field = row.locator('input[type="text"]');
  try {
    await field.fill('#112233');
    await expect(save).toBeEnabled();
    await save.click();
    await expect(form.getByRole('status')).toHaveText('Design saved');
    await expect(field).toHaveValue('#112233');
  } finally {
    await field.fill('');
    if (await save.isEnabled()) await save.click();
    await expect(form.getByRole('status')).toHaveText('Design saved');
  }
  await expect(field).toHaveValue('');
});

test('an invalid token value shows the problem beside it and is not stored', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  await page.getByRole('tab', { name: /^Settings$/ }).click();
  const panel = page.locator('#sbx-leftpanel-settings');
  await panel.locator('[data-settings-view="site"]').click();
  const form = panel.getByTestId('site-settings');
  const field = form.locator('.sbx-ss__row', { hasText: 'Main surface' }).first().locator('input[type="text"]');
  await field.fill('url(javascript:alert(1))');
  await form.getByTestId('site-settings-save').click();
  await expect(form.getByRole('alert').first()).toBeVisible();
  await expect(field).toHaveAttribute('aria-invalid', 'true');
});
