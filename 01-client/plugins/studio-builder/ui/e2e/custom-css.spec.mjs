// Settings › Site › Custom CSS: an administrator's site stylesheet is reduced on save, shown back as stored,
// emitted in Preview, and shown in the editing canvas (after which a guard keeps blocks selectable).
import { test, expect } from '@playwright/test';
import { openBuilder, settled, sandboxPageId } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked left panel (desktop)');
});

test.afterEach(async ({ page }) => {
  await page.goto(`/plugins/studio-builder/admin/builder.php?page=${sandboxPageId()}&lang=en`);
});

async function openCss(page) {
  await page.getByRole('tab', { name: /^Settings$/ }).click();
  const panel = page.locator('#sbx-leftpanel-settings');
  await panel.locator('[data-settings-view="site"]').click();
  await panel.locator('[data-ss-group="css"] summary').click();
  return panel.getByTestId('custom-css');
}

test('custom CSS is saved reduced, shown as stored, and reaches Preview and the canvas', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const cc = await openCss(page);
  const editor = cc.getByRole('textbox', { name: 'Custom CSS code' });
  const save = cc.getByTestId('custom-css-save');
  await expect(save).toBeDisabled();
  try {
    await editor.fill('.e2e-probe { outline: 3px solid #123456; }\n.e2e-bad { background: url(javascript:alert(1)); }');
    await expect(save).toBeEnabled();
    await save.click();
    await expect(cc.getByRole('status')).toContainText('unsafe parts were removed');
    await expect(editor).toHaveValue(/\.e2e-probe \{ outline: 3px solid #123456; \}/);
    await expect(editor).not.toHaveValue(/javascript:/);

    const html = await page.evaluate(async () => {
      const boot = JSON.parse(document.getElementById('sb-builder-boot').textContent);
      const get = async (base) => (await fetch(`${base}${base.includes('?') ? '&' : '?'}page=${boot.pageId}`, { credentials: 'same-origin' })).text();
      return { preview: await get(boot.previewUrl), canvas: await get(boot.canvasUrl) };
    });
    expect(html.preview).toContain('data-sb="tenant-css"');
    expect(html.preview).toContain('.e2e-probe');
    expect(html.canvas).toContain('data-sb="tenant-css"');
    expect(html.canvas).toContain('.e2e-probe');
    // the guard comes after the tenant stylesheet, so tenant CSS cannot block selecting or scrolling
    expect(html.canvas.indexOf('data-sb="editor-guard"')).toBeGreaterThan(html.canvas.indexOf('data-sb="tenant-css"'));
    expect(html.canvas).not.toContain('<script');
  } finally {
    await editor.fill('');
    if (await save.isEnabled()) await save.click();
    await expect(cc.getByRole('status')).toContainText('CSS saved');
  }
});

test('a stylesheet over the size limit cannot be saved', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const cc = await openCss(page);
  await cc.getByRole('textbox', { name: 'Custom CSS code' }).fill('a{color:red}'.repeat(6000));
  await expect(cc.getByTestId('custom-css-save')).toBeDisabled();
  await expect(cc.getByText('The stylesheet is too large.')).toBeVisible();
});
