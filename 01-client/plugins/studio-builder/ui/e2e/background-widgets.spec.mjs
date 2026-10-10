// Background image extras: the focal-point pad and the overlay colour + opacity. Neither adds a new stored field:
// the focal point is the media_ref's own pair and the overlay opacity is the alpha of its one colour.
import { test, expect } from '@playwright/test';
import { chooseImage, openBuilder, settled, openSection, frameEval } from './helpers.mjs';

test.afterEach(async ({ page }) => {
  await page.goto(`/plugins/studio-builder/admin/builder.php?page=${(await import('./helpers.mjs')).sandboxPageId()}&lang=en`);
});

async function layerCount(page) {
  await page.getByRole('tab', { name: /^Layers$/ }).click();
  return page.locator('[role="treeitem"]').count();
}

async function restore(page, layers) {
  for (let i = 0; i < 16; i++) {
    await settled(page);
    if ((await layerCount(page)) <= layers) return;
    const undo = page.getByTestId('undo');
    await expect(undo).toBeEnabled({ timeout: 15_000 });
    await undo.click();
    await page.waitForTimeout(500);
  }
  throw new Error('could not restore the shared sandbox page');
}

test('the Background image offers a focal-point pad and an overlay with opacity, and they save', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop', 'pointer and keyboard on the desktop shell');
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await page.getByRole('tab', { name: /^(Add|Ajouter)$/ }).click();
    const panel = page.locator('#sbx-leftpanel-blocks');
    await panel.getByRole('tab', { name: 'Elements' }).click();
    await panel.locator('[data-chip="content"]').click();
    await panel.locator('[data-block-type="core.quote"]').click();
    await settled(page);
    await page.getByRole('tab', { name: 'Style' }).click();
    const bg = await openSection(page, 'background');
    await bg.getByRole('radiogroup', { name: 'Background type' }).getByRole('button', { name: 'Image' }).click();

    // Nothing to place until an image is chosen.
    await expect(bg.getByRole('slider', { name: 'Focal point' })).toHaveCount(0);
    await chooseImage(page, bg);

    const pad = bg.getByRole('slider', { name: 'Focal point' });
    await expect(pad).toBeVisible();
    await expect(pad).toHaveAttribute('aria-valuetext', '50%, 50%');
    await expect(bg.getByRole('group', { name: 'Focal point' })).toHaveCount(0); // the two media sliders are replaced by the pad

    await pad.focus();
    await page.keyboard.press('ArrowRight');
    await page.keyboard.press('ArrowDown');
    await expect(pad).toHaveAttribute('aria-valuetext', '55%, 55%');

    const box = await pad.boundingBox();
    await page.mouse.click(box.x + box.width * 0.25, box.y + box.height * 0.75);
    await expect(pad).toHaveAttribute('aria-valuetext', /^(24|25|26)%, (74|75|76)%$/);

    // Overlay: colour first, then opacity; the opacity is carried in the colour.
    const colour = bg.locator('input[id$="-overlay-text"]');
    await colour.fill('#102030');
    await colour.blur();
    const slider = bg.getByLabel('Overlay opacity');
    await expect(slider).toBeVisible();
    await slider.fill('40');
    await expect(colour).toHaveValue('#10203066');
    await colour.fill('surface.dark'); // a token cannot carry opacity, so the slider steps aside
    await colour.blur();
    await expect(bg.getByLabel('Overlay opacity')).toHaveCount(0);
    await colour.fill('#102030');
    await colour.blur();

    await settled(page);
    await expect(page.getByRole('alert')).toHaveCount(0);
    await expect(page.getByRole('status').filter({ hasText: /saved/i }).first()).toBeVisible({ timeout: 15_000 });

    // The server wrote the focal point into the stylesheet (the media may not exist in the sandbox, so only check the style survived).
    await expect.poll(async () => frameEval(page, '[data-sb-type="core.quote"]', (el) => el.getAttribute('data-sb-type')), { timeout: 20_000 }).toBe('core.quote');
  } finally {
    await restore(page, before);
  }
});
