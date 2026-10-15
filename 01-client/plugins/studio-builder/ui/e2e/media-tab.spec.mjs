// The Add panel's Media tab opens (it once crashed the whole editor: a missing icon import).
import { test, expect } from '@playwright/test';
import { openBuilder, settled } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked panel (desktop)');
});

test('the Media tab shows the add-image button and the editor stays up', async ({ page }) => {
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  await openBuilder(page);
  await settled(page);
  await page.getByRole('tab', { name: 'Media', exact: true }).click();
  await expect(page.locator('#sbx-leftpanel-blocks').getByRole('button', { name: /image/i }).first()).toBeVisible();
  await expect(page.getByRole('tab', { name: /^Layers$/ })).toBeVisible(); // the shell is still there
  expect(errors).toEqual([]);
});
