// The French pack reaches the browser: labels in the touched components are French, not English.
import { test, expect } from '@playwright/test';
import { sandboxPageId } from './helpers.mjs';

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
