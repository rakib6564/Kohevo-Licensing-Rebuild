// The Site & SEO and Redirects screens do what they say: what an admin saves there reaches the live site (a published
// page's head, the sitemap, a redirect), and the AI & MCP screen lists the real tools. admin-forms.spec.mjs covers layout.
import { test, expect } from '@playwright/test';
import { openBuilder, settled, publishThrowawayPage, archivePage } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'admin screen; run once');
});

const ADMIN = '/plugins/studio-builder/admin';
const SEO_FIELDS = ['title_format', 'description', 'robots'];

async function liveProbe(page, prefix) {
  await openBuilder(page);
  await settled(page);
  const stamp = Date.now();
  const created = await publishThrowawayPage(page, `${prefix} ${stamp}`, `e2e-${prefix.toLowerCase()}-${stamp}`);
  expect(created.published).toBe(200);
  return { ...created, title: `${prefix} ${stamp}`, url: created.siteUrl + created.path };
}

async function saveSeo(page, values) {
  await page.goto(`${ADMIN}/seo.php`);
  for (const [name, value] of Object.entries(values)) {
    const field = page.locator(`[name="${name}"]`);
    if (name === 'robots') await field.selectOption(value);
    else await field.fill(value);
  }
  await page.getByRole('button', { name: 'Save SEO settings' }).click();
  await expect(page.locator('.alert-success')).toContainText('Saved');
}

test('Site & SEO: the title format, default description and robots policy reach a published page and the sitemap', async ({ page, request }) => {
  test.setTimeout(90_000);
  await page.goto(`${ADMIN}/seo.php`);
  const before = {};
  for (const name of SEO_FIELDS) before[name] = await page.locator(`[name="${name}"]`).inputValue();

  const probe = await liveProbe(page, 'SeoProbe');
  try {
    await saveSeo(page, { title_format: '%title% · E2E Brand', description: 'E2E default description', robots: 'index,follow' });
    await expect(page.locator('[name="title_format"]')).toHaveValue('%title% · E2E Brand');

    let html = await (await request.get(probe.url)).text();
    // A page made in the builder has no SEO title of its own, so the site's format names it.
    expect(html).toContain(`<title>${probe.title} · E2E Brand</title>`);
    expect(html).toContain('<meta name="description" content="E2E default description">');
    expect(html).toContain('<meta name="robots" content="index,follow">');
    expect(await (await request.get('/sitemap.xml?format=xml')).text()).toContain(probe.path);

    // A site set to noindex keeps every page out of search, and out of the sitemap.
    await saveSeo(page, { robots: 'noindex,follow' });
    html = await (await request.get(probe.url)).text();
    expect(html).toContain('<meta name="robots" content="noindex,follow">');
    expect(await (await request.get('/sitemap.xml?format=xml')).text()).not.toContain(probe.path);
  } finally {
    await saveSeo(page, before);
    await openBuilder(page);
    await archivePage(page, probe.id);
  }
});

test('Redirects: a saved rule sends the old address to the new one, a bad rule is refused, and Delete removes it', async ({ page, request }) => {
  test.setTimeout(90_000);
  const probe = await liveProbe(page, 'RedirectProbe');
  const from = `/e2e-old-${Date.now()}`;
  try {
    await page.goto(`${ADMIN}/redirects.php`);
    await page.getByRole('button', { name: /Add Redirect Rule/ }).click();
    const drawer = page.locator('#sb-redirect-drawer');
    await drawer.locator('[name="from_path"]').fill(from);
    await drawer.locator('[name="to_path"]').fill('https://bad host/page');
    await drawer.getByRole('button', { name: /Save Rule/ }).click();
    await expect(page.locator('.alert-danger')).toContainText('destination');

    await page.getByRole('button', { name: /Add Redirect Rule/ }).click();
    await drawer.locator('[name="from_path"]').fill(from);
    await drawer.locator('[name="to_path"]').fill(probe.path);
    await drawer.locator('[name="status_code"]').selectOption('301');
    await drawer.getByRole('button', { name: /Save Rule/ }).click();
    await expect(page.locator('.alert-success')).toContainText(from);

    const moved = await request.get(from, { maxRedirects: 0 });
    expect(moved.status()).toBe(301);
    expect(moved.headers()['location']).toMatch(new RegExp(`${probe.path.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$`));

    // The same address twice is refused: delete the rule first to change where it goes.
    await page.getByRole('button', { name: /Add Redirect Rule/ }).click();
    await drawer.locator('[name="from_path"]').fill(from);
    await drawer.locator('[name="to_path"]').fill('/elsewhere');
    await drawer.getByRole('button', { name: /Save Rule/ }).click();
    await expect(page.locator('.alert-danger')).toContainText('already a rule');
  } finally {
    await page.goto(`${ADMIN}/redirects.php`);
    const row = page.locator('.data-row', { hasText: from });
    if (await row.count()) {
      await row.locator('.data-row-summary').click();
      page.once('dialog', (d) => void d.accept());
      await row.locator('.data-row-detail').getByRole('button', { name: /Delete/ }).click();
      await expect(page.locator('.alert-success')).toContainText('removed');
    }
    await openBuilder(page);
    await archivePage(page, probe.id);
  }
  expect((await request.get(from, { maxRedirects: 0 })).status()).toBe(404);
});

test('AI & MCP lists every MCP tool, with a count that matches the list, and offers nothing to configure', async ({ page }) => {
  await page.goto(`${ADMIN}/ai-mcp.php`);
  const rows = page.locator('.data-row');
  expect(await rows.count()).toBeGreaterThan(10);
  await expect(page.locator('.sb-kpi-card').first().locator('.sb-kpi-value')).toHaveText(String(await rows.count()));
  await expect(rows.filter({ hasText: 'studio_list_pages' })).toHaveCount(1);
  await expect(page.locator('.content-wrapper form[method="POST"], .content-wrapper form[method="post"]')).toHaveCount(0);
});
