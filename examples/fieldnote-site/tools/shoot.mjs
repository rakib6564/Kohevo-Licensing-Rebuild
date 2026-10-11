// Full-page screenshots of every page at desktop (1440) and mobile (390):  node tools/shoot.mjs <base-url> <out-dir>
import { chromium } from 'playwright';
import fs from 'node:fs';
import { projects } from '../src/content.mjs';
const [base, out] = process.argv.slice(2);
fs.mkdirSync(out, { recursive: true });
const pages = { home: '/', work: '/work', services: '/services', about: '/about', contact: '/contact', ...Object.fromEntries(projects.map((p) => [p.slug, `/${p.slug}`])) };
const only = process.argv.slice(4);
const b = await chromium.launch();
for (const [w, h, tag] of [[1440, 900, 'd'], [390, 844, 'm']]) {
  const ctx = await b.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 1 });
  const p = await ctx.newPage();
  for (const [name, path] of Object.entries(pages)) {
    if (only.length && !only.includes(name)) continue;
    await p.goto(base + path, { waitUntil: 'networkidle' }).catch(() => {});
    const H = await p.evaluate(() => document.documentElement.scrollHeight);
    for (let y = 0; y < H; y += 500) { await p.evaluate((y) => window.scrollTo(0, y), y); await p.waitForTimeout(120); }
    await p.evaluate(() => window.scrollTo(0, 0)); await p.waitForTimeout(700);
    await p.screenshot({ path: `${out}/${name}-${tag}.png`, fullPage: true });
    // ordered slices (1100 px tall on desktop, 1500 on mobile) so each can be read at full size
    const step = tag === 'd' ? 1100 : 1500; const full = await p.evaluate(() => document.documentElement.scrollHeight);
    for (let y = 0, i = 0; y < full; y += step, i++) await p.screenshot({ path: `${out}/${name}-${tag}-${String(i).padStart(2, '0')}.png`, fullPage: true, clip: { x: 0, y, width: w, height: Math.min(step, full - y) } });
  }
  await ctx.close();
}
await b.close();
