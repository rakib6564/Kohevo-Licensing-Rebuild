// The French pack reaches the browser: labels in the touched components are French, not English.
import { test, expect } from '@playwright/test';
import { sandboxPageId, settled } from './helpers.mjs';

// `?lang=fr` is stored in the (shared) admin session, so always put English back for the other suites.
test.afterEach(async ({ page }) => {
  await page.goto(`/plugins/studio-builder/admin/builder.php?page=${sandboxPageId()}&lang=en`);
});

async function openFrench(page) {
  await page.goto(`/plugins/studio-builder/admin/builder.php?page=${sandboxPageId()}&lang=fr`);
  await page.locator('header.sbx-topbar').waitFor({ state: 'visible' });
  await page.locator('iframe.sbx-canvas__frame').waitFor({ state: 'visible' });
}

test('desktop shell labels are French', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop', 'desktop labels');
  await openFrench(page);
  await expect(page.getByRole('tab', { name: 'Calques' })).toBeVisible();
  await expect(page.getByRole('tab', { name: 'Ajouter' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Publier' })).toBeVisible();
  await expect(page.getByTestId('canvas-grid-toggle')).toContainText('Grille');
  await expect(page.getByTestId('zoom-fit')).toContainText('Ajuster');
  await page.getByTestId('shortcut-help-toggle').click();
  await expect(page.getByTestId('shortcut-help')).toContainText('Raccourcis clavier');
  await expect(page.locator('html')).toHaveAttribute('lang', 'fr');
  // the multilang plugin must not float its switcher over the builder's controls
  await expect(page.locator('body > .mlt-switcher, body > [class*="mlt-"]')).toHaveCount(0);
});

test('phone dock, sheets and top bar are French', async ({ page }, info) => {
  test.skip(info.project.name !== 'mobile', 'phone labels');
  await openFrench(page);
  const labels = await page.locator('nav.sbx-mobile-dock .sbx-dock-btn__label').allTextContents();
  expect(labels).toEqual(['Blocs', 'Modifier', 'Thème', 'Aperçu', 'Plus']);
  await expect(page.getByTestId('status-chip')).toContainText(/Enregistré|Non enregistré|Publié/);
  await page.getByTestId('mobile-dock-more').click();
  await expect(page.getByTestId('sheet-more')).toContainText('Historique des versions');
  await page.keyboard.press('Escape');
  await page.getByTestId('open-responsive-view').click();
  await expect(page.getByTestId('sheet-responsive')).toContainText('Vue adaptative');
});

test('block inspector controls are French (tabs, placeholders, select options)', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop', 'desktop inspector');
  await openFrench(page);
  // insert a block so the inspector shows block tabs: add a section, then the first element
  await page.getByRole('tab', { name: 'Calques' }).click();
  let steps = 1; // the block insert (+ the section when the page had none)
  if ((await page.locator('[data-row]').count()) === 0) {
    steps = 2;
    await page.getByRole('button', { name: /Ajouter une section/ }).first().click();
    await expect.poll(() => page.locator('[data-row]').count(), { timeout: 15_000 }).toBeGreaterThan(0);
  }
  await page.getByRole('tab', { name: 'Ajouter' }).click();
  await page.getByRole('tab', { name: /Éléments/ }).click();
  await page.locator('.sbx-palette-card').first().click();
  await expect(page.getByRole('tab', { name: 'Avancé' })).toBeVisible();
  await settled(page); // the inspector re-renders when the server swaps the temporary id
  try {
  await page.getByRole('tab', { name: 'Avancé' }).click();
  await expect(page.getByPlaceholder('p. ex. ma-classe hero-banner')).toBeVisible();
  await page.getByRole('tab', { name: 'Style' }).last().click();
  const panel = page.locator('[role="tabpanel"]').filter({ has: page.locator('select') }).first();
  const optionTexts = (await panel.locator('option').allTextContents()).join('|');
  expect(optionTexts.length).toBeGreaterThan(20);
  expect(optionTexts).not.toMatch(/\b(Solid|Dashed|Dotted|Small|Medium|Large|Capitalize|Bold \(700\)|Cover|Contain)\b/);
  } finally {
    // the sandbox page is shared by every spec: put it back how we found it
    for (let i = 0; i < steps; i++) {
      await page.getByTestId('undo').click();
      await page.waitForTimeout(400);
    }
  }
});
