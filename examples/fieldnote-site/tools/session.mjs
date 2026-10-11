// A logged-in admin browser session, and a helper for the Studio authoring API.
//
//   Local sandbox (localhost only): signs in with the sandbox's throw-away credentials.
//   Any other site:                 opens a visible browser at the login page and waits for YOU to sign in.
//                                   The script never sees, asks for or stores a password.
import { chromium } from 'playwright';

const LOCAL = /^https?:\/\/(localhost|127\.0\.0\.1|\[::1\])(:|\/|$)/;

export async function session(base) {
  base = base.replace(/\/+$/, '') + '/';          // keep a sub-folder install's path: relative goto() calls resolve against it
  const local = LOCAL.test(base);
  const browser = await chromium.launch({ headless: local });
  const ctx = await browser.newContext({ baseURL: base, viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();
  await page.goto('admin/login.php');
  if (local) {
    await page.getByPlaceholder('Email').fill(process.env.SBX_EMAIL || 'admin@sbx.test');
    await page.getByPlaceholder('Password').fill(process.env.SBX_PASSWORD || 'sbx-pass-123');
    await page.getByRole('button', { name: 'Log in' }).click();
  } else {
    console.log('A browser window is open: sign in to the admin there. Waiting up to 10 minutes…');
  }
  await page.waitForURL(/\/admin\/?(\?.*)?$/, { timeout: local ? 30_000 : 600_000 });
  const adminHome = page.url();
  await page.goto('plugins/studio-builder/admin/pages.php');
  const csrf = await page.locator('input[name="_csrf"]').first().getAttribute('value');
  const prefix = new URL(adminHome).pathname.replace(/\/admin\/?$/, '');
  const apiUrl = new URL(adminHome).origin + prefix + '/plugins/studio-builder/admin/api.php';
  const api = (method, action, body, query = '') => page.evaluate(async ({ method, action, body, query, csrf, apiUrl }) => {
    const u = new URL(apiUrl, location.href); u.searchParams.set('action', action);
    const r = await fetch(u + query, { method, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrf }, body: method === 'POST' ? JSON.stringify(body) : undefined });
    return { s: r.status, j: await r.json().catch(() => null) };
  }, { method, action, body, query, csrf, apiUrl });
  return { browser, page, api, base, local };
}
