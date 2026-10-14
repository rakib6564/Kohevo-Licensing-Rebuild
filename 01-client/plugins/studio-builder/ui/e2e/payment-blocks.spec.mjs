// The payment blocks: a fixed-amount button (signed by the server, so the price cannot be edited by a visitor) and a Stripe
// Payment Link button. In the editor canvas they are cards, never a working form.
import { test, expect } from '@playwright/test';
import { openBuilder, settled, sandboxPageId, showEdit, frameEval, frameDocument, restoreLayers, layerCount } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked Add panel (desktop)');
});

test.afterEach(async ({ page }) => {
  await page.goto(`/plugins/studio-builder/admin/builder.php?page=${sandboxPageId()}&lang=en`);
});

const panel = (page) => page.locator('#sbx-leftpanel-blocks');

/** The block the editor has selected (just inserted), so a check never reads another block on the shared sandbox page. */
async function selectedBlock(page) {
  const box = page.locator('.sbx-overlay__box.is-primary');
  await expect(box).toHaveCount(1, { timeout: 20_000 });
  return box.getAttribute('data-overlay-for');
}
const inBlock = (id, selector) => `[data-sb-node="${id}"] ${selector}`;

async function openComponents(page) {
  await page.getByRole('tab', { name: /^(Add|Ajouter)$/ }).click();
  await panel(page).getByRole('tab', { name: 'Components' }).click();
}

test('both payment blocks are offered under Kohevo components and can be added', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await openComponents(page);
    const comps = panel(page).getByTestId('palette-components');
    await expect(comps.locator('[data-block-type="payment.button"]')).toBeEnabled();
    await expect(comps.locator('[data-block-type="payment.link"]')).toBeEnabled();
  } finally {
    await restoreLayers(page, before);
  }
});

test('the payment button shows what visitors will pay on the canvas, follows the amount, and is never a form', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await openComponents(page);
    await panel(page).getByTestId('palette-components').locator('[data-block-type="payment.button"]').click();
    await settled(page);
    const id = await selectedBlock(page);
    await expect.poll(() => frameEval(page, inBlock(id, '.sb-pay-card__summary'), (el) => el.textContent), { timeout: 20_000 }).toContain('10.00 USD');
    expect(await (await frameDocument(page)).locator(inBlock(id, 'form, input[type="hidden"]')).count()).toBe(0); // the editor canvas never gets a working form

    await showEdit(page);
    const amount = page.getByLabel('Amount', { exact: true });
    await amount.fill('25');
    await amount.blur();
    await expect.poll(() => frameEval(page, inBlock(id, '.sb-pay-card__summary'), (el) => el.textContent), { timeout: 20_000 }).toContain('25.00 USD');
    await expect(page.getByRole('alert')).toHaveCount(0);
  } finally {
    await restoreLayers(page, before);
  }
});

test('the Stripe link block accepts a Stripe address and refuses any other', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await openComponents(page);
    await panel(page).getByTestId('palette-components').locator('[data-block-type="payment.link"]').click();
    await settled(page);
    const id = await selectedBlock(page);
    await expect.poll(() => frameEval(page, inBlock(id, '.sb-embed-card__note'), (el) => el.textContent), { timeout: 20_000 }).toContain('Paste a Stripe Payment Link');

    await showEdit(page);
    const url = page.getByLabel('Stripe link', { exact: true });
    await url.fill('https://buy.stripe.com/test_123');
    await url.blur();
    await expect.poll(() => frameEval(page, inBlock(id, '.sb-embed-card__note'), (el) => el.textContent), { timeout: 20_000 }).toContain('go to your Stripe page');

    await url.fill('https://evil.test/pay');
    await url.blur();
    await settled(page);
    // The server refuses the address when the draft is saved, and the canvas keeps the last good card.
    await expect(page.getByRole('alert').first()).toBeVisible({ timeout: 20_000 });
  } finally {
    await restoreLayers(page, before);
  }
});
