// Canvas overlay (selection box, name chip, keyboard-reachable toolbar) and the bottom bar (B2-P1).
import { test, expect } from '@playwright/test';
import { openBuilder, frameDocument, settled } from './helpers.mjs';

const ROWS = '[role="treeitem"]';

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

async function firstSectionId(page) {
  await page.getByRole('tab', { name: /Layers/ }).click();
  return page.locator(`${ROWS}[aria-level="1"]`).first().getAttribute('data-row');
}

/** Lock or unlock a Layers row through its menu. */
async function rowAction(page, id, action) {
  await page.getByTestId(`row-menu-${id}`).click();
  await page.getByRole('menuitem').and(page.locator(`[data-action="${action}"]`)).click();
}

async function selectSection(page) {
  await openBuilder(page);
  await ensureSections(page, 2);
  const id = await firstSectionId(page);
  await page.locator(`${ROWS}[data-row="${id}"]`).click();
  await expect(page.getByTestId('overlay-toolbar')).toBeVisible();
  return id;
}

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked layout (desktop); mobile is covered by the mobile-shell suite');
});

test('selecting a node draws the box and toolbar in the parent, over the node, and writes nothing into the frame', async ({ page }) => {
  const id = await selectSection(page);
  const frame = await frameDocument(page);

  // The box sits on top of the node's rectangle (frame coordinates are scaled into the stage).
  const box = await page.locator(`[data-overlay-for="${id}"]`).boundingBox();
  const node = await page.locator('iframe.sbx-canvas__frame').evaluate((f, nodeId) => {
    const el = f.contentDocument.querySelector(`[data-sb-node="${nodeId}"]`);
    const fr = f.getBoundingClientRect();
    const r = el.getBoundingClientRect();
    const s = fr.width / f.clientWidth;
    return { x: fr.left + r.left * s, y: fr.top + r.top * s, w: r.width * s, h: r.height * s };
  }, id);
  expect(Math.abs(box.x - node.x)).toBeLessThan(3);
  expect(Math.abs(box.y - node.y)).toBeLessThan(3);
  expect(Math.abs(box.width - node.w)).toBeLessThan(3);

  await expect(page.getByTestId('overlay-name')).not.toHaveText('');
  // Nothing of the toolbar or overlay lives inside the canvas document.
  expect(await frame.locator('.sbx-canvas-action-bar, [class*="sbx-overlay"]').count()).toBe(0);
});

test('the toolbar stays inside the canvas area and above the node when there is room', async ({ page }) => {
  await selectSection(page);
  const bar = await page.getByTestId('overlay-toolbar').boundingBox();
  const wrap = await page.getByTestId('canvas-overlay').boundingBox();
  expect(bar.x).toBeGreaterThanOrEqual(wrap.x - 1);
  expect(bar.y).toBeGreaterThanOrEqual(wrap.y - 1);
  expect(bar.x + bar.width).toBeLessThanOrEqual(wrap.x + wrap.width + 1);
  expect(bar.y + bar.height).toBeLessThanOrEqual(wrap.y + wrap.height + 1);
});

test('the toolbar is a keyboard toolbar: roving arrow keys, Enter activates (duplicate)', async ({ page }) => {
  const id = await selectSection(page);
  const bar = page.getByTestId('overlay-toolbar');
  await expect(bar).toHaveAttribute('role', 'toolbar');

  const duplicate = page.getByTestId('overlay-duplicate');
  await page.getByTestId('overlay-edit').focus();
  await page.keyboard.press('ArrowRight');
  await expect(duplicate).toBeFocused();
  await page.keyboard.press('ArrowLeft');
  await expect(page.getByTestId('overlay-edit')).toBeFocused();
  await page.keyboard.press('End');
  await expect(page.getByTestId('overlay-remove')).toBeFocused();
  await page.keyboard.press('Home');
  // The first section cannot move up, so Home lands on the first ENABLED button.
  await expect(page.getByTestId('overlay-up')).toBeDisabled();
  await expect(page.getByTestId('overlay-down')).toBeFocused();

  // Exactly one button is in the tab order (roving tabindex).
  expect(await bar.locator('button[tabindex="0"]').count()).toBe(1);

  const before0 = await page.locator(`${ROWS}[aria-level="1"]`).evaluateAll((els) => els.map((e) => e.getAttribute('data-row')));
  const before = before0.length;
  await duplicate.focus();
  await page.keyboard.press('Enter');
  await expect.poll(() => page.locator(`${ROWS}[aria-level="1"]`).count(), { timeout: 15_000 }).toBe(before + 1);

  // take the copy away again: the page is shared with the next specs
  await settled(page);
  await page.getByRole('tab', { name: /Layers/ }).click();
  const copyId = (await page.locator(`${ROWS}[aria-level="1"]`).evaluateAll((els) => els.map((e) => e.getAttribute('data-row')))).find((x) => x !== id && !before0.includes(x));
  await page.locator(`${ROWS}[data-row="${copyId}"]`).click(); // a row's actions show on hover or selection
  await page.getByRole('tab', { name: /Layers/ }).click();
  await rowAction(page, copyId, 'delete');
  await expect.poll(() => page.locator(`${ROWS}[aria-level="1"]`).count(), { timeout: 15_000 }).toBe(before);
});

test('a locked layer keeps duplicate but disables move, edit and delete', async ({ page }) => {
  const id = await selectSection(page);
  await page.getByRole('tab', { name: /Layers/ }).click();
  await rowAction(page, id, 'lock');
  await page.locator(`${ROWS}[data-row="${id}"]`).click();
  await expect(page.getByTestId('overlay-toolbar')).toBeVisible();
  for (const a of ['up', 'down', 'edit', 'remove']) await expect(page.getByTestId(`overlay-${a}`)).toBeDisabled();
  await expect(page.getByTestId('overlay-duplicate')).toBeEnabled();

  // leave the sandbox page as we found it
  await page.getByRole('tab', { name: /Layers/ }).click();
  await rowAction(page, id, 'unlock');
});

test('Escape removes the overlay; the box follows zoom changes from the bottom bar', async ({ page }) => {
  const id = await selectSection(page);
  const wide = (await page.locator(`[data-overlay-for="${id}"]`).boundingBox()).width;
  await page.getByTestId('zoom-out').click();
  await expect.poll(async () => (await page.locator(`[data-overlay-for="${id}"]`).boundingBox()).width).toBeLessThan(wide - 2);

  await page.keyboard.press('Escape');
  await expect(page.getByTestId('canvas-overlay')).toHaveAttribute('data-active', 'false');
  await expect(page.getByTestId('overlay-toolbar')).toHaveCount(0);
});

test('bottom bar: zoom − / + steps, fit, guides toggle and the shortcut help', async ({ page }) => {
  await openBuilder(page);
  const pct = page.getByTestId('zoom-percent');
  await page.getByTestId('zoom-fit').click();
  await page.getByTestId('zoom-in').click();
  const afterIn = parseInt(await pct.textContent(), 10);
  expect([25, 50, 75, 100]).toContain(afterIn);
  await page.getByTestId('zoom-out').click();
  const afterOut = parseInt(await pct.textContent(), 10);
  expect(afterOut).toBeLessThan(afterIn);
  await expect(page.getByTestId('zoom-fit')).toHaveAttribute('aria-pressed', 'false');
  await page.getByTestId('zoom-fit').click();
  await expect(page.getByTestId('zoom-fit')).toHaveAttribute('aria-pressed', 'true');

  const grid = page.getByTestId('canvas-grid-toggle');
  await expect(grid).toHaveAttribute('aria-pressed', 'false');
  await grid.click();
  await expect(grid).toHaveAttribute('aria-pressed', 'true');

  await page.getByTestId('shortcut-help-toggle').click();
  const help = page.getByTestId('shortcut-help');
  await expect(help).toBeVisible();
  await expect(help).toContainText('Undo');
  await expect(help).toContainText('Clear the selection');
  await page.keyboard.press('Escape');
  await expect(help).toHaveCount(0);
});

test('the breadcrumb lives in the bottom bar and selects its segment', async ({ page }) => {
  const id = await selectSection(page);
  const crumbs = page.getByTestId('bottom-bar').getByRole('navigation');
  await expect(crumbs).toContainText('Page');
  await crumbs.getByRole('button', { name: 'Page' }).click();
  await expect(page.getByTestId('overlay-toolbar')).toHaveCount(0);
  await page.getByRole('tab', { name: /Layers/ }).click();
  await page.locator(`${ROWS}[data-row="${id}"]`).click();
  await expect(page.getByTestId('overlay-toolbar')).toBeVisible();
});

test('Cmd/Ctrl+K opens Add and focuses its search', async ({ page }) => {
  await openBuilder(page);
  await page.getByRole('tab', { name: /Layers/ }).click();
  await page.keyboard.press('ControlOrMeta+k');
  await expect(page.locator('.sbx-palette__search-input:visible')).toBeFocused();
});
