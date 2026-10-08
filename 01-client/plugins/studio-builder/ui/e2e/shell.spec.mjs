// Builder shell smoke + canvas security invariants (B2-P1 harness baseline).
import { test, expect } from '@playwright/test';
import { openBuilder, frameDocument } from './helpers.mjs';

test('the builder shell loads without script errors', async ({ page }) => {
  const { errors } = await openBuilder(page);
  await expect(page.locator('header.sbx-topbar')).toBeVisible();
  await expect(page.locator('iframe.sbx-canvas__frame')).toBeVisible();
  expect(errors, `uncaught page errors: ${errors.join(' | ')}`).toEqual([]);
});

test('the canvas frame is script-less and nothing from the parent is injected into it', async ({ page }) => {
  await openBuilder(page);
  const sandbox = await page.locator('iframe.sbx-canvas__frame').getAttribute('sandbox');
  expect(sandbox).toContain('allow-same-origin');
  expect(sandbox).not.toContain('allow-scripts');

  const frame = await frameDocument(page);
  expect(await frame.locator('script').count(), 'no <script> in the canvas document').toBe(0);
  // Handlers and inline JS must never be present in rendered nodes either.
  const handlers = await frame.evaluate(() => [...document.querySelectorAll('*')]
    .flatMap((el) => [...el.attributes].filter((a) => /^on/i.test(a.name)).map((a) => `${el.tagName}.${a.name}`)));
  expect(handlers).toEqual([]);
});

test('adding a section from the Layers panel puts it in the canvas', async ({ page }, testInfo) => {
  // The Layers panel is a sheet below 860px; mobile flows are covered by the mobile-shell suite.
  test.skip(testInfo.project.name !== 'desktop', 'desktop docked panel only');
  await openBuilder(page);
  const frame = await frameDocument(page);
  const before = await frame.locator('[data-sb-node]').count();
  await page.getByRole('button', { name: /Add section/ }).first().click();
  await expect.poll(async () => (await frameDocument(page)).locator('[data-sb-node]').count(), { timeout: 15_000 }).toBeGreaterThan(before);
});

test('the shell never scrolls horizontally', async ({ page }) => {
  await openBuilder(page);
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow, 'horizontal overflow in px').toBeLessThanOrEqual(0);
});

test('top bar controls do not overlap each other', async ({ page }, testInfo) => {
  // Known B2-P1 defect: at 390px the status chip and Publish collide. Remove this line with the mobile-shell fix.
  test.fixme(testInfo.project.name === 'mobile', 'two-row compact top bar not built yet (B2-P1 mobile shell)');
  await openBuilder(page);
  const boxes = await page.evaluate(() => [...document.querySelectorAll('header.sbx-topbar button, header.sbx-topbar [role="status"], header.sbx-topbar select')]
    .map((el) => ({ name: (el.getAttribute('aria-label') || el.textContent || el.tagName).trim().slice(0, 24), r: el.getBoundingClientRect() }))
    .filter((b) => b.r.width > 0 && b.r.height > 0));
  const clashes = [];
  for (let i = 0; i < boxes.length; i++) {
    for (let j = i + 1; j < boxes.length; j++) {
      const a = boxes[i].r; const b = boxes[j].r;
      const x = Math.min(a.right, b.right) - Math.max(a.left, b.left);
      const y = Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top);
      if (x > 2 && y > 2) clashes.push(`${boxes[i].name} × ${boxes[j].name}`);
    }
  }
  expect(clashes).toEqual([]);
});
