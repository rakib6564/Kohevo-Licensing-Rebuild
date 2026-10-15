// A heading's highlighted phrase has its own colour, italic, weight and underline (a "dual colour" heading).
import { test, expect } from '@playwright/test';
import { openBuilder, settled, frameDocument, layerCount, restoreLayers, showEdit } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked panel (desktop)');
});

test('the highlighted phrase takes its own colour, italic and underline, and a bad colour is refused', async ({ page }) => {
  test.setTimeout(90_000);
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await page.getByRole('tab', { name: /^Add$/ }).click();
    const blocks = page.locator('#sbx-leftpanel-blocks');
    await blocks.getByRole('tab', { name: 'Elements' }).click();
    await blocks.locator('[data-chip="content"]').click();
    await blocks.locator('[data-block-type="core.heading"]').click();
    await settled(page);
    const edit = page.locator('#sbx-leftpanel-inspector');
    await expect(edit.getByLabel('Heading Text')).toBeVisible();
    await edit.getByLabel('Heading Text').fill('Three steps, in order.');
    await edit.getByLabel('Highlighted Word').fill('in order.');

    // The new controls sit with the highlight.
    await expect(edit.getByLabel('Highlight in italic')).toBeVisible();
    await expect(edit.getByLabel('Highlight underlined')).toBeVisible();
    await expect(edit.getByLabel('Highlight weight')).toBeVisible();

    const custom = edit.getByLabel('Highlight colour (custom)', { exact: true }).last();
    await custom.fill('orange');
    await expect(edit.locator('[role="alert"], .sbx-field__error').first()).toBeVisible(); // not a colour: advisory, nothing stored
    await custom.fill('#d4623d');
    await edit.getByLabel('Highlight in italic').check();
    await edit.getByLabel('Highlight underlined').check();
    await edit.getByLabel('Highlight weight').selectOption('bold');

    const span = (await frameDocument(page)).locator('.sb-heading .sb-hl');
    await expect.poll(async () => span.count(), { timeout: 20_000 }).toBe(1);
    await expect(span).toHaveClass(/sb-hl--italic/);
    await expect(span).toHaveClass(/sb-hl--underline/);
    await expect(span).toHaveClass(/sb-hl--w-bold/);
    await expect.poll(async () => span.evaluate((el) => getComputedStyle(el).color), { timeout: 20_000 }).toBe('rgb(212, 98, 61)');
    await expect.poll(async () => span.evaluate((el) => getComputedStyle(el).fontStyle)).toBe('italic');
  } finally {
    await restoreLayers(page, before);
  }
});
