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

test('top bar: no control overlaps another and nothing is clipped', async ({ page }) => {
  await openBuilder(page);
  const result = await page.evaluate(() => {
    const header = document.querySelector('header.sbx-topbar');
    const units = [...header.querySelectorAll([
      '.sbx-brand-badge', '.sbx-topbar__back-btn', '.sbx-page-switcher__select', '.sbx-topbar__page-title',
      '.sbx-status-pill', '.sbx-topbar__rev-badge', '.sbx-status', '.sbx-status-chip', '.sbx-badge',
      '.sbx-view-mode-btn', '.sbx-viewport-group .sbx-btn', '.sbx-inspector-toggle', '.sbx-topbar__history-group .sbx-btn',
      '.sbx-topbar__responsive-btn', '.sbx-topbar__tools > *', '.sbx-topbar__cta-group > *',
    ].join(','))].map((el) => ({
      name: (el.getAttribute('data-testid') || el.getAttribute('aria-label') || el.className || el.tagName).toString().slice(0, 40),
      r: el.getBoundingClientRect(),
    })).filter((u) => u.r.width > 0 && u.r.height > 0);
    const clashes = [];
    for (let i = 0; i < units.length; i++) {
      for (let j = i + 1; j < units.length; j++) {
        const a = units[i].r; const b = units[j].r;
        const x = Math.min(a.right, b.right) - Math.max(a.left, b.left);
        const y = Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top);
        if (x > 2 && y > 2) clashes.push(`${units[i].name} x ${units[j].name}`);
      }
    }
    const h = header.getBoundingClientRect();
    const outside = units.filter((u) => u.r.right > h.right + 1 || u.r.left < h.left - 1).map((u) => u.name);
    const left = header.querySelector('.sbx-topbar__left');
    return { clashes, outside, leftClipped: left.scrollWidth - left.clientWidth };
  });
  expect(result.clashes, 'overlapping controls').toEqual([]);
  expect(result.outside, 'controls outside the bar').toEqual([]);
  expect(result.leftClipped, 'the left group clips its content by this many px').toBeLessThanOrEqual(1);
});
