// What a real rebuild (FightxLife) had to leave in custom CSS, now native: a Button's own button takes a gradient, a
// fixed height and a smaller height on a phone from Style › Parts; a Grid takes unequal column sizes from Layout.
import { test, expect } from '@playwright/test';
import { openBuilder, frameEval, settled, openSection, layerCount, restoreLayers, archivePage } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked Inspector (desktop)');
});

async function addBlock(page, search, type) {
  await page.getByRole('tab', { name: /^(Add|Ajouter)$/ }).click();
  const panel = page.locator('#sbx-leftpanel-blocks');
  await panel.getByRole('searchbox').fill(search);
  await panel.locator(`[data-block-type="${type}"]`).first().click();
  await settled(page);
}

test('a button part takes a gradient and a height, and its own height on a phone', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await addBlock(page, 'button', 'core.button');
    const setup = page.getByRole('dialog', { name: 'Set up Button' });
    await setup.getByLabel('Label').fill('Donate');
    await setup.getByLabel('Address').fill('/donate');
    await setup.getByRole('button', { name: 'Add' }).click();
    await settled(page);
    const body = await openSection(page, 'parts');
    const parts = body.locator('[data-part-styles]');
    await expect(parts.getByLabel('Part', { exact: true })).toHaveValue('button');
    await parts.getByLabel('Gradient fill').check();
    await settled(page);
    await expect.poll(() => frameEval(page, '.sb-button', (el) => getComputedStyle(el).backgroundImage), { timeout: 20_000 }).toContain('linear-gradient');

    await parts.locator('summary', { hasText: 'Size' }).click();
    await parts.getByLabel('Height', { exact: true }).fill('60px');
    await parts.getByLabel('Height', { exact: true }).blur();
    await settled(page);
    await expect.poll(() => frameEval(page, '.sb-button', (el) => getComputedStyle(el).height), { timeout: 20_000 }).toBe('60px');

    // On a phone the same panel edits the phone's own value; the desktop one stays.
    await page.getByRole('button', { name: 'Mobile' }).first().click();
    await expect(parts.getByText(/apply on Mobile and smaller/)).toBeVisible();
    await expect(parts.getByLabel('Gradient fill')).toHaveCount(0);
    await parts.getByLabel('Height', { exact: true }).fill('44px');
    await parts.getByLabel('Height', { exact: true }).blur();
    await settled(page);
    await expect.poll(() => frameEval(page, '.sb-button', (el) => getComputedStyle(el).height), { timeout: 20_000 }).toBe('44px');
    await page.getByRole('button', { name: 'Desktop' }).first().click();
    await expect.poll(() => frameEval(page, '.sb-button', (el) => getComputedStyle(el).height), { timeout: 20_000 }).toBe('60px');
    await expect(page.getByRole('alert')).toHaveCount(0);
  } finally {
    await restoreLayers(page, before);
  }
});

test('a grid takes unequal column sizes, and refuses what is not a size', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const before = await layerCount(page);
  try {
    await addBlock(page, 'grid', 'layout.grid');
    const body = await openSection(page, 'layout');
    const sizes = body.getByLabel('Column sizes');
    await sizes.fill('2fr;color:red');
    await expect(body.getByRole('alert')).toContainText('up to 12 sizes');
    await sizes.fill('2fr 1fr');
    await sizes.press('Enter');
    await settled(page);
    await expect.poll(async () => {
      const cols = await frameEval(page, '.sb-block--layout-grid', (el) => getComputedStyle(el).gridTemplateColumns);
      const [a, b] = String(cols).split(' ').map(parseFloat);
      return b ? Math.round(a / b) : 0;
    }, { timeout: 20_000 }).toBe(2);
  } finally {
    await restoreLayers(page, before);
  }
});

test('an Off-canvas drawer keeps its part styles once the runtime has moved it to <body>', async ({ page, browser }) => {
  await openBuilder(page);
  await settled(page);
  const stamp = Date.now();
  const drawer = {
    id: `blk_e2edrawer${String(stamp % 1e8).padStart(8, '0')}0`, type: 'layout.offcanvas', version: 1, bindings: [], children: [],
    props: { drawer_id: `drawer-${stamp}`, title: '', trigger_text: 'Open Menu' },
    style: { align: { base: 'left' } },
    visibility: { auth_state: 'any', devices: ['base', 'sm', 'md', 'lg'] },
    style_parts: {
      panel: { size: { width: '300px', max_width: 'none' } },
      header: { position: { mode: 'absolute', top: '17px', right: '22px' } },
      close: { background: { color: '#fdeaf4' }, border: { radius: '999px' } },
    },
  };
  const made = await page.evaluate(async ({ stamp, drawer }) => {
    const boot = JSON.parse(document.getElementById('sb-builder-boot').textContent);
    const call = async (action, body) => {
      const u = new URL(boot.apiUrl, location.href);
      u.searchParams.set('action', action);
      const r = await fetch(u, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': boot.csrfToken }, body: JSON.stringify(body) });
      return { s: r.status, j: await r.json() };
    };
    const created = (await call('create_page', { title: `E2E drawer ${stamp}`, slug: `e2e-drawer-${stamp}`, page_type: 'page', route_mode: 'standalone' })).j.data.page;
    const ops = await call('operations', {
      page_id: created.id, expected_revision_id: created.active_draft_revision_id, revision_kind: 'manual',
      operations: [{ op: 'insert_block', payload: { parent_id: null, index: 0, block: { type: 'core.container', metadata: { label: 'Drawer' }, children: [drawer] } } }],
    });
    const rev = ops.j.data && ops.j.data.revision ? ops.j.data.revision.id : null;
    const p = await call('publish', { page_id: created.id, expected_revision_id: rev });
    return { id: created.id, path: created.public_path, ops: ops.s, opsBody: ops.s === 200 ? null : ops.j, published: p.s, siteUrl: boot.siteUrl };
  }, { stamp, drawer });
  expect(made.ops, JSON.stringify(made.opsBody)).toBe(200);
  expect(made.published).toBe(200);

  const visitor = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  const pub = await visitor.newPage();
  try {
    await pub.goto(new URL(made.path, made.siteUrl).toString());
    const panel = pub.locator(`body > #drawer-${stamp}`);
    await expect(panel).toHaveAttribute('data-sb-scope', /^sb-x-[0-9a-f]{16}$/);
    await pub.getByRole('link', { name: 'Open Menu' }).click();
    await expect(panel).toHaveAttribute('data-sb-open', '');
    await expect.poll(() => panel.locator('.sb-offcanvas__panel').evaluate((el) => getComputedStyle(el).width)).toBe('300px');
    expect(await panel.locator('.sb-offcanvas__header').evaluate((el) => getComputedStyle(el).position)).toBe('absolute');
    expect(await panel.locator('.sb-offcanvas__close').evaluate((el) => getComputedStyle(el).backgroundColor)).toBe('rgb(253, 234, 244)');
  } finally {
    await visitor.close();
    if (!process.env.SBX_KEEP_PAGES) await archivePage(page, made.id);
  }
});
