// Container blocks: icon toggles and a column picker for their own props; flex & grid options and size sit apart.
import { test, expect } from '@playwright/test';
import { openBuilder, settled, sandboxPageId } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked Inspector (desktop)');
});

test.afterEach(async ({ page }) => {
  await page.goto(`/plugins/studio-builder/admin/builder.php?page=${sandboxPageId()}&lang=en`);
});

async function insert(page, type) {
  await page.getByRole('tab', { name: /^(Add|Ajouter)$/ }).click();
  const panel = page.locator('#sbx-leftpanel-blocks');
  await panel.getByRole('tab', { name: 'Elements' }).click();
  await panel.locator('[data-chip="layout"]').click();
  await panel.locator(`[data-block-type="${type}"]`).first().click();
  await settled(page);
}

test('a flex container uses icon toggles and keeps its flex options and size apart', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  await page.getByRole('tab', { name: /^Layers$/ }).click();
  const layers = await page.locator('[role="treeitem"]').count();
  try {
    await insert(page, 'layout.flex');
    const content = page.locator('[data-section="content"] .sbx-isec__body');
    await expect(page.locator('[data-section="content"] .sbx-isec__title')).toHaveText('Layout');
    await expect(content.locator('select')).toHaveCount(1); // only Gap is still a select
    await content.getByRole('button', { name: 'Column', exact: true }).click();
    await expect(content.getByRole('button', { name: 'Column', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await content.getByRole('button', { name: 'Column', exact: true }).click(); // back to the default
    await expect(content.getByRole('button', { name: 'Row', exact: true })).toHaveAttribute('aria-pressed', 'true');

    await expect(page.locator('[data-section="layout"] .sbx-isec__title')).toHaveText('Flex & grid options');
    await expect(page.locator('[data-section="layout"] .sbx-isec__toggle')).toHaveAttribute('aria-expanded', 'false');

    await page.locator('[id^="sbx-blk-"][id$="-tab-style"]').click();
    await expect(page.locator('[data-section="dimensions"] .sbx-isec__toggle')).toHaveAttribute('aria-expanded', 'true');
  } finally {
    for (let i = 0; i < 16; i++) {
      await settled(page);
      await page.getByRole('tab', { name: /^Layers$/ }).click();
      if ((await page.locator('[role="treeitem"]').count()) <= layers) break;
      await expect(page.getByTestId('undo')).toBeEnabled({ timeout: 15_000 });
      await page.getByTestId('undo').click();
      await page.waitForTimeout(500);
    }
  }
});

test('a grid picks its column count from tiles or a number', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  await page.getByRole('tab', { name: /^Layers$/ }).click();
  const layers = await page.locator('[role="treeitem"]').count();
  try {
    await insert(page, 'layout.grid');
    const content = page.locator('[data-section="content"] .sbx-isec__body');
    await expect(content.getByRole('button', { name: '2', exact: true })).toHaveAttribute('aria-pressed', 'true'); // the default
    await content.getByRole('button', { name: '4', exact: true }).click();
    await expect(content.getByRole('button', { name: '4', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await content.getByRole('spinbutton').fill('8');
    await expect(content.getByRole('spinbutton')).toHaveValue('8');
    await expect(content.getByRole('button', { name: '4', exact: true })).toHaveAttribute('aria-pressed', 'false');
  } finally {
    for (let i = 0; i < 16; i++) {
      await settled(page);
      await page.getByRole('tab', { name: /^Layers$/ }).click();
      if ((await page.locator('[role="treeitem"]').count()) <= layers) break;
      await expect(page.getByTestId('undo')).toBeEnabled({ timeout: 15_000 });
      await page.getByTestId('undo').click();
      await page.waitForTimeout(500);
    }
  }
});

test('size words show their real size, and the Responsive section speaks plainly', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  await page.getByRole('tab', { name: /^Layers$/ }).click();
  const layers = await page.locator('[role="treeitem"]').count();
  try {
    await insert(page, 'layout.flex');
    const content = page.locator('[data-section="content"] .sbx-isec__body');
    const gap = (await content.locator('select option').allTextContents()).join('|');
    expect(gap).toContain('Medium · 16px');
    expect(gap).toContain('Extra small · 4px');
    expect(gap).not.toMatch(/(^|\|)(xs|sm|md|lg|xl|2xl)(\||$)/);

    await page.locator('[id^="sbx-blk-"][id$="-tab-advanced"]').click();
    await page.locator('[data-section="responsive"] .sbx-isec__toggle').click();
    const body = page.locator('[data-section="responsive"] .sbx-isec__body');
    for (const label of ['Mobile (up to 639px)', 'Large phone (640–767px)', 'Tablet (768–1023px)', 'Desktop (1024px and up)']) {
      await expect(body.getByText(label, { exact: true })).toBeVisible();
    }
    const audience = (await body.locator('select option').allTextContents()).join('|');
    expect(audience).toContain('Everyone');
    expect(audience).toContain('Signed-in visitors');
    expect(audience).not.toMatch(/(^|\|)(any|guest|authenticated)(\||$)/);
  } finally {
    for (let i = 0; i < 16; i++) {
      await settled(page);
      await page.getByRole('tab', { name: /^Layers$/ }).click();
      if ((await page.locator('[role="treeitem"]').count()) <= layers) break;
      await expect(page.getByTestId('undo')).toBeEnabled({ timeout: 15_000 });
      await page.getByTestId('undo').click();
      await page.waitForTimeout(500);
    }
  }
});
