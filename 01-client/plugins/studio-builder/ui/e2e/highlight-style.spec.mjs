// An older heading's highlighted phrase keeps its own colour, italic, weight and underline; new headings use Dual Color Heading.
import { test, expect } from '@playwright/test';
import { openBuilder, settled, frameDocument, layerCount, restoreLayers, showEdit } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked panel (desktop)');
});

/** The builder's own API, from the page: the controls only show on a heading that already has a highlight, and the Inspector cannot add one. */
const api = (page, action, body) => page.evaluate(async ({ action, body }) => {
  const boot = JSON.parse(document.getElementById('sb-builder-boot').textContent);
  const url = new URL(boot.apiUrl, location.href);
  url.searchParams.set('action', action);
  if (!body) url.searchParams.set('page', String(boot.pageId));
  const res = await fetch(url, {
    method: body ? 'POST' : 'GET',
    credentials: 'same-origin',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': boot.csrfToken },
    body: body ? JSON.stringify({ page_id: boot.pageId, ...body }) : undefined,
  });
  const json = await res.json();
  if (!res.ok) throw new Error(JSON.stringify(json));
  return json.data;
}, { action, body });

const apply = async (page, operation) => {
  const { revision } = await api(page, 'document');
  return api(page, 'operations', { expected_revision_id: revision.id, revision_kind: 'manual', operations: [operation] });
};

test('a new heading offers no highlight', async ({ page }) => {
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
    await expect(page.locator('#sbx-leftpanel-inspector').getByRole('textbox', { name: 'Title', exact: true })).toBeVisible();
    await expect(page.getByLabel('Highlighted Word')).toHaveCount(0);
  } finally {
    await restoreLayers(page, before);
  }
});

test('the highlighted phrase takes its own colour, italic and underline, and a bad colour is refused', async ({ page }) => {
  test.setTimeout(90_000);
  await openBuilder(page);
  await settled(page);
  const stamp = String(Date.now() % 1e8).padStart(12, '0');
  const node = (id, type, props, children = []) => ({ id, type, version: 1, props, children, bindings: [], style: {}, visibility: { auth_state: 'any', devices: ['base', 'sm', 'md', 'lg'] } });
  await apply(page, { op: 'insert_block', payload: { parent_id: null, index: 0, block: node(`blk_e2eb${stamp}`, 'core.container', {}, [node(`blk_e2eh${stamp}`, 'core.heading', { text: 'Three steps, in order.', highlight: 'in order.', level: 'h2' })]) } });
  const box = (await api(page, 'document')).document.containers[0].id; // the server gives inserted blocks their own ids
  try {
    await openBuilder(page);
    await settled(page);
    await (await frameDocument(page)).locator('[data-sb-type="core.heading"]', { hasText: 'Three steps' }).first().click({ force: true });
    await showEdit(page);
    const edit = page.locator('#sbx-leftpanel-inspector');
    await expect(edit.getByLabel('Highlighted Word')).toBeVisible();
    await edit.getByLabel('Highlighted Word').fill('ok');
    await expect(edit.getByText('nothing is highlighted')).toBeVisible(); // a phrase that is not in the text says so
    await edit.getByLabel('Highlighted Word').fill('in order.');
    await expect(edit.getByText('nothing is highlighted')).toHaveCount(0);

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
    // The look is also inline, so a site's own highlight CSS (often more specific than the look classes) cannot hide it.
    await expect(span).toHaveAttribute('style', /font-style:italic/);
    await expect(span).toHaveAttribute('style', /font-weight:700/);
  } finally {
    await apply(page, { op: 'remove_block', payload: { block_id: box } });
  }
});
