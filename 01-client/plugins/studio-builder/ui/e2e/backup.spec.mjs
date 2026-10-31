// The Backup screen: make a backup, change the site, check what a restore would do, restore, and find the site put back.
import { test, expect } from '@playwright/test';

const screen = '/plugins/studio-builder/admin/backup.php';
const seo = '/plugins/studio-builder/admin/seo.php';

/** The actions of the newest backup sit in its row's detail. */
async function openNewest(page) {
  await page.goto(screen);
  await page.locator('.data-list .data-row').first().locator('.data-row-summary').click();
}

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'an admin screen; run once');
});

const description = (page) => page.locator('[name="description"]');

async function setDescription(page, text) {
  await page.goto(seo);
  await description(page).fill(text);
  await page.locator('form:has([name="description"]) button[type="submit"]').first().click();
  await expect(description(page)).toHaveValue(text);
}

test('a backup is made, previewed, restored, and the safety backup is there to go back', async ({ page }) => {
  await setDescription(page, 'Before the backup');

  await page.goto(screen);
  await expect(page.getByRole('heading', { name: 'Studio backup' })).toBeVisible();
  await page.getByRole('button', { name: 'Make backup' }).click();
  await expect(page.getByRole('status')).toContainText('Backup made');

  await openNewest(page);
  const download = page.locator('a[href*="?download="]').first();
  const href = await download.getAttribute('href');
  const file = await page.request.get(href);
  expect(file.status()).toBe(200);
  expect(file.headers()['content-type']).toContain('application/zip');

  await setDescription(page, 'After the backup');

  await openNewest(page);
  await page.locator('a[href*="?preview="]').first().click();
  const preview = page.getByTestId('backup-preview');
  await expect(preview).toContainText('Check before you restore');
  await expect(preview).toContainText('pages will be updated');

  // Nothing has changed yet.
  await page.goto(seo);
  await expect(description(page)).toHaveValue('After the backup');

  await openNewest(page);
  await page.locator('a[href*="?preview="]').first().click();
  await page.getByLabel('I understand this changes the live site').check();
  await page.getByRole('button', { name: 'Restore this backup' }).click();
  await expect(page.getByTestId('backup-result')).toContainText('pages created');
  await expect(page.getByText('Before a restore').first()).toBeVisible();

  await page.goto(seo);
  await expect(description(page)).toHaveValue('Before the backup');
});

test('a file that is not a backup is refused and leaves nothing behind', async ({ page }) => {
  await page.goto(screen);
  const before = await page.locator('a[href*="?download="]').count();
  await page.locator('input[name="backup_file"]').setInputFiles({ name: 'photo.zip', mimeType: 'application/zip', buffer: Buffer.from('not a zip') });
  await page.getByRole('button', { name: 'Upload and check' }).click();
  await expect(page.getByRole('alert')).toContainText('not a zip archive');
  await expect(page.locator('a[href*="?download="]')).toHaveCount(before);
});
