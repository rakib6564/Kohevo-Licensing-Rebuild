// Fragments: a form inside a slot gets its answer in place, and nothing that answers later may overwrite it.
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect } from '@playwright/test';

const here = path.dirname(fileURLToPath(import.meta.url));
const runtimePath = path.join(here, '..', '..', 'assets', 'public', 'studio-runtime.js');
const page0 = '<!doctype html><html><body><div data-sb-fragment="/slot" data-sb-fragment-group="cart" id="cart"></div><div data-sb-fragment="/count" data-sb-fragment-group="cart" id="count"></div></body></html>';
const form = (label) => `<p id="state">${label}</p><form method="post" action="/save"><button>Save</button></form>`;

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'one browser is enough');
});

async function load(page, { slowFirstLoad = false } = {}) {
  let slots = 0;
  let counts = 0;
  let releaseFirst = () => {};
  const firstLoadHeld = new Promise((resolve) => { releaseFirst = resolve; });
  await page.route('http://frag.test/**', async (route) => {
    const { pathname } = new URL(route.request().url());
    const html = (body) => route.fulfill({ contentType: 'text/html', body });
    if (pathname === '/') return html(page0);
    if (pathname === '/count') { counts += 1; return html(`count ${counts}`); }
    if (pathname === '/save') return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ html: form('Saved, with a message') }) });
    slots += 1;
    if (slots === 1 && slowFirstLoad) await firstLoadHeld;
    return html(form('from a GET'));
  });
  await page.goto('http://frag.test/');
  await page.addScriptTag({ path: runtimePath });
  return { releaseFirst, slotLoads: () => slots };
}

test('a form\'s answer stays in its slot, and only the other slots of the group reload', async ({ page }) => {
  const { slotLoads } = await load(page);
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.locator('#state')).toHaveText('Saved, with a message');
  await expect(page.locator('#count')).toHaveText('count 2');
  expect(slotLoads()).toBe(1);
  await expect(page.locator('#state')).toHaveText('Saved, with a message');
});

test('an older load that answers late does not overwrite newer content', async ({ page }) => {
  const { releaseFirst } = await load(page, { slowFirstLoad: true });
  await page.locator('#cart').evaluate((el) => {
    el.innerHTML = '<form method="post" action="/save"><button>Save</button></form>';
  });
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.locator('#state')).toHaveText('Saved, with a message');
  releaseFirst();
  await page.waitForTimeout(150);
  await expect(page.locator('#state')).toHaveText('Saved, with a message');
});
