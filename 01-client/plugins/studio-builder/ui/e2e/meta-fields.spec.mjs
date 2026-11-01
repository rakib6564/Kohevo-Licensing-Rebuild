// Meta fields: a long list of types in groups, each type asks only for its own options, and what is saved is shown back.
import { test, expect } from '@playwright/test';
import { addMetaField, ensurePostType, removeMetaField } from './helpers.mjs';

const EDIT = '/plugins/studio-builder/admin/post-type-edit.php?key=services';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'admin screen; run once');
});

test('the type chooser is grouped, each type shows only its options, and a saved field keeps them', async ({ page }) => {
  const key = `workmode_${Date.now()}`;
  await ensurePostType(page, 'services', 'Service');
  await page.goto(EDIT);
  await page.locator('[data-add="field"]').click();
  const row = page.getByTestId('meta-field-row').last();
  const type = row.locator('[data-field-type]');

  expect(await type.locator('option').count()).toBeGreaterThanOrEqual(25);
  expect(await type.locator('optgroup').count()).toBe(7);

  await type.selectOption('number');
  await expect(row.locator('[data-opt="min"]')).toBeVisible();
  await expect(row.locator('[data-opt="step"]')).toBeVisible();
  await expect(row.locator('[data-opt="choices"]')).toBeHidden();

  await type.selectOption('select');
  await expect(row.locator('[data-opt="choices"]')).toBeVisible();
  await expect(row.locator('[data-opt="min"]')).toBeHidden();

  await type.selectOption('relation');
  await expect(row.locator('[data-opt="target"]')).toBeVisible();

  await type.selectOption('boolean');
  await expect(row.locator('[data-field-options]')).toBeHidden(); // a toggle takes no options

  // A select with two choices: saved with its type and options.
  await addMetaField(page, 'services', { label: 'Work mode', key, type: 'select', choices: 'remote : Remote\nonsite : On-site', help: 'How the project was delivered' });
  await page.goto(EDIT);
  const saved = page.getByTestId('meta-field-row').filter({ has: page.locator(`code:text-is("${key}")`) });
  await expect(saved).toContainText('Select');
  await saved.locator('summary').click();
  await expect(saved.locator('textarea[name$="[choices]"]')).toHaveValue('remote : Remote\nonsite : On-site');
  await expect(saved.locator('input[name$="[help]"]')).toHaveValue('How the project was delivered');

  // The same key on the same post type is refused.
  await page.locator('[data-add="field"]').click();
  const again = page.getByTestId('meta-field-row').last();
  await again.locator('input[name$="[label]"]').fill('Again');
  await again.locator('input[name$="[key]"]').fill(key);
  await page.getByTestId('type-update').click();
  await expect(page.locator('.alert-error')).toContainText('already exists');

  // A select without choices is refused.
  await page.goto(EDIT);
  await page.locator('[data-add="field"]').click();
  const empty = page.getByTestId('meta-field-row').last();
  await empty.locator('input[name$="[label]"]').fill('Empty select');
  await empty.locator('input[name$="[key]"]').fill(`${key}_b`);
  await empty.locator('[data-field-type]').selectOption('select');
  await page.getByTestId('type-update').click();
  await expect(page.locator('.alert-error')).toContainText('at least one choice');

  await removeMetaField(page, 'services', key);
});
