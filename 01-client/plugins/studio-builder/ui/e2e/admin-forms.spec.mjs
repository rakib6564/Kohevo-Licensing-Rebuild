// The Studio admin forms share one layout: the label sits above its control, controls fill their column, nothing overflows.
import { test, expect } from '@playwright/test';

const ADMIN = '/plugins/studio-builder/admin';

for (const [file, reveal] of [['seo.php', null], ['code-tracking.php', null], ['ai-mcp.php', null], ['types-fields.php', 'sb-cpt-drawer'], ['redirects.php', 'sb-redirect-drawer']]) {
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

test('the Studio tab bar wraps instead of showing a scrollbar on a desktop screen', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop', 'desktop width');
  await page.goto(`${ADMIN}/seo.php`);
  const bar = page.locator('.sb-admin-nav-bar');
  const { scroll, client } = await bar.evaluate((el) => ({ scroll: el.scrollWidth, client: el.clientWidth }));
  expect(scroll).toBeLessThanOrEqual(client + 1);
});
