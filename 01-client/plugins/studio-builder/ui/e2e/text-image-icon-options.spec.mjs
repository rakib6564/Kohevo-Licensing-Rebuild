// A Text Editor's, an Image's and an Icon's panels, laid out like the reference editor: the text's drop cap and columns,
// the image's link and its Normal / Hover look, the icon's views, size and rotation. Each change reaches the canvas.
import { test, expect } from '@playwright/test';
import { chooseImage, openBuilder, settled, undoReady } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked inspector (desktop)');
});

async function layerCount(page) {
  await page.getByRole('tab', { name: /^Layers$/ }).click();
  return page.locator('[role="treeitem"]').count();
}

/** Undo back to `layers` rows: the sandbox page is shared, so nothing a test adds may stay on it. */
async function restore(page, layers) {
  for (let i = 0; i < 30; i++) {
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
  if (before === null) return;
  await restore(page, before).catch(() => {});
  before = null;
});

/** Add a block of `type` and return its id, with its Inspector open. */
async function add(page, type) {
  await openBuilder(page);
  await settled(page);
  before = await layerCount(page);
  await page.getByRole('tab', { name: /^Add$/ }).click();
  const blocks = page.locator('#sbx-leftpanel-blocks');
  await blocks.getByRole('tab', { name: 'Elements' }).click();
  for (const chip of [null, 'content', 'media']) {
    if (chip) await blocks.locator(`[data-chip="${chip}"]`).click().catch(() => {});
    const card = blocks.locator(`[data-block-type="${type}"]`).first();
    if (await card.isVisible({ timeout: 1500 }).catch(() => false)) { await card.click(); break; }
  }
  // An image asks for its picture first.
  const setup = page.locator('dialog', { hasText: 'Set up Image' });
  if (await setup.isVisible({ timeout: 2500 }).catch(() => false)) {
    await chooseImage(page, setup);
    await setup.getByRole('button', { name: 'Add', exact: true }).click();
  }
  await settled(page);
  const tabId = await page.locator('[id^="sbx-blk-"][id$="-tab-content"]').first().getAttribute('id');
  return tabId.replace('sbx-blk-', '').replace(/-tab-content$/, '');
}

const nodeIn = (page, id) => page.frameLocator('iframe.sbx-canvas__frame').locator(`[data-sb-node="${id}"]`);

async function canvasHtml(page) {
  const src = await page.locator('iframe.sbx-canvas__frame').getAttribute('src');
  return (await page.request.get(`${src}9`)).text();
}

/** A slider's number box. */
const sliderNumber = (panel, label) => panel.getByRole('spinbutton', { name: new RegExp(`^${label} \\(`) });

test('Text Editor: a drop cap and columns under Content, then one Text Editor section under Style', async ({ page }) => {
  const id = await add(page, 'core.rich_text');
  const panel = page.locator('#sbx-leftpanel-inspector');
  await expect(panel.getByLabel('Columns Gap')).toHaveCount(0); // one column: no gap yet
  await panel.getByLabel('Drop Cap').check();
  await expect.poll(() => nodeIn(page, id).locator('.sb-rich-text.sb-dropcap').count(), { timeout: 20_000 }).toBe(1);
  const columns = panel.getByLabel('Columns', { exact: true });
  await columns.fill('2');
  await columns.blur();
  await expect.poll(() => nodeIn(page, id).locator('.sb-rich-text').evaluate((el) => getComputedStyle(el).columnCount), { timeout: 20_000 }).toBe('2');
  await expect(panel.getByLabel('Columns Gap')).toBeVisible();

  await page.locator(`#sbx-blk-${id}-tab-style`).click();
  await expect(page.locator('[data-section="text_style"]')).toBeVisible();
  await expect(page.locator('[data-section="typography"], [data-section="align"]')).toHaveCount(0);
  await expect(panel.getByTestId('text-shadow')).toBeVisible();
  await expect(page.getByRole('alert')).toHaveCount(0);
});

test('Image: a link shows its address only when custom; Style has one Image section with Normal and Hover', async ({ page }) => {
  const id = await add(page, 'core.image');
  const panel = page.locator('#sbx-leftpanel-inspector');
  await expect(panel.getByLabel('Link URL')).toHaveCount(0);
  await panel.getByLabel('Link', { exact: true }).selectOption('custom');
  await expect(panel.getByLabel('Link URL')).toBeVisible();
  await expect(panel.getByLabel('Open in a new tab')).toBeVisible();
  await panel.getByLabel('Link', { exact: true }).selectOption('media');
  await expect(panel.getByLabel('Link URL')).toHaveCount(0);
  await expect.poll(() => nodeIn(page, id).locator('a.sb-image__link > img').count(), { timeout: 20_000 }).toBe(1);

  await page.locator(`#sbx-blk-${id}-tab-style`).click();
  await expect(page.locator('[data-section="image_style"]')).toBeVisible();
  await expect(page.locator('[data-section="dimensions"], [data-section="opacity"], [data-section="border"]')).toHaveCount(0);
  const states = panel.getByTestId('image-states');
  const gray = sliderNumber(states, 'Grayscale');
  await gray.fill('60');
  await expect.poll(() => nodeIn(page, id).evaluate((el) => getComputedStyle(el).filter), { timeout: 20_000 }).toContain('grayscale(0.6)');
  await states.getByRole('tab', { name: 'Hover' }).click();
  await sliderNumber(states, 'Opacity').fill('0.5');
  await expect.poll(async () => /:hover[^{]*\{[^}]*opacity:0?\.5/.test(await canvasHtml(page)), { timeout: 20_000 }).toBe(true);
  await expect(page.getByRole('alert')).toHaveCount(0);
});

test('Icon: stacked or framed views under Content; size and rotation in its one Icon section under Style', async ({ page }) => {
  const id = await add(page, 'core.icon');
  const panel = page.locator('#sbx-leftpanel-inspector');
  await expect(panel.getByRole('group', { name: 'Shape' })).toHaveCount(0);
  await expect(panel.getByLabel('Size (px)')).toHaveCount(0); // set from the Style tab
  await expect(panel.getByLabel('Size', { exact: true })).toHaveCount(0); // one size: the Style slider
  await panel.getByRole('group', { name: 'View' }).getByText('Stacked', { exact: true }).click();
  await expect.poll(() => nodeIn(page, id).locator('.sb-icon--stacked .sb-icon__frame svg').count(), { timeout: 20_000 }).toBe(1);
  await expect(panel.getByRole('group', { name: 'Shape' })).toBeVisible();

  await page.locator(`#sbx-blk-${id}-tab-style`).click();
  await expect(page.locator('[data-section="icon_style"]')).toBeVisible();
  await expect(page.locator('[data-section="align"]')).toHaveCount(0); // alignment is under Content
  const props = panel.getByTestId('icon-props');
  await expect(sliderNumber(props, 'Size')).toHaveValue('32'); // the preset, until a pixel size is set
  await sliderNumber(props, 'Size').fill('48');
  await expect.poll(() => nodeIn(page, id).locator('.sb-icon__svg').evaluate((el) => getComputedStyle(el).width), { timeout: 20_000 }).toBe('48px');
  await sliderNumber(props, 'Rotate').fill('45');
  await expect.poll(() => nodeIn(page, id).locator('.sb-icon__svg').evaluate((el) => el.getAttribute('style') || ''), { timeout: 20_000 }).toContain('rotate(45deg)');
  await expect(props.getByText('Secondary Color')).toBeVisible();
  await expect(page.getByRole('alert')).toHaveCount(0);
});
