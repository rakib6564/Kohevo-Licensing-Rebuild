// In-place text editing on the canvas follows what each block declares (B2-P3d): the declared prop is written,
// a block that declares nothing is not editable in place.
import { test, expect } from '@playwright/test';
import { openBuilder, frameDocument, frameEval, settled, sandboxPageId, openSection } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked Inspector (desktop)');
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
    await expect(undo).toBeEnabled({ timeout: 5_000 });
    await undo.click();
    await page.waitForTimeout(500);
  }
  throw new Error('could not restore the shared sandbox page');
}

async function insertBlock(page, type, chip = 'content') {
  await page.getByRole('tab', { name: /^(Add|Ajouter)$/ }).click();
  const panel = page.locator('#sbx-leftpanel-blocks');
  await panel.getByRole('tab', { name: 'Elements' }).click();
  await panel.locator(`[data-chip="${chip}"]`).click();
  await panel.locator(`[data-block-type="${type}"]`).click();
  await settled(page);
}


async function canvasHtml(page) {
  const src = await page.locator('iframe.sbx-canvas__frame').getAttribute('src');
  return (await page.request.get(`${src}9`)).text();
}

test('double-clicking a heading edits its declared text prop and saves it', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await insertBlock(page, 'core.heading');
    const frame = await frameDocument(page);
    const heading = frame.locator('[data-sb-type="core.heading"] .sb-heading').last();
    await heading.dblclick();
    await expect(heading).toHaveAttribute('contenteditable', 'true');
    await page.keyboard.press('ControlOrMeta+A');
    await page.keyboard.type('Declared and saved');
    await page.keyboard.press('Enter');
    await settled(page);
    await expect.poll(async () => (await canvasHtml(page)).includes('Declared and saved'), { timeout: 20_000 }).toBe(true);
  } finally {
    await restore(page, before);
  }
});

test('a quote edits through its nested declared element', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await insertBlock(page, 'core.quote');
    const frame = await frameDocument(page);
    const text = frame.locator('[data-sb-type="core.quote"] .sb-quote__text p').last();
    await text.dblclick();
    await expect(text).toHaveAttribute('contenteditable', 'true');
    await page.keyboard.press('ControlOrMeta+A');
    await page.keyboard.type('Said and kept');
    await page.keyboard.press('Enter');
    await settled(page);
    await expect.poll(async () => (await canvasHtml(page)).includes('Said and kept'), { timeout: 20_000 }).toBe(true);
    await expect(page.getByRole('alert')).toHaveCount(0);
  } finally {
    await restore(page, before);
  }
});

test('a block that declares no inline text is not editable in place', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await insertBlock(page, 'core.icon', 'media');
    const frame = await frameDocument(page);
    const node = frame.locator('[data-sb-type="core.icon"]').last();
    await node.dblclick();
    await expect(frame.locator('[contenteditable="true"]')).toHaveCount(0);
  } finally {
    await restore(page, before);
  }
});

test('a heading highlights one word, in the accent colour', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await insertBlock(page, 'core.heading');
    const word = page.getByLabel('Highlighted Word', { exact: true });
    await word.fill('Heading');
    await word.blur();
    await settled(page);
    const frame = await frameDocument(page);
    const hl = frame.locator('[data-sb-type="core.heading"] .sb-heading .sb-hl').last();
    await expect(hl).toHaveText('Heading', { timeout: 20_000 });
    await expect.poll(async () => frameEval(page, '[data-sb-type="core.heading"] .sb-heading .sb-hl', (el) => getComputedStyle(el).color !== getComputedStyle(el.parentElement).color), { timeout: 10_000 }).toBe(true);
    await expect(page.getByRole('alert')).toHaveCount(0);
  } finally {
    await restore(page, before);
  }
});

test('rich text can highlight a selection, and the saved page carries only the highlight class', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await insertBlock(page, 'core.rich_text');
    const editor = page.locator('.sbx-rt__editable').first();
    await editor.click();
    await page.keyboard.press('ControlOrMeta+A');
    await page.keyboard.type('Plain and loud');
    await page.keyboard.press('ControlOrMeta+A');
    await page.getByRole('button', { name: 'Highlight', exact: true }).click();
    await settled(page);
    await expect.poll(async () => /<span class="sb-hl">Plain and loud<\/span>/.test(await canvasHtml(page)), { timeout: 20_000 }).toBe(true);
    await expect(page.getByRole('alert')).toHaveCount(0);
  } finally {
    await restore(page, before);
  }
});
