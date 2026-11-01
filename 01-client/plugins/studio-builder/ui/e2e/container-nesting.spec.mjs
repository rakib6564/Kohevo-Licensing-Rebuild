// A page built from nothing, the way an author does it: a widget on an empty page wraps itself in a Container, a Container
// goes inside that one and turns into a Grid, a block is dragged into it, and the published page shows the nesting, with a
// semantic <section> at the top level.
import { test, expect } from '@playwright/test';
import { openBuilder, settled, archivePage } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'mouse drag on the docked panel');
});

const ROWS = '[role="treeitem"]';

test('a page of nested Containers, from an empty page to the public page', async ({ page }) => {
  test.setTimeout(120_000);
  const errors = (await openBuilder(page)).errors;
  const stamp = Date.now();
  const created = await page.evaluate(async ({ stamp }) => {
    const boot = JSON.parse(document.getElementById('sb-builder-boot').textContent);
    const u = new URL(boot.apiUrl, location.href);
    u.searchParams.set('action', 'create_page');
    const r = await fetch(u, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': boot.csrfToken }, body: JSON.stringify({ title: `E2E nesting ${stamp}`, slug: `e2e-nesting-${stamp}`, page_type: 'page', route_mode: 'standalone' }) });
    const page = (await r.json()).data.page;
    return { id: page.id, path: page.public_path, siteUrl: boot.siteUrl };
  }, { stamp });

  try {
    await page.goto(`/plugins/studio-builder/admin/builder.php?page=${created.id}`);
    await page.locator('iframe.sbx-canvas__frame').waitFor({ state: 'visible' });
    const canvas = page.frameLocator('iframe.sbx-canvas__frame');
    const tops = canvas.locator('main .sb-top[data-sb-node]');
    await expect(tops).toHaveCount(0);

    // A widget cannot sit at the top level: adding one brings its Container.
    await page.getByRole('tab', { name: /^Add$/ }).click();
    const blocks = page.locator('#sbx-leftpanel-blocks');
    await blocks.getByRole('tab', { name: 'Elements' }).click();
    await blocks.locator('[data-chip="content"]').click();
    await blocks.locator('[data-block-type="core.heading"]').click();
    await settled(page);
    await expect(tops).toHaveCount(1);
    await expect(tops.locator('.sb-block--core-heading, [data-sb-type="core.heading"]')).toHaveCount(1);

    await page.getByRole('tab', { name: /^Layers$/ }).click();
    const ownRows = page.locator(`${ROWS}[aria-level="1"]`).filter({ hasNotText: /^(Header|Footer)/ });
    const topId = await tops.getAttribute('data-sb-node');
    const headingId = await page.locator(`${ROWS}[aria-level="2"]`).first().getAttribute('data-row');
    expect(await ownRows.count()).toBeGreaterThan(0);

    // A Container inside the selected Container, set to Grid from the same pane as any Container.
    await page.locator(`${ROWS}[data-row="${topId}"]`).click();
    await page.getByRole('tab', { name: /^Add$/ }).click();
    await blocks.getByRole('tab', { name: 'Elements' }).click();
    await blocks.locator('[data-chip="layout"]').click();
    await blocks.locator('[data-block-type="core.container"]').first().click();
    await settled(page);
    const pane = page.getByTestId('container-pane');
    await expect(pane).toBeVisible({ timeout: 15_000 });
    const nestedTab = await page.locator('[id^="sbx-blk-"][id$="-tab-content"]').first().getAttribute('id');
    const nestedId = nestedTab.replace('sbx-blk-', '').replace(/-tab-content$/, '');
    expect(nestedId).not.toBe(topId);
    await pane.getByLabel('Container layout').selectOption('grid');
    const nested = canvas.locator(`[data-sb-node="${topId}"] [data-sb-node="${nestedId}"]`);
    await expect.poll(() => nested.evaluate((el) => getComputedStyle(el).display)).toBe('grid');
    await settled(page);

    // Drag the heading into the Grid.
    await page.getByRole('tab', { name: /^Layers$/ }).click();
    const nestedRow = page.locator(`${ROWS}[data-row="${nestedId}"]`);
    await expect(nestedRow).toHaveAttribute('aria-level', '2');
    await page.locator(`${ROWS}[data-row="${headingId}"]`).hover();
    const h = await page.getByTestId(`row-handle-${headingId}`).boundingBox();
    await page.mouse.move(h.x + h.width / 2, h.y + h.height / 2);
    await page.mouse.down();
    const target = await nestedRow.boundingBox();
    await page.mouse.move(target.x + 60, target.y + target.height / 2, { steps: 8 });
    await expect(nestedRow).toHaveClass(/is-drop-inside/);
    await page.mouse.up();
    await expect(page.locator(`${ROWS}[data-row="${headingId}"]`)).toHaveAttribute('aria-level', '3', { timeout: 15_000 });
    await expect(nested.locator(`[data-sb-node="${headingId}"]`)).toHaveCount(1, { timeout: 20_000 });
    await settled(page);

    // Saved: the autosave has landed when Save draft has nothing left to do.
    await expect(page.getByRole('button', { name: 'Save draft' })).toBeDisabled({ timeout: 20_000 });
    await expect(page.locator('.sbx-btn--publish')).toBeEnabled({ timeout: 15_000 });
    await page.locator('.sbx-btn--publish').click();
    await expect(page.getByTestId('status-chip')).toHaveText('Published', { timeout: 20_000 });

    const visitor = await page.context().newPage();
    await visitor.goto(`${created.siteUrl}${created.path}`);
    const top = visitor.locator(`main section.sb-top`);
    await expect(top).toHaveCount(1);
    await expect(top).toHaveClass(/sb-block--core-container/);
    const grid = top.locator('.sb-block--core-container');
    await expect(grid).toHaveCount(1);
    await expect(grid).not.toHaveClass(/sb-top/);
    await expect.poll(() => grid.evaluate((el) => getComputedStyle(el).display)).toBe('grid');
    await expect(grid.locator('h1, h2, h3, h4, h5, h6')).toHaveCount(1);
    await expect(visitor.locator('.sb-section__inner')).toHaveCount(0);
    await visitor.close();
    expect(errors).toEqual([]);
  } finally {
    await archivePage(page, created.id).catch(() => {});
  }
});
