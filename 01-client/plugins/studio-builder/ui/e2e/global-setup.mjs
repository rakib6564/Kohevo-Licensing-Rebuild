// Log in once as the sandbox admin, make sure a test page exists, and save the session.
import { chromium } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const AUTH_DIR = path.join(here, '.auth');

// Disposable sandbox credentials (see e2e/provision.php); override for another sandbox.
const EMAIL = process.env.SBX_EMAIL || 'admin@sbx.test';
const PASSWORD = process.env.SBX_PASSWORD || 'sbx-pass-123';

export default async function globalSetup(config) {
  const baseURL = config.projects[0].use.baseURL || process.env.SBX_URL || `http://localhost:${process.env.SBX_PORT || 8100}`;
  if (!/^https?:\/\/(localhost|127\.0\.0\.1|\[::1\])(:|\/|$)/.test(baseURL)) {
    throw new Error(`e2e global setup only logs in to a local sandbox, not ${baseURL}`);
  }
  fs.mkdirSync(AUTH_DIR, { recursive: true });
  const browser = await chromium.launch();
  const page = await (await browser.newContext({ baseURL })).newPage();

  await page.goto('/admin/login.php');
  await page.getByPlaceholder('Email').fill(EMAIL);
  await page.getByPlaceholder('Password').fill(PASSWORD);
  await page.getByRole('button', { name: 'Log in' }).click();
  await page.waitForURL(/\/admin\/?(\?.*)?$/);

  // A fresh page for this run (the sandbox is disposable), created through the form an author uses.
  await page.goto('/plugins/studio-builder/admin/pages.php');
  await page.getByLabel('Title').fill(`E2E ${Date.now()}`);
  await page.getByRole('button', { name: /Create & Open Canvas Builder/ }).click();
  await page.waitForURL(/builder\.php\?page=\d+/);
  const builderUrl = page.url();
  const pageId = new URL(builderUrl, baseURL).searchParams.get('page');
  fs.writeFileSync(path.join(AUTH_DIR, 'page.json'), JSON.stringify({ pageId }));

  await page.context().storageState({ path: path.join(AUTH_DIR, 'state.json') });
  await browser.close();
}
