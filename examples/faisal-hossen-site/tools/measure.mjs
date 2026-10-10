import { chromium } from 'playwright';
const [url, w = '1440'] = process.argv.slice(2);
const b = await chromium.launch(); const p = await (await b.newContext({ viewport: { width: +w, height: 900 } })).newPage();
await p.goto(url, { waitUntil: 'networkidle' });
const H = await p.evaluate(() => document.documentElement.scrollHeight);
for (let y = 0; y < H; y += 500) { await p.evaluate((y) => window.scrollTo(0, y), y); await p.waitForTimeout(80); }
await p.evaluate(() => window.scrollTo(0, 0)); await p.waitForTimeout(500);
const rows = await p.evaluate(() => [...document.querySelectorAll('h1,h2,h3,.eyebrow,.button,.sb-button')].map((e) => { const r = e.getBoundingClientRect(); return [e.tagName.toLowerCase(), (e.innerText || '').replace(/\s+/g, ' ').slice(0, 28), Math.round(r.top + scrollY), Math.round(r.left), Math.round(r.width), Math.round(r.height)]; }));
console.log(JSON.stringify({ H, rows }));
await b.close();
