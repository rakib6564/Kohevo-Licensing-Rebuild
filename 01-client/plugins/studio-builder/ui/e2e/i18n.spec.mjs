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
  await page.getByRole('tab', { name: 'Calques' }).click();
  let steps = 0; // undo steps to take back: the section (when the page had none) and the block
  try {
    if ((await page.locator('[data-row]').count()) === 0) {
      steps++;
      await page.getByRole('button', { name: /Ajouter une section/ }).first().click();
      await expect.poll(() => page.locator('[data-row]').count(), { timeout: 15_000 }).toBeGreaterThan(0);
      await settled(page);
    }
    // selecting the new section opens the inspector asynchronously: keep trying until the Add panel stays put
    await expect(async () => {
      await page.getByRole('tab', { name: 'Ajouter' }).click();
      await page.locator('#sbx-leftpanel-blocks').getByRole('tab', { name: /Éléments/ }).click();
      await expect(page.locator('#sbx-leftpanel-blocks .sbx-palette-card').first()).toBeVisible({ timeout: 1500 });
    }).toPass({ timeout: 15_000 });
    steps++;
    await page.locator('#sbx-leftpanel-blocks .sbx-palette-card').first().click();
    await expect(page.getByRole('tab', { name: 'Avancé' })).toBeVisible();
    await settled(page); // the inspector re-renders when the server swaps the temporary id
    await page.getByRole('tab', { name: 'Avancé' }).click();
    await expect(page.getByPlaceholder('p. ex. ma-classe hero-banner')).toBeVisible();
    await page.getByRole('tab', { name: 'Style' }).last().click();
    const panel = page.locator('[role="tabpanel"]').filter({ has: page.locator('select') }).first();
    const optionTexts = (await panel.locator('option').allTextContents()).join('|');
    expect(optionTexts.length).toBeGreaterThan(20);
    expect(optionTexts).not.toMatch(/\b(Solid|Dashed|Dotted|Small|Medium|Capitalize|Bold \(700\)|Cover|Contain)\b/);
  } finally {
    // the sandbox page is shared by every spec: put it back how we found it
    for (let i = 0; i < steps; i++) {
      await page.getByTestId('undo').click();
      await page.waitForTimeout(500);
    }
  }
});

test('Add panel cards come from the manifest and are French', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop', 'desktop Add panel');
  await openFrench(page);
  await page.getByRole('tab', { name: 'Ajouter' }).click();
  const panel = page.locator('#sbx-leftpanel-blocks');
  await panel.getByRole('tab', { name: /Éléments/ }).click();
  const titles = async () => panel.locator('.sbx-palette-card__title').allTextContents();
  await panel.locator('[data-view-all="layout"]').click();
  const layout = await titles();
  expect(layout).toEqual(expect.arrayContaining(['Héros', 'Grille']));
  await panel.getByRole('group', { name: 'Catégories' }).getByRole('button', { name: /^Tout/ }).click();
  await panel.locator('[data-view-all="content"]').click();
  const content = await titles();
  expect(content).toEqual(expect.arrayContaining(['Titre', 'Bouton']));
  expect([...layout, ...content].join('|')).not.toMatch(/\b(Hero|Heading|Button|Grid)\b/);
  const descs = (await panel.locator('.sbx-palette-card__desc').allTextContents()).join('|');
  expect(descs).toContain('Créer une hiérarchie');
});
