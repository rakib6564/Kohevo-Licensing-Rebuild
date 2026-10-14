// The Structure window: the page outline floating over the editor, opened from the top bar or with Ctrl/⌘ I.
import { test, expect } from '@playwright/test';
import { openBuilder, settled, sandboxPageId, undoReady } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'floating window (desktop)');
});

test.afterEach(async ({ page }) => {
  await page.goto(`/plugins/studio-builder/admin/builder.php?page=${sandboxPageId()}&lang=en`);
});

/** Put one section on the shared sandbox page, run the check, and undo that section again. */
async function withSection(page, run) {
  await openBuilder(page);
  await settled(page);
  const canvas = page.frameLocator('iframe.sbx-canvas__frame');
  const sections = canvas.locator('[data-sb-node^="sec_"]');
  await expect(canvas.locator('body')).toBeAttached();
  await page.waitForTimeout(1500);
  const initial = await sections.count();
  await page.getByRole('tab', { name: /^Add$/ }).click();
  await page.getByRole('tab', { name: 'Sections' }).click();
  await page.locator('#sbx-leftpanel-blocks [data-preset="system-section-team"] .sbx-preset-card__insert').click();
  await settled(page);
  await expect(sections).toHaveCount(initial + 1);
  await expect(canvas.locator('[data-sb-node^="blk_"]').first()).toBeVisible();
  try {
    await run();
  } finally {
    for (let i = 0; i < 16; i++) {
      await settled(page);
      if ((await sections.count()) <= initial) break;
      const undo = page.getByTestId('undo');
      if (!(await undoReady(undo))) break; // nothing left to undo: stop quietly so the test's own error is the one reported
      await undo.click();
      await page.waitForTimeout(500);
    }
  }
}

test('the top bar opens a floating outline that can be dragged and closed', async ({ page }) => {
  await withSection(page, async () => {
    const win = page.getByTestId('structure-window');
    await expect(win).toHaveCount(0);
    await page.getByTestId('toggle-structure').click();
    await expect(win).toBeVisible();
    await expect(win.locator('[role="treeitem"]').first()).toBeVisible();

    const before = await win.boundingBox();
    const bar = await page.getByTestId('structure-window-bar').boundingBox();
    await page.mouse.move(bar.x + 40, bar.y + 10);
    await page.mouse.down();
    await page.mouse.move(bar.x - 160, bar.y + 90, { steps: 6 });
    await page.mouse.up();
    const after = await win.boundingBox();
    expect(Math.round(before.x - after.x)).toBeGreaterThan(100);
    expect(Math.round(after.y - before.y)).toBeGreaterThan(60);

    await page.getByTestId('structure-window-close').click();
    await expect(win).toHaveCount(0);
  });
});

test('picking a row in the window selects it, and Ctrl+I toggles the window', async ({ page }) => {
  await withSection(page, async () => {
    await page.locator('body').click({ position: { x: 4, y: 4 } });
    await page.keyboard.press('Control+i');
    const win = page.getByTestId('structure-window');
    await expect(win).toBeVisible();
    await win.locator('[role="treeitem"]').first().click();
    await expect(page.locator('.sbx-overlay__box.is-primary')).toHaveCount(1);
    await page.keyboard.press('Control+i');
    await expect(win).toHaveCount(0);
  });
});

test('Escape inside the window puts it away', async ({ page }) => {
  await withSection(page, async () => {
    await page.getByTestId('toggle-structure').click();
    const win = page.getByTestId('structure-window');
    await win.locator('[role="treeitem"]').first().focus();
    await page.keyboard.press('Escape');
    await expect(win).toHaveCount(0);
  });
});
