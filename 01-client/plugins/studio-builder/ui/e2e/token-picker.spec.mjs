// Theme tokens are chosen by name, with a sample, not by their raw refs.
import { test, expect } from '@playwright/test';
import { openBuilder, settled, sandboxPageId, openSection } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked Inspector (desktop)');
});

test.afterEach(async ({ page }) => {
  await page.goto(`/plugins/studio-builder/admin/builder.php?page=${sandboxPageId()}&lang=en`);
});

test('the token picker lists named tokens under readable headings and stores the ref', async ({ page }) => {
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

    await openSection(page, 'tokens');
    const body = page.locator('[data-section="tokens"] .sbx-isec__body');
    for (const label of ['Surface colour', 'Text colour', 'Spacing', 'Corner radius', 'Shadow', 'Font']) {
      await expect(body.getByText(label, { exact: true })).toBeVisible();
    }
    await expect(body.locator('select')).toHaveCount(0);

    const surface = body.getByRole('combobox', { name: /^Surface colour/ });
    await surface.click();
    const list = body.getByRole('listbox');
    await expect(list.getByText('Surfaces', { exact: true })).toBeVisible();
    await expect(list.getByText('Accent', { exact: true }).first()).toBeVisible();
    await expect(list).not.toContainText('surface.primary');
    await expect(list).not.toContainText('color.accent');
    await list.getByRole('option', { name: /^Main surface/ }).click();
    await expect(surface).toContainText('Main surface');
    await expect(surface).toHaveAttribute('title', 'surface.primary');

    // keyboard: reopen, move, choose, and Escape closes without changing
    await surface.focus();
    await page.keyboard.press('Enter');
    await expect(list).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(list).toHaveCount(0);
    await surface.click();
    await body.getByRole('listbox').getByRole('option', { name: /^Default/ }).click();
    await expect(surface).toContainText('Default');
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
