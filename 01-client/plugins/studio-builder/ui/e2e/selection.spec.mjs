// Selection model wired into the shell: Layers, canvas and Inspector stay in sync (B2-P1).
import { test, expect } from '@playwright/test';
import { openBuilder, frameDocument, settled } from './helpers.mjs';

const ROWS = '[role="treeitem"]';

/** Make sure the page has at least `n` sections, adding through the Layers panel like an author. */
async function ensureSections(page, n) {
  await page.getByRole('tab', { name: /Layers/ }).click();
  for (let guard = 0; guard < n + 2; guard++) {
    const count = await page.locator(ROWS).count();
    if (count >= n) break;
    await page.getByRole('button', { name: /Add section/ }).first().click();
    await expect.poll(() => page.locator(ROWS).count(), { timeout: 15_000 }).toBeGreaterThan(count);
  }
  await settled(page);
}

async function topLevelRowIds(page) {
  await page.getByRole('tab', { name: /Layers/ }).click();
  return page.locator(`${ROWS}[aria-level="1"]`).evaluateAll((els) => els.map((e) => e.getAttribute('data-row')));
}

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked Layers panel (desktop); mobile sheets are covered by the mobile-shell suite');
});

test('Cmd/Ctrl-click in Layers multi-selects: the Inspector shows the count and the canvas outlines both nodes', async ({ page }) => {
  await openBuilder(page);
  await ensureSections(page, 2);
  const [a, b] = await topLevelRowIds(page);

  await page.locator(`${ROWS}[data-row="${a}"]`).click();
  await page.getByRole('tab', { name: /Layers/ }).click();
  await page.locator(`${ROWS}[data-row="${b}"]`).click({ modifiers: ['ControlOrMeta'] });

  await expect(page.locator(`${ROWS}[aria-selected="true"]`)).toHaveCount(2);
  await expect(page.getByTestId('inspector-multi')).toContainText('2 selected');
  const frame = await frameDocument(page);
  await expect(frame.locator('.sbx-selected')).toHaveCount(2);

  // A plain click collapses back to one node and restores the normal Inspector.
  await page.getByRole('tab', { name: /Layers/ }).click();
  await page.locator(`${ROWS}[data-row="${a}"]`).click();
  await expect(page.locator(`${ROWS}[aria-selected="true"]`)).toHaveCount(1);
  await expect(page.getByTestId('inspector-multi')).toHaveCount(0);
});

test('Cmd/Ctrl-click on the canvas adds to the selection and Layers reflects it', async ({ page }) => {
  await openBuilder(page);
  await ensureSections(page, 2);
  const [a, b] = await topLevelRowIds(page);

  // The canvas repaints after a save, and a click that lands while it does is lost: tap the first node until the
  // selection shows it, only then add the second with the modifier.
  await expect(async () => {
    const frame = await frameDocument(page);
    await frame.locator(`[data-sb-node="${a}"]`).click({ position: { x: 4, y: 4 }, timeout: 5_000 });
    await expect(page.locator(`${ROWS}[aria-selected="true"]`)).toHaveCount(1, { timeout: 2_000 });
  }).toPass({ timeout: 20_000 });
  await expect(async () => {
    const frame = await frameDocument(page);
    await frame.locator(`[data-sb-node="${b}"]`).click({ position: { x: 4, y: 4 }, modifiers: ['ControlOrMeta'], timeout: 5_000 });
    await expect(page.locator(`${ROWS}[aria-selected="true"]`)).toHaveCount(2, { timeout: 2_000 });
  }).toPass({ timeout: 20_000 });

  const frame = await frameDocument(page);
  await expect(frame.locator('.sbx-selected')).toHaveCount(2);
  await expect(page.getByTestId('inspector-multi')).toBeVisible();
});

test('Escape clears the whole selection', async ({ page }) => {
  await openBuilder(page);
  await ensureSections(page, 2);
  const [a, b] = await topLevelRowIds(page);
  await page.locator(`${ROWS}[data-row="${a}"]`).click();
  await page.getByRole('tab', { name: /Layers/ }).click();
  await page.locator(`${ROWS}[data-row="${b}"]`).click({ modifiers: ['ControlOrMeta'] });
  await expect(page.locator(`${ROWS}[aria-selected="true"]`)).toHaveCount(2);

  await page.keyboard.press('Escape');
  await expect(page.locator(`${ROWS}[aria-selected="true"]`)).toHaveCount(0);
});

test('a canvas node the editor no longer has is not selected: no empty Inspector, the canvas refreshes', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop', 'docked Inspector (desktop)');
  await openBuilder(page);
  await settled(page);
  await page.getByRole('tab', { name: /^Add$/ }).click();
  await page.getByRole('tab', { name: 'Sections' }).click();
  await page.locator('#sbx-leftpanel-blocks [data-preset="system-section-team"] .sbx-preset-card__insert').click();
  await settled(page);
  await expect(page.frameLocator('iframe.sbx-canvas__frame').locator('[data-sb-node^="blk_"]').first()).toBeVisible();
  const frame = await frameDocument(page);
  await frame.evaluate(() => {
    const el = document.createElement('p');
    el.setAttribute('data-sb-node', 'blk_stale_ghost');
    el.setAttribute('data-sb-type', 'core.text');
    el.textContent = 'ghost';
    el.style.cssText = 'display:block;min-height:40px';
    document.body.prepend(el);
  });
  await page.frameLocator('iframe.sbx-canvas__frame').locator('[data-sb-node="blk_stale_ghost"]').click();
  await expect(page.getByRole('status').filter({ hasText: 'out of date' })).toHaveCount(1);
  // The repaint drops the ghost node, and nothing is left selected (no empty Inspector with a selection box).
  await expect(page.frameLocator('iframe.sbx-canvas__frame').locator('[data-sb-node="blk_stale_ghost"]')).toHaveCount(0, { timeout: 15_000 });
  await expect(page.locator('.sbx-overlay__box.is-primary')).toHaveCount(0);
  await expect(page.locator('.sbx-inspector-empty')).toHaveCount(1);
});

test('a canvas that drifted from the document repaints by itself', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop', 'docked canvas (desktop)');
  await openBuilder(page);
  await settled(page);
  await page.getByRole('tab', { name: /^Add$/ }).click();
  await page.getByRole('tab', { name: 'Sections' }).click();
  await page.locator('#sbx-leftpanel-blocks [data-preset="system-section-team"] .sbx-preset-card__insert').click();
  await settled(page);
  const frame = page.frameLocator('iframe.sbx-canvas__frame');
  await expect(frame.locator('[data-sb-node^="blk_"]').first()).toBeVisible();
  // A section the document does not have, as if a patch had been missed.
  await (await frameDocument(page)).evaluate(() => {
    const el = document.createElement('section');
    el.setAttribute('data-sb-node', 'sec_stale_ghost');
    el.setAttribute('data-sb-type', 'section');
    el.textContent = 'ghost section';
    document.body.prepend(el);
  });
  await expect(frame.locator('[data-sb-node="sec_stale_ghost"]')).toHaveCount(0, { timeout: 15_000 });
  await expect(frame.locator('[data-sb-node^="blk_"]').first()).toBeVisible();
});
