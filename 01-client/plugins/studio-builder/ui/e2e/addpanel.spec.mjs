// Add panel UX (B2-P2a): Elements by category with a rail and "View all", search across tabs,
// a Components tab with nothing fake in it, and cards that are disabled with a visible reason.
import { test, expect } from '@playwright/test';
import { openBuilder, settled, sandboxPageId } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked Add panel (desktop)');
});

test.afterEach(async ({ page }) => {
  await page.goto(`/plugins/studio-builder/admin/builder.php?page=${sandboxPageId()}&lang=en`);
});

/** The docked Add panel (the Layers tab mounts a second, compact palette). */
const panel = (page) => page.locator('#sbx-leftpanel-blocks');

async function openTab(page, name) {
  await page.getByRole('tab', { name: /^(Add|Ajouter)$/ }).click();
  await panel(page).getByRole('tab', { name }).click();
}

test('Elements are grouped by category with a rail; "View all" drills into one category', async ({ page }) => {
  await openBuilder(page);
  await openTab(page, 'Elements');
  const rail = panel(page).getByRole('group', { name: 'Categories' });
  await expect(rail.getByRole('button', { name: /^All/ })).toHaveAttribute('aria-pressed', 'true');
  const chips = await rail.locator('.sbx-chip').allTextContents();
  expect(chips.length).toBeGreaterThanOrEqual(4);
  const headings = await panel(page).locator('.sbx-elements__group h3').allTextContents();
  expect(headings[0]).toBe('Layout');

  const before = await panel(page).locator('[data-block-type]').count();
  const viewAll = panel(page).locator('[data-view-all="layout"]');
  await expect(viewAll).toBeVisible();
  await viewAll.click();
  await expect(rail.locator('[data-chip="layout"]')).toHaveAttribute('aria-pressed', 'true');
  await expect(panel(page).locator('.sbx-elements__group')).toHaveCount(1);
  expect(await panel(page).locator('[data-block-type]').count()).toBeGreaterThan(4);
  expect(before).toBeGreaterThan(0);
  await rail.getByRole('button', { name: /^All/ }).click();
  expect(await panel(page).locator('.sbx-elements__group').count()).toBeGreaterThan(1);
});

test('one search covers sections, elements and components; clearing it brings the tabs back', async ({ page }) => {
  await openBuilder(page);
  await openTab(page, 'Sections');
  const search = panel(page).getByRole('searchbox');
  await search.fill('hero');
  const results = panel(page).getByTestId('add-search-results');
  await expect(results).toBeVisible();
  const headings = await results.locator('h3').allTextContents();
  expect(headings.join('|')).toMatch(/Sections.*\|?.*Elements|Sections/s);
  await expect(results.locator('[data-preset="system-section-hero-classic"]')).toBeVisible();
  await expect(results.locator('[data-block-type="core.hero"]')).toBeVisible();
  await search.fill('zzzz-nothing');
  await expect(panel(page).getByTestId('search-empty')).toBeVisible();
  await search.fill('');
  await expect(panel(page).getByTestId('section-presets')).toBeVisible();
});

test('Components lists only real things — no placeholder header/footer cards', async ({ page }) => {
  await openBuilder(page);
  await openTab(page, 'Components');
  const comps = panel(page).getByTestId('palette-components');
  await expect(comps).toBeVisible();
  await expect(comps.getByRole('heading', { name: 'Kohevo components' })).toBeVisible();
  await expect(comps.getByRole('heading', { name: 'Global components' })).toBeVisible();
  await expect(comps).not.toContainText('Global header');
  await expect(comps).not.toContainText('Global footer');
  // the sandbox is licensed for studio-builder only, so no module component is offered
  await expect(comps.locator('[data-block-type^="booking."], [data-block-type^="membership."], [data-block-type^="forms."]')).toHaveCount(0);
});

test('at the block limit, cards are disabled and say why (and insert nothing)', async ({ page }) => {
  const pattern = '**/api.php?action=bootstrap*';
  await page.route(pattern, async (route) => {
    const res = await route.fetch();
    const json = await res.json();
    const manifest = (json.data || json).manifest || json.data || json;
    manifest.limits = { ...manifest.limits, max_blocks: 1 };
    await route.fulfill({ response: res, json });
  });
  await openBuilder(page);
  await page.unroute(pattern); // the manifest is already loaded; later navigations must not be intercepted
  await openTab(page, 'Elements');
  let inserted = 0;
  try {
    const heading = panel(page).locator('[data-block-type="core.heading"]').first();
    await expect(heading).not.toHaveAttribute('aria-disabled', 'true');
    const rowsBefore = await page.locator('[role="treeitem"]').count();
    await heading.click();
    inserted = 1 + (rowsBefore === 0 ? 1 : 0); // the block, plus the section created for it on an empty page
    await settled(page);
    const another = panel(page).locator('[data-block-type]').nth(1);
    await expect(another).toHaveAttribute('aria-disabled', 'true');
    await expect(another.getByTestId('insert-reason')).toHaveText('This page has reached its block limit.');
    await page.getByRole('tab', { name: 'Layers' }).click();
    const rows = await page.locator('[role="treeitem"]').count();
    await page.getByRole('tab', { name: /^Add$/ }).click();
    await another.dispatchEvent('click'); // a disabled card must ignore it
    await page.waitForTimeout(800);
    await page.getByRole('tab', { name: 'Layers' }).click();
    expect(await page.locator('[role="treeitem"]').count()).toBe(rows);
  } finally {
    for (let i = 0; i < inserted; i++) { // put the shared sandbox page back how we found it
      await page.getByTestId('undo').click();
      await page.waitForTimeout(500);
    }
  }
});

test('Elements and the rail are French in French', async ({ page }) => {
  await page.goto(`/plugins/studio-builder/admin/builder.php?page=${sandboxPageId()}&lang=fr`);
  await page.locator('header.sbx-topbar').waitFor({ state: 'visible' });
  await openTab(page, /Éléments/);
  const rail = panel(page).getByRole('group', { name: 'Catégories' });
  const chips = (await rail.locator('.sbx-chip').allTextContents()).join('|');
  expect(chips).toContain('Tout');
  expect(chips).toContain('Mise en page');
  await expect(panel(page).locator('[data-view-all="layout"]')).toContainText('Tout voir');
  await panel(page).getByRole('tab', { name: 'Composants' }).click();
  await expect(panel(page).getByRole('heading', { name: 'Composants Kohevo' })).toBeVisible();
});
