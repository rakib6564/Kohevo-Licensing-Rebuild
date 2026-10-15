// Pages admin: the list, New page, and one page's settings are separate screens, each doing one job.
import { test, expect } from '@playwright/test';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'admin screens; run once');
});

const ADMIN = '/plugins/studio-builder/admin';

test('create, find, rename, duplicate and delete a page, each on its own screen', async ({ page }) => {
  test.setTimeout(120_000);
  const stamp = Date.now();
  const title = `Crud ${stamp}`;
  const slug = `crud-${stamp}`;

  // The list has no create form any more: it offers a button to the New page screen.
  await page.goto(`${ADMIN}/pages.php`);
  await expect(page.locator('#sb-title')).toHaveCount(0);
  await page.getByTestId('new-page').click();
  await expect(page).toHaveURL(/page-new\.php$/);

  // New page: one form; cancelling goes back to the list, creating opens the builder.
  await page.locator('#sb-title').fill(title);
  await page.locator('#sb-slug').fill(slug);
  await page.getByRole('button', { name: /Create & Open Canvas Builder/ }).click();
  await page.locator('header.sbx-topbar').waitFor({ state: 'visible' });

  // The list finds it by search and offers its Settings.
  await page.goto(`${ADMIN}/pages.php?q=${slug}`);
  const row = page.locator('.data-row', { hasText: title });
  await expect(row).toHaveCount(1);
  await row.locator('.data-row-summary').click(); // a row opens to show its actions
  await row.getByRole('link', { name: 'Settings' }).click();
  await expect(page).toHaveURL(/page-edit\.php\?id=\d+$/);
  await expect(page.getByTestId('page-meta')).toContainText('Draft');
  await expect(page.getByTestId('page-meta')).toContainText(`/${slug}`);

  // Rename and move: saved with a notice, and the new address shows.
  const renamed = `${title} renamed`;
  await page.locator('#sb-title').fill(renamed);
  await page.locator('#sb-slug').fill(`${slug}-2`);
  await page.getByRole('button', { name: 'Save changes' }).click();
  await expect(page.locator('.alert-success')).toContainText('Page saved');
  await expect(page.locator('#sb-title')).toHaveValue(renamed);
  await expect(page.getByTestId('page-meta')).toContainText(`/${slug}-2`);

  // A bad address is refused and the page keeps its old one.
  await page.locator('#sb-slug').evaluate((el) => { el.removeAttribute('pattern'); });
  await page.locator('#sb-slug').fill('Bad Address!');
  await page.getByRole('button', { name: 'Save changes' }).click();
  await expect(page.locator('.alert-error')).toBeVisible();
  await page.goto(page.url());
  await expect(page.locator('#sb-slug')).toHaveValue(`${slug}-2`);

  // Duplicate lands on the copy.
  await page.locator('#sb-copy-title').fill(`${title} copy`);
  await page.locator('#sb-copy-slug').fill(`${slug}-copy`);
  await page.getByRole('button', { name: 'Duplicate' }).click();
  await expect(page.locator('.alert-success')).toContainText('duplicated');
  await expect(page.locator('#sb-title')).toHaveValue(`${title} copy`);

  // Delete asks first; Yes returns to the list with a notice and the page is gone.
  page.once('dialog', (d) => d.dismiss());
  await page.getByRole('button', { name: 'Delete page' }).click();
  await expect(page).toHaveURL(/page-edit\.php/);
  page.once('dialog', (d) => d.accept());
  await page.getByRole('button', { name: 'Delete page' }).click();
  await expect(page).toHaveURL(/pages\.php/);
  await expect(page.locator('.alert-success')).toContainText('1 page(s) deleted');
  await page.goto(`${ADMIN}/pages.php?q=${slug}`);
  await expect(page.locator('.data-row', { hasText: `${title} copy` })).toHaveCount(0);
  await expect(page.locator('.data-row', { hasText: renamed })).toHaveCount(1);

  // Tidy the rest.
  await page.locator('.data-row', { hasText: renamed }).locator('.data-row-summary').click();
  await page.locator('.data-row', { hasText: renamed }).getByRole('link', { name: 'Settings' }).click();
  page.once('dialog', (d) => d.accept());
  await page.getByRole('button', { name: 'Delete page' }).click();
  await expect(page.locator('.alert-success')).toBeVisible();
});

test('a page that does not exist says so instead of an empty form', async ({ page }) => {
  await page.goto(`${ADMIN}/page-edit.php?id=99999999`);
  await expect(page.locator('.alert-error')).toContainText('does not exist');
  await expect(page.locator('#sb-title')).toHaveCount(0);
});
