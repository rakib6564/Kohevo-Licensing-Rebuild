// A group control is one row with a pencil: the pencil opens the rest of the group in a card; Escape or a click outside closes it.
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

test('the shadow group is a row with a pencil: it opens a card, Escape and a click outside close it, and focus returns', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await page.getByRole('tab', { name: /^(Add|Ajouter)$/ }).click();
    const panel = page.locator('#sbx-leftpanel-blocks');
    await panel.getByRole('tab', { name: 'Elements' }).click();
    await panel.locator('[data-chip="content"]').click();
    await panel.locator('[data-block-type="core.quote"]').click();
    await settled(page);
    await page.locator('[id^="sbx-blk-"][id$="-tab-style"]').click();
    await openSection(page, 'shadow');
    const section = page.locator('[data-section="shadow"]');
    const pencil = section.getByRole('button', { name: 'Edit shadow' });

    await expect(section.getByRole('dialog')).toHaveCount(0);
    await expect(section.getByRole('combobox').or(section.locator('select')).first()).toBeVisible(); // the preset stays inline
    await pencil.click();
    const card = section.getByRole('dialog', { name: 'Edit shadow' });
    await expect(card).toBeVisible();
    await expect(pencil).toHaveAttribute('aria-expanded', 'true');
    await expect(card.getByRole('button', { name: 'Custom shadow' })).toBeVisible();

    await page.keyboard.press('Escape');
    await expect(card).toHaveCount(0);
    await expect(pencil).toBeFocused();

    await pencil.click();
    await expect(card).toBeVisible();
    await page.locator('.sbx-isearch input').click();
    await expect(card).toHaveCount(0);

    await pencil.click();
    await card.getByRole('button', { name: 'Custom shadow' }).click();
    await expect(card.getByLabel('Blur', { exact: true })).toBeVisible();
    await card.getByRole('button', { name: 'Done' }).click();
    await expect(card).toHaveCount(0);
    await expect(section.getByText('Custom shadow')).toBeVisible(); // the row now says the shadow is custom
  } finally {
    await restore(page, before);
  }
});

test('typography and border keep the common controls in the row and move the rest into the pencil card', async ({ page }) => {
  test.setTimeout(90_000);
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await page.getByRole('tab', { name: /^(Add|Ajouter)$/ }).click();
    const panel = page.locator('#sbx-leftpanel-blocks');
    await panel.getByRole('tab', { name: 'Elements' }).click();
    await panel.locator('[data-chip="content"]').click();
    await panel.locator('[data-block-type="core.quote"]').click();
    await settled(page);
    await page.locator('[id^="sbx-blk-"][id$="-tab-style"]').click();

    const typo = await openSection(page, 'typography');
    for (const inline of ['Font Weight', 'Font Size']) await expect(typo.getByLabel(inline, { exact: true })).toBeVisible();
    await expect(typo.locator('input[id$="-tcolor-text"]')).toBeVisible();
    for (const inCard of ['Line Height', 'Font Family', 'Text Transform']) await expect(typo.getByLabel(inCard, { exact: true })).toHaveCount(0);
    await typo.getByRole('button', { name: 'Edit typography' }).click();
    const card = typo.getByRole('dialog', { name: 'Edit typography' });
    for (const inCard of ['Line Height', 'Font Family', 'Text Transform']) await expect(card.getByLabel(inCard, { exact: true })).toBeVisible();
    // an edit inside the card reaches the document: the section summary is unchanged but the value sticks after closing
    const lh = card.getByLabel('Line Height', { exact: true });
    await lh.fill('1.8');
    await lh.blur();
    await settled(page);
    await card.getByRole('button', { name: 'Done' }).click();
    await typo.getByRole('button', { name: 'Edit typography' }).click();
    await expect(typo.getByRole('dialog').getByLabel('Line Height', { exact: true })).toHaveValue('1.8');
    await typo.getByRole('dialog').getByRole('button', { name: 'Done' }).click();

    const border = await openSection(page, 'border');
    await expect(border.getByLabel('Border Style', { exact: true })).toBeVisible();
    await expect(border.getByText('Corners', { exact: false })).toHaveCount(0);
    await border.getByRole('button', { name: 'Edit border' }).click();
    await expect(border.getByRole('dialog', { name: 'Edit border' }).getByText(/Corners/)).toBeVisible();
  } finally {
    await restore(page, before);
  }
});
