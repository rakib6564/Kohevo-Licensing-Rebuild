// Reviews, Redirects and Meta fields use the platform's expandable data rows, not a raw table.
import { test, expect } from '@playwright/test';

const ADMIN = '/plugins/studio-builder/admin';

for (const [file, empty] of [['reviews.php', /No revisions recorded/], ['redirects.php', /No redirect rules/], ['types-fields.php', /No custom fields|Configured Meta Fields/]]) {
  test(`${file}: no raw table, fits the screen, rows expand`, async ({ page }) => {
    await page.goto(`${ADMIN}/${file}`);
    await expect(page.locator('table')).toHaveCount(0);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
    const rows = page.locator('.data-list .data-row');
    if (await rows.count()) {
      const first = rows.first();
      await first.locator('.data-row-summary').click();
      await expect(first.locator('.data-row-detail')).toBeVisible();
    } else {
      await expect(page.locator('body')).toContainText(empty);
    }
  });
}
