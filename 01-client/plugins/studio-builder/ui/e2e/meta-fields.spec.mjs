// Meta fields: a long list of types in groups, each type asks only for its own options, and what is saved is shown back.
import { test, expect } from '@playwright/test';
import { ensurePostType } from './helpers.mjs';

const TYPES = '/plugins/studio-builder/admin/types-fields.php';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'admin screen; run once');
});

test('the type chooser is grouped, each type shows only its options, and a saved field is listed with them', async ({ page }) => {
  const key = `workmode_${Date.now()}`;
  await ensurePostType(page, 'services', 'Service');
  await page.goto(TYPES);
  await page.evaluate(() => { document.getElementById('sb-field-drawer').style.display = 'block'; });
  const type = page.locator('#sb-meta-type');

  expect(await type.locator('option').count()).toBeGreaterThanOrEqual(25);
  expect(await type.locator('optgroup').count()).toBe(7);

  await type.selectOption('number');
  await expect(page.locator('[data-opt="min"]')).toBeVisible();
  await expect(page.locator('[data-opt="step"]')).toBeVisible();
  await expect(page.locator('[data-opt="choices"]')).toBeHidden();

  await type.selectOption('select');
  await expect(page.locator('[data-opt="choices"]')).toBeVisible();
  await expect(page.locator('[data-opt="min"]')).toBeHidden();

  await type.selectOption('relation');
  await expect(page.locator('[data-opt="target"]')).toBeVisible();

  await type.selectOption('boolean');
  await expect(page.locator('#sb-meta-options')).toBeHidden(); // a toggle takes no options

  // Save a select with two choices: it is listed with its type and options.
  await type.selectOption('select');
  const drawer = page.locator('#sb-field-drawer');
  await drawer.locator('select[name="post_type"]').selectOption('services');
  await drawer.locator('input[name="label"]').fill('Work mode');
  await drawer.locator('input[name="key"]').fill(key);
  await drawer.locator('textarea[name="choices"]').fill('remote : Remote\nonsite : On-site');
  await drawer.locator('input[name="help"]').fill('How the project was delivered');
  await drawer.getByRole('button', { name: /Save Field/ }).click();
  await expect(page.locator('.alert-success')).toContainText('Work mode');

  const row = page.locator('.data-row', { hasText: key });
  await expect(row).toContainText('Select');
  await row.locator('.data-row-summary').click();
  await expect(row.locator('.data-row-detail')).toContainText('2 choices');
  await expect(row.locator('.data-row-detail')).toContainText('How the project was delivered');

  // The same key on the same post type is refused.
  await page.evaluate(() => { document.getElementById('sb-field-drawer').style.display = 'block'; });
  await drawer.locator('input[name="label"]').fill('Again');
  await drawer.locator('input[name="key"]').fill(key);
  await drawer.getByRole('button', { name: /Save Field/ }).click();
  await expect(page.locator('.alert-danger')).toContainText('already exists');

  // A select without choices is refused.
  await page.evaluate(() => { document.getElementById('sb-field-drawer').style.display = 'block'; });
  await drawer.locator('input[name="label"]').fill('Empty select');
  await drawer.locator('input[name="key"]').fill(`${key}_b`);
  await drawer.locator('#sb-meta-type').selectOption('select');
  await drawer.getByRole('button', { name: /Save Field/ }).click();
  await expect(page.locator('.alert-danger')).toContainText('at least one choice');

  // Clean up.
  const mine = page.locator('.data-row', { hasText: key });
  await mine.first().locator('.data-row-summary').click();
  page.once('dialog', (d) => void d.accept());
  await mine.first().getByRole('button', { name: /Delete/ }).click();
  await expect(page.locator('.alert-success')).toContainText('deleted');
});
