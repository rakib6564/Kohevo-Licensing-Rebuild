// The contextual Inspector (B2-P3c): header, tabs, collapsible sections with summaries, search, and the ⋯ menu.
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
    await expect(undo).toBeEnabled({ timeout: 5_000 });
    await undo.click();
    await page.waitForTimeout(500);
  }
  throw new Error('could not restore the shared sandbox page');
}

/** Insert a quote (a text block) and leave it selected in the Inspector. */
async function insertQuote(page) {
  await page.getByRole('tab', { name: /^(Add|Ajouter)$/ }).click();
  const panel = page.locator('#sbx-leftpanel-blocks');
  await panel.getByRole('tab', { name: 'Elements' }).click();
  await panel.locator('[data-chip="content"]').click();
  await panel.locator('[data-block-type="core.quote"]').click();
  await settled(page);
}

const toggleOf = (page, id) => page.locator(`[data-section="${id}"] .sbx-isec__toggle`);

test('a block shows Content, Style and Advanced, with only the relevant sections open', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await insertQuote(page);
    const tabs = page.locator('[id^="sbx-blk-"][role="tab"]');
    await expect(tabs).toHaveText(['Content', 'Style', 'Advanced']);

    await expect(toggleOf(page, 'content')).toHaveAttribute('aria-expanded', 'true');
    await page.locator('[id^="sbx-blk-"][id$="-tab-style"]').click();
    await expect(toggleOf(page, 'typography')).toHaveAttribute('aria-expanded', 'true'); // a quote is text
    for (const closed of ['background', 'border', 'shadow', 'dimensions', 'opacity']) {
      await expect(toggleOf(page, closed)).toHaveAttribute('aria-expanded', 'false');
      await expect(page.locator(`[data-section="${closed}"] .sbx-isec__body`)).toBeHidden();
    }
    await page.locator('[id^="sbx-blk-"][id$="-tab-advanced"]').click();
    await expect(toggleOf(page, 'classes')).toHaveAttribute('aria-expanded', 'true');
    await expect(toggleOf(page, 'stacking')).toHaveAttribute('aria-expanded', 'false');
  } finally {
    await restore(page, before);
  }
});

test('a section remembers being opened while the author moves between tabs, and shows what it holds', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await insertQuote(page);
    await page.locator('[id^="sbx-blk-"][id$="-tab-style"]').click();
    await openSection(page, 'opacity');
    await page.locator('[id^="sbx-blk-"][id$="-tab-advanced"]').click();
    await page.locator('[id^="sbx-blk-"][id$="-tab-style"]').click();
    await expect(toggleOf(page, 'opacity')).toHaveAttribute('aria-expanded', 'true');

    await page.locator('[data-section="opacity"] input[type="range"]').fill('0.5');
    await expect(toggleOf(page, 'opacity').locator('.sbx-isec__summary')).toHaveText('50%'); // the collapsed header says what is set
    await toggleOf(page, 'opacity').click();
    await expect(toggleOf(page, 'opacity')).toHaveAttribute('aria-expanded', 'false');
    await expect(toggleOf(page, 'opacity').locator('.sbx-isec__summary')).toHaveText('50%');
  } finally {
    await restore(page, before);
  }
});

test('search lists the matching sections from every tab, opened, and Escape clears it', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await insertQuote(page);
    const search = page.getByRole('searchbox', { name: 'Search settings' });
    await search.fill('border');
    await expect(page.locator('[data-searching="true"] [data-section]')).toHaveCount(1);
    await expect(toggleOf(page, 'border')).toHaveAttribute('aria-expanded', 'true');
    await expect(page.getByRole('status').filter({ hasText: '1 matching sections' })).toHaveCount(1);

    await search.fill('z-index');
    await expect(page.locator('[data-searching="true"] [data-section="stacking"]')).toHaveCount(1); // found on the Advanced tab

    await search.fill('zzzz');
    await expect(page.getByText('No setting matches that search.')).toBeVisible();

    await search.press('Escape');
    await expect(search).toHaveValue('');
    await expect(page.locator('[data-searching="true"]')).toHaveCount(0);
  } finally {
    await restore(page, before);
  }
});

test('the header ⋯ menu renames, duplicates and locks the selected block', async ({ page }) => {
  test.setTimeout(90_000); // several edits to undo afterwards, one step at a time
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await insertQuote(page);
    const title = page.locator('.sbx-ihead .sbx-inspector__title');
    await expect(title).toHaveText('Quote');

    const item = (action) => page.getByRole('menuitem').and(page.locator(`[data-action="${action}"]`));
    await page.getByTestId('inspector-menu').click();
    await item('rename').click();
    const rename = page.getByRole('textbox', { name: 'Rename' });
    await rename.fill('Pull quote');
    await rename.press('Enter');
    await expect(title).toHaveText('Pull quote');
    await expect(page.locator('.sbx-ihead__type')).toHaveText('Quote'); // the type stays visible once renamed

    await page.getByTestId('inspector-menu').click();
    await item('lock').click();
    await settled(page);
    // A locked layer freezes the whole Inspector (the menu too); the banner is the way back.
    await expect(page.getByTestId('lock-banner')).toBeVisible();
    await expect(page.getByTestId('inspector-menu')).toBeDisabled();
    await page.getByTestId('lock-banner').getByRole('button', { name: /Unlock/ }).click();
    await expect(page.getByTestId('lock-banner')).toHaveCount(0);
    await settled(page);

    const layers = await layerCount(page);
    await page.getByTestId('inspector-menu').click();
    await item('duplicate').click();
    await settled(page);
    expect(await layerCount(page)).toBeGreaterThan(layers);
  } finally {
    await restore(page, before);
  }
});
