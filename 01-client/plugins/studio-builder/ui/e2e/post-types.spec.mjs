// Post types are the site's own: Studio ships none built in, so there is nothing to restore. A type can be created,
// and deleting one asks first.
import { test, expect } from '@playwright/test';

const TYPES = '/plugins/studio-builder/admin/types-fields.php';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'admin screen; run once');
});

test('a post type is created, deleting it asks first, and no built-in types can be restored', async ({ page }) => {
  const key = `event_${Date.now()}`;
  await page.goto(TYPES);
  await expect(page.getByRole('button', { name: /Restore .*built-in/ })).toHaveCount(0);
  for (const builtIn of ['portfolio', 'testimonials', 'case_studies']) {
    await expect(page.locator(`[data-post-type="${builtIn}"]`)).toHaveCount(0);
  }

  await page.evaluate(() => { document.getElementById('sb-cpt-drawer').style.display = 'block'; });
  const d = page.locator('#sb-cpt-drawer');
  await d.locator('input[name="key"]').fill(key);
  await d.locator('input[name="singular"]').fill('Event');
  await d.getByRole('button', { name: /Save Post Type/ }).click();
  const card = page.locator(`[data-post-type="${key}"]`);
  await expect(card).toHaveCount(1);

  let asked = '';
  page.once('dialog', (dlg) => { asked = dlg.message(); void dlg.dismiss(); });
  await card.getByRole('button', { name: /Delete/ }).click();
  expect(asked).toContain('Event');
  await expect(card).toHaveCount(1); // dismissed: nothing happened

  page.once('dialog', (dlg) => void dlg.accept());
  await card.getByRole('button', { name: /Delete/ }).click();
  await expect(page.locator('.alert-success')).toContainText('deleted');
  await expect(card).toHaveCount(0);
  await expect(page.getByRole('button', { name: /Restore .*built-in/ })).toHaveCount(0);
});
