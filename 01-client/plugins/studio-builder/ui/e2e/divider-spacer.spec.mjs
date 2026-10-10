// The Divider and Spacer elements: found in the Layout group, drawn on the canvas, and set from the Inspector.
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

test('a Divider is added from the Layout group and its choices change the line on the canvas', async ({ page }) => {
  test.setTimeout(90_000);
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await addElement(page, 'core.divider');
    const hr = canvas(page).locator('[data-sb-type="core.divider"].sbx-selected hr.sb-divider');
    await expect(hr).toHaveCount(1);
    await expect(hr).toHaveClass(/sb-divider--solid/);

    const inspector = page.getByTestId('inspector-panel');
    await inspector.getByLabel('Line style', { exact: true }).selectOption('dashed');
    await inspector.getByLabel('Thickness', { exact: true }).selectOption('thick');
    await inspector.getByLabel('Width', { exact: true }).selectOption('narrow');
    await settled(page);
    await expect(hr).toHaveClass(/sb-divider--dashed/, { timeout: 15_000 });
    await expect(hr).toHaveClass(/sb-divider--thick/);
    await expect(hr).toHaveClass(/sb-divider--w-narrow/);
    await expect.poll(() => hr.evaluate((el) => `${getComputedStyle(el).borderTopStyle}/${getComputedStyle(el).borderTopWidth}`), { timeout: 15_000 }).toBe('dashed/4px');
    expect(await hr.evaluate((el) => Math.round(el.getBoundingClientRect().width / el.parentElement.getBoundingClientRect().width * 100))).toBe(50);

    // The line takes the colour of the block's border: a theme border colour from the Border section reaches the hr.
    const asTheme = await hr.evaluate((el) => getComputedStyle(el).borderTopColor);
    await page.locator('[id^="sbx-blk-"][id$="-tab-style"]').click();
    const border = await openSection(page, 'border');
    await border.locator('.sbx-color__theme .sbx-tp__btn').first().click();
    await page.getByRole('listbox').getByRole('option').filter({ hasText: /Accent/ }).first().click();
    await settled(page);
    await expect.poll(() => hr.evaluate((el) => getComputedStyle(el).borderTopColor), { timeout: 15_000 }).not.toBe(asTheme);
  } finally {
    await restore(page, before);
  }
});

test('a Spacer is added from the Layout group and its height follows the chosen size', async ({ page }) => {
  test.setTimeout(90_000);
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await addElement(page, 'core.spacer');
    const gap = canvas(page).locator('[data-sb-type="core.spacer"].sbx-selected .sb-spacer');
    await expect(gap).toHaveCount(1);
    await expect.poll(() => gap.evaluate((el) => getComputedStyle(el).height)).toBe('32px'); // md = 2rem

    await page.getByTestId('inspector-panel').getByLabel('Height', { exact: true }).selectOption('xl');
    await settled(page);
    await expect.poll(() => gap.evaluate((el) => getComputedStyle(el).height), { timeout: 15_000 }).toBe('88px'); // xl = 5.5rem
  } finally {
    await restore(page, before);
  }
});
