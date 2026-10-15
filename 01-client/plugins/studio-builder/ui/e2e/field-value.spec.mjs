// The Field value block: set a custom field on the page's settings screen, then add the block in the builder, name the
// field, and the canvas shows the page's value for it.
import { test, expect } from '@playwright/test';
import { openBuilder, settled, sandboxPageId, showEdit, frameEval, restoreLayers, layerCount } from './helpers.mjs';

const TYPES = '/plugins/studio-builder/admin/types-fields.php';
const EDIT = (id) => `/plugins/studio-builder/admin/page-edit.php?id=${id}`;

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked Add panel (desktop)');
});

test('a custom field value of the page shows in the Field value block on the canvas', async ({ page }) => {
  const key = `fv_${Date.now()}`;
  const value = `Value ${Date.now()}`;
  const pageId = sandboxPageId();

  await page.goto(TYPES);
  await page.evaluate(() => { document.getElementById('sb-field-drawer').style.display = 'block'; });
  const d = page.locator('#sb-field-drawer');
  await d.locator('select[name="post_type"]').selectOption('services');
  await d.locator('input[name="label"]').fill('Field value test');
  await d.locator('input[name="key"]').fill(key);
  await d.getByRole('button', { name: /Save Field/ }).click();
  await expect(page.locator('.alert-success')).toContainText('Field value test');

  await page.goto(EDIT(pageId));
  const card = page.locator('[data-testid="page-meta-form"]');
  await card.locator('#sb-ctype').selectOption('services');
  await card.locator(`input[name="meta[${key}]"]`).fill(value);
  await card.getByRole('button', { name: /Save custom fields/ }).click();
  await expect(page.locator('.alert-success')).toContainText('Custom fields saved');

  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await page.getByRole('tab', { name: /^(Add|Ajouter)$/ }).click();
    const panel = page.locator('#sbx-leftpanel-blocks');
    await panel.getByRole('searchbox').fill('field value');
    await panel.getByTestId('add-search-results').locator('[data-block-type="theme.field_value"]').click();
    await settled(page);
    const box = page.locator('.sbx-overlay__box.is-primary');
    await expect(box).toHaveCount(1, { timeout: 20_000 });
    const id = await box.getAttribute('data-overlay-for');

    await showEdit(page);
    const field = page.getByLabel('Field key', { exact: true });
    await field.fill(key);
    await field.blur();
    await expect.poll(() => frameEval(page, `[data-sb-node="${id}"] .sb-field-value`, (el) => el.textContent), { timeout: 20_000 }).toContain(value);
  } finally {
    await restoreLayers(page, before);
    await page.goto(EDIT(pageId));
    await page.locator('#sb-ctype').selectOption('');
    await page.locator('[data-testid="page-meta-form"]').getByRole('button', { name: /Save custom fields/ }).click();
    await page.goto(TYPES);
    const row = page.locator('.data-row', { hasText: key }).first();
    if (await row.count()) {
      await row.locator('.data-row-summary').click();
      page.once('dialog', (dlg) => void dlg.accept());
      await row.getByRole('button', { name: /Delete/ }).click();
    }
  }
});
