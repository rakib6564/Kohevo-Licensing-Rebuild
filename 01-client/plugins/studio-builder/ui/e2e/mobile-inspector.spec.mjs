// The Inspector on a phone (B2-P3d): the same registry in the sheet, with touch-sized controls, 16px text fields
// (so iOS does not zoom on focus), and a way to open the sheet to its full height.
import { test, expect } from '@playwright/test';
import { openBuilder, frameDocument, settled, openSection } from './helpers.mjs';

const dock = (page, key) => page.getByTestId(`mobile-dock-${key}`);

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name === 'desktop', 'phone shell');
});

async function insertHeading(page) {
  await dock(page, 'blocks').click();
  await page.getByRole('tab', { name: 'Elements' }).click();
  await page.locator('[data-chip="content"]').click();
  await page.locator('[data-block-type="core.heading"]').click();
  await settled(page);
  await expect(page.getByTestId('sheet-expand')).toBeVisible(); // adding a block opens its Inspector
}

async function removeInsertedBlock(page) {
  for (let i = 0; i < 10; i++) {
    const undo = page.getByTestId('undo');
    if (!(await undo.isEnabled())) return;
    await undo.click({ force: true });
    await page.waitForTimeout(400);
  }
}

test('every control in the Inspector sheet is a touch target, and text fields are 16px', async ({ page }) => {
  await openBuilder(page);
  try {
    await insertHeading(page);
    for (const tab of ['content', 'style', 'advanced']) {
      await page.locator(`[id^="sbx-blk-"][id$="-tab-${tab}"]`).click();
      if (tab === 'style') { await openSection(page, 'layout'); await openSection(page, 'spacing'); await openSection(page, 'effects'); }
      if (tab === 'advanced') { await openSection(page, 'identity'); await openSection(page, 'attributes'); }
      const small = await page.evaluate(() => {
        const out = [];
        for (const el of document.querySelectorAll('.sbx-left .sbx-inspector button, .sbx-left .sbx-inspector input:not([type="color"]):not([type="hidden"]), .sbx-left .sbx-inspector select, .sbx-left .sbx-inspector textarea')) {
          if (!el.offsetParent) continue;
          const r = el.getBoundingClientRect();
          const cs = getComputedStyle(el);
          const isText = el.tagName === 'SELECT' || el.tagName === 'TEXTAREA' || ['text', 'number', 'search', ''].includes(el.getAttribute('type') || '');
          const label = (el.getAttribute('aria-label') || el.textContent || el.id || el.className).toString().trim().slice(0, 30);
          if (r.height < 43.5 && el.getAttribute('type') !== 'checkbox' && el.getAttribute('type') !== 'range') out.push(`${el.tagName} "${label}" is ${Math.round(r.width)}x${Math.round(r.height)}`);
          if (isText && parseFloat(cs.fontSize) < 16) out.push(`${el.tagName} "${label}" has ${cs.fontSize} text`);
        }
        return out;
      });
      expect(small, `too small on the ${tab} tab`).toEqual([]);
    }
  } finally {
    await removeInsertedBlock(page);
  }
});

test('Full panel opens the sheet to the top of the screen and Half panel brings it back', async ({ page }) => {
  await openBuilder(page);
  try {
    await insertHeading(page);
    const sheet = page.locator('.sbx-left.is-mobile-open');
    const visible = await page.evaluate(() => (window.visualViewport ? window.visualViewport.height : window.innerHeight));
    const viewport = { height: visible };
    const expand = page.getByTestId('sheet-expand');
    await expect(expand).toHaveText('Full panel');
    const before = (await sheet.boundingBox()).height;
    await expand.click();
    await expect(expand).toHaveAttribute('aria-pressed', 'true');
    await expect(expand).toHaveText('Half panel');
    await expect.poll(async () => (await sheet.boundingBox()).height).toBeGreaterThan(viewport.height - 40);
    await expand.click();
    await expect(expand).toHaveText('Full panel');
    await expect.poll(async () => (await sheet.boundingBox()).height).toBeLessThan(viewport.height - 40);
    expect(before).toBeLessThan(viewport.height - 40);
  } finally {
    await removeInsertedBlock(page);
  }
});

test('the Inspector works on a phone: the search box is styled, and an edit saves', async ({ page }) => {
  await openBuilder(page);
  try {
    await insertHeading(page);
    const search = page.getByRole('searchbox', { name: 'Search settings' });
    expect(await search.evaluate((el) => getComputedStyle(el).backgroundColor)).not.toBe('rgb(255, 255, 255)'); // it was an unstyled white box
    await search.fill('spacing');
    await expect(page.locator('[data-section="spacing"]')).toBeVisible();
    await search.fill('');

    const text = page.getByLabel('Heading Text', { exact: false }).first();
    await text.fill('Phone edit');
    await text.blur();
    await settled(page);
    const frame = await frameDocument(page);
    await expect(frame.locator('[data-sb-type="core.heading"] .sb-heading').last()).toHaveText('Phone edit', { timeout: 20_000 });
  } finally {
    await removeInsertedBlock(page);
  }
});
