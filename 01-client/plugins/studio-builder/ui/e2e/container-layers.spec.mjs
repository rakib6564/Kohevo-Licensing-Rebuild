// A Container's layers, laid out like the reference editor: a Background Overlay (colour, opacity, Normal / Hover), the
// Shape Dividers at its top and bottom edge, and a background video, each behind its children. Each reaches the canvas.
import { test, expect } from '@playwright/test';
import { openBuilder, openSection, settled, undoReady } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked inspector (desktop)');
});

async function layerCount(page) {
  await page.getByRole('tab', { name: /^Layers$/ }).click();
  return page.locator('[role="treeitem"]').count();
}

/** Undo back to `layers` rows: the sandbox page is shared, so nothing a test adds may stay on it. */
async function restore(page, layers) {
  for (let i = 0; i < 24; i++) {
    await settled(page);
    if ((await layerCount(page)) <= layers) return;
    const undo = page.getByTestId('undo');
    if (!(await undoReady(undo))) return;
    await undo.click();
    await page.waitForTimeout(500);
  }
}

let before = null;
test.afterEach(async ({ page }) => {
  if (before === null) return; // skipped (tablet, phone) or never got as far as adding one
  await restore(page, before).catch(() => {});
  before = null;
});

/** Add a Container (Flexbox) and return its id, with its Inspector open. */
async function addContainer(page) {
  await openBuilder(page);
  await settled(page);
  before = await layerCount(page);
  await page.getByRole('tab', { name: /^Add$/ }).click();
  const blocks = page.locator('#sbx-leftpanel-blocks');
  await blocks.getByRole('tab', { name: 'Elements' }).click();
  for (const chip of [null, 'layout']) {
    if (chip) await blocks.locator(`[data-chip="${chip}"]`).click().catch(() => {});
    const card = blocks.locator('[data-block-type="core.container"]').first();
    if (await card.isVisible({ timeout: 1500 }).catch(() => false)) { await card.click(); break; }
  }
  await settled(page);
  await expect(page.getByTestId('container-pane')).toBeVisible({ timeout: 15_000 });
  const tabId = await page.locator('[id^="sbx-blk-"][id$="-tab-content"]').first().getAttribute('id');
  return tabId.replace('sbx-blk-', '').replace(/-tab-content$/, '');
}

const nodeIn = (page, id) => page.frameLocator('iframe.sbx-canvas__frame').locator(`[data-sb-node="${id}"]`);
const computed = (page, id, prop) => nodeIn(page, id).evaluate((el, p) => getComputedStyle(el)[p], prop);

const before_ = (page, id, prop) => nodeIn(page, id).evaluate((el, p) => getComputedStyle(el, '::before')[p], prop);

/** The saved canvas render of the open page (what a reload would show). */
async function canvasHtml(page) {
  const src = await page.locator('iframe.sbx-canvas__frame').getAttribute('src');
  return (await page.request.get(`${src}9`)).text();
}

test('Background Overlay: a colour and its opacity draw a layer under the children, live; Hover changes it', async ({ page }) => {
  const id = await addContainer(page);
  await openSection(page, 'overlay');
  const states = page.getByTestId('overlay-states');
  const colour = states.getByRole('textbox').first();
  await colour.fill('#112233');
  await colour.blur();
  await expect.poll(() => before_(page, id, 'display'), { timeout: 20_000 }).toBe('block');
  await expect.poll(() => before_(page, id, 'backgroundColor')).toBe('rgb(17, 34, 51)');
  await states.getByRole('spinbutton', { name: /^Opacity \(/ }).fill('0.4');
  await expect.poll(() => before_(page, id, 'opacity'), { timeout: 20_000 }).toBe('0.4');
  await states.getByRole('tab', { name: 'Hover' }).click();
  await page.getByTestId('overlay-hover').getByRole('spinbutton', { name: /^Opacity \(/ }).fill('0.9');
  await expect.poll(async () => /:hover\{[^}]*--sb-ov-op:0\.9 !important/.test(await canvasHtml(page)), { timeout: 20_000 }).toBe(true);
  await expect(page.getByRole('alert')).toHaveCount(0);
});

test('Shape Divider: a wave at the top and a tilt at the bottom, behind the children; a background video plays behind them too', async ({ page }) => {
  const id = await addContainer(page);
  await openSection(page, 'shape_divider');
  const shapes = page.getByTestId('shape-dividers');
  await shapes.getByRole('radio', { name: 'Wave', exact: true }).click();
  await expect.poll(() => nodeIn(page, id).locator('.sb-shape--top svg path').count(), { timeout: 20_000 }).toBe(1);
  await expect(shapes.getByText('Bring to front')).toBeVisible();
  await shapes.getByRole('tab', { name: 'Bottom' }).click();
  await shapes.getByRole('radio', { name: 'Tilt', exact: true }).click();
  await expect.poll(() => nodeIn(page, id).locator('.sb-shape--bottom').count(), { timeout: 20_000 }).toBe(1);
  await expect.poll(() => nodeIn(page, id).evaluate((el) => getComputedStyle(el).isolation)).toBe('isolate');

  await openSection(page, 'background');
  await page.locator('[data-section="background"]').getByRole('radiogroup', { name: 'Background type' }).getByRole('button', { name: 'Video' }).click();
  const video = page.locator('[data-section="background"]').getByLabel('Background Video');
  await video.fill('https://cdn.example.test/loop.mp4');
  await video.blur();
  await expect.poll(() => nodeIn(page, id).locator('video.sb-bg-video').count(), { timeout: 20_000 }).toBe(1);
  await expect(page.getByRole('alert')).toHaveCount(0);
});
