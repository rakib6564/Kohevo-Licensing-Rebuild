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

/** Undo until the canvas holds as many nodes as before (an insert may also have created a section). */
async function restore(page, nodeCount) {
  for (let i = 0; i < 16; i++) {
    await settled(page);
    const frame = await frameDocument(page);
    if ((await frame.locator('[data-sb-node]').count()) <= nodeCount) return;
    await page.getByTestId('undo').click();
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
  const frame0 = await frameDocument(page);
  const before = await frame0.locator('[data-sb-node]').count();
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
  const frame0 = await frameDocument(page);
  const before = await frame0.locator('[data-sb-node]').count();
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
