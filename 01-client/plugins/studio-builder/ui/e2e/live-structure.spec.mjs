// Insert, duplicate and delete show on the canvas at once: the save is held back for the whole test, so whatever the
// canvas shows did not come from the server's render after the save. When the save is let through, the page settles
// to the same thing.
import { test, expect } from '@playwright/test';
import { layerCount, openBuilder, restoreLayers, settled } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked Inspector and canvas toolbar (desktop)');
});

const OPERATIONS = '**/admin/api.php?action=operations*';
const canvas = (page) => page.frameLocator('iframe.sbx-canvas__frame');

async function addQuote(page) {
  await page.getByRole('tab', { name: /^(Add|Ajouter)$/ }).click();
  const panel = page.locator('#sbx-leftpanel-blocks');
  await panel.getByRole('tab', { name: 'Elements' }).click();
  await panel.locator('[data-chip="content"]').click();
  await panel.locator('[data-block-type="core.quote"]').click();
}

test('insert, duplicate and delete show on the canvas before the save returns', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  let release;
  const gate = new Promise((resolve) => { release = resolve; });
  try {
    await addQuote(page); // the sandbox page starts empty: this creates the section too
    await settled(page);
    const quotes = canvas(page).locator('[data-sb-type="core.quote"]');
    await expect(quotes).toHaveCount(1);

    await page.route(OPERATIONS, async (route) => { await gate; await route.continue(); });

    // A new block: a placeholder at once, then its real markup, with the save still held.
    await addQuote(page);
    await expect(quotes).toHaveCount(2, { timeout: 10_000 });
    await expect(canvas(page).locator('.sbx-pending')).toHaveCount(0, { timeout: 10_000 });
    await expect(canvas(page).locator('[data-sb-type="core.quote"] blockquote, [data-sb-type="core.quote"] figure')).not.toHaveCount(0);

    // A duplicate: a copy of what is on the canvas.
    await page.getByRole('button', { name: 'Duplicate', exact: true }).first().click();
    await expect(quotes).toHaveCount(3, { timeout: 10_000 });

    // A delete.
    await page.getByRole('button', { name: 'Remove item', exact: true }).first().click();
    await expect(quotes).toHaveCount(2, { timeout: 10_000 });
  } finally {
    release();
    await page.unrouteAll({ behavior: 'ignoreErrors' });
    await settled(page);
    // The server's render replaces the live structure and agrees with it.
    await expect(canvas(page).locator('[data-sb-type="core.quote"]')).toHaveCount(2, { timeout: 20_000 });
    await expect(canvas(page).locator('.sbx-pending')).toHaveCount(0, { timeout: 20_000 });
    await restoreLayers(page, before);
  }
});

test('a section preset shows its real content before the save returns', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  let release;
  const gate = new Promise((resolve) => { release = resolve; });
  try {
    await page.route(OPERATIONS, async (route) => { await gate; await route.continue(); });
    const rendered = page.waitForResponse((res) => res.url().includes('action=render_section') && res.ok());

    await page.getByRole('tab', { name: /^(Add|Ajouter)$/ }).click();
    await page.getByRole('tab', { name: /^(Sections)$/ }).click();
    await page.locator('#sbx-leftpanel-blocks [data-preset="system-section-pricing"] .sbx-preset-card__insert').click();

    // The save is held, so this came from render_section, not from the page's own render after the save.
    await rendered;
    await expect(canvas(page).getByText('Simple pricing')).toBeVisible({ timeout: 10_000 });
    await expect(canvas(page).locator('.sbx-pending')).toHaveCount(0, { timeout: 10_000 });
    expect(await canvas(page).getByText(/^(Starter|Pro|Business)$/).count()).toBe(3);
  } finally {
    release();
    await page.unrouteAll({ behavior: 'ignoreErrors' });
    await settled(page);
    await expect(canvas(page).getByText('Simple pricing')).toBeVisible({ timeout: 20_000 });
    await expect(canvas(page).locator('.sbx-pending')).toHaveCount(0, { timeout: 20_000 });
    await restoreLayers(page, before);
  }
});
