// Theme colours for the border, the custom shadow and the text decoration: picked from the palette, written out as the theme's own
// custom property, and visible on the canvas at once.
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

async function insertQuote(page) {
  await page.getByRole('tab', { name: /^(Add|Ajouter)$/ }).click();
  const panel = page.locator('#sbx-leftpanel-blocks');
  await panel.getByRole('tab', { name: 'Elements' }).click();
  await panel.locator('[data-chip="content"]').click();
  await panel.locator('[data-block-type="core.quote"]').click();
  await settled(page);
  await page.locator('[id^="sbx-blk-"][id$="-tab-style"]').click();
}

/** Every rule the canvas document has, as text (the live paint and the server's render both end up here). */
const canvasCss = (page) => page.frameLocator('iframe.sbx-canvas__frame').locator('html').evaluate(
  (el) => Array.from(el.ownerDocument.styleSheets).flatMap((s) => { try { return Array.from(s.cssRules).map((r) => r.cssText); } catch { return []; } }).join('\n'),
);

async function pickTheme(page, scope, option = 1) {
  await scope.locator('.sbx-tp__btn').click();
  await page.getByRole('listbox').getByRole('option').nth(option).click();
}

test('the border has a theme colour: it reaches the canvas as a class, and Custom takes it back', async ({ page }) => {
  test.setTimeout(90_000);
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await insertQuote(page);
    const border = await openSection(page, 'border');
    const quote = page.frameLocator('iframe.sbx-canvas__frame').locator('[data-sb-type="core.quote"]').last();
    const theme = border.locator('.sbx-color__theme').first();
    await pickTheme(page, theme);
    await expect(quote).toHaveClass(/sb-bd--/);
    await settled(page);
    await expect(quote).toHaveClass(/sb-bd--/);
    await theme.locator('.sbx-tp__btn').click();
    await page.getByRole('listbox').getByRole('option').first().click();
    await expect(quote).not.toHaveClass(/sb-bd--/);
  } finally {
    await restore(page, before);
  }
});

test('a side border, the custom shadow and the text decoration take theme colours, written out as the theme custom property', async ({ page }) => {
  test.setTimeout(120_000);
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await insertQuote(page);

    // a side of the border, in the card
    const border = await openSection(page, 'border');
    await border.getByRole('button', { name: 'Edit border' }).click();
    const card = border.getByRole('dialog', { name: 'Edit border' });
    await card.locator('details.sbx-more summary').click();
    const top = card.locator('fieldset', { has: page.getByText('Top', { exact: true }) }).first().locator('.sbx-color__theme');
    await pickTheme(page, top);
    await settled(page);
    await expect.poll(() => canvasCss(page), { timeout: 15_000 }).toMatch(/border-top-color:\s*var\(--sb-/);
    await card.getByRole('button', { name: 'Done' }).click();

    // the custom shadow's colour
    const shadow = await openSection(page, 'shadow');
    await shadow.getByRole('button', { name: 'Edit shadow' }).click();
    const shadowCard = shadow.getByRole('dialog', { name: 'Edit shadow' });
    await shadowCard.getByRole('button', { name: 'Custom shadow' }).click();
    await pickTheme(page, shadowCard.locator('.sbx-color__theme'));
    await settled(page);
    await expect.poll(() => canvasCss(page), { timeout: 15_000 }).toMatch(/box-shadow:[^;]*var\(--sb-/);
    await shadowCard.getByRole('button', { name: 'Done' }).click();

    // the text decoration's colour
    const typo = await openSection(page, 'typography');
    await typo.getByRole('button', { name: 'Edit typography' }).click();
    const typoCard = typo.getByRole('dialog', { name: 'Edit typography' });
    await typoCard.getByLabel('Text decoration', { exact: true }).selectOption('underline');
    await pickTheme(page, typoCard.locator('.sbx-color__theme'));
    await settled(page);
    await expect.poll(() => canvasCss(page), { timeout: 15_000 }).toMatch(/text-decoration-color:\s*var\(--sb-/);
  } finally {
    await restore(page, before);
  }
});
