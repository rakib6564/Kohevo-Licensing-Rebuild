// The Pages list: tick rows, Delete asks first, then the chosen pages leave the list and the rest stay.
import { test, expect } from '@playwright/test';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'admin list; run once');
});

const LIST = '/plugins/studio-builder/admin/pages.php';

async function create(page, title, slug) {
  await page.goto(LIST);
  await page.locator('#sb-title').fill(title);
  await page.locator('#sb-slug').fill(slug);
  await page.getByRole('button', { name: /Create & Open Canvas Builder/ }).click();
  await page.locator('header.sbx-topbar').waitFor({ state: 'visible' });
}

const row = (page, title) => page.locator('.data-row', { hasText: title });

test('Delete is offered once rows are ticked, asks first, and removes only the ticked pages', async ({ page }) => {
  test.setTimeout(120_000);
  const stamp = Date.now();
  const a = `Bulk A ${stamp}`; const b = `Bulk B ${stamp}`; const c = `Bulk C ${stamp}`;
  await create(page, a, `bulk-a-${stamp}`);
  await create(page, b, `bulk-b-${stamp}`);
  await create(page, c, `bulk-c-${stamp}`);

  await page.goto(LIST);
  const del = page.getByRole('button', { name: 'Delete', exact: true });
  await expect(del).toBeHidden(); // nothing ticked: the bar is hidden

  await row(page, a).locator('.data-row-select').check();
  await row(page, b).locator('.data-row-select').check();
  await expect(del).toBeVisible();
  await expect(page.locator('.data-list-selected-count')).toHaveText('2');

  // Saying No keeps everything.
  page.once('dialog', (d) => { expect(d.message()).toContain('2'); d.dismiss(); });
  await del.click();
  await expect(row(page, a)).toHaveCount(1);

  // Saying Yes deletes the two ticked pages and leaves the third.
  page.once('dialog', (d) => d.accept());
  await del.click();
  await expect(page.locator('.alert-success')).toContainText('2 page(s) deleted');
  await expect(row(page, a)).toHaveCount(0);
  await expect(row(page, b)).toHaveCount(0);
  await expect(row(page, c)).toHaveCount(1);

  // tidy up the third one the same way
  await row(page, c).locator('.data-row-select').check();
  page.once('dialog', (d) => d.accept());
  await page.getByRole('button', { name: 'Delete', exact: true }).click();
  await expect(row(page, c)).toHaveCount(0);
});
