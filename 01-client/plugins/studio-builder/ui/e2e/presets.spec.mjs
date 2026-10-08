// Add panel → Sections: composed, thumbnailed section presets (B2-P2a).
import { test, expect } from '@playwright/test';
import { openBuilder, frameDocument, settled, sandboxPageId } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked Add panel (desktop)');
});

// `?lang=fr` is stored in the shared admin session: always put English back.
test.afterEach(async ({ page }) => {
  await page.goto(`/plugins/studio-builder/admin/builder.php?page=${sandboxPageId()}&lang=en`);
});

/** The docked Add panel (the Layers tab mounts a second, compact palette). */
const panel = (page) => page.locator('#sbx-leftpanel-blocks');

async function openSections(page) {
  await page.getByRole('tab', { name: /^(Add|Ajouter)$/ }).click();
  await page.getByRole('tab', { name: /^(Sections)$/ }).click();
  await expect(panel(page).getByTestId('section-presets')).toBeVisible();
}

async function undoTimes(page, n) {
  for (let i = 0; i < n; i++) {
    await page.getByTestId('undo').click();
    await page.waitForTimeout(400);
  }
}

test('the Sections tab lists the composed presets by category, each with a wireframe', async ({ page }) => {
  await openBuilder(page);
  await openSections(page);
  const cards = panel(page).locator('.sbx-preset-card');
  expect(await cards.count()).toBeGreaterThanOrEqual(16);
  expect(await panel(page).locator('.sbx-preset-card svg[data-testid="wireframe"]').count()).toBe(await cards.count());
  const headings = await panel(page).locator('.sbx-presets__group h3').allTextContents();
  expect(headings.slice(0, 3)).toEqual(['Hero', 'Features', 'Content']);
  await expect(panel(page).locator('[data-preset="system-section-hero-classic"] .sbx-preset-card__name')).toHaveText('Hero — Classic');
});

test('one click inserts a real, editable section — not a bare primitive', async ({ page }) => {
  await openBuilder(page);
  await openSections(page);
  const before = await page.locator('[role="treeitem"][aria-level="1"]').count().catch(() => 0);
  await panel(page).locator('[data-preset="system-section-pricing"] .sbx-preset-card__insert').click();
  await settled(page);
  const frame = await frameDocument(page);
  await expect(frame.getByText('Simple pricing')).toBeVisible();
  // three plans, each a card with its own heading and a button
  expect(await frame.getByText(/^(Starter|Pro|Business)$/).count()).toBe(3);
  expect(await frame.locator('a, button').filter({ hasText: /Choose Starter|Choose Pro|Contact us/ }).count()).toBeGreaterThanOrEqual(3);
  await page.getByRole('tab', { name: 'Layers' }).click();
  expect(await page.locator('[role="treeitem"][aria-level="1"]').count()).toBeGreaterThan(before);
  await undoTimes(page, 1);
});

test('search filters by name and description; an empty result says so', async ({ page }) => {
  await openBuilder(page);
  await openSections(page);
  const search = panel(page).getByRole('searchbox');
  await search.fill('questions');
  await expect(panel(page).locator('.sbx-preset-card')).toHaveCount(1);
  await expect(panel(page).locator('[data-preset="system-section-faq"]')).toBeVisible();
  await search.fill('zzzz-nothing');
  await expect(panel(page).locator('.sbx-preset-card')).toHaveCount(0);
  await expect(panel(page).getByTestId('search-empty')).toHaveText('Nothing matches your search.');
});

test('favourites are a real toggle button and do not trigger insertion', async ({ page }) => {
  await openBuilder(page);
  await openSections(page);
  const star = panel(page).locator('[data-preset="system-section-faq"] .sbx-preset-card__star');
  await expect(star).toHaveAttribute('aria-pressed', 'false');
  await star.click();
  await expect(star).toHaveAttribute('aria-pressed', 'true');
  await expect(panel(page).getByTestId('section-presets')).toBeVisible();
  // the card itself is a button; the star is a sibling, never nested inside it
  expect(await panel(page).locator('.sbx-preset-card__insert button').count()).toBe(0);
});

test('presets are French in French: names, descriptions and category headings', async ({ page }) => {
  await page.goto(`/plugins/studio-builder/admin/builder.php?page=${sandboxPageId()}&lang=fr`);
  await page.locator('header.sbx-topbar').waitFor({ state: 'visible' });
  await openSections(page);
  await expect(panel(page).locator('[data-preset="system-section-hero-classic"] .sbx-preset-card__name')).toHaveText('Héros — Classique');
  await expect(panel(page).locator('[data-preset="system-section-faq"] .sbx-preset-card__desc')).toHaveText('Questions et réponses qui se déplient.');
  const headings = (await panel(page).locator('.sbx-presets__group h3').allTextContents()).join('|');
  expect(headings).toContain('Héros');
  expect(headings).toContain('Tarifs');
  expect(headings).not.toMatch(/\b(Pricing|Features)\b/);
});
