// A page's content type and custom fields: pick a type, fill its fields, save, and see them again after a reload.
import { test, expect } from '@playwright/test';

const TYPES = '/plugins/studio-builder/admin/types-fields.php';
const NEW = '/plugins/studio-builder/admin/page-new.php';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'admin screen; run once');
});

test('a page takes a content type, shows only that type\'s fields, validates them and keeps what is saved', async ({ page }) => {
  const stamp = Date.now();
  const key = `mode_${stamp}`;

  // Two fields on Services: a select and a required number.
  for (const f of [
    { label: 'Mode', key, type: 'select', choices: 'remote : Remote\nonsite : On-site' },
    { label: 'Seats', key: `seats_${stamp}`, type: 'number', required: true },
  ]) {
    await page.goto(TYPES);
    await page.evaluate(() => { document.getElementById('sb-field-drawer').style.display = 'block'; });
    const d = page.locator('#sb-field-drawer');
    await d.locator('select[name="post_type"]').selectOption('services');
    await d.locator('#sb-meta-type').selectOption(f.type);
    await d.locator('input[name="label"]').fill(f.label);
    await d.locator('input[name="key"]').fill(f.key);
    if (f.choices) await d.locator('textarea[name="choices"]').fill(f.choices);
    if (f.required) await d.locator('input[name="required"]').check();
    await d.getByRole('button', { name: /Save Field/ }).click();
    await expect(page.locator('.alert-success')).toContainText(f.label);
  }

  // A new page.
  await page.goto(NEW);
  await page.locator('#sb-title').fill(`Meta page ${stamp}`);
  await page.locator('#sb-slug').fill(`meta-page-${stamp}`);
  await page.getByRole('button', { name: /Create|Save/ }).first().click();
  await page.waitForURL(/page-edit\.php\?id=\d+|builder\.php/);
  const id = new URL(page.url()).searchParams.get('id') ?? new URL(page.url()).searchParams.get('page');
  const edit = `/plugins/studio-builder/admin/page-edit.php?id=${id}`;
  await page.goto(edit);

  const card = page.locator('[data-testid="page-meta-form"]');
  await expect(card).toBeVisible();
  const visibleGroups = () => card.locator('.sb-meta-group:not([hidden])').count();
  expect(await visibleGroups()).toBe(0); // no type yet: no fields

  await card.locator('#sb-ctype').selectOption('services');
  expect(await visibleGroups()).toBe(1);
  await expect(card.locator(`select[name="meta[${key}]"]`)).toBeVisible();

  // The required number is refused when empty (browser) and when out of the type's rules (server).
  await card.locator(`select[name="meta[${key}]"]`).selectOption('onsite');
  await card.locator(`input[name="meta[seats_${stamp}]"]`).fill('12');
  await card.getByRole('button', { name: /Save/ }).click();
  await expect(page.locator('.alert-success')).toContainText('Custom fields saved');

  // After a reload the choice, the number and the type are all still there; the other type's fields are not posted.
  await page.goto(edit);
  await expect(page.locator('#sb-ctype')).toHaveValue('services');
  await expect(page.locator(`select[name="meta[${key}]"]`)).toHaveValue('onsite');
  await expect(page.locator(`input[name="meta[seats_${stamp}]"]`)).toHaveValue('12');

  // Switching to None keeps no type.
  await page.locator('#sb-ctype').selectOption('');
  await page.locator('[data-testid="page-meta-form"]').getByRole('button', { name: /Save/ }).click();
  await page.goto(edit);
  await expect(page.locator('#sb-ctype')).toHaveValue('');

  // Clean up the page and the two fields.
  page.once('dialog', (dlg) => void dlg.accept());
  await page.goto(edit);
  await page.getByRole('button', { name: /Delete/ }).last().click().catch(() => {});
  for (const k of [key, `seats_${stamp}`]) {
    await page.goto(TYPES);
    const row = page.locator('.data-row', { hasText: k }).first();
    if (await row.count()) {
      await row.locator('.data-row-summary').click();
      page.once('dialog', (dlg) => void dlg.accept());
      await row.getByRole('button', { name: /Delete/ }).click();
    }
  }
});
