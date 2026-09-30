// Kohevo Studio builder — developer build (never run on customer installs).
//
//   npm ci && npm run build
//
// Bundles src/main.jsx (React shell) into ../assets/builder/ as ES modules
// with code splitting: the rich-text editor (Lexical) is a separate chunk
// loaded only when a rich_text field is opened. Output is committed; the
// PHP host page (admin/builder.php) serves it as static files.

import { build } from 'esbuild';
import { rmSync, mkdirSync, copyFileSync, readdirSync, statSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const out = join(here, '..', 'assets', 'builder');

rmSync(out, { recursive: true, force: true });
mkdirSync(out, { recursive: true });

await build({
  entryPoints: { builder: join(here, 'src', 'main.jsx') },
  outdir: out,
  bundle: true,
  splitting: true,
  format: 'esm',
  target: ['es2020', 'chrome100', 'firefox100', 'safari15'],
  jsx: 'automatic',
  conditions: ['production'],
  minify: true,
  sourcemap: false,
  legalComments: 'linked',
  chunkNames: 'chunks/[name]-[hash]',
  define: { 'process.env.NODE_ENV': '"production"' },
  logLevel: 'info',
});

copyFileSync(join(here, 'src', 'builder.css'), join(out, 'builder.css'));

let total = 0;
const walk = (dir) => readdirSync(dir).forEach((f) => {
  const p = join(dir, f);
  if (statSync(p).isDirectory()) walk(p);
  else total += statSync(p).size;
});
walk(out);
console.log(`assets/builder: ${(total / 1024).toFixed(1)} KiB total`);
