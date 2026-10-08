// The Pages view on a phone: reachable from the Layers sheet, touch-sized controls, no sideways scroll.
import { test, expect } from '@playwright/test';
import { openBuilder } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'mobile', 'phone shell');
});

test('Layers sheet → Pages: rows and their actions are touch-sized and the page does not scroll sideways', async ({ page }) => {
  await openBuilder(page);
  await page.getByTestId('mobile-dock-blocks').click();
  const sheet = page.locator('.sbx-left.is-mobile-open');
  await sheet.getByRole('tab', { name: /^Layers$/ }).click();
  await sheet.locator('[data-nav="pages"]').click();
  const panel = sheet.getByTestId('pages-panel');
  const row = panel.locator('.sbx-page-row.is-current');
  await expect(row).toBeVisible();
  const heights = await row.locator('.sbx-page-row__actions .sbx-btn').evaluateAll((els) => els.map((e) => e.getBoundingClientRect().height));
  expect(heights.length).toBe(3);
  for (const h of heights) expect(h).toBeGreaterThanOrEqual(38);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
  // the new-page dialog fits the phone
  await panel.getByTestId('page-new').click();
  const box = await page.getByRole('dialog').boundingBox();
  expect(box.x).toBeGreaterThanOrEqual(0);
  expect(box.x + box.width).toBeLessThanOrEqual(page.viewportSize().width + 1);
});
