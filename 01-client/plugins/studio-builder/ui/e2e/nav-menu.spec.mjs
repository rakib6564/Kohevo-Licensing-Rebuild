// The Nav menu element: found in the Layout group, drawn on the canvas, its links edited and its layout set from the Inspector.
import { test, expect } from '@playwright/test';
import { openBuilder, settled, sandboxPageId, openSection } from './helpers.mjs';

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
    await expect(undo).toBeEnabled({ timeout: 15_000 });
    await undo.click();
    await page.waitForTimeout(500);
  }
  throw new Error('could not restore the shared sandbox page');
}

const canvas = (page) => page.frameLocator('iframe.sbx-canvas__frame');

async function addElement(page, type) {
  await page.getByRole('tab', { name: /^(Add|Ajouter)$/ }).click();
  const panel = page.locator('#sbx-leftpanel-blocks');
  await panel.getByRole('tab', { name: 'Elements' }).click();
  await panel.locator('[data-chip="layout"]').click();
  await panel.locator(`[data-block-type="${type}"]`).click();
  await settled(page);
}

test('a Nav menu is added from the Layout group, its links are edited and its layout changes the list on the canvas', async ({ page }) => {
  test.setTimeout(90_000);
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await addElement(page, 'core.nav_menu');
    const nav = canvas(page).locator('[data-sb-type="core.nav_menu"].sbx-selected nav.sb-nav');
    await expect(nav).toHaveCount(1);
    await expect(nav.locator('a.sb-nav__link')).toHaveCount(3);
    await expect.poll(() => nav.locator('.sb-nav__list').evaluate((el) => getComputedStyle(el).flexDirection)).toBe('row');

    const inspector = page.getByTestId('inspector-panel');
    await inspector.getByLabel('Layout', { exact: true }).selectOption('vertical');
    await settled(page);
    await expect.poll(() => nav.locator('.sb-nav__list').evaluate((el) => getComputedStyle(el).flexDirection), { timeout: 15_000 }).toBe('column');

    await inspector.getByLabel('Link style', { exact: true }).selectOption('pill');
    await settled(page);
    await expect(nav).toHaveClass(/sb-nav--pill/, { timeout: 15_000 });

    await inspector.locator('input[value="Home"]').first().fill('Start');
    await settled(page);
    await expect(nav.locator('a.sb-nav__link').first()).toHaveText('Start', { timeout: 15_000 });
  } finally {
    await restore(page, before);
  }
});
