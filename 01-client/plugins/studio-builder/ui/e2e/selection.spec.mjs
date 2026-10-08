// Selection model wired into the shell: Layers, canvas and Inspector stay in sync (B2-P1).
import { test, expect } from '@playwright/test';
import { openBuilder, frameDocument } from './helpers.mjs';

const ROWS = '[role="treeitem"]';

/** Make sure the page has at least `n` sections, adding through the Layers panel like an author. */
async function ensureSections(page, n) {
  await page.getByRole('tab', { name: /Layers/ }).click();
  for (let guard = 0; guard < n + 2; guard++) {
    const count = await page.locator(ROWS).count();
    if (count >= n) return;
    await page.getByRole('button', { name: /Add section/ }).first().click();
    await expect.poll(() => page.locator(ROWS).count(), { timeout: 15_000 }).toBeGreaterThan(count);
  }
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
  const frame = await frameDocument(page);

  await frame.locator(`[data-sb-node="${a}"]`).click({ position: { x: 4, y: 4 } });
  await frame.locator(`[data-sb-node="${b}"]`).click({ position: { x: 4, y: 4 }, modifiers: ['ControlOrMeta'] });

  await expect(page.locator(`${ROWS}[aria-selected="true"]`)).toHaveCount(2);
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
