// Built-in headers (HeaderPresetCatalog): while a header is open, the library window lists the five designs, and
// applying one replaces the header's content with that design on the canvas (a header is never stacked inside another).
import { test, expect } from '@playwright/test';
import { settled } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'library window (desktop)');
});

const DESIGNS = [
  ['system-header-classic', 'Header — Classic'],
  ['system-header-centered', 'Header — Centred'],
  ['system-header-split', 'Header — Split'],
  ['system-header-minimal', 'Header — Minimal'],
  ['system-header-two-tier', 'Header — Two-tier'],
];

const canvas = (page) => page.frameLocator('iframe.sbx-canvas__frame');

test('a header page offers the five built-in headers, and applying one replaces its content', async ({ page }) => {
  await page.goto('/plugins/studio-builder/admin/page-new.php');
  await page.getByLabel('Title').fill(`Header presets ${Date.now()}`);
  await page.locator('#sb-type').selectOption('header_partial');
  await page.getByRole('button', { name: /Create & Open Canvas Builder/ }).click();
  await page.waitForURL(/builder\.php\?page=\d+/);
  await page.locator('iframe.sbx-canvas__frame').waitFor();
  await settled(page);

  // Applying replaces the header's content, so the builder asks first; accept, and check it names the design.
  const prompts = [];
  page.on('dialog', (dialog) => { prompts.push(dialog.message()); dialog.accept(); });

  const modal = page.getByTestId('library-modal');
  for (const [key, name] of DESIGNS) {
    await page.getByTestId('open-library').click();
    await expect(modal).toBeVisible();
    const headerTab = modal.locator('[data-lib-tab="header_preset"]');
    await expect(headerTab).toContainText('5');
    await headerTab.click();
    const card = modal.locator(`[data-template="${key}"]`);
    await expect(card).toContainText(name);
    const action = card.getByTestId('library-insert');
    await expect(action).toHaveText(/Apply/);
    await action.click();
    await expect.poll(() => prompts.at(-1) || '').toContain(name);
    await expect(modal).toBeHidden();
    await settled(page);

    // The design is on the canvas, and only one header's worth of it: the brand appears once per row the design has.
    await expect(canvas(page).locator('.sb-link', { hasText: 'Your brand' }).first()).toBeAttached({ timeout: 20_000 });
    await expect(canvas(page).locator('.sb-nav').first()).toBeAttached();
    await expect(canvas(page).locator('[data-sb-offcanvas-open]').first()).toBeAttached();
    const brands = await canvas(page).locator('.sb-link', { hasText: 'Your brand' }).count();
    expect(brands, `${key}: the header was replaced, not stacked`).toBeLessThanOrEqual(2);
  }
  await expect(page.getByRole('alert')).toHaveCount(0);
});
