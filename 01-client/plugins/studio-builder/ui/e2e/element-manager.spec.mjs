// Settings › Elements: administrators switch block types off, the Add panel stops offering them, and a switched-off type is refused by the server.
import { test, expect } from '@playwright/test';
import { openBuilder, settled, sandboxPageId } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked left panel (desktop)');
});

test.afterEach(async ({ page }) => {
  await page.goto(`/plugins/studio-builder/admin/builder.php?page=${sandboxPageId()}&lang=en`);
});

async function openElements(page) {
  await page.getByRole('tab', { name: /^Settings$/ }).click();
  const panel = page.locator('#sbx-leftpanel-settings');
  await panel.locator('[data-settings-view="elements"]').click();
  return panel.getByTestId('element-manager');
}

test('a switched-off element leaves the Add panel, and switching it back on restores it', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const em = await openElements(page);
  const row = em.locator('[data-element="core.countdown"]');
  const save = em.getByTestId('element-manager-save');
  await expect(row).toBeVisible();
  await expect(save).toBeDisabled();
  try {
    await row.getByRole('switch').click();
    await expect(row.getByRole('switch')).toHaveAttribute('aria-checked', 'false');
    await save.click();
    await expect(em.getByRole('status').first()).toContainText('Elements saved');

    await page.getByRole('tab', { name: /^(Add|Ajouter)$/ }).click();
    const panel = page.locator('#sbx-leftpanel-blocks');
    await panel.getByRole('tab', { name: 'Elements' }).click();
    await panel.locator('[data-chip="content"]').click();
    await expect(panel.locator('[data-block-type="core.heading"]').first()).toBeVisible();
    await expect(panel.locator('[data-block-type="core.countdown"]')).toHaveCount(0);
  } finally {
    await page.getByRole('tab', { name: /^Settings$/ }).click();
    const again = page.locator('#sbx-leftpanel-settings').getByTestId('element-manager');
    if (!(await again.isVisible())) await page.locator('#sbx-leftpanel-settings [data-settings-view="elements"]').click();
    await again.locator('[data-em-action="enable-all"]').click();
    await again.getByTestId('element-manager-save').click();
    await expect(again.getByRole('status').first()).toContainText('Elements saved');
  }
  await page.getByRole('tab', { name: /^(Add|Ajouter)$/ }).click();
  const panel2 = page.locator('#sbx-leftpanel-blocks');
  await panel2.getByRole('tab', { name: 'Elements' }).click();
  await panel2.locator('[data-chip="content"]').click();
  await expect(panel2.locator('[data-block-type="core.countdown"]').first()).toBeVisible();
});

test('"Switch off unused" selects only elements with no usage, and the list shows usage counts', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const em = await openElements(page);
  await expect(em.locator('.sbx-em__row').first()).toBeVisible();
  await em.locator('[data-em-action="disable-unused"]').click();
  const used = em.locator('.sbx-em__row:has(.sbx-em__usage:not(.sbx-em__usage--none))');
  for (const r of await used.all()) await expect(r.getByRole('switch')).toHaveAttribute('aria-checked', 'true');
  const unused = em.locator('.sbx-em__row:has(.sbx-em__usage--none)');
  expect(await unused.count()).toBeGreaterThan(0);
  for (const r of await unused.all()) await expect(r.getByRole('switch')).toHaveAttribute('aria-checked', 'false');
  await em.locator('[data-em-action="enable-all"]').click();
  await expect(em.getByTestId('element-manager-save')).toBeDisabled(); // back to what is saved
});

test('the server refuses to insert a switched-off element, and rejects an unknown type in the list', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const call = (method, action, body) => page.evaluate(async ({ method, action, body }) => {
    const boot = JSON.parse(document.getElementById('sb-builder-boot').textContent);
    const url = new URL(boot.apiUrl, location.href);
    url.searchParams.set('action', action);
    if (method === 'GET') url.searchParams.set('page', String(boot.pageId));
    const res = await fetch(url, {
      method,
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': boot.csrfToken },
      body: method === 'POST' ? JSON.stringify(action === 'operations' ? { page_id: boot.pageId, ...body } : body) : undefined,
    });
    return { status: res.status, json: await res.json() };
  }, { method, action, body });

  const doc = await call('GET', 'document');
  const sections = (doc.json.data.document && doc.json.data.document.sections) || [];
  const sectionId = sections[0] ? sections[0].id : 'sec_unknown'; // the guard runs before the target is looked up
  const revisionId = (doc.json.data.revision && doc.json.data.revision.id) || null;
  const insert = () => call('POST', 'operations', {
    expected_revision_id: revisionId,
    revision_kind: 'manual',
    operations: [{ op: 'insert_block', payload: { parent_id: sectionId, index: 0, block: { type: 'core.countdown' } } }],
  });
  try {
    expect((await call('POST', 'save_elements', { disabled: ['core.countdown'] })).status).toBe(200);
    const refused = await insert();
    expect(refused.status).toBe(422);
    expect(JSON.stringify(refused.json)).toContain('block_disabled');
    expect((await call('POST', 'save_elements', { disabled: ['core.not_a_block'] })).status).toBe(422);
  } finally {
    expect((await call('POST', 'save_elements', { disabled: [] })).status).toBe(200);
  }
});
