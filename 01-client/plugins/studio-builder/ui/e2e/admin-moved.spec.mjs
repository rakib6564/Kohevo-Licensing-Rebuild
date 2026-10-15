// Themes and Global settings are no longer admin screens: their settings live in the builder (Settings > Site).
import { test, expect } from '@playwright/test';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'admin screens; run once');
});

for (const file of ['themes.php', 'global-settings.php']) {
  test(`${file} opens the builder instead of a dead screen`, async ({ page }) => {
    await page.goto(`/plugins/studio-builder/admin/${file}`);
    await expect(page).toHaveURL(/builder\.php\?page=\d+/);
    await page.locator('header.sbx-topbar').waitFor({ state: 'visible' });
  });
}

test('the Studio menus no longer list Themes or Global settings', async ({ page }) => {
  await page.goto('/plugins/studio-builder/admin/pages.php');
  const bar = page.getByRole('navigation', { name: 'Studio Navigation' });
  await expect(bar.getByRole('link', { name: 'Pages' })).toBeVisible();
  await expect(bar.getByRole('link', { name: 'Themes' })).toHaveCount(0);
  await expect(bar.getByRole('link', { name: 'Global settings' })).toHaveCount(0);
  await expect(page.locator('aside, nav').getByRole('link', { name: 'Themes', exact: true })).toHaveCount(0);
});
