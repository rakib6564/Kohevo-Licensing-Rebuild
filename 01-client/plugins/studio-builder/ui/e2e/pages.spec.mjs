// Navigator → Pages (B2-P2c): list with status chips, the page being edited, and create, rename,
// duplicate and archive through the builder API.
import { test, expect } from '@playwright/test';
import { openBuilder, sandboxPageId } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'docked Layers panel (desktop)');
});

test.afterEach(async ({ page }) => {
  await page.goto(`/plugins/studio-builder/admin/builder.php?page=${sandboxPageId()}&lang=en`);
});

const unique = () => `e2e-${Date.now().toString(36)}${Math.floor(Math.random() * 1000)}`;
const pages = (page) => page.getByTestId('pages-panel');

async function openPages(page) {
  await page.getByRole('tab', { name: /^(Layers|Calques)$/ }).click();
  await page.locator('[data-nav="pages"]').click();
  await expect(pages(page).locator('.sbx-page-row').first()).toBeVisible();
}

/** Archive every page whose title starts with `prefix`, from the sandbox page, so a failed run leaves nothing listed. */
async function archiveAll(page, prefix) {
  await page.goto(`/plugins/studio-builder/admin/builder.php?page=${sandboxPageId()}&lang=en`);
  await page.locator('header.sbx-topbar').waitFor({ state: 'visible' });
  await openPages(page);
  for (let i = 0; i < 6; i++) {
    const row = pages(page).locator('.sbx-page-row', { hasText: prefix }).first();
    if (!(await row.count())) return;
    page.once('dialog', (d) => d.accept());
    await row.locator('[data-action="archive"]').click();
    await expect(row).toHaveCount(0, { timeout: 15_000 }).catch(() => {});
  }
}

test('the Pages view lists this site\'s pages with the current one marked, and offers the commands', async ({ page }) => {
  await openBuilder(page);
  await openPages(page);
  const current = pages(page).locator('.sbx-page-row.is-current');
  await expect(current).toHaveCount(1);
  await expect(current).toHaveAttribute('data-page-id', String(sandboxPageId()));
  await expect(current).toContainText('Current page');
  await expect(current.locator('[data-chip-kind="draft"]')).toBeVisible();
  await expect(current.locator('[data-action="rename"]')).toBeEnabled();
  await expect(current.locator('[data-action="duplicate"]')).toBeEnabled();
  await expect(current.locator('[data-action="archive"]')).toBeDisabled(); // not the page being edited
  await expect(pages(page).getByTestId('page-new')).toBeVisible();
  // the Layers view is one click away and keeps working
  await page.locator('[data-nav="layers"]').click();
  await expect(page.locator('[data-nav="layers"]')).toHaveAttribute('aria-pressed', 'true');
  await expect(pages(page)).toHaveCount(0);
  await expect(page.getByRole('button', { name: /Add section/ }).first()).toBeVisible();
});

test('duplicate makes a draft copy; archive takes it off the list; a taken address is refused in the dialog', async ({ page }) => {
  const stem = unique();
  try {
    await openBuilder(page);
    await openPages(page);
    const current = pages(page).locator('.sbx-page-row.is-current');
    await current.locator('[data-action="duplicate"]').click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByLabel('Page name')).toHaveValue(/\(copy\)$/);
    await dialog.getByLabel('Page name').fill(`${stem} copy`);
    await dialog.getByLabel('Address').fill(`${stem}-copy`);
    await dialog.getByTestId('page-form-submit').click();
    const copy = pages(page).locator('.sbx-page-row', { hasText: `${stem} copy` });
    await expect(copy).toHaveCount(1, { timeout: 15_000 });
    await expect(copy.locator('[data-chip-kind="draft"]')).toBeVisible();
    await expect(copy.locator('.sbx-page-row__path')).toHaveText(`/${stem}-copy`);

    // the same address again: the server's message appears and no second page is made
    await current.locator('[data-action="duplicate"]').click();
    const again = page.getByRole('dialog');
    await again.getByLabel('Page name').fill(`${stem} again`);
    await again.getByLabel('Address').fill(`${stem}-copy`);
    await again.getByTestId('page-form-submit').click();
    await expect(again.getByTestId('page-form-error')).toContainText(/already exists/i);
    await again.getByRole('button', { name: 'Cancel' }).click();
    await expect(pages(page).locator('.sbx-page-row', { hasText: `${stem} again` })).toHaveCount(0);

    page.once('dialog', (d) => d.accept());
    await copy.locator('[data-action="archive"]').click();
    await expect(pages(page).locator('.sbx-page-row', { hasText: `${stem} copy` })).toHaveCount(0, { timeout: 15_000 });
  } finally {
    await archiveAll(page, stem);
  }
});

test('a new page opens in the builder; the list on the other side shows it too', async ({ page }) => {
  const stem = unique();
  try {
    await openBuilder(page);
    await openPages(page);
    await pages(page).getByTestId('page-new').click();
    const dialog = page.getByRole('dialog');
    await dialog.getByLabel('Page name').fill(`${stem} fresh`);
    await expect(dialog.getByLabel('Address')).toHaveValue(`${stem}-fresh`); // follows the name until edited
    await dialog.getByTestId('page-form-submit').click();
    await page.waitForURL((u) => u.searchParams.get('page') !== String(sandboxPageId()), { timeout: 20_000 });
    await page.locator('header.sbx-topbar').waitFor({ state: 'visible' });
    await openPages(page);
    const row = pages(page).locator('.sbx-page-row.is-current');
    await expect(row).toContainText(`${stem} fresh`);
    await expect(row.locator('.sbx-page-row__path')).toHaveText(`/${stem}-fresh`);
  } finally {
    await archiveAll(page, stem);
  }
});

test('renaming the page being edited reloads it under the new name; renaming back restores it', async ({ page }) => {
  const stem = unique();
  await openBuilder(page);
  await openPages(page);
  const current = pages(page).locator('.sbx-page-row.is-current');
  const original = (await current.locator('.sbx-page-row__title').textContent()).trim();
  try {
    await current.locator('[data-action="rename"]').click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByLabel('Page name')).toHaveValue(original);
    await dialog.getByLabel('Page name').fill(`${stem} renamed`);
    await dialog.getByTestId('page-form-submit').click();
    await expect(page.locator('.sbx-topbar__page-title, .sbx-page-switcher__select')).toContainText(`${stem} renamed`, { timeout: 20_000 });
  } finally {
    await page.goto(`/plugins/studio-builder/admin/builder.php?page=${sandboxPageId()}&lang=en`);
    await openPages(page);
    const row = pages(page).locator('.sbx-page-row.is-current');
    if ((await row.locator('.sbx-page-row__title').textContent()).trim() !== original) {
      await row.locator('[data-action="rename"]').click();
      await page.getByRole('dialog').getByLabel('Page name').fill(original);
      await page.getByRole('dialog').getByTestId('page-form-submit').click();
      await expect(page.locator('.sbx-topbar__page-title, .sbx-page-switcher__select')).toContainText(original, { timeout: 20_000 });
    }
  }
});

test('the Pages view is French in French', async ({ page }) => {
  await page.goto(`/plugins/studio-builder/admin/builder.php?page=${sandboxPageId()}&lang=fr`);
  await page.locator('header.sbx-topbar').waitFor({ state: 'visible' });
  await openPages(page);
  const current = pages(page).locator('.sbx-page-row.is-current');
  await expect(current).toContainText('Page en cours');
  await expect(current).toContainText('Brouillon');
  await expect(current.locator('[data-action="rename"]')).toHaveText('Renommer');
  await expect(current.locator('[data-action="duplicate"]')).toHaveText('Dupliquer');
  await expect(current.locator('[data-action="archive"]')).toHaveText('Archiver');
  await expect(pages(page).getByTestId('page-new')).toContainText('Nouvelle page');
});
