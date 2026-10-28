// What a real rebuild (FightxLife) had to leave in custom CSS, now native: a Button's own button takes a gradient, a
// fixed height and a smaller height on a phone from Style › Parts; a Grid takes unequal column sizes from Layout.
import { test, expect } from '@playwright/test';
import { openBuilder, frameEval, settled, openSection, layerCount, restoreLayers } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked Inspector (desktop)');
});

async function addBlock(page, search, type) {
  await page.getByRole('tab', { name: /^(Add|Ajouter)$/ }).click();
  const panel = page.locator('#sbx-leftpanel-blocks');
  await panel.getByRole('searchbox').fill(search);
  await panel.locator(`[data-block-type="${type}"]`).first().click();
  await settled(page);
}

test('a button part takes a gradient and a height, and its own height on a phone', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await addBlock(page, 'button', 'core.button');
    const setup = page.getByRole('dialog', { name: 'Set up Button' });
    await setup.getByLabel('Label').fill('Donate');
    await setup.getByLabel('Address').fill('/donate');
    await setup.getByRole('button', { name: 'Add' }).click();
    await settled(page);
    const body = await openSection(page, 'parts');
    const parts = body.locator('[data-part-styles]');
    await expect(parts.getByLabel('Part', { exact: true })).toHaveValue('button');
    await parts.getByLabel('Gradient fill').check();
    await settled(page);
    await expect.poll(() => frameEval(page, '.sb-button', (el) => getComputedStyle(el).backgroundImage), { timeout: 20_000 }).toContain('linear-gradient');

    await parts.locator('summary', { hasText: 'Size' }).click();
    await parts.getByLabel('Height', { exact: true }).fill('60px');
    await parts.getByLabel('Height', { exact: true }).blur();
    await settled(page);
    await expect.poll(() => frameEval(page, '.sb-button', (el) => getComputedStyle(el).height), { timeout: 20_000 }).toBe('60px');

    // On a phone the same panel edits the phone's own value; the desktop one stays.
    await page.getByRole('button', { name: 'Mobile' }).first().click();
    await expect(parts.getByText(/apply on Mobile and smaller/)).toBeVisible();
    await expect(parts.getByLabel('Gradient fill')).toHaveCount(0);
    await parts.getByLabel('Height', { exact: true }).fill('44px');
    await parts.getByLabel('Height', { exact: true }).blur();
    await settled(page);
    await expect.poll(() => frameEval(page, '.sb-button', (el) => getComputedStyle(el).height), { timeout: 20_000 }).toBe('44px');
    await page.getByRole('button', { name: 'Desktop' }).first().click();
    await expect.poll(() => frameEval(page, '.sb-button', (el) => getComputedStyle(el).height), { timeout: 20_000 }).toBe('60px');
    await expect(page.getByRole('alert')).toHaveCount(0);
  } finally {
    await restoreLayers(page, before);
  }
});

test('a grid takes unequal column sizes, and refuses what is not a size', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await addBlock(page, 'grid', 'layout.grid');
    const body = await openSection(page, 'layout');
    const sizes = body.getByLabel('Column sizes');
    await sizes.fill('2fr;color:red');
    await expect(body.getByRole('alert')).toContainText('up to 12 sizes');
    await sizes.fill('2fr 1fr');
    await sizes.press('Enter');
    await settled(page);
    await expect.poll(async () => {
      const cols = await frameEval(page, '.sb-block--layout-grid', (el) => getComputedStyle(el).gridTemplateColumns);
      const [a, b] = String(cols).split(' ').map(parseFloat);
      return b ? Math.round(a / b) : 0;
    }, { timeout: 20_000 }).toBe(2);
  } finally {
    await restoreLayers(page, before);
  }
});
