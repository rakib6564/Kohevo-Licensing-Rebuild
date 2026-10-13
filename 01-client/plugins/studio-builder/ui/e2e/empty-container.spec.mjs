// An empty container invites content: "Drag widget here" with Add, template and AI buttons, and a freshly added
// Container first asks which layout it should use (Flexbox or Grid).
import { test, expect } from '@playwright/test';
import { openBuilder, settled, sandboxPageId, frameDocument } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked left panel (desktop)');
});

test.afterEach(async ({ page }) => {
  await page.goto(`/plugins/studio-builder/admin/builder.php?page=${sandboxPageId()}&lang=en`);
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

async function addContainer(page) {
  await page.getByRole('tab', { name: /^Add$/ }).click();
  const panel = page.locator('#sbx-leftpanel-blocks');
  await panel.getByRole('tab', { name: 'Elements' }).click();
  await panel.locator('[data-chip="layout"]').click();
  await panel.locator('[data-block-type="core.container"]').click();
  await settled(page);
}

const css = (loc, prop) => loc.evaluate((el, p) => getComputedStyle(el)[p], prop);

test('a new Container asks for its layout; Grid makes it a grid, and the empty box then invites content', async ({ page }) => {
  test.setTimeout(90_000);
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await addContainer(page);
    const chooser = page.getByTestId('layout-chooser');
    await expect(chooser).toBeVisible();
    await expect(chooser).toContainText('Which layout would you like to use?');

    await chooser.locator('[data-layout="grid"]').click();
    await expect(chooser).toHaveCount(0);
    const box = (await frameDocument(page)).locator('[data-sb-type="core.container"]').first();
    await expect.poll(() => css(box, 'display'), { timeout: 15_000 }).toBe('grid');

    const zone = page.getByTestId('overlay-empty');
    await expect(zone).toBeVisible();
    await expect(zone).toContainText('Drag widget here');
    await expect(zone.getByTestId('zone-add')).toBeVisible();
    await expect(zone.getByTestId('zone-templates')).toBeVisible();
    await expect(zone.getByTestId('zone-ai')).toBeVisible();
  } finally {
    await restore(page, before);
  }
});

test('the chooser closes, Flexbox keeps the container as it is, and Add on the empty box opens the Elements tab', async ({ page }) => {
  test.setTimeout(90_000);
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await addContainer(page);
    await page.getByTestId('layout-chooser').locator('[data-layout="flex"]').click();
    await expect(page.getByTestId('layout-chooser')).toHaveCount(0);
    const box = (await frameDocument(page)).locator('[data-sb-type="core.container"]').first();
    expect(await css(box, 'display')).toBe('flex');

    await page.getByTestId('zone-add').click();
    await expect(page.getByRole('tab', { name: /^Add$/ })).toHaveAttribute('aria-selected', 'true');
    await expect(page.locator('#sbx-leftpanel-blocks').getByRole('tab', { name: 'Elements' })).toHaveAttribute('aria-selected', 'true');
    // the container is selected, so the next element lands inside it and the box stops being empty
    await page.locator('#sbx-leftpanel-blocks [data-chip="content"]').click();
    await page.locator('#sbx-leftpanel-blocks [data-block-type="core.heading"]').click();
    await settled(page);
    await expect(page.getByTestId('overlay-empty')).toHaveCount(0);
    await expect(box.locator('[data-sb-type="core.heading"]')).toHaveCount(1);
  } finally {
    await restore(page, before);
  }
});

test('the chooser can be dismissed with its close button', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await addContainer(page);
    await page.getByTestId('chooser-close').click();
    await expect(page.getByTestId('layout-chooser')).toHaveCount(0);
    await expect(page.getByTestId('overlay-empty')).toBeVisible(); // the plain invitation stays
  } finally {
    await restore(page, before);
  }
});
