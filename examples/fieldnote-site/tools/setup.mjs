// Site-level setup: design tokens, Custom CSS and the fonts <link>.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const css = ['base', 'home', 'pages'].map((n) => fs.readFileSync(path.join(root, 'src', 'css', `${n}.css`), 'utf8')).join('\n');
const HEAD = '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">';
const TOKENS = {
  'surface.page': '#faf6ef', 'surface.primary': '#faf6ef', 'surface.secondary': '#f1e9db', 'surface.muted': '#fffdf9', 'surface.inverse': '#14231f', 'surface.accent': '#e4572e',
  'text.primary': '#1b1713', 'text.muted': '#6a6258', 'text.inverse': '#f7f1e6', 'text.accent': '#e4572e', 'color.accent': '#e4572e', 'border.default': '#ded3c1',
  'font.body': "Inter, system-ui, -apple-system, 'Segoe UI', sans-serif", 'font.heading': "Fraunces, Georgia, 'Times New Roman', serif",
  'radius.full': '999px', 'radius.lg': '22px', 'radius.md': '12px', 'radius.sm': '6px',
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
