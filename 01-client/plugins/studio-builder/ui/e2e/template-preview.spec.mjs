// A single, archive or 404 template is previewed with a sample of what it gets on the site: an archive with a type and
// name, a single template on a chosen page; a 404 needs nothing.
import { test, expect } from '@playwright/test';
import { settled, archivePage } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'preview bar (desktop)');
});

// The templates made here are published for the entire site, and the editor lays every page out in its Single
// template, so each one is deleted afterwards: otherwise every later spec edits its page inside this template.
const made = [];
test.afterEach(async ({ page }) => {
  if (!made.length) return;
  // archivePage needs the builder's boot data, so a test that stopped elsewhere opens one of its templates first.
  if (!(await page.locator('#sb-builder-boot').count())) await page.goto(`/plugins/studio-builder/admin/builder.php?page=${made[0]}`);
  while (made.length) await archivePage(page, made.pop());
});

/** Create a theme template of `type` from the Templates screen and open its page in the builder (deleted after the test). */
async function createTemplateAndOpen(page, type, name) {
  await page.goto('/plugins/studio-builder/admin/templates.php');
  await page.evaluate(() => { const d = document.getElementById('sb-tpl-drawer'); if (d) d.style.display = 'block'; });
  await page.locator('#sb-tpl-drawer input[name="name"]').fill(name);
  await page.locator('#sb-tpl-drawer select[name="template_type"]').selectOption(type);
  await page.locator('#sb-tpl-drawer').getByRole('button', { name: /Create Template/ }).click();
  await page.waitForLoadState();
  await expect(page.getByText(name).first()).toBeVisible();
  await page.goto(`/plugins/studio-builder/admin/templates.php?type=${type}`);
  const hrefs = await page.locator('a', { hasText: 'Edit in Canvas' }).evaluateAll((as) => as.map((a) => a.getAttribute('href')));
  const newest = hrefs.map((h) => ({ h, id: Number((h.match(/page=(\d+)/) || [])[1]) })).sort((a, b) => b.id - a.id)[0];
  made.push(newest.id);
  await page.goto(newest.h);
  await page.locator('iframe.sbx-canvas__frame').waitFor();
  await settled(page);
}

test('an archive template is previewed with a chosen archive type and name', async ({ page }) => {
  await createTemplateAndOpen(page, 'archive', `Preview archive ${Date.now()}`);
  // Give the template a title block, so the sample has something to show in.
  await page.getByRole('tab', { name: /^Add$/ }).click();
  const panel = page.locator('#sbx-leftpanel-blocks');
  await panel.getByRole('searchbox').fill('archive title');
  await panel.locator('[data-block-type="theme.archive_title"]').click();
  await settled(page);
  const save = page.getByRole('button', { name: 'Save draft' });
  if (await save.isEnabled()) await save.click(); // the builder may already have saved by itself
  await expect(page.getByRole('status').filter({ hasText: 'All changes saved' }).first()).toBeVisible({ timeout: 15_000 });
  await expect(save).toBeDisabled();
  await page.getByTestId('mode-visitor').click();
  const type = page.getByTestId('preview-archive');
  await expect(type).toBeVisible();
  const frame = page.frameLocator('iframe.sbx-canvas__frame').last();
  await expect(page.locator('iframe.sbx-canvas__frame').last()).toHaveAttribute('src', /archive=category&term=News/);
  await type.selectOption('tag');
  const term = page.getByTestId('preview-term');
  await term.fill('Dev');
  await term.press('Enter');
  await expect(page.locator('iframe.sbx-canvas__frame').last()).toHaveAttribute('src', /archive=tag&term=Dev/);
  await expect(frame.locator('.sb-archive-title').first()).toHaveText('Tag: #Dev', { timeout: 20_000 });
});

test('a single template is previewed on a chosen page; a 404 template needs no choice', async ({ page }) => {
  await createTemplateAndOpen(page, 'single', `Preview single ${Date.now()}`);
  await page.getByTestId('mode-visitor').click();
  const host = page.getByTestId('preview-host');
  await expect(host).toBeVisible();
  await expect(host.locator('option').first()).toBeAttached({ timeout: 10_000 });
  const chosen = await host.inputValue();
  expect(Number(chosen)).toBeGreaterThan(0);
  await expect(page.locator('iframe.sbx-canvas__frame').last()).toHaveAttribute('src', new RegExp(`as_page=${chosen}$`));

  await createTemplateAndOpen(page, 'not_found', `Preview 404 ${Date.now()}`);
  await page.getByTestId('mode-visitor').click();
  await expect(page.getByTestId('visitor-exit')).toBeVisible();
  await expect(page.getByTestId('preview-host')).toHaveCount(0);
  await expect(page.getByTestId('preview-archive')).toHaveCount(0);
});
