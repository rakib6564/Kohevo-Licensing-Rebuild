// A style edit is painted by the server's own CSS, but it no longer reloads the canvas: the render is fetched and
// morphed into the open frame, so the frame is the same document before and after (a marker on its window survives),
// the scroll position is kept, and the editor's selection classes are still there.
import { test, expect } from '@playwright/test';
import { layerCount, openBuilder, restoreLayers, settled, frameEval } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked Inspector (desktop)');
});

const canvasFrame = (page) => page.frames().find((f) => f !== page.mainFrame());

test('a style edit repaints the canvas in place: same document, new look, selection kept', async ({ page }) => {
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

    // Mark the frame's window: a reload would throw it away.
    await canvasFrame(page).evaluate(() => { window.__sbxMarker = 'same-document'; });
    const size = page.getByLabel('Font Size', { exact: true });
    await size.fill('2rem');
    await size.blur();
    await settled(page);

    await expect.poll(() => frameEval(page, '[data-sb-type="core.quote"]', (el) => getComputedStyle(el).fontSize), { timeout: 20_000 }).toBe('32px');
    expect(await canvasFrame(page).evaluate(() => window.__sbxMarker)).toBe('same-document');
    expect(await canvasFrame(page).evaluate(() => document.querySelectorAll('.sbx-selected').length)).toBeGreaterThan(0);

    await size.fill('3rem');
    await size.blur();
    await settled(page);
    await expect.poll(() => frameEval(page, '[data-sb-type="core.quote"]', (el) => getComputedStyle(el).fontSize), { timeout: 20_000 }).toBe('48px');
    expect(await canvasFrame(page).evaluate(() => window.__sbxMarker)).toBe('same-document');
  } finally {
    await restoreLayers(page, before);
  }
});
