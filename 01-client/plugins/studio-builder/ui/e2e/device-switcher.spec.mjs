// Responsive values are edited one device at a time (Mobile = base, Tablet = md, Desktop = lg).
import { test, expect } from '@playwright/test';
import { openBuilder, settled, sandboxPageId, openSection } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked Inspector (desktop)');
});

test.afterEach(async ({ page }) => {
  await page.goto(`/plugins/studio-builder/admin/builder.php?page=${sandboxPageId()}&lang=en`);
});

test('alignment is one icon group per device, and the device buttons also switch the canvas', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  await page.getByRole('tab', { name: /^Layers$/ }).click();
  const layers = await page.locator('[role="treeitem"]').count();
  try {
    await page.getByRole('tab', { name: /^(Add|Ajouter)$/ }).click();
    const panel = page.locator('#sbx-leftpanel-blocks');
    await panel.getByRole('tab', { name: 'Elements' }).click();
    await panel.locator('[data-chip="content"]').click();
    await panel.locator('[data-block-type="core.quote"]').click();
    await settled(page);

    await openSection(page, 'align');
    const body = page.locator('[data-section="align"] .sbx-isec__body');
    await expect(body.getByRole('group', { name: /^Alignment — / })).toBeVisible();
    await expect(body.locator('select')).toHaveCount(1); // only the sm override, hidden in <details>

    await body.getByRole('button', { name: 'Tablet' }).click();
    await expect(page.getByRole('button', { name: 'Tablet' }).first()).toHaveAttribute('aria-pressed', 'true');
    await body.getByRole('button', { name: 'center', exact: true }).click();
    await expect(body.getByRole('button', { name: 'center', exact: true })).toHaveAttribute('aria-pressed', 'true');

    await body.getByRole('button', { name: 'Mobile' }).click();
    await expect(body.getByRole('button', { name: 'center', exact: true })).toHaveAttribute('aria-pressed', 'false');
    await body.getByRole('button', { name: 'Tablet' }).click();
    await expect(body.getByRole('button', { name: 'center', exact: true })).toHaveAttribute('aria-pressed', 'true');
  } finally {
    for (let i = 0; i < 16; i++) {
      await settled(page);
      await page.getByRole('tab', { name: /^Layers$/ }).click();
      if ((await page.locator('[role="treeitem"]').count()) <= layers) break;
      await expect(page.getByTestId('undo')).toBeEnabled({ timeout: 15_000 });
      await page.getByTestId('undo').click();
      await page.waitForTimeout(500);
    }
  }
});
