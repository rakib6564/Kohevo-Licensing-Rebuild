import fs from 'node:fs';
import path from 'node:path';
import { expect } from '@playwright/test';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));

export function sandboxPageId() {
  return JSON.parse(fs.readFileSync(path.join(here, '.auth', 'page.json'), 'utf8')).pageId;
}

/** Open the builder for the sandbox page and wait until the shell is interactive. */
export async function openBuilder(page) {
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  await page.goto(`/plugins/studio-builder/admin/builder.php?page=${sandboxPageId()}`);
  await page.locator('header.sbx-topbar').waitFor({ state: 'visible' });
  await page.locator('iframe.sbx-canvas__frame').waitFor({ state: 'visible' });
  return { errors };
}

/** The canvas frame's document (same-origin, script-less). */
export async function frameDocument(page) {
  const handle = await page.locator('iframe.sbx-canvas__frame').elementHandle();
  const frame = await handle.contentFrame();
  await frame.waitForLoadState('domcontentloaded');
  return frame;
}

/**
 * Wait until the document has no optimistic client ids left (`tmp_*`). A block or section you just
 * added carries a temporary id until the server round trip returns its real one; reading an id (or
 * clicking a node) before that races the swap, which only shows on a slow runner.
 */
export async function settled(page) {
  const frame = page.frameLocator('iframe.sbx-canvas__frame');
  await expect.poll(async () => (await frame.locator('[data-sb-node^="tmp_"]').count()) + (await page.locator('[data-row^="tmp_"]').count()), { timeout: 20_000 }).toBe(0);
}

/** Open one Inspector section (`data-section` = its registry id) if it is closed; returns its body. */
export async function openSection(page, id) {
  const section = page.locator(`[data-section="${id}"]`);
  // Sections live under Content, Style or Advanced depending on the block: switch to the tab that has this one.
  if ((await section.count()) === 0) {
    for (const name of ['Content', 'Style', 'Advanced']) {
      const tab = page.getByRole('tab', { name, exact: true });
      if ((await tab.count()) === 0) continue;
      await tab.first().click();
      if (await section.first().waitFor({ state: 'attached', timeout: 1500 }).then(() => true, () => false)) break;
    }
  }
  const toggle = section.locator('.sbx-isec__toggle');
  if ((await toggle.getAttribute('aria-expanded')) !== 'true') await toggle.click();
  await expect(toggle).toHaveAttribute('aria-expanded', 'true');
  return section.locator('.sbx-isec__body');
}

/**
 * Evaluate `fn` on one element of the canvas frame, looking the frame up again on every call. The frame reloads when a
 * repaint lands, which destroys a cached Frame or handle; use this inside `expect.poll` and a reload just means "try again".
 */
export async function frameEval(page, selector, fn, { last = true } = {}) {
  try {
    const frame = await frameDocument(page);
    const loc = last ? frame.locator(selector).last() : frame.locator(selector).first();
    return await loc.evaluate(fn);
  } catch { return 'retry'; }
}

/** A 1x1 PNG, uploaded when the site has no image yet. */
const PIXEL = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');

/** Open the image dialog from `scope`'s "Choose image" button and use the first image (uploading one if the site has none). */
export async function chooseImage(page, scope = page) {
  await scope.getByTestId('media-choose').first().click();
  const dialog = page.getByTestId('media-dialog');
  await expect(dialog).toBeVisible();
  await expect.poll(async () => (await dialog.locator('[data-media-id]').count()) + (await dialog.getByTestId('media-empty').count()), { timeout: 15_000 }).toBeGreaterThan(0);
  if (await dialog.locator('[data-media-id]').count() === 0) {
    await dialog.getByTestId('media-file').setInputFiles({ name: 'pixel.png', mimeType: 'image/png', buffer: PIXEL });
    await expect(dialog.locator('[data-media-id]').first()).toBeVisible();
  }
  await dialog.locator('[data-media-id]').first().click();
  await page.getByTestId('media-use').click();
  await expect(dialog).toHaveCount(0);
}

/** Layers rows on the shared sandbox page (sections and blocks). */
export async function layerCount(page) {
  await page.getByRole('tab', { name: /^Layers$/ }).click();
  return page.locator('[role="treeitem"]').count();
}

/** Undo what a spec added to the shared sandbox page, so the specs after it start from the same page. */
export async function restoreLayers(page, layers) {
  for (let i = 0; i < 16; i++) {
    await settled(page);
    if ((await layerCount(page)) <= layers) return;
    const undo = page.getByTestId('undo');
    if (!(await undoReady(undo))) return;
    await undo.click();
    await page.waitForTimeout(500);
  }
  throw new Error('could not restore the shared sandbox page');
}

/**
 * Undo is usable within a few seconds, or there is nothing to undo. Restores stop quietly in the second case: a test that
 * failed before it changed anything must report its own error, not a cleanup one that hides it.
 */
export async function undoReady(undo) {
  return expect(undo).toBeEnabled({ timeout: 4_000 }).then(() => true, () => false);
}

/** The one left panel swaps to its Edit tab for a selection made on the canvas or by adding an element; a row picked in Layers keeps the panel on Layers, so open Edit to reach the controls. */
export async function showEdit(page) {
  await page.getByRole('tab', { name: /^(Edit|Éditer)$/ }).click();
  await expect(page.locator('#sbx-leftpanel-inspector')).toBeVisible();
}

/**
 * Create and publish a throwaway page through the builder API (the builder must be open). Returns its id, public path
 * and the site URL; archive it with `archivePage` when done.
 */
export async function publishThrowawayPage(page, title, slug) {
  return page.evaluate(async ({ title, slug }) => {
    const boot = JSON.parse(document.getElementById('sb-builder-boot').textContent);
    const call = async (action, body) => {
      const u = new URL(boot.apiUrl, location.href);
      u.searchParams.set('action', action);
      const r = await fetch(u, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': boot.csrfToken }, body: JSON.stringify(body) });
      return { s: r.status, j: await r.json() };
    };
    const created = (await call('create_page', { title, slug, page_type: 'page', route_mode: 'standalone' })).j.data.page;
    const p = await call('publish', { page_id: created.id, expected_revision_id: created.active_draft_revision_id });
    return { id: created.id, path: created.public_path, published: p.s, siteUrl: boot.siteUrl };
  }, { title, slug });
}

/** Archive a page through the builder API (the builder must be open). */
export async function archivePage(page, id) {
  await page.evaluate(async ({ id }) => {
    const boot = JSON.parse(document.getElementById('sb-builder-boot').textContent);
    const u = new URL(boot.apiUrl, location.href);
    u.searchParams.set('action', 'archive_page');
    await fetch(u, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': boot.csrfToken }, body: JSON.stringify({ page_id: id }) });
  }, { id });
}

/** Make sure the site has a post type with this key (Studio ships none built in); creates it on Types & fields if missing. */
export async function ensurePostType(page, key, singular) {
  await page.goto('/plugins/studio-builder/admin/types-fields.php');
  if (await page.locator(`[data-post-type="${key}"]`).count()) return;
  await page.evaluate(() => { document.getElementById('sb-cpt-drawer').style.display = 'block'; });
  const d = page.locator('#sb-cpt-drawer');
  await d.locator('input[name="key"]').fill(key);
  await d.locator('input[name="singular"]').fill(singular);
  await d.getByRole('button', { name: /Save Post Type/ }).click();
  await expect(page.locator(`[data-post-type="${key}"]`)).toHaveCount(1);
}
