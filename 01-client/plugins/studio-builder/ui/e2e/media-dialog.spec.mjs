// The builder's own image picker: choose or upload an image from the Inspector, see it, set its focal point, and
// never see a raw "Media ID" box. No other plugin is involved.
import { test, expect } from '@playwright/test';
import { chooseImage, openBuilder, settled } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked Inspector (desktop)');
});

/** Insert an Image block: the "Set up Image" step asks for the image, then adds it. Returns that step's scope. */
async function startImageBlock(page) {
  await page.getByRole('tab', { name: /^Add$/ }).click();
  const panel = page.locator('#sbx-leftpanel-blocks');
  await panel.getByRole('tab', { name: 'Elements' }).click();
  await panel.locator('[data-block-type="core.image"]').first().click();
  const setup = page.locator('dialog', { hasText: 'Set up Image' });
  await expect(setup).toBeVisible();
  return setup;
}
async function addImageBlock(page) {
  const setup = await startImageBlock(page);
  await chooseImage(page, setup);
  await setup.getByRole('button', { name: 'Add', exact: true }).click();
  await settled(page);
  // select the new block from Layers (a 1x1 test image is too small to click on the canvas)
  await page.getByRole('tab', { name: /^Layers$/ }).click();
  const section = page.locator('[role="treeitem"][aria-level="1"]').first();
  if ((await section.getAttribute('aria-expanded')) === 'false') { await section.focus(); await page.keyboard.press('ArrowRight'); }
  await page.locator('[role="treeitem"]', { hasText: 'Image' }).last().click();
  await expect(page.locator('.sbx-inspector-host').getByTestId('media-choose')).toBeVisible();
}

test('an Image block picks its image in a dialog, previews it, and keeps a clickable focal point and alt text', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  await addImageBlock(page);

  const host = page.locator('.sbx-inspector-host');
  await expect(host.getByLabel('Media ID')).toHaveCount(0);
  await expect(host.getByTestId('media-choose')).toHaveText('Replace image');
  const pad = host.getByRole('slider', { name: 'Image focal point' });
  await expect(pad).toBeVisible();
  await expect(pad.locator('img')).toHaveAttribute('src', /\/uploads\/|^https?:/);
  await expect(host.getByLabel('Alternative text')).not.toHaveValue('');

  const box = await pad.boundingBox();
  await page.mouse.click(box.x + box.width * 0.8, box.y + box.height * 0.2);
  await expect(pad).toHaveAttribute('aria-valuetext', /X (79|80|81)%, Y (19|20|21)%/);

  await settled(page);
  const frame = page.frameLocator('iframe.sbx-canvas__frame');
  await expect(frame.locator('[data-sb-type="core.image"] img').first()).toBeVisible();
});

test('the dialog searches, refuses a file that is not an image, and closes with Escape', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const setup = await startImageBlock(page);
  await setup.getByTestId('media-choose').first().click();
  const dialog = page.getByTestId('media-dialog');
  await expect(dialog).toBeVisible();

  await dialog.getByRole('searchbox').fill('zzz-no-such-image-zzz');
  await expect(dialog.getByTestId('media-empty')).toContainText('No image matches your search.');
  await dialog.getByRole('searchbox').fill('');

  await dialog.getByTestId('media-file').setInputFiles({ name: 'notes.txt', mimeType: 'text/plain', buffer: Buffer.from('hello') });
  await expect(dialog.getByTestId('media-error')).toContainText('Only JPG, PNG, GIF, WebP or SVG');

  await page.keyboard.press('Escape');
  await expect(dialog).toHaveCount(0);
});
