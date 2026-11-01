// A post type's own edit screen and the item screen it shapes: meta boxes hold an item's content, one template shows it.
import { test, expect } from '@playwright/test';
import { ensurePostType, layerCount, openBuilder, restoreLayers, settled, showEdit } from './helpers.mjs';

const ADMIN = '/plugins/studio-builder/admin';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'admin screen; run once');
});

test('the edit screen has its sections in order and keeps fields, columns, filters and labels', async ({ page }) => {
  const key = `book_${Date.now()}`;
  await page.goto(`${ADMIN}/post-type-edit.php`);
  const order = await page.locator('#sb-type-form > section .sb-card__title').allTextContents();
  expect(order).toEqual(['General Settings', 'Labels', 'Advanced Settings', 'Meta Fields', 'Admin Columns', 'Admin Filters']);
  await expect(page.getByTestId('type-actions')).toContainText('Actions');

  await page.locator('#sb-type-name').fill('Books');
  await page.locator('#sb-type-singular').fill('Book');
  await page.locator('#sb-type-key').fill(key);
  await page.locator('#sb-label-add_new').fill('Add a book');
  await expect(page.locator('#sb-label-edit_item')).toHaveAttribute('placeholder', /Edit/);
  await page.locator('input[name="rewrite_slug"]').fill(`books-${key}`.replace(/_/g, '-'));

  const add = async (label, fieldKey, type, choices) => {
    await page.locator('[data-add="field"]').click();
    const row = page.getByTestId('meta-field-row').last();
    await row.locator('input[name$="[label]"]').fill(label);
    await expect(row.locator('input[name$="[key]"]')).not.toHaveValue(''); // the key follows the label
    await row.locator('input[name$="[key]"]').fill(fieldKey);
    await row.locator('[data-field-type]').selectOption(type);
    if (choices) await row.locator('textarea[name$="[choices]"]').fill(choices);
    return row;
  };
  await add('Genre', 'genre', 'select', 'novel : Novel\nessay : Essay');
  const pages = await add('Pages', 'pages', 'number');
  await pages.locator('[data-row-act="duplicate"]').click();
  await expect(page.getByTestId('meta-field-row')).toHaveCount(3);
  await page.getByTestId('meta-field-row').nth(2).locator('[data-row-act="up"]').click();
  await expect(page.getByTestId('meta-field-row').nth(1).locator('[data-show="key"]')).toHaveText('pages_copy');
  await page.getByTestId('meta-field-row').nth(1).locator('[data-row-act="delete"]').click();

  await page.locator('[data-add="column"]').click();
  const col = page.getByTestId('admin-column-row').last();
  await col.locator('input[name$="[label]"]').fill('Genre');
  await col.locator('input[name$="[source]"]').fill('genre');
  await col.locator('input[name$="[sortable]"]').check();
  await page.locator('[data-add="filter"]').click();
  await page.getByTestId('admin-filter-row').last().locator('input[name$="[source]"]').fill('genre');
  await page.getByTestId('type-update').click();
  await expect(page.locator('.alert-success')).toContainText('saved');

  await expect(page.getByTestId('meta-field-row').locator('[data-show="key"]')).toHaveText(['genre', 'pages']);
  await expect(page.locator('#sb-type-key')).toHaveAttribute('readonly', '');
  await expect(page.locator('input[name="supports[]"]:checked')).toHaveCount(3); // each support keeps its own name
  await expect(page.locator('#sb-label-add_new')).toHaveValue('Add a book');

  // A column on a key that is not a field is refused, and the screen keeps what was typed.
  await page.locator('[data-add="column"]').click();
  await page.getByTestId('admin-column-row').last().locator('input[name$="[source]"]').fill('nope');
  await page.getByTestId('type-update').click();
  await expect(page.locator('.alert-error')).toContainText('nope');

  await page.goto(`${ADMIN}/post-type-edit.php?key=${key}`);
  // The list wears its labels, columns and filter.
  await page.goto(`${ADMIN}/pages.php?type=${key}`);
  await expect(page.getByTestId('new-page')).toContainText('Add a book');

  // Item screen: title, Settings box, publish, template, image, SEO.
  await page.goto(`${ADMIN}/page-new.php?type=${key}`);
  await expect(page.locator('h2').first()).toContainText('Add new book');
  await page.locator('#sb-title').fill(`Dune ${key}`);
  await page.getByRole('button', { name: /Create/ }).first().click();
  await page.waitForURL(/builder\.php/);
  const id = new URL(page.url()).searchParams.get('page');

  await page.goto(`${ADMIN}/page-edit.php?id=${id}`);
  for (const box of ['item-settings', 'item-publish', 'item-template', 'item-image', 'item-seo', 'item-excerpt']) {
    await expect(page.getByTestId(box)).toBeVisible();
  }
  await expect(page.getByTestId('item-settings')).toContainText('genre'); // field names show on a new type
  await page.locator('select[name="meta[genre]"]:enabled').selectOption('novel');
  await page.locator('input[name="meta[pages]"]:enabled').fill('412');
  await page.locator('#sb-excerpt').fill('Desert planet.');
  await page.locator('#sb-seo-title').fill('Dune, the book');
  await page.locator('#sb-status').selectOption('published');
  await page.getByTestId('item-update').click();
  await expect(page.locator('.alert-success')).toContainText('Book published');
  await expect(page.locator('#sb-status')).toHaveValue('published');
  await expect(page.locator('#sb-excerpt')).toHaveValue('Desert planet.');
  await expect(page.locator('#sb-seo-title')).toHaveValue('Dune, the book');
  const address = await page.locator('[data-testid="item-publish"] code').textContent();

  const live = await page.request.get(address);
  expect(live.status()).toBe(200);

  // Published with a date in the future is refused; Scheduled takes it, and the item waits off the site.
  await page.locator('#sb-publish-date').fill('2099-01-01T10:00');
  await page.getByTestId('item-update').click();
  await expect(page.locator('.alert-error')).toContainText('Scheduled');
  await page.locator('#sb-publish-date').fill('2099-01-01T10:00');
  await page.locator('#sb-status').selectOption('scheduled');
  await page.getByTestId('item-update').click();
  await expect(page.locator('.alert-success')).toContainText('Book scheduled');
  await expect(page.locator('#sb-status')).toHaveValue('scheduled');
  await expect(page.locator('#sb-publish-date')).toHaveValue('2099-01-01T10:00');
  expect((await page.request.get(address)).status()).toBe(404);
  await page.goto(`${ADMIN}/pages.php?type=${key}`);
  await expect(page.locator('.data-row .badge').first()).toHaveText('Scheduled');
  await page.goto(`${ADMIN}/page-edit.php?id=${id}`);
  await page.locator('#sb-publish-date').fill('2020-05-04T10:00');
  await page.locator('#sb-status').selectOption('published');
  await page.getByTestId('item-update').click();
  await expect(page.locator('.alert-success')).toContainText('Book published');

  await page.goto(`${ADMIN}/page-edit.php?id=${id}`);
  await page.locator('#sb-publish-date').fill('2020-05-04T10:00');
  await page.getByTestId('item-update').click();
  await expect(page.locator('.alert-success')).toContainText('Book updated');
  await expect(page.locator('#sb-publish-date')).toHaveValue('2020-05-04T10:00');

  // Column, sort and filter on the list.
  await page.goto(`${ADMIN}/pages.php?type=${key}`);
  await expect(page.locator('.data-row-cell').first()).toHaveText('Novel');
  await page.getByTestId('admin-filters').locator('select').selectOption('essay');
  await expect(page.locator('.data-row')).toHaveCount(0);
  await page.getByTestId('admin-filters').locator('select').selectOption('novel');
  await expect(page.locator('.data-row')).toHaveCount(1);
  await page.locator('.data-list-head-cell a').click();
  await expect(page).toHaveURL(/sort=genre/);

  // Public off: the item leaves the site, and comes back with it. Status back to draft does the same.
  await page.goto(`${ADMIN}/post-type-edit.php?key=${key}`);
  await page.locator('input[name="public"]').uncheck();
  await page.getByTestId('type-update').click();
  expect((await page.request.get(address)).status()).toBe(404);
  await page.goto(`${ADMIN}/post-type-edit.php?key=${key}`);
  await page.locator('input[name="public"]').check();
  await page.getByTestId('type-update').click();
  expect((await page.request.get(address)).status()).toBe(200);
  await page.goto(`${ADMIN}/page-edit.php?id=${id}`);
  await page.locator('#sb-status').selectOption('draft');
  await page.getByTestId('item-update').click();
  await expect(page.locator('.alert-success')).toContainText('back to draft');
  expect((await page.request.get(address)).status()).toBe(404);

  // Delete metadata keeps the item and empties its fields; Delete asks first and removes the type.
  await page.goto(`${ADMIN}/post-type-edit.php?key=${key}`);
  page.once('dialog', (d) => void d.accept());
  await page.getByTestId('type-delete-metadata').click();
  await expect(page.locator('.alert-success')).toContainText('1 item');
  await page.goto(`${ADMIN}/page-edit.php?id=${id}`);
  await expect(page.locator('input[name="meta[pages]"]:enabled')).toHaveValue('');

  page.once('dialog', (d) => void d.accept());
  await page.getByRole('button', { name: /Delete page/ }).click();
  await page.goto(`${ADMIN}/post-type-edit.php?key=${key}`);
  page.once('dialog', (d) => void d.accept());
  await page.getByTestId('type-delete').click();
  await expect(page.locator('.alert-success')).toContainText('deleted');
  await expect(page.locator(`[data-post-type="${key}"]`)).toHaveCount(0);
});

test('the featured image box picks from the media library, saves it, and removes it', async ({ page }) => {
  const key = `gallery_${Date.now()}`;
  await page.goto(`${ADMIN}/post-type-edit.php`);
  await page.locator('#sb-type-name').fill('Galleries');
  await page.locator('#sb-type-singular').fill('Gallery');
  await page.locator('#sb-type-key').fill(key);
  await page.getByTestId('type-update').click();
  await page.goto(`${ADMIN}/page-new.php?type=${key}`);
  await page.locator('#sb-title').fill(`Pic ${key}`);
  await page.getByRole('button', { name: /Create/ }).first().click();
  await page.waitForURL(/builder\.php/);
  const id = new URL(page.url()).searchParams.get('page');
  await page.goto(`${ADMIN}/page-edit.php?id=${id}`);

  // A real upload through the library's own endpoint, then the real picker.
  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64');
  await page.getByTestId('item-image').getByRole('button', { name: 'Set featured image' }).click();
  const chooser = page.locator('input[type="file"]').first();
  await chooser.setInputFiles({ name: `${key}.png`, mimeType: 'image/png', buffer: png });
  const tile = page.locator('img[src*="uploads"]').first();
  await expect(tile).toBeVisible({ timeout: 15_000 });
  await tile.click();
  const use = page.getByRole('button', { name: /^(Use|Select|Choose|Insert)/ });
  if (await use.count()) await use.first().click();
  await expect(page.locator('#sb-item-image_id')).not.toHaveValue('');
  await page.getByTestId('item-update').click();
  await expect(page.locator('.alert-success')).toContainText('updated');
  await expect(page.getByTestId('item-image').locator('img')).toBeVisible();

  await page.getByTestId('item-image').getByRole('button', { name: 'Remove featured image' }).click();
  await page.getByTestId('item-update').click();
  await expect(page.getByTestId('item-image').locator('img')).toHaveCount(0);

  page.once('dialog', (d) => void d.accept());
  await page.getByRole('button', { name: /Delete page/ }).click();
  await page.goto(`${ADMIN}/post-type-edit.php?key=${key}`);
  page.once('dialog', (d) => void d.accept());
  await page.getByTestId('type-delete').click();
});

test('the Query Loop offers the site\'s content types', async ({ page }) => {
  await ensurePostType(page, 'services', 'Service');
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await page.getByRole('tab', { name: /^(Add|Ajouter)$/ }).click();
    const panel = page.locator('#sbx-leftpanel-blocks');
    await panel.getByRole('searchbox').fill('posts grid');
    await panel.getByTestId('add-search-results').locator('[data-block-type="core.query_loop"]').click();
    await settled(page);
    await showEdit(page);
    const select = page.getByTestId('post-type-select');
    await expect(select.locator('option[value="services"]')).toHaveCount(1);
    await select.selectOption('services');
    await settled(page);
    await expect(select).toHaveValue('services');
  } finally {
    await restoreLayers(page, before);
  }
});
