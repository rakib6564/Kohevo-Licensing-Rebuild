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
  await expect(form.locator('[data-ss-group]')).toHaveText([/Global colors/, /Global fonts/, /^Text/, /Corners/, /Shadows/, /Spacing/, /^Layout/, /Custom CSS/]);
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

test('the colour palette shows one chip per colour token, follows an edit, and a chip jumps to its field', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  await page.getByRole('tab', { name: /^Settings$/ }).click();
  const panel = page.locator('#sbx-leftpanel-settings');
  await panel.locator('[data-settings-view="site"]').click();
  const form = panel.getByTestId('site-settings');
  const palette = form.getByTestId('ss-palette');
  const colourRows = form.locator('[data-ss-group="colors"] .sbx-ss__row');
  await expect(palette.locator('.sbx-ss__chip')).toHaveCount(await colourRows.count());
  const chip = palette.locator('.sbx-ss__chip[data-chip-for="surface.page"]');
  await expect(chip).toHaveCount(1);
  await chip.click();
  await expect(form.locator('input[data-token-ref="surface.page"]')).toBeFocused();
  await form.locator('input[data-token-ref="surface.page"]').fill('#123456');
  await expect(chip).toHaveCSS('background-color', 'rgb(18, 52, 86)');
  await expect(form.locator('[data-ss-group="fonts"] .sbx-ss__fontprev').first()).toBeVisible();
});

test('Page widths are three site-wide sizes: a new Wide reaches the page, and a percentage is refused', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  await page.getByRole('tab', { name: /^Settings$/ }).click();
  const panel = page.locator('#sbx-leftpanel-settings');
  await panel.locator('[data-settings-view="site"]').click();
  const form = panel.getByTestId('site-settings');
  const group = form.locator('[data-ss-group="layout"]');
  await group.locator('summary').click();
  await expect(group).toContainText('Each Container picks Default, Narrow, Normal, Wide, Full or a custom width');
  const wide = group.locator('.sbx-ss__row', { hasText: 'Wide' }).first().locator('input[type="text"]');
  const save = form.getByTestId('site-settings-save');
  try {
    await wide.fill('100%');
    await save.click();
    await expect(wide).toHaveAttribute('aria-invalid', 'true');
    await wide.fill('61rem');
    await save.click();
    await expect(form.getByRole('status')).toHaveText('Design saved');
    await page.reload();
    await settled(page);
    const frame = page.frameLocator('iframe.sbx-canvas__frame');
    await expect.poll(async () => (await frame.locator('html').evaluate((el) => getComputedStyle(el).getPropertyValue('--sb-layout-wide').trim()))).toBe('61rem');
  } finally {
    await page.getByRole('tab', { name: /^Settings$/ }).click();
    await panel.locator('[data-settings-view="site"]').click();
    const again = form.locator('[data-ss-group="layout"]');
    if (!(await again.getAttribute('open'))) await again.locator('summary').click();
    const field = again.locator('.sbx-ss__row', { hasText: 'Wide' }).first().locator('input[type="text"]');
    await field.fill('');
    if (await save.isEnabled()) await save.click();
    await expect(form.getByRole('status')).toHaveText('Design saved');
  }
});
