// The Studio admin forms share one layout: the label sits above its control, controls fill their column, nothing overflows.
import { test, expect } from '@playwright/test';

const ADMIN = '/plugins/studio-builder/admin';

// ai-mcp.php is not listed: it is a read-only list of the MCP tools, with no form.
for (const [file, reveal] of [['seo.php', null], ['code-tracking.php', null], ['types-fields.php', 'sb-cpt-drawer'], ['redirects.php', 'sb-redirect-drawer']]) {
  test(`${file}: labels sit above their controls, which are wide enough to use`, async ({ page }) => {
    await page.goto(`${ADMIN}/${file}`);
    if (reveal) await page.evaluate((id) => { document.getElementById(id).style.display = 'block'; }, reveal);
    const fields = page.locator('.sb-field:visible');
    expect(await fields.count()).toBeGreaterThan(1);
    const problems = await fields.evaluateAll((els) => els.flatMap((f) => {
      const label = f.querySelector('.sb-field__label');
      const control = f.querySelector('input:not([type="hidden"]), select, textarea');
      if (!label || !control) return [];
      const l = label.getBoundingClientRect();
      const c = control.getBoundingClientRect();
      const out = [];
      if (l.bottom > c.top + 1) out.push(`${label.textContent.trim()}: label is not above its control`);
      if (c.width < 180) out.push(`${label.textContent.trim()}: control is only ${Math.round(c.width)}px wide`);
      return out;
    }));
    expect(problems).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
  });
}

test('a Studio screen opens on its own header, with no second tab bar above it', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop', 'desktop width');
  await page.goto(`${ADMIN}/seo.php`);
  await expect(page.locator('.sb-admin-nav-bar')).toHaveCount(0);
  const head = await page.locator('.sb-admin .sb-screen-head, .sb-admin .sb-page-head').first().boundingBox();
  const main = await page.locator('.sb-admin').boundingBox();
  expect(head.y - main.y, 'the header is the first thing on the screen').toBeLessThanOrEqual(2);
  await expect(page.locator('header .sb-topbar-action')).toHaveCount(3);
});

test('a page\'s settings, duplicate and delete cards are as wide as the strip above them', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop', 'desktop width');
  await page.goto(`${ADMIN}/pages.php`);
  const href = await page.locator('a[href*="page-edit.php?id="]').first().getAttribute('href');
  await page.goto(`${ADMIN}/${href.split('/').pop()}`);
  const strip = (await page.getByTestId('page-meta').boundingBox()).width;
  const cards = page.locator('form.sb-form-card');
  expect(await cards.count()).toBeGreaterThanOrEqual(3);
  for (const w of await cards.evaluateAll((els) => els.map((e) => e.getBoundingClientRect().width))) {
    expect(Math.abs(w - strip), 'a card is as wide as the strip').toBeLessThanOrEqual(2);
  }
});
