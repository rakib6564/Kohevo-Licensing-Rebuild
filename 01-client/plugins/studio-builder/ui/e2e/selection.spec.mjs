// Selection model wired into the shell: Layers, canvas and Inspector stay in sync (B2-P1).
import { test, expect } from '@playwright/test';
import { openBuilder, frameDocument, settled, layerCount, restoreLayers } from './helpers.mjs';

const ROWS = '[role="treeitem"]';

// These specs add Containers to the shared sandbox page (some a whole Team preset). Each test notes how many layers the
// page had when it opened, and undoes back to that afterwards, so the specs that run next do not inherit the leftovers.
let baseline = null;
async function open(page) {
  await openBuilder(page);
  await settled(page);
  baseline = await layerCount(page);
}
test.afterEach(async ({ page }) => {
  if (baseline === null) return;
  const layers = baseline;
  baseline = null;
  try {
    await restoreLayers(page, layers);
  } catch {
    // the test's own error is the one worth reading
  }
});

/** Make sure the page has at least `n` top-level Containers (not rows: a Container with blocks is many rows), adding through the Layers panel like an author. */
async function ensureContainers(page, n) {
  await page.getByRole('tab', { name: /Layers/ }).click();
  for (let guard = 0; guard < n + 2; guard++) {
    const count = await page.locator(`${ROWS}[aria-level="1"]`).count();
    if (count >= n) break;
    await page.getByRole('button', { name: /Add Container/ }).first().click();
    await expect.poll(() => page.locator(`${ROWS}[aria-level="1"]`).count(), { timeout: 15_000 }).toBeGreaterThan(count);
  }
  await settled(page);
  // A new Container asks for its layout, and the question sits over it: answer it so the canvas can be clicked.
  await page.getByTestId('chooser-close').click({ timeout: 1_000 }).catch(() => {});
}

async function topLevelRowIds(page) {
  await page.getByRole('tab', { name: /Layers/ }).click();
  return page.locator(`${ROWS}[aria-level="1"]`).evaluateAll((els) => els.map((e) => e.getAttribute('data-row')));
}

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked Layers panel (desktop); mobile sheets are covered by the mobile-shell suite');
});

test('Cmd/Ctrl-click in Layers multi-selects: the Inspector shows the count and the canvas outlines both nodes', async ({ page }) => {
  await open(page);
  await ensureContainers(page, 2);
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
  await open(page);
  await ensureContainers(page, 2);
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
  await open(page);
  await ensureContainers(page, 2);
  const [a, b] = await topLevelRowIds(page);
  await page.locator(`${ROWS}[data-row="${a}"]`).click();
  await page.getByRole('tab', { name: /Layers/ }).click();
  await page.locator(`${ROWS}[data-row="${b}"]`).click({ modifiers: ['ControlOrMeta'] });
  await expect(page.locator(`${ROWS}[aria-selected="true"]`)).toHaveCount(2);

  await page.keyboard.press('Escape');
  await expect(page.locator(`${ROWS}[aria-selected="true"]`)).toHaveCount(0);
});

test('a canvas node the editor does not have and nothing around it is refused with a note, never selected', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop', 'docked Inspector (desktop)');
  await open(page);
  await settled(page);
  await page.getByRole('tab', { name: /^Add$/ }).click();
  await page.getByRole('tab', { name: 'Containers' }).click();
  await page.locator('#sbx-leftpanel-blocks [data-preset="system-container-team"] .sbx-preset-card__insert').click();
  await settled(page);
  await expect(page.frameLocator('iframe.sbx-canvas__frame').locator('[data-sb-node^="blk_"]').first()).toBeVisible();
  // A canvas repaint right after the insert can drop the injected node, so draw it again until the click lands on it.
  await expect(async () => {
    await (await frameDocument(page)).evaluate(() => {
      if (document.querySelector('[data-sb-node="blk_stale_ghost"]')) return;
      const el = document.createElement('p');
      el.setAttribute('data-sb-node', 'blk_stale_ghost');
      el.setAttribute('data-sb-type', 'core.text');
      el.textContent = 'ghost';
      el.style.cssText = 'display:block;min-height:40px';
      document.body.prepend(el);
    });
    await page.frameLocator('iframe.sbx-canvas__frame').locator('[data-sb-node="blk_stale_ghost"]').click({ timeout: 2_000 });
    await expect(page.getByRole('status').filter({ hasText: 'cannot be selected here' })).toHaveCount(1, { timeout: 2_000 });
  }).toPass({ timeout: 20_000 });
  // The repaint drops the ghost node, and nothing is left selected (no empty Inspector with a selection box).
  await expect(page.frameLocator('iframe.sbx-canvas__frame').locator('[data-sb-node="blk_stale_ghost"]')).toHaveCount(0, { timeout: 15_000 });
  await expect(page.locator('.sbx-overlay__box.is-primary')).toHaveCount(0);
  await expect(page.locator('.sbx-inspector-empty')).toHaveCount(1);
});

test('a canvas that drifted from the document repaints by itself', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop', 'docked canvas (desktop)');
  await open(page);
  await settled(page);
  await page.getByRole('tab', { name: /^Add$/ }).click();
  await page.getByRole('tab', { name: 'Containers' }).click();
  await page.locator('#sbx-leftpanel-blocks [data-preset="system-container-team"] .sbx-preset-card__insert').click();
  await settled(page);
  const frame = page.frameLocator('iframe.sbx-canvas__frame');
  await expect(frame.locator('[data-sb-node^="blk_"]').first()).toBeVisible();
  // A Container the document does not have, as if a patch had been missed.
  await (await frameDocument(page)).evaluate(() => {
    const el = document.createElement('section');
    el.setAttribute('data-sb-node', 'blk_stale_ghost');
    el.setAttribute('data-sb-type', 'core.container');
    el.className = 'sb-top';
    el.textContent = 'ghost Container';
    document.body.prepend(el);
  });
  await expect(frame.locator('[data-sb-node="blk_stale_ghost"]')).toHaveCount(0, { timeout: 15_000 });
  await expect(frame.locator('[data-sb-node^="blk_"]').first()).toBeVisible();
});

test('clicking content the editor does not own selects the nearest node it does own', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop', 'docked Inspector (desktop)');
  await open(page);
  await settled(page);
  await page.getByRole('tab', { name: /^Add$/ }).click();
  await page.getByRole('tab', { name: 'Containers' }).click();
  await page.locator('#sbx-leftpanel-blocks [data-preset="system-container-team"] .sbx-preset-card__insert').click();
  await settled(page);
  const frame = page.frameLocator('iframe.sbx-canvas__frame');
  // A block inside the new Container: the insert has already selected the Container itself, and the ghost must move the selection.
  const block = frame.locator('main .sb-top [data-sb-node^="blk_"]').first();
  await expect(block).toBeVisible();
  const owner = await block.getAttribute('data-sb-node');
  // A node the editor has never heard of, drawn inside a real block (what a template or shared part would do).
  // A canvas re-sync right after the insert can prune it, so draw it again until it survives the click.
  await expect(async () => {
    await (await frameDocument(page)).evaluate((id) => {
      if (document.querySelector('[data-sb-node="blk_unowned_ghost"]')) return;
      const host = document.querySelector(`[data-sb-node="${id}"]`);
      const el = document.createElement('span');
      el.setAttribute('data-sb-node', 'blk_unowned_ghost');
      el.setAttribute('data-sb-type', 'core.text');
      el.textContent = 'drawn elsewhere';
      el.style.cssText = 'display:inline-block;min-width:60px;min-height:20px';
      host.prepend(el);
    }, owner);
    await frame.locator('[data-sb-node="blk_unowned_ghost"]').click({ force: true, timeout: 2_000 });
  }).toPass({ timeout: 20_000 });
  await expect(page.locator('.sbx-inspector-host [role="tab"]').first()).toBeVisible();
  await expect(page.locator('.sbx-inspector-empty')).toHaveCount(0);
  await expect(page.locator(`.sbx-overlay__box.is-primary[data-overlay-for="${owner}"]`)).toHaveCount(1);
});
