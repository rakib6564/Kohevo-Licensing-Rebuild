// A boxed Container's width shows on the canvas the moment it is picked (not after the save and the repaint), the presets
// are labelled with this site's sizes, and Custom takes a size per device.
import { test, expect } from '@playwright/test';
import { openBuilder, frameEval, settled, layerCount, restoreLayers } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked Inspector (desktop)');
});

// The width kept for the children: the element's content box, plus the site's gutter on both sides (the box is drawn by side
// padding on the one element, and `gutter: site` sits inside the limit, as the inner element's own padding did).
const GUTTER = 16;
const boxWidth = (page) => frameEval(page, '.sb-top[data-sb-node]', (el) => {
  const s = getComputedStyle(el);
  return Math.round(el.clientWidth - parseFloat(s.paddingLeft) - parseFloat(s.paddingRight));
});

test('a boxed width is painted at once, and a custom width is kept per device', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  // The autosave is held: whatever the canvas shows meanwhile, it did not get from the server.
  let release;
  const gate = new Promise((resolve) => { release = resolve; });
  try {
    await page.getByRole('tab', { name: /^(Add|Ajouter)$/ }).click();
    const panel = page.locator('#sbx-leftpanel-blocks');
    await panel.getByRole('tab', { name: 'Elements' }).click();
    await panel.locator('[data-chip="content"]').click();
    await panel.locator('[data-block-type="core.heading"]').click();
    await settled(page);

    // Select the new Container by its top edge (its padding, not the heading).
    const frame = page.frameLocator('iframe.sbx-canvas__frame');
    await frame.locator('.sb-top[data-sb-node]').last().click({ position: { x: 4, y: 4 } });
    const width = page.locator('select[id$="-box-width"]');
    await expect(width).toHaveValue('default');
    await expect(width.locator('option')).toHaveText(['Default · 1280px', 'Narrow · 672px', 'Normal · 1024px', 'Wide · 1280px', 'Full width · no limit', 'Custom']);

    await page.route('**/admin/api.php?action=operations*', async (route) => { await gate; await route.continue(); });
    await width.selectOption('narrow');
    await expect.poll(() => boxWidth(page), { timeout: 2_000 }).toBe(672 - 2 * GUTTER);
    await width.selectOption('normal');
    await expect.poll(() => boxWidth(page), { timeout: 2_000 }).toBe(1024 - 2 * GUTTER);
    release();
    await page.unrouteAll({ behavior: 'ignoreErrors' });
    await settled(page);

    await width.selectOption('custom');
    const size = page.getByRole('spinbutton', { name: 'Custom width', exact: true });
    await size.fill('900');
    await size.blur();
    await expect(size).toHaveValue('900');
    await expect.poll(() => boxWidth(page), { timeout: 2_000 }).toBe(900 - 2 * GUTTER);
    // The server's render agrees once the save lands: the frame repaints with the same limit.
    await settled(page);
    await expect.poll(() => frameEval(page, '.sb-top[data-sb-node]', (el) => getComputedStyle(el).getPropertyValue('--sb-box').trim()), { timeout: 20_000 }).toBe('900px');

    // A phone has its own limit, and a preset takes the device's width away again.
    await page.getByRole('group', { name: /Custom width — / }).getByRole('button', { name: 'Mobile' }).click();
    await expect(size).toHaveValue(''); // a device starts empty and inherits Desktop's width
    await size.fill('320');
    await size.blur();
    await expect(page.getByRole('group', { name: /Custom width — / }).getByRole('button', { name: 'Mobile' })).toHaveClass(/is-set/);
    await settled(page);
    await page.getByRole('group', { name: /Custom width — / }).getByRole('button', { name: 'Desktop' }).click();
    await width.selectOption('normal');
    await expect(page.getByRole('spinbutton', { name: 'Custom width', exact: true })).toHaveCount(0);
    await width.selectOption('custom');
    await expect(page.getByRole('group', { name: /Custom width — / }).getByRole('button', { name: 'Mobile' })).not.toHaveClass(/is-set/);
  } finally {
    release();
    await page.unrouteAll({ behavior: 'ignoreErrors' });
    await restoreLayers(page, before);
  }
});
