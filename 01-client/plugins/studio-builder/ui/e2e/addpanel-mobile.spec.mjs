// The Add sheet on a phone: the category rail scrolls inside itself, chips are touch-sized,
// and nothing makes the page scroll sideways.
import { test, expect } from '@playwright/test';
import { openBuilder } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'mobile', 'phone shell');
});

test('Blocks sheet: Elements show a touch-sized category rail and View all works', async ({ page }) => {
  await openBuilder(page);
  await page.getByTestId('mobile-dock-blocks').click();
  const sheet = page.locator('.sbx-left.is-mobile-open');
  await expect(sheet).toHaveCount(1);
  await sheet.getByRole('tab', { name: /^Add$/ }).click();
  await sheet.getByRole('tab', { name: 'Elements' }).click();
  const rail = sheet.getByRole('group', { name: 'Categories' });
  await expect(rail).toBeVisible();
  const chips = rail.locator('.sbx-chip');
  expect(await chips.count()).toBeGreaterThanOrEqual(4);
  for (const box of await chips.evaluateAll((els) => els.map((e) => { const r = e.getBoundingClientRect(); return { h: r.height, w: r.width }; }))) {
    expect(box.h).toBeGreaterThanOrEqual(38);
  }
  // the rail may scroll on its own, the page may not
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
  await sheet.locator('[data-view-all]').first().click();
  expect(await sheet.locator('.sbx-elements__group').count()).toBe(1);
});
