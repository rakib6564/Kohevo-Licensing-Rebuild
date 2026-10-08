import fs from 'node:fs';
import path from 'node:path';
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
