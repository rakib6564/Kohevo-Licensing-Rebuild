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

test('the sidebar lists the Studio screens once, grouped, with Design opening the builder', async ({ page }) => {
  await page.goto('/plugins/studio-builder/admin/pages.php');
  const sidebar = page.locator('aside');
  await expect(sidebar.locator('.sidebar-subsection')).toHaveText(['Content', 'Site', 'Search', 'Advanced']);
  for (const name of ['Home', 'Pages', 'Templates', 'Menus', 'Design', 'SEO', 'Redirects', 'Tracking & code', 'Content types', 'History', 'AI & MCP']) {
    await expect(sidebar.getByRole('link', { name, exact: true })).toHaveCount(1);
  }
  await expect(sidebar.getByRole('link', { name: 'Themes', exact: true })).toHaveCount(0);
  await expect(sidebar.getByRole('link', { name: 'Global settings', exact: true })).toHaveCount(0);
  await expect(page.locator('.sb-admin-nav-bar, .sb-admin-header')).toHaveCount(0);
  await sidebar.getByRole('link', { name: 'Design', exact: true }).click();
  await expect(page).toHaveURL(/builder\.php\?page=\d+/);
});
