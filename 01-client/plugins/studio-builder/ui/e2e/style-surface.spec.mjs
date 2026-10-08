// The style-surface controls (B2-P3c slice 4): layout, spacing, states, wrapper tag and section style write
// values the server keeps, and the canvas shows them.
import { test, expect } from '@playwright/test';
import { openBuilder, frameDocument, settled, sandboxPageId, openSection } from './helpers.mjs';

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

async function insertQuote(page) {
  await page.getByRole('tab', { name: /^(Add|Ajouter)$/ }).click();
  const panel = page.locator('#sbx-leftpanel-blocks');
  await panel.getByRole('tab', { name: 'Elements' }).click();
  await panel.locator('[data-chip="content"]').click();
  await panel.locator('[data-block-type="core.quote"]').click();
  await settled(page);
}

const styleTab = (page) => page.locator('[id^="sbx-blk-"][id$="-tab-style"]').click();
const advancedTab = (page) => page.locator('[id^="sbx-blk-"][id$="-tab-advanced"]').click();
const errors = (page) => page.getByRole('alert').filter({ hasText: /Not a valid value/ });

/** The quote's wrapper in the canvas (the server writes style on it). */
async function wrapper(page) {
  const frame = await frameDocument(page);
  return frame.locator('[data-sb-type="core.quote"]').last();
}

test('Layout offers flex controls only for flex, refuses a bare number, and the canvas shows what is kept', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await insertQuote(page);
    await styleTab(page);
    await openSection(page, 'layout');
    const section = page.locator('[data-section="layout"]');
    await expect(section.getByRole('group', { name: 'Direction' })).toHaveCount(0); // nothing to direct until it is flex
    await section.getByRole('button', { name: 'Flex', exact: true }).click();
    await expect(section.getByRole('group', { name: 'Direction' })).toBeVisible();
    await section.getByRole('button', { name: 'Column', exact: true }).click();

    const gap = section.getByLabel('Gap', { exact: true });
    await gap.fill('12');
    await gap.blur();
    await expect(errors(page)).toBeVisible(); // a length needs a unit
    await gap.fill('18px');
    await gap.blur();
    await expect(errors(page)).toHaveCount(0);
    await settled(page);
    await expect(page.locator('[data-section="layout"] .sbx-isec__toggle')).toContainText('flex · column · 18px');

    const w = await wrapper(page);
    await expect.poll(async () => w.evaluate((el) => `${getComputedStyle(el).display}/${getComputedStyle(el).flexDirection}/${getComputedStyle(el).rowGap}`), { timeout: 20_000 }).toBe('flex/column/18px');
  } finally {
    await restore(page, before);
  }
});

test('Spacing links the four sides by default, so one value sets them all', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await insertQuote(page);
    await styleTab(page);
    await openSection(page, 'spacing');
    const margin = page.getByRole('group', { name: 'Margin' });
    await margin.getByLabel('Top', { exact: true }).fill('24px');
    await margin.getByLabel('Top', { exact: true }).blur();
    await settled(page);
    for (const side of ['Right', 'Bottom', 'Left']) await expect(margin.getByLabel(side, { exact: true })).toHaveValue('24px');

    await margin.getByRole('button', { name: /Sides linked/ }).click(); // unlink
    await margin.getByLabel('Left', { exact: true }).fill('0');
    await margin.getByLabel('Left', { exact: true }).blur();
    await expect(margin.getByLabel('Top', { exact: true })).toHaveValue('24px');
    await expect(margin.getByLabel('Left', { exact: true })).toHaveValue('0');

    const w = await wrapper(page);
    await expect.poll(async () => w.evaluate((el) => `${getComputedStyle(el).marginTop}/${getComputedStyle(el).marginLeft}`), { timeout: 20_000 }).toBe('24px/0px');
  } finally {
    await restore(page, before);
  }
});

test('a hover state is saved and reaches the page as a :hover rule', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await insertQuote(page);
    await styleTab(page);
    await openSection(page, 'states');
    const section = page.locator('[data-section="states"]');
    await expect(section.getByText(/Normal is the base style/)).toBeVisible();
    await section.getByRole('tab', { name: 'Hover' }).click();
    const colour = section.locator('input[id$="-color-text"]');
    await colour.fill('#ff0000');
    await settled(page);
    await expect(section.getByRole('tab', { name: 'Hover' })).toHaveClass(/has-value/);

    const src = await page.locator('iframe.sbx-canvas__frame').getAttribute('src');
    await expect.poll(async () => {
      const html = await (await page.request.get(`${src}9`)).text();
      return /:hover\{[^}]*color:#ff0000/.test(html);
    }, { timeout: 20_000 }).toBe(true);
  } finally {
    await restore(page, before);
  }
});

test('the wrapper element can be changed, and goes back to a div', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await insertQuote(page);
    await advancedTab(page);
    await openSection(page, 'tag');
    const select = page.getByRole('combobox', { name: 'Wrapper element' });
    await expect(select).toHaveValue('div');
    await select.selectOption('article');
    await settled(page);
    const w = await wrapper(page);
    await expect.poll(async () => w.evaluate((el) => el.tagName), { timeout: 20_000 }).toBe('ARTICLE');
    await select.selectOption('div');
    await expect.poll(async () => (await wrapper(page)).evaluate((el) => el.tagName), { timeout: 20_000 }).toBe('DIV');
  } finally {
    await restore(page, before);
  }
});

test('Effects sliders write only values the server accepts, and the custom shadow keeps its required fields', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await insertQuote(page);
    await styleTab(page);
    await openSection(page, 'effects');
    await page.getByLabel('Rotate', { exact: true }).fill('15');
    await openSection(page, 'shadow');
    await page.locator('[data-section="shadow"]').getByRole('button', { name: 'Custom shadow' }).click();
    const blur = page.locator('[data-section="shadow"]').getByLabel('Blur', { exact: true });
    await blur.fill('20px');
    await blur.blur();
    await settled(page);
    await expect(page.getByRole('alert')).toHaveCount(0);
    await expect(page.getByRole('status').filter({ hasText: /saved/i }).first()).toBeVisible({ timeout: 15_000 });

    const w = await wrapper(page);
    await expect.poll(async () => w.evaluate((el) => `${getComputedStyle(el).transform !== 'none'}/${getComputedStyle(el).boxShadow !== 'none'}`), { timeout: 20_000 }).toBe('true/true');
  } finally {
    await restore(page, before);
  }
});

test('a section takes its own background and padding', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await insertQuote(page); // the sandbox page starts empty; adding a block creates its section
    await page.getByRole('tab', { name: /^Layers$/ }).click();
    await page.locator('[role="treeitem"]').first().click();
    const tab = page.locator('[id^="sbx-sec-"][id$="-tab-style"]');
    await expect(tab).toBeVisible();
    await tab.click();
    await page.locator('input[id$="-style-bg-text"]').fill('#123456');
    const padding = page.getByRole('group', { name: 'Section padding' });
    await padding.getByLabel('Top', { exact: true }).fill('48px');
    await padding.getByLabel('Top', { exact: true }).blur();
    await settled(page);
    await expect(errors(page)).toHaveCount(0);
    const frame = await frameDocument(page);
    const section = frame.locator('[data-sb-node^="sec_"]').first();
    await expect.poll(async () => section.evaluate((el) => `${getComputedStyle(el).backgroundColor}/${getComputedStyle(el).paddingTop}`), { timeout: 20_000 }).toBe('rgb(18, 52, 86)/48px');
  } finally {
    await restore(page, before);
  }
});
