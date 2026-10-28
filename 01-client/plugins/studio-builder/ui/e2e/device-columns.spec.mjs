// Counts per device (DeviceProps): a Gallery's columns get their own value on a phone from the Content tab, and the
// canvas, at phone width, lays the gallery out with that many columns (Auto: two).
import { test, expect } from '@playwright/test';
import { openBuilder, frameEval, settled, layerCount, restoreLayers } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked Inspector (desktop)');
});

test('a gallery takes its own column count on mobile, and the phone canvas shows it', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await page.getByRole('tab', { name: /^(Add|Ajouter)$/ }).click();
    const panel = page.locator('#sbx-leftpanel-blocks');
    await panel.getByRole('tab', { name: 'Elements' }).click();
    await panel.locator('[data-chip="media"]').click();
    await panel.locator('[data-block-type="core.gallery"]').click();
    await settled(page);

    const prop = page.locator('[data-device-prop="columns"]');
    await expect(prop).toBeVisible();
    await prop.getByRole('button', { name: 'Mobile' }).click();
    await expect(page.getByRole('button', { name: 'Mobile' }).first()).toHaveAttribute('aria-pressed', 'true');
    const pills = prop.getByRole('group', { name: /Columns · Mobile/ });
    await expect(pills.getByRole('button', { name: 'Auto' })).toHaveAttribute('aria-pressed', 'true');
    await pills.getByRole('button', { name: '3', exact: true }).click();
    await settled(page);

    await expect.poll(() => frameEval(page, '.sb-gallery', (el) => el.getAttribute('style')), { timeout: 20_000 }).toContain('--sb-dc-m:3');
    await expect.poll(() => frameEval(page, '.sb-gallery', (el) => getComputedStyle(el).gridTemplateColumns.split(' ').length), { timeout: 20_000 }).toBe(3);
    await expect(prop.locator('.sbx-responsive__device.is-set')).toHaveCount(1);

    // Back to Auto: a phone shows the gallery's default, two columns.
    await pills.getByRole('button', { name: 'Auto' }).click();
    await settled(page);
    await expect.poll(() => frameEval(page, '.sb-gallery', (el) => getComputedStyle(el).gridTemplateColumns.split(' ').length), { timeout: 20_000 }).toBe(2);
    await prop.getByRole('button', { name: 'Desktop' }).click();
  } finally {
    await restoreLayers(page, before);
  }
});
