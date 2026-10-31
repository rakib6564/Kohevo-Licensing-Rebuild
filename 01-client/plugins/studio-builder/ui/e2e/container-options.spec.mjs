// The Container's own section, laid out like the reference editor (Flexbox or Grid, Boxed or Full width with its width,
// min height, items with linked gaps, overflow and the HTML element), plus the Background's Normal/Hover tabs and the
// linked border widths. Every change shows on the canvas from the edit itself, and the server's render agrees.
import { test, expect } from '@playwright/test';
import { openBuilder, settled, undoReady } from './helpers.mjs';

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

/** The saved canvas render of the open page (what a reload would show). */
async function canvasHtml(page) {
  const src = await page.locator('iframe.sbx-canvas__frame').getAttribute('src');
  return (await page.request.get(`${src}9`)).text();
}

test('the Container section sets boxed width, min height, direction and linked gaps, live on the canvas', async ({ page }) => {
  const id = await addContainer(page);
  const pane = page.getByTestId('container-pane');
  // One section under Content: no separate "Flex & grid options" and no props pane.
  await expect(page.locator('[data-section="layout"]')).toHaveCount(0);
  await expect(pane.getByLabel('Container layout')).toHaveValue('flex');

  await pane.getByLabel('Content width').selectOption('boxed');
  // Boxed with no width of its own: full width, children held to the site content width (80rem in the sandbox) by side padding,
  // and a top-level Container keeps the site's gutter (1rem) inside that padding.
  await expect.poll(async () => {
    const [pl, w] = await nodeIn(page, id).evaluate((el) => [parseFloat(getComputedStyle(el).paddingLeft), el.getBoundingClientRect().width]);
    return Math.abs(pl - (16 + Math.max(0, (w - 1280) / 2)));
  }).toBeLessThan(1.5);

  const minHeight = pane.getByRole('spinbutton', { name: 'Min height', exact: true });
  await minHeight.fill('50');
  await minHeight.blur();
  await pane.getByLabel('Min height (Unit)').selectOption('vh');
  await expect.poll(async () => nodeIn(page, id).evaluate((el) => Math.round(parseFloat(getComputedStyle(el).minHeight) / (el.ownerDocument.defaultView.innerHeight / 100)))).toBe(50);

  await pane.getByRole('group', { name: 'Direction' }).getByRole('button', { name: 'Row', exact: true }).click();
  await expect.poll(() => computed(page, id, 'flexDirection')).toBe('row');

  const column = pane.getByRole('textbox', { name: 'Column', exact: true });
  await column.fill('20');
  await column.blur();
  await expect.poll(() => computed(page, id, 'columnGap')).toBe('20px');
  await expect.poll(() => computed(page, id, 'rowGap')).toBe('20px'); // linked

  await settled(page);
  await expect.poll(async () => {
    const html = await canvasHtml(page);
    return html.includes('--sb-box:var(--sb-layout-content,80rem)') && html.includes('min-height:50vh');
  }, { timeout: 20_000 }).toBe(true);
  await expect(page.getByRole('alert')).toHaveCount(0);
});

test('Additional options: the container can be a whole link, with a safe address only', async ({ page }) => {
  const id = await addContainer(page);
  const pane = page.getByTestId('container-pane');
  await pane.getByTestId('container-more').locator('summary').click();
  await pane.getByLabel('HTML tag').selectOption('a');
  await settled(page);
  const url = pane.getByRole('textbox', { name: 'Link', exact: true });
  await url.fill('javascript:alert(1)');
  await url.blur();
  await expect(pane.getByRole('alert')).toContainText('https://');
  await url.fill('https://example.test/menu');
  await url.blur();
  await pane.getByLabel('Open in a new tab').check();
  await expect.poll(async () => {
    const m = (await canvasHtml(page)).match(new RegExp(`<a [^>]*data-sb-node="${id}"[^>]*>`));
    return m ? m[0] : '';
  }, { timeout: 20_000 }).toContain('href="https://example.test/menu" target="_blank" rel="noopener noreferrer"');
});

test('Background has Normal and Hover tabs; the border widths are linked', async ({ page }) => {
  const id = await addContainer(page);
  await page.locator(`#sbx-blk-${id}-tab-style`).click();
  const panel = page.locator('#sbx-leftpanel-inspector');
  for (const name of ['background', 'border']) {
    const toggle = panel.locator(`[data-section="${name}"] .sbx-isec__toggle`);
    if ((await toggle.getAttribute('aria-expanded')) !== 'true') await toggle.click();
  }
  const bg = panel.locator('[data-section="background"]');
  await bg.getByRole('tab', { name: 'Hover' }).click();
  const hover = page.getByTestId('bg-hover');
  await hover.getByRole('textbox').first().fill('#123456');
  await hover.getByRole('textbox').first().blur();
  await expect.poll(async () => (await canvasHtml(page)).includes('#123456'), { timeout: 20_000 }).toBe(true);

  const border = panel.locator('[data-section="border"]');
  await border.getByLabel('Border Style', { exact: true }).selectOption('solid');
  // The side widths sit in the Border group itself, linked by default.
  const top = border.getByRole('textbox', { name: 'Top', exact: true });
  await top.fill('3');
  await top.blur();
  await expect.poll(() => nodeIn(page, id).evaluate((el) => ['Top', 'Right', 'Bottom', 'Left'].map((s) => getComputedStyle(el)[`border${s}Width`]).join(' '))).toBe('3px 3px 3px 3px');
});

test('a Container\'s tabs follow the reference: Layout, Style, Advanced in its order, Kohevo\'s own under More options', async ({ page }) => {
  await addContainer(page);
  const sections = () => page.locator('[role="tabpanel"] [data-section]').evaluateAll((els) => els.map((e) => e.dataset.section));
  await expect(page.locator('[id$="-tab-content"]')).toHaveText('Layout');
  await page.locator('[id$="-tab-style"]').click();
  expect((await sections()).slice(0, 4)).toEqual(['background', 'overlay', 'border', 'shape_divider']);
  await expect(page.getByTestId('more-options')).toHaveCount(1);
  await page.screenshot({ path: process.env.SHOT_DIR ? `${process.env.SHOT_DIR}/style.png` : undefined });
  await page.locator('[data-section="shape_divider"] .sbx-isec__toggle').click();
  await expect(page.getByTestId('shape-picker').getByRole('radio')).toHaveCount(19);
  await page.locator('[id$="-tab-advanced"]').click();
  expect((await sections()).slice(0, 6)).toEqual(['advanced', 'motion', 'transform', 'responsive', 'attributes', 'custom_css']);
  await page.screenshot({ path: process.env.SHOT_DIR ? `${process.env.SHOT_DIR}/advanced.png` : undefined });
  await page.locator('[data-section="motion"] .sbx-isec__toggle').click();
  await page.getByTestId('scrolling-effects').getByRole('switch').click();
  await page.screenshot({ path: process.env.SHOT_DIR ? `${process.env.SHOT_DIR}/motion.png` : undefined });
  await page.locator('[id$="-scroll-vertical"]').first().fill('3');
  await expect(page.getByRole('alert')).toHaveCount(0);
  await page.locator('[data-section="custom_css"] .sbx-isec__toggle').click();
  await page.locator('[id^="sbx-element-css-"]').fill('selector { outline: 3px solid rgb(1, 2, 3); }');
  await page.locator('[id^="sbx-element-css-"]').blur();
  await expect(page.getByRole('alert')).toHaveCount(0);
  await expect(page.getByRole('status').filter({ hasText: /saved/i }).first()).toBeVisible({ timeout: 15_000 });
  await expect(page.locator('[id^="sbx-element-css-"]')).toHaveValue(/outline/);
});
