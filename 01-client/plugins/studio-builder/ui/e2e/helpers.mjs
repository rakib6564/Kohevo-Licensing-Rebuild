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
