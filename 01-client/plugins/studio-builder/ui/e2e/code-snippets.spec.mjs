// Code & tracking: site verification tokens and header/footer snippets reach the PUBLIC page only,
// are refused when unsafe, and never appear in Preview or the canvas (the canvas only gets the font links).
import { test, expect } from '@playwright/test';
import { openBuilder, settled, sandboxPageId } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'admin screen; run once');
});

test.afterEach(async ({ page }) => {
  await page.goto(`/plugins/studio-builder/admin/builder.php?page=${sandboxPageId()}&lang=en`);
});

const SCREEN = '/plugins/studio-builder/admin/code-tracking.php';
const TOKEN = 'e2eVerifyToken_12345';

async function save(page, fields) {
  await page.goto(SCREEN);
  for (const [name, value] of Object.entries(fields)) await page.locator(`[name="${name}"]`).fill(value);
  await page.getByRole('button', { name: /^Save$/ }).click();
}

test('unsafe snippets are refused with a reason, and nothing is saved', async ({ page }) => {
  await save(page, { head_snippet: '<div>hello</div>', footer_snippet: '<script>alert(1)' });
  await expect(page.locator('.alert-danger')).toContainText('Nothing was saved');
  await expect(page.locator('.alert-danger')).toContainText('<div> is not allowed here');
  await expect(page.locator('.alert-danger')).toContainText('<script> is not closed');
  await save(page, { verify_google: 'not a token!' });
  await expect(page.locator('.alert-danger')).toContainText('Google verification');
  await page.goto(SCREEN);
  await expect(page.locator('[name="head_snippet"]')).toHaveValue('');
});

test('a pasted meta tag becomes a token; snippets show on the public page but not in Preview or the canvas', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  // A throwaway published page to look at.
  const created = await page.evaluate(async () => {
    const boot = JSON.parse(document.getElementById('sb-builder-boot').textContent);
    const call = async (m, action, body, query = '') => {
      const u = new URL(boot.apiUrl, location.href);
      u.searchParams.set('action', action);
      const r = await fetch(u + query, { method: m, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': boot.csrfToken }, body: m === 'POST' ? JSON.stringify(body) : undefined });
      return { s: r.status, j: await r.json() };
    };
    const slug = `e2e-snippets-${Date.now()}`;
    const c = await call('POST', 'create_page', { title: 'Snippets probe', slug, page_type: 'page', route_mode: 'standalone' });
    const page = c.j.data.page;
    const p = await call('POST', 'publish', { page_id: page.id, expected_revision_id: page.active_draft_revision_id });
    return { id: page.id, path: page.public_path, published: p.s, siteUrl: boot.siteUrl, previewUrl: boot.previewUrl, canvasUrl: boot.canvasUrl };
  });
  expect(created.published).toBe(200);
  try {
    await save(page, {
      verify_google: `<meta name="google-site-verification" content="${TOKEN}" />`,
      head_snippet: '<meta name="e2e-head" content="head-snippet-marker"><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter&display=swap">',
      footer_snippet: '<script>window.__e2eFooter = "footer-snippet-marker";</script>',
    });
    await expect(page.locator('.alert-success')).toContainText('code snippets');
    await expect(page.locator('[name="verify_google"]')).toHaveValue(TOKEN);

    const seen = await page.evaluate(async ({ created }) => {
      const text = async (url) => (await fetch(url, { credentials: 'same-origin' })).text();
      const q = (base) => `${base}${base.includes('?') ? '&' : '?'}page=${created.id}`;
      return {
        live: await text(created.siteUrl + created.path),
        preview: await text(q(created.previewUrl)),
        canvas: await text(q(created.canvasUrl)),
      };
    }, { created });
    expect(seen.live).toContain(`<meta name="google-site-verification" content="${TOKEN}">`);
    expect(seen.live).toContain('head-snippet-marker');
    expect(seen.live).toContain('footer-snippet-marker');
    expect(seen.live.indexOf('head-snippet-marker')).toBeLessThan(seen.live.indexOf('</head>'));
    expect(seen.live.indexOf('footer-snippet-marker')).toBeGreaterThan(seen.live.indexOf('</head>'));
    // the canvas shows the site's fonts (an allowed font link), but still none of the snippet's other markup
    expect(seen.canvas).toContain('<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter&amp;display=swap">');
    expect(seen.canvas).not.toContain('<script');
    for (const where of [seen.preview, seen.canvas]) {
      expect(where).not.toContain('head-snippet-marker');
      expect(where).not.toContain('footer-snippet-marker');
      expect(where).not.toContain(TOKEN);
    }
  } finally {
    await save(page, { verify_google: '', head_snippet: '', footer_snippet: '' });
  }
  await page.goto(`/plugins/studio-builder/admin/builder.php?page=${sandboxPageId()}&lang=en`);
  await page.evaluate(async ({ id }) => {
    const boot = JSON.parse(document.getElementById('sb-builder-boot').textContent);
    const u = new URL(boot.apiUrl, location.href);
    u.searchParams.set('action', 'archive_page');
    await fetch(u, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': boot.csrfToken }, body: JSON.stringify({ page_id: id }) });
  }, { id: created.id });
});
