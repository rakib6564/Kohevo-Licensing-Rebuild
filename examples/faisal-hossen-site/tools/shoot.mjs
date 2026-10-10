import { chromium } from 'playwright';
const [base, out] = process.argv.slice(2);
const pages = { home: '/', work: '/work', about: '/about', contact: '/contact', peoria: '/work/peoria-hardwood-floors', nicola: '/work/nicola', ai: '/work/ai-flooring-visualizer' };
const b = await chromium.launch();
for (const [w, h, tag] of [[1440, 900, 'd'], [390, 844, 'm']]) {
  const ctx = await b.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 1 });
  const p = await ctx.newPage();
  for (const [name, path] of Object.entries(pages)) {
    await p.goto(base + path, { waitUntil: 'networkidle' }).catch(() => {});
    const H = await p.evaluate(() => document.documentElement.scrollHeight);
    for (let y = 0; y < H; y += 500) { await p.evaluate((y) => window.scrollTo(0, y), y); await p.waitForTimeout(120); }
    await p.evaluate(() => window.scrollTo(0, 0)); await p.waitForTimeout(700);
    await p.screenshot({ path: `${out}/${name}-${tag}.png`, fullPage: true });
  }
  await ctx.close();
}
await b.close();
