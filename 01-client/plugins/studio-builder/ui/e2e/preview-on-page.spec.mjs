// A header or footer is previewed on a real page: the preview bar offers the site's pages, and the frame asks for that page with this header.
import { test, expect } from '@playwright/test';
import { settled } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'preview bar (desktop)');
});

test('a header partial is previewed on a chosen page, and a normal page has no such picker', async ({ page }) => {
  const title = `Preview header ${Date.now()}`;
  await page.goto('/plugins/studio-builder/admin/page-new.php');
  await page.getByLabel('Title').fill(title);
  await page.locator('#sb-type').selectOption('header_partial');
  await page.getByRole('button', { name: /Create & Open Canvas Builder/ }).click();
  await page.waitForURL(/builder\.php\?page=\d+/);
  await page.locator('iframe.sbx-canvas__frame').waitFor();
  await settled(page);

  await page.getByTestId('mode-visitor').click();
  const host = page.getByTestId('preview-host');
  await expect(host).toBeVisible();
  await expect(host.locator('option').first()).toBeAttached({ timeout: 10_000 });
  const chosen = await host.inputValue();
  expect(Number(chosen)).toBeGreaterThan(0);

  const frame = page.locator('iframe[title="Visitor preview"], iframe.sbx-canvas__frame').last();
  await expect(frame).toHaveAttribute('src', new RegExp(`as_page=${chosen}$`));
  const doc = page.frameLocator('iframe.sbx-canvas__frame').last();
  await expect(doc.locator('header.sb-site-header--partial')).toHaveCount(1, { timeout: 15_000 });
  await expect(doc.locator('main, .sb-main').first()).toBeAttached();

  // Pick another page, if there is one: the frame follows.
  const options = await host.locator('option').evaluateAll((os) => os.map((o) => o.value));
  if (options.length > 1) {
    const other = options.find((v) => v !== chosen);
    await host.selectOption(other);
    await expect(frame).toHaveAttribute('src', new RegExp(`as_page=${other}$`));
  }

  // The same URL for something that is not a header or footer is refused, not rendered.
  const res = await page.request.get(`/plugins/studio-builder/admin/preview.php?page=${chosen}&as_page=${chosen}`);
  expect(res.status()).toBe(404);
  // ...and so is a page standing in for a header: the header page cannot host a header preview.
  const self = new URL(page.url()).searchParams.get('page');
  expect((await page.request.get(`/plugins/studio-builder/admin/preview.php?page=${self}&as_page=${self}`)).status()).toBe(404);
});

test('an ordinary page shows no page picker in its preview', async ({ page }) => {
  await page.goto('/plugins/studio-builder/admin/page-new.php');
  await page.getByLabel('Title').fill(`Plain ${Date.now()}`);
  await page.getByRole('button', { name: /Create & Open Canvas Builder/ }).click();
  await page.waitForURL(/builder\.php\?page=\d+/);
  await page.locator('iframe.sbx-canvas__frame').waitFor();
  await settled(page);
  await page.getByTestId('mode-visitor').click();
  await expect(page.getByTestId('visitor-exit')).toBeVisible();
  await expect(page.getByTestId('preview-host')).toHaveCount(0);
});
