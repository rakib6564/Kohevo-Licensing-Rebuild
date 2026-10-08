// The Advanced tab (B2-P3d): class names, ID and accessibility, data attributes. Each refuses what the server refuses.
import { test, expect } from '@playwright/test';
import { openBuilder, frameDocument, settled, sandboxPageId, openSection } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked Inspector (desktop)');
});

test.afterEach(async ({ page }) => {
  await page.goto(`/plugins/studio-builder/admin/builder.php?page=${sandboxPageId()}&lang=en`);
});

async function layerCount(page) {
  await page.getByRole('tab', { name: /^Layers$/ }).click();
  return page.locator('[role="treeitem"]').count();
}

async function restore(page, layers) {
  for (let i = 0; i < 16; i++) {
    await settled(page);
    if ((await layerCount(page)) <= layers) return;
    const undo = page.getByTestId('undo');
    await expect(undo).toBeEnabled({ timeout: 5_000 });
    await undo.click();
    await page.waitForTimeout(500);
  }
  throw new Error('could not restore the shared sandbox page');
}

async function insertBlock(page, type, chip = 'content') {
  await page.getByRole('tab', { name: /^(Add|Ajouter)$/ }).click();
  const panel = page.locator('#sbx-leftpanel-blocks');
  await panel.getByRole('tab', { name: 'Elements' }).click();
  await panel.locator(`[data-chip="${chip}"]`).click();
  await panel.locator(`[data-block-type="${type}"]`).click();
  await settled(page);
}



const advancedTab = (page) => page.locator('[id^="sbx-blk-"][id$="-tab-advanced"]').click();
const alerts = (page) => page.getByRole('alert');

test('ID, accessible label and role reach the page; a second block cannot take the same ID', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await insertBlock(page, 'core.heading');
    await advancedTab(page);
    await openSection(page, 'identity');
    const id = page.getByLabel('ID', { exact: true });
    await id.fill('1bad id');
    await id.blur();
    await expect(alerts(page).filter({ hasText: /Start with a letter/ })).toBeVisible();
    await id.fill('pricing');
    await id.blur();
    await expect(alerts(page)).toHaveCount(0);
    await page.getByLabel('Accessible label', { exact: true }).fill('Our pricing');
    await page.getByLabel('Accessible label', { exact: true }).blur();
    await page.getByRole('combobox', { name: 'Role' }).selectOption('region');
    await settled(page);
    const frame = await frameDocument(page);
    const first = frame.locator('#pricing');
    await expect(first).toHaveAttribute('aria-label', 'Our pricing', { timeout: 20_000 });
    await expect(first).toHaveAttribute('role', 'region');

    await insertBlock(page, 'core.heading');
    await advancedTab(page);
    await openSection(page, 'identity');
    const second = page.getByLabel('ID', { exact: true });
    await second.fill('pricing');
    await second.blur();
    await expect(alerts(page).filter({ hasText: /Another block already uses this ID/ })).toBeVisible();
    await expect(second).toHaveAttribute('aria-invalid', 'true');
    await expect((await frameDocument(page)).locator('#pricing')).toHaveCount(1);
  } finally {
    await restore(page, before);
  }
});

test('data attributes are added and removed as rows, and a refused name stays in the box', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await insertBlock(page, 'core.heading');
    await advancedTab(page);
    await openSection(page, 'attributes');
    const section = page.locator('[data-section="attributes"]');
    await section.getByLabel('Name', { exact: true }).fill('sb-node');
    await section.getByLabel('Value', { exact: true }).fill('x');
    await section.getByRole('button', { name: 'Add attribute' }).click();
    await expect(alerts(page).filter({ hasText: /Not a valid attribute/ })).toBeVisible(); // data-sb-* is the builder's
    await section.getByLabel('Name', { exact: true }).fill('track');
    await section.getByLabel('Value', { exact: true }).fill('cta click');
    await section.getByRole('button', { name: 'Add attribute' }).click();
    await expect(alerts(page)).toHaveCount(0);
    await settled(page);
    const frame = await frameDocument(page);
    await expect(frame.locator('[data-track="cta click"]')).toHaveCount(1, { timeout: 20_000 });
    await expect(page.locator('[data-section="attributes"] .sbx-isec__toggle')).toContainText('1');

    await section.getByRole('button', { name: 'Remove data-track' }).click();
    await expect(frame.locator('[data-track]')).toHaveCount(0, { timeout: 20_000 });
  } finally {
    await restore(page, before);
  }
});

test('class names accept letters, digits, - and _ only', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await insertBlock(page, 'core.heading');
    await advancedTab(page);
    await openSection(page, 'classes');
    const field = page.getByLabel('CSS Classes', { exact: true });
    await field.fill('good a.b');
    await field.blur();
    await expect(alerts(page).filter({ hasText: /Class names may only use/ })).toBeVisible();
    await field.fill('promo-banner big_text');
    await field.blur();
    await expect(alerts(page)).toHaveCount(0);
    await settled(page);
    const frame = await frameDocument(page);
    await expect(frame.locator('[data-sb-type="core.heading"].promo-banner.big_text')).toHaveCount(1, { timeout: 20_000 });
  } finally {
    await restore(page, before);
  }
});
