// The Field value block: set a custom field on the page's settings screen, then add the block in the builder, name the
// field from the dropdown, and the canvas shows the page's value for it.
import { test, expect } from '@playwright/test';
import { openBuilder, settled, sandboxPageId, showEdit, frameEval, restoreLayers, layerCount, ensurePostType, addMetaField, removeMetaField } from './helpers.mjs';

const EDIT = (id) => `/plugins/studio-builder/admin/page-edit.php?id=${id}`;

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked Add panel (desktop)');
});

test('a custom field value of the page shows in the Field value block on the canvas', async ({ page }) => {
  const key = `fv_${Date.now()}`;
  const value = `Value ${Date.now()}`;
  const pageId = sandboxPageId();

  await ensurePostType(page, 'services', 'Service');
  await addMetaField(page, 'services', { label: 'Field value test', key });

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
    const field = page.getByTestId('meta-field-select');
    await expect(field.locator(`option[value="${key}"]`)).toHaveCount(1); // the field defined above is in the list
    await field.selectOption(key);
    await expect.poll(() => frameEval(page, `[data-sb-node="${id}"] .sb-field-value`, (el) => el.textContent), { timeout: 20_000 }).toContain(value);
  } finally {
    await restoreLayers(page, before);
    await page.goto(EDIT(pageId));
    await page.locator('#sb-ctype').selectOption('');
    await page.getByTestId('item-update').click();
    await removeMetaField(page, 'services', key);
  }
});
