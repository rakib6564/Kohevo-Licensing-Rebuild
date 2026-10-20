// A Button's and a Heading's panels, laid out like the reference editor: the button's icon, its one Style section with
// Normal / Hover, text shadow, linked corners and padding; the heading's link, HTML tag, text stroke and text colour.
// Each change reaches the canvas, and the server's render agrees.
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

/** Add a block of `type` (filling a button's set-up dialog) and return its id, with its Inspector open. */
async function add(page, type) {
  await openBuilder(page);
  await settled(page);
  before = await layerCount(page);
  await page.getByRole('tab', { name: /^Add$/ }).click();
  const blocks = page.locator('#sbx-leftpanel-blocks');
  await blocks.getByRole('tab', { name: 'Elements' }).click();
  for (const chip of [null, 'content']) {
    if (chip) await blocks.locator(`[data-chip="${chip}"]`).click().catch(() => {});
    const card = blocks.locator(`[data-block-type="${type}"]`).first();
    if (await card.isVisible({ timeout: 1500 }).catch(() => false)) { await card.click(); break; }
  }
  const setup = page.getByRole('dialog').filter({ hasText: /Set up/ });
  if (await setup.isVisible({ timeout: 2500 }).catch(() => false)) {
    const fields = setup.locator('input[type="text"], input:not([type]), input[type="url"]');
    for (let k = 0; k < await fields.count(); k++) await fields.nth(k).fill(k === 1 ? '/book' : 'Book your tasting');
    await setup.getByRole('button', { name: /^Add$/ }).click();
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

test('Button: an icon, then one Style section with Hover colours, text shadow, linked corners and padding', async ({ page }) => {
  const id = await add(page, 'core.button');
  const panel = page.locator('#sbx-leftpanel-inspector');
  await expect(panel.getByLabel('Icon Position')).toHaveCount(0); // nothing to place yet
  await panel.getByLabel('Icon', { exact: true }).selectOption('star');
  await expect.poll(() => nodeIn(page, id).locator('.sb-button__icon svg').count(), { timeout: 20_000 }).toBe(1);
  await expect(panel.getByRole('group', { name: 'Icon Position' })).toBeVisible();

  await page.locator(`#sbx-blk-${id}-tab-style`).click();
  await expect(page.locator('[data-section="button_style"]')).toBeVisible();
  await expect(page.locator('[data-section="typography"], [data-section="border"], [data-section="background"]')).toHaveCount(0);

  const states = panel.getByTestId('normal-hover');
  await states.getByRole('tab', { name: 'Hover' }).click();
  const hoverText = states.getByRole('textbox').first();
  await hoverText.fill('#aa3300');
  await hoverText.blur();
  await expect.poll(async () => /:hover[^{]*\{[^}]*color:#aa3300/.test(await canvasHtml(page)), { timeout: 20_000 }).toBe(true);

  await panel.getByRole('button', { name: 'Edit text shadow' }).click();
  const shadowCard = page.getByRole('dialog', { name: 'Edit text shadow' });
  const y = shadowCard.getByRole('textbox', { name: 'Offset Y', exact: true });
  await y.fill('3');
  await y.blur();
  await expect.poll(() => nodeIn(page, id).locator('.sb-button').evaluate((el) => getComputedStyle(el.closest('[data-sb-node]')).textShadow), { timeout: 20_000 }).toContain('3px');
  await shadowCard.getByRole('button', { name: 'Done' }).click();

  const corner = panel.getByRole('textbox', { name: 'Top left', exact: true });
  await corner.fill('6');
  await corner.blur();
  await expect.poll(() => nodeIn(page, id).evaluate((el) => getComputedStyle(el).borderBottomRightRadius)).toBe('6px'); // linked
  await expect(page.getByRole('alert')).toHaveCount(0);
});

test('Heading: a link, another HTML tag, a text stroke and its text colour', async ({ page }) => {
  const id = await add(page, 'core.heading');
  const panel = page.locator('#sbx-leftpanel-inspector');
  await expect(panel.getByLabel('Open in a new tab')).toHaveCount(0);
  const link = panel.getByRole('textbox', { name: 'Link', exact: true });
  await link.fill('/menu');
  await link.blur();
  await expect.poll(() => nodeIn(page, id).locator('a.sb-heading__link[href="/menu"]').count(), { timeout: 20_000 }).toBe(1);
  await panel.getByLabel('HTML Tag').selectOption('p');
  await expect.poll(() => nodeIn(page, id).locator('p.sb-heading').count(), { timeout: 20_000 }).toBe(1);

  await page.locator(`#sbx-blk-${id}-tab-style`).click();
  await panel.getByRole('button', { name: 'Edit text stroke' }).click();
  const width = page.getByRole('dialog', { name: 'Edit text stroke' }).getByRole('textbox', { name: 'Width', exact: true });
  await width.fill('2');
  await width.blur();
  await expect.poll(() => nodeIn(page, id).evaluate((el) => getComputedStyle(el).webkitTextStrokeWidth), { timeout: 20_000 }).toBe('2px');
  await page.getByRole('dialog', { name: 'Edit text stroke' }).getByRole('button', { name: 'Done' }).click();

  const colour = panel.getByTestId('normal-hover').getByRole('textbox').first();
  await colour.fill('#123456');
  await colour.blur();
  await expect.poll(() => nodeIn(page, id).locator('.sb-heading').evaluate((el) => getComputedStyle(el).color), { timeout: 20_000 }).toBe('rgb(18, 52, 86)');
  await expect(page.getByRole('alert')).toHaveCount(0);
});
