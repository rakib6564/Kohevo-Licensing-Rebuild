// Any post type can be deleted (built-in or custom), and the built-in ones can be brought back.
import { test, expect } from '@playwright/test';

const TYPES = '/plugins/studio-builder/admin/types-fields.php';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'admin screen; run once');
});

test('a built-in post type can be deleted, asks first, and is restored from the button', async ({ page }) => {
  await page.goto(TYPES);
  const cards = page.locator('.card:has(a:text("Manage Items")), .sb-card:has(a:text("Manage Items"))');
  const before = await cards.count();
  expect(before).toBeGreaterThanOrEqual(4);
  const target = cards.filter({ hasText: 'Testimonial' }).first();

  let asked = '';
  page.once('dialog', (d) => { asked = d.message(); void d.dismiss(); });
  await target.getByRole('button', { name: /Delete/ }).click();
  expect(asked).toContain('Testimonial');
  await expect(cards).toHaveCount(before); // dismissed: nothing happened

  page.once('dialog', (d) => void d.accept());
  await target.getByRole('button', { name: /Delete/ }).click();
  await expect(page.locator('.alert-success')).toContainText('deleted');
  await expect(cards).toHaveCount(before - 1);
  await expect(page.getByRole('button', { name: /Restore 1 built-in/ })).toBeVisible();

  await page.getByRole('button', { name: /Restore 1 built-in/ }).click();
  await expect(page.locator('.alert-success')).toContainText('restored');
  await expect(cards).toHaveCount(before);
});
