// New elements (B2-P2b): Icon, List, Quote and Link insert from the Add panel, render in the canvas,
// and repaint from the server when their properties change.
import { test, expect } from '@playwright/test';
import { openBuilder, frameDocument, settled, sandboxPageId } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked Add panel (desktop)');
});

test.afterEach(async ({ page }) => {
  await page.goto(`/plugins/studio-builder/admin/builder.php?page=${sandboxPageId()}&lang=en`);
});

const panel = (page) => page.locator('#sbx-leftpanel-blocks');

const CATEGORY = { 'core.icon': 'media', 'core.list': 'content', 'core.quote': 'content', 'core.link': 'content', 'core.card': 'layout', 'core.table': 'content', 'core.countdown': 'content' };

/** Open the Elements tab on the category that holds `type` (each group only previews four cards). */
async function openElements(page, type = 'core.quote') {
  await page.getByRole('tab', { name: /^(Add|Ajouter)$/ }).click();
  await panel(page).getByRole('tab', { name: 'Elements' }).click();
  await panel(page).locator(`[data-chip="${CATEGORY[type]}"]`).click();
}

/**
 * How many layers the page has, read from the Layers tree (the editor's own model). The canvas frame is not a safe
 * source: right after load it can still be an empty document, which would make "restore" chase a count it can never reach.
 */
async function layerCount(page) {
  await page.getByRole('tab', { name: /^Layers$/ }).click();
  return page.locator('[role="treeitem"]').count();
}

/** Undo until the page has no more layers than before (an insert may also have created a section). */
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

test('the four new elements are in the Elements tab with titles and icons', async ({ page }) => {
  await openBuilder(page);
  for (const [type, title] of [['core.icon', 'Icon'], ['core.list', 'List'], ['core.quote', 'Quote'], ['core.link', 'Link'], ['core.card', 'Card'], ['core.table', 'Table'], ['core.countdown', 'Countdown']]) {
    await openElements(page, type);
    const card = panel(page).locator(`[data-block-type="${type}"]`);
    await expect(card).toBeVisible();
    await expect(card).toContainText(title);
    expect(await card.locator('svg').count()).toBeGreaterThan(0);
  }
});

test('each element inserts and renders its own markup in the canvas', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    for (const [type, selector] of [['core.icon', '.sb-icon svg'], ['core.list', 'ul.sb-list li'], ['core.quote', 'figure.sb-quote blockquote'], ['core.card', 'div.sb-card'], ['core.table', 'table.sb-table tbody tr'], ['core.countdown', '.sb-countdown time']]) {
      await openElements(page, type);
      await panel(page).locator(`[data-block-type="${type}"]`).click();
      await settled(page);
      const frame = await frameDocument(page);
      await expect.poll(() => frame.locator(selector).count(), { timeout: 20_000 }).toBeGreaterThan(0);
    }
  } finally {
    await restore(page, before);
  }
});

test('editing a quote repaints the canvas from the server', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await openElements(page);
    await panel(page).locator('[data-block-type="core.quote"]').click();
    await settled(page);
    const field = page.getByRole('textbox', { name: 'Quote', exact: true });
    await expect(field).toBeVisible({ timeout: 15_000 });
    await field.fill('A repainted line');
    await field.blur();
    await expect.poll(async () => (await frameDocument(page)).locator('figure.sb-quote').first().textContent(), { timeout: 20_000 }).toContain('A repainted line');
  } finally {
    await restore(page, before);
  }
});

test('Columns and form-field variants insert ready-set blocks; content added next lands inside the columns', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await page.getByRole('tab', { name: /^(Add|Ajouter)$/ }).click();
    await panel(page).getByRole('tab', { name: 'Elements' }).click();
    await panel(page).locator('[data-chip="layout"]').click();
    const card = panel(page).locator('[data-variant="columns-3"]');
    await expect(card).toBeVisible();
    await expect(card).toContainText('Three columns');
    expect(await card.getAttribute('draggable')).toBe('false'); // a drag would lose the ready-set props
    await card.click();
    await settled(page);
    let frame = await frameDocument(page);
    await expect.poll(() => frame.locator('.sb-grid.sb-cols-3').count(), { timeout: 20_000 }).toBe(1);
    expect(await frame.locator('.sb-grid.sb-cols-3').evaluate((n) => n.offsetHeight)).toBeGreaterThanOrEqual(56); // an empty layout block stays visible and droppable

    // the new grid is selected (and the inspector took the panel), so the next element goes into it
    await page.getByRole('tab', { name: /^(Add|Ajouter)$/ }).click();
    await panel(page).getByRole('tab', { name: 'Elements' }).click();
    await panel(page).locator('[data-chip="content"]').click();
    await panel(page).locator('[data-block-type="core.quote"]').click();
    await settled(page);
    frame = await frameDocument(page);
    await expect.poll(() => frame.locator('.sb-grid.sb-cols-3 figure.sb-quote').count(), { timeout: 20_000 }).toBe(1);

    // a form-field variant renders its own control
    await page.getByRole('tab', { name: /^(Add|Ajouter)$/ }).click();
    await panel(page).getByRole('tab', { name: 'Elements' }).click();
    await panel(page).locator('[data-chip="forms"]').click();
    await panel(page).locator('[data-variant="textarea"]').click();
    await settled(page);
    frame = await frameDocument(page);
    await expect.poll(() => frame.locator('textarea[name="message"]').count(), { timeout: 20_000 }).toBeGreaterThan(0);
  } finally {
    await restore(page, before);
  }
});

test('search finds variants and the Elements tab is French in French', async ({ page }) => {
  await openBuilder(page);
  await page.getByRole('tab', { name: /^(Add|Ajouter)$/ }).click();
  await panel(page).getByRole('searchbox').fill('columns');
  await expect(panel(page).getByTestId('add-search-results').locator('[data-variant="columns-2"]')).toBeVisible();
  await page.goto(`/plugins/studio-builder/admin/builder.php?page=${sandboxPageId()}&lang=fr`);
  await page.locator('header.sbx-topbar').waitFor({ state: 'visible' });
  await page.getByRole('tab', { name: /^(Add|Ajouter)$/ }).click();
  await panel(page).getByRole('tab', { name: /Éléments/ }).click();
  await panel(page).locator('[data-chip="layout"]').click();
  await expect(panel(page).locator('[data-variant="columns-3"]')).toContainText('Trois colonnes');
  await expect(panel(page).locator('[data-variant="stack"]')).toContainText('Pile');
});
