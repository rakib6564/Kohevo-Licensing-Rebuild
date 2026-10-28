// The form submit pipeline: a Studio form on a published page becomes a Forms form, a visitor's submit is checked by
// Forms and answered in place (field errors, then the success message), and the submission lands in Forms.
import { test, expect } from '@playwright/test';
import { openBuilder, settled, archivePage } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'one viewport is enough; run once');
});

let seq = 0;
/** A complete canonical block, as the operations endpoint wants it. */
const node = (type, props, children = []) => ({
  id: `blk_e2eform${String(Date.now() % 1e8).padStart(8, '0')}${String(seq++).padStart(4, '0')}`,
  type, version: 1, props, children, bindings: [],
  style: { align: { base: 'left' } },
  visibility: { auth_state: 'any', devices: ['base', 'sm', 'md', 'lg'] },
});
const field = (props) => node('core.form_field', props);

test('a visitor submits a Studio form: errors show beside the fields, then the success message, and Forms stores it', async ({ page, browser }) => {
  await openBuilder(page);
  await settled(page);
  const stamp = Date.now();
  const made = await page.evaluate(async ({ stamp, form }) => {
    const boot = JSON.parse(document.getElementById('sb-builder-boot').textContent);
    const call = async (action, body) => {
      const u = new URL(boot.apiUrl, location.href);
      u.searchParams.set('action', action);
      const r = await fetch(u, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': boot.csrfToken }, body: JSON.stringify(body) });
      return { s: r.status, j: await r.json() };
    };
    const created = (await call('create_page', { title: `E2E form ${stamp}`, slug: `e2e-form-${stamp}`, page_type: 'page', route_mode: 'standalone' })).j.data.page;
    const ops = await call('operations', {
      page_id: created.id, expected_revision_id: created.active_draft_revision_id, revision_kind: 'manual',
      operations: [{ op: 'insert_section', payload: { index: 0, section: { label: 'Contact', blocks: [form] } } }],
    });
    const rev = ops.j.data && ops.j.data.revision ? ops.j.data.revision.id : null;
    const p = await call('publish', { page_id: created.id, expected_revision_id: rev });
    return { id: created.id, path: created.public_path, ops: ops.s, opsBody: ops.s === 200 ? null : ops.j, published: p.s, siteUrl: boot.siteUrl };
  }, { stamp, form: node('core.form', { form_name: 'contact', submit_text: 'Send', success_message: `Thanks ${stamp}` }, [
    field({ name: 'full_name', label: 'Name', required: true, width: '50' }),
    field({ name: 'email', label: 'Email', field_type: 'email', required: true, width: '50' }),
    field({ name: 'topic', label: 'Topic', field_type: 'radio', options: 'Sales, Support', required: true }),
    field({ name: 'agree', label: 'I agree', field_type: 'checkbox', required: true }),
  ]) });
  expect(made.ops, JSON.stringify(made.opsBody)).toBe(200);
  expect(made.published).toBe(200);

  // A visitor: no admin session, so the page is the cached public one.
  const visitor = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  const pub = await visitor.newPage();
  try {
    await pub.goto(new URL(made.path, made.siteUrl).toString());
    const form = pub.locator('form[data-sb-form-slug]');
    await expect(form).toBeVisible();
    // Name and Email sit side by side.
    const [a, b] = await Promise.all([pub.getByLabel('Name').boundingBox(), pub.getByLabel('Email').boundingBox()]);
    expect(Math.abs(a.y - b.y)).toBeLessThan(2);
    expect(b.x).toBeGreaterThan(a.x + a.width);
    await expect(form.getByRole('radio')).toHaveCount(2);

    // Forms checks what the browser does not: an address it refuses comes back as that field's error.
    await pub.getByLabel('Name').fill('Ada');
    await pub.getByLabel('Email').fill('ada@example');
    await form.getByRole('radio', { name: 'Support' }).check();
    await form.getByRole('checkbox', { name: /I agree/ }).check();
    await form.getByRole('button', { name: 'Send' }).click();
    const status = form.locator('[data-sb-form-status]');
    await expect(status).toHaveAttribute('data-sb-state', 'error', { timeout: 15_000 });
    await expect(pub.getByLabel('Email')).toHaveAttribute('aria-invalid', 'true');
    await expect(form.locator('[data-sb-field="email"] [data-sb-field-error]')).toBeVisible();
    await expect(pub.getByLabel('Name')).toHaveValue('Ada'); // nothing the visitor typed is lost
    await pub.getByLabel('Email').fill(`ada+${stamp}@example.com`);
    await form.getByRole('button', { name: 'Send' }).click();
    await expect(status).toHaveAttribute('data-sb-state', 'success', { timeout: 15_000 });
    await expect(status).toHaveText(`Thanks ${stamp}`);
    await expect(pub.getByLabel('Name')).toHaveValue('');

    // The submission is in Forms.
    await page.goto('/plugins/forms/admin/submissions.php');
    await expect(page.locator('body')).toContainText(`ada+${stamp}@example.com`, { timeout: 15_000 });
  } finally {
    await visitor.close();
    await openBuilder(page);
    if (!process.env.SBX_KEEP_PAGES) await archivePage(page, made.id);
  }
});
