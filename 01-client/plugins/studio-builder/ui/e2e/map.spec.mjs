// The Map element: found in the Content group, drawn on the canvas as an OpenStreetMap frame, and set from the Inspector.
import { test, expect } from '@playwright/test';
import { openBuilder, settled, sandboxPageId, openSection, undoReady } from './helpers.mjs';

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
    if (!(await undoReady(undo))) return; // nothing left to undo: stop quietly so the test's own error is the one reported
    await undo.click();
    await page.waitForTimeout(500);
  }
  throw new Error('could not restore the shared sandbox page');
}

const canvas = (page) => page.frameLocator('iframe.sbx-canvas__frame');

async function addElement(page, type) {
  await page.getByRole('tab', { name: /^(Add|Ajouter)$/ }).click();
  const panel = page.locator('#sbx-leftpanel-blocks');
  await panel.getByRole('tab', { name: 'Elements' }).click();
  await panel.locator('[data-chip="content"]').click();
  await panel.locator(`[data-block-type="${type}"]`).click();
  await settled(page);
}

test('a Map is added from the Content group and its point and height change the frame on the canvas', async ({ page }) => {
  test.setTimeout(90_000);
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await addElement(page, 'core.map');
    const frame = canvas(page).locator('[data-sb-type="core.map"].sbx-selected .sb-map iframe');
    await expect(frame).toHaveCount(1);
    await expect(frame).toHaveAttribute('src', /openstreetmap\.org\/export\/embed\.html\?bbox=.*marker=50\.85030,4\.35170/);

    const inspector = page.getByTestId('inspector-panel');
    await inspector.getByLabel(/^Latitude/).fill('48.8566');
    await inspector.getByLabel('Height', { exact: true }).selectOption('lg');
    await settled(page);
    await expect(frame).toHaveAttribute('src', /marker=48\.85660,4\.35170/, { timeout: 15_000 });
    await expect.poll(() => frame.evaluate((el) => Math.round(el.getBoundingClientRect().height)), { timeout: 15_000 }).toBe(544); // lg = 34rem
  } finally {
    await restore(page, before);
  }
});
