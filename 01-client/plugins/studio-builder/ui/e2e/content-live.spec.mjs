// A content edit the live patch has no place for still reaches the canvas without a reload: the server's render of the
// saved page is merged into the open frame (same document). Before, a Hero eyebrow typed into an empty field and an
// Accordion item's question were saved but stayed off the canvas until the editor was reloaded.
import { test, expect } from '@playwright/test';
import { layerCount, openBuilder, restoreLayers, settled } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked Inspector (desktop)');
});

const canvasFrame = (page) => page.frames().find((f) => f !== page.mainFrame());
const canvasHas = (page, text) => canvasFrame(page).evaluate((t) => document.body.textContent.includes(t), text);

async function insertAndOpenContent(page, type) {
  await page.getByRole('tab', { name: /^(Add|Ajouter)$/ }).click();
  const panel = page.locator('#sbx-leftpanel-blocks');
  await panel.getByRole('tab', { name: 'Elements' }).click();
  await panel.locator(`[data-block-type="${type}"]`).first().click();
  await settled(page);
  await page.locator('[id^="sbx-blk-"][id$="-tab-content"]').click();
}

for (const { type, label } of [
  { type: 'core.hero', label: 'Eyebrow' },
  { type: 'core.accordion', label: /^Question/ },
]) {
  test(`${type}: typing into "${label}" shows on the canvas without a reload`, async ({ page }) => {
    await openBuilder(page);
    await settled(page);
    const before = await layerCount(page);
    try {
      await insertAndOpenContent(page, type);
      const marker = `Live ${Date.now().toString(36)}`;
      await canvasFrame(page).evaluate(() => { window.__sbxMarker = 'same-document'; });
      const field = page.locator('#sbx-leftpanel-inspector').getByLabel(label).first();
      await field.fill(marker);
      await field.blur();
      await expect.poll(() => canvasHas(page, marker), { timeout: 20_000 }).toBe(true);
      expect(await canvasFrame(page).evaluate(() => window.__sbxMarker)).toBe('same-document');
    } finally {
      await restoreLayers(page, before);
    }
  });
}
