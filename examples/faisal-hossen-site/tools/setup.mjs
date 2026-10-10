// Site-level setup shared by the installer and the library installer: design tokens, Custom CSS and the fonts <link>.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const css = ['base', 'home', 'pages'].map((n) => fs.readFileSync(path.join(root, 'src', 'css', `${n}.css`), 'utf8')).join('\n');
const HEAD = '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">';
const TOKENS = {
  'surface.page': '#f5f3ee', 'surface.primary': '#f5f3ee', 'surface.secondary': '#eeece6', 'surface.muted': '#fbfaf7', 'surface.inverse': '#11130f', 'surface.accent': '#b6ff45',
  'text.primary': '#11130f', 'text.muted': '#6f716b', 'text.inverse': '#fbfaf7', 'text.accent': '#11130f', 'color.accent': '#11130f', 'border.default': '#dcdad4',
  'font.body': "Inter, system-ui, -apple-system, 'Segoe UI', sans-serif", 'font.heading': "'DM Sans', Inter, system-ui, sans-serif",
  'radius.full': '999px', 'radius.lg': '24px', 'radius.md': '12px', 'radius.sm': '6px',
};

export async function applySiteSetup(page, api, must) {
  console.log('tokens', must(await api('POST', 'save_tokens', { group: 'default', tokens: TOKENS }), 'tokens') && 'ok');
  must(await api('POST', 'save_custom_css', { css }), 'custom css');
  console.log('custom css', css.length, 'bytes');

  await page.goto('plugins/studio-builder/admin/code-tracking.php');
  await page.locator('[name="head_snippet"]').fill(HEAD);
  await page.getByRole('button', { name: /^Save$/ }).click();
  console.log('fonts snippet:', (await page.locator('.alert-success, .alert-danger').first().innerText()).slice(0, 100));
  await page.goto('plugins/studio-builder/admin/pages.php');
}
