// Modals and drawers on a public page, against the real public runtime: several panels on one page each open from
// their own trigger. (Each trigger used to be wired once per panel, so the second handler closed what the first opened.)
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect } from '@playwright/test';

const here = path.dirname(fileURLToPath(import.meta.url));
const runtimePath = path.join(here, '..', '..', 'assets', 'public', 'studio-runtime.js');

const modal = (id) => `
  <a href="#${id}" class="sb-button" data-sb-modal-open="${id}">Open ${id}</a>
  <div class="sb-modal sb-modal--md" id="${id}" role="dialog" aria-labelledby="${id}-title" aria-modal="true" aria-hidden="true">
    <a href="#" class="sb-modal__backdrop" aria-label="Close"></a>
    <div class="sb-modal__dialog"><header class="sb-modal__header"><h3 id="${id}-title">Title ${id}</h3><a href="#" class="sb-modal__close" data-sb-modal-close aria-label="Close">&times;</a></header><div class="sb-modal__body">Body ${id}</div></div>
  </div>`;
const drawer = (id) => `
  <a href="#${id}" class="sb-button" data-sb-offcanvas-open="${id}">Open ${id}</a>
  <div class="sb-offcanvas sb-offcanvas--right" id="${id}" role="dialog" aria-labelledby="${id}-title" aria-modal="true" aria-hidden="true">
    <a href="#" class="sb-offcanvas__backdrop" data-sb-offcanvas-close aria-label="Close"></a>
    <aside class="sb-offcanvas__panel"><header class="sb-offcanvas__header"><h3 id="${id}-title">Drawer ${id}</h3><a href="#" class="sb-offcanvas__close" data-sb-offcanvas-close aria-label="Close">&times;</a></header><div class="sb-offcanvas__body">Menu ${id}</div></aside>
  </div>`;
const CSS = '.sb-modal,.sb-offcanvas{display:none;position:fixed;inset:0}.sb-modal[data-sb-open],.sb-offcanvas[data-sb-open]{display:block}.sb-offcanvas__panel{position:fixed;top:0;bottom:0;right:0;width:20rem}';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'no layout involved; run once');
});

async function load(page, markup) {
  await page.setContent(`<!doctype html><html><head><style>${CSS}</style></head><body>${markup}</body></html>`);
  await page.addScriptTag({ path: runtimePath });
}

test('two modals on one page each open from their own trigger', async ({ page }) => {
  await load(page, modal('first') + modal('second'));
  await page.locator('[data-sb-modal-open="first"]').click();
  await expect(page.locator('#first')).toBeVisible();
  await expect(page.locator('#second')).toBeHidden();
  await page.locator('#first [data-sb-modal-close]').click();
  await expect(page.locator('#first')).toBeHidden();

  await page.locator('[data-sb-modal-open="second"]').click();
  await expect(page.locator('#second')).toBeVisible();
  await expect(page.locator('#first')).toBeHidden();
});

test('a modal and a drawer on one page do not disturb each other', async ({ page }) => {
  await load(page, modal('enquiry') + drawer('menu') + modal('other'));
  await page.locator('[data-sb-offcanvas-open="menu"]').click();
  await expect(page.locator('#menu')).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(page.locator('#menu')).toBeHidden();
  await page.locator('[data-sb-modal-open="enquiry"]').click();
  await expect(page.locator('#enquiry')).toBeVisible();
  await expect(page.locator('#other')).toBeHidden();
});

test('a drawer and a modal open again after being closed, by the close button, Escape or the backdrop', async ({ page }) => {
  // Closing used to leave the panel's close() behind, so every later click on the trigger called that stale close()
  // and the panel never opened again.
  await load(page, drawer('menu') + modal('enquiry'));
  for (const [id, kind] of [['menu', 'offcanvas'], ['enquiry', 'modal']]) {
    const trigger = page.locator(`[data-sb-${kind}-open="${id}"]`);
    const closers = [
      () => page.locator(`#${id} [data-sb-${kind}-close]`).last().click(),
      () => page.keyboard.press('Escape'),
      // This page's CSS gives the backdrop no box, so the click is dispatched on it rather than aimed at it.
      () => page.locator(`#${id} .sb-${kind}__backdrop`).dispatchEvent('click'),
    ];
    for (const close of closers) {
      await trigger.click();
      await expect(page.locator(`#${id}`)).toBeVisible();
      await close();
      await expect(page.locator(`#${id}`)).toBeHidden();
    }
    await trigger.click();
    await expect(page.locator(`#${id}`)).toBeVisible();
    await page.keyboard.press('Escape');
  }
});

test('a modal or drawer inside a transformed or blurred block still covers the page, and goes back when the runtime is disabled', async ({ page }) => {
  await page.setViewportSize({ width: 1000, height: 700 });
  await load(page, `<div style="transform:translateY(0);width:300px;height:120px">${modal('boxed')}</div><header style="position:sticky;top:0;height:60px;backdrop-filter:blur(8px)">${drawer('menu')}</header>`);
  await page.locator('[data-sb-modal-open="boxed"]').click();
  const cover = await page.locator('#boxed').boundingBox();
  expect(Math.round(cover.width)).toBe(1000);
  expect(Math.round(cover.height)).toBe(700);
  await page.locator('#boxed [data-sb-modal-close]').click();

  await page.locator('[data-sb-offcanvas-open="menu"]').click();
  const panel = await page.locator('#menu .sb-offcanvas__panel').boundingBox();
  expect(Math.round(panel.height)).toBe(700);

  const ok = await page.evaluate(() => { if (!window.StudioRuntime || !window.StudioRuntime.disable) return false; window.StudioRuntime.disable(); return true; });
  expect(ok).toBe(true);
  expect(await page.locator('#boxed').evaluate((el) => el.parentElement.style.transform)).toBe('translateY(0px)');
  expect(await page.locator('#menu').evaluate((el) => el.parentElement.tagName)).toBe('HEADER');
});
