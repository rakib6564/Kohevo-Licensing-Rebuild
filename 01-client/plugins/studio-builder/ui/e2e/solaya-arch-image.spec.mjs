// Solaya's arched image (bin/solaya-site/solaya.css, `.sl-arch`) only sets defaults: a width, height or fit the
// author gives the Image block in the editor must win over them, on the canvas (inline style) and on the published
// page (a scoped class). Runs against the real base stylesheet and the real solaya.css.
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { test, expect } from '@playwright/test';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.join(here, '..', '..', '..', '..');
const solaya = fs.readFileSync(path.join(root, 'bin', 'solaya-site', 'solaya.css'), 'utf8');
const base = execFileSync('php', ['-r', `define('SLATE_TESTING', true); define('SLATE_ROOT', ${JSON.stringify(root)}); require SLATE_ROOT . '/src/autoload.php'; echo \\Slate\\Module\\StudioBuilder\\Render\\StudioStylesheet::css();`], { encoding: 'utf8' });
const pixel = 'data:image/svg+xml,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="800" height="600"/>');

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'one wide column is enough');
});

async function load(page, { style = '', authorCss = '' } = {}) {
  await page.setViewportSize({ width: 1280, height: 900 });
  await page.setContent(`<!doctype html><html><head><style>${base}</style><style>${authorCss}</style><style>${solaya}</style></head>
    <body class="sb-body" style="margin:0"><div style="display:grid;width:1200px">
      <div class="sb-block sb-block--core-image sb-align-left sl-arch zz-author"${style ? ` style="${style}"` : ''}>
        <figure class="sb-image"><img src="${pixel}" alt=""></figure>
      </div></div></body></html>`);
}

const measure = (page) => page.locator('img').evaluate((img) => {
  const r = img.getBoundingClientRect();
  return { size: `${Math.round(r.width)}x${Math.round(r.height)}`, fit: getComputedStyle(img).objectFit, arched: getComputedStyle(img).borderTopLeftRadius === '999px' };
});

test('with nothing set, the arch is a centred 30rem portrait with a 4:5 crop', async ({ page }) => {
  await load(page);
  expect(await measure(page)).toEqual({ size: '480x600', fit: 'cover', arched: true });
});

test('a width set in the editor (inline on the canvas) is followed past the 30rem default, and so are height and fit', async ({ page }) => {
  await load(page, { style: 'width:600px' });
  expect(await measure(page)).toEqual({ size: '600x750', fit: 'cover', arched: true });
  await load(page, { style: 'width:240px;height:200px;object-fit:contain' });
  expect(await measure(page)).toEqual({ size: '240x200', fit: 'contain', arched: true });
});

test('the same settings as the published page writes them (a class rule) win too', async ({ page }) => {
  await load(page, { authorCss: '.zz-author{width:560px;height:300px;object-fit:contain}' });
  expect(await measure(page)).toEqual({ size: '560x300', fit: 'contain', arched: true });
});
