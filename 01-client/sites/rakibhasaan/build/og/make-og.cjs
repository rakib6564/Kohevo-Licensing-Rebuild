// rakibhasaan.com — share cards (1200×630), the logo (512×512) and the favicon (256×256), rendered from HTML with the
// site's own look: ink, bone, the orange dot, Geist. Writes into the bundle's media folder; site.php and the page builds
// pick them up by name (og-<slug>.png, og-default.png, rakib-logo.png, rakib-favicon.png).
//
//   node sites/rakibhasaan/build/og/make-og.cjs            (needs Playwright, from the Studio editor's dev dependencies)
'use strict';
const path = require('path');
const fs = require('fs');
const { createRequire } = require('module');
const req = createRequire(path.resolve(__dirname, '../../../../plugins/studio-builder/ui/package.json'));
const { chromium } = req('@playwright/test');

const MEDIA = path.resolve(__dirname, '../../media');
const img = (f) => 'data:' + (f.endsWith('.png') ? 'image/png' : f.endsWith('.webp') ? 'image/webp' : 'image/jpeg') + ';base64,' + fs.readFileSync(path.join(MEDIA, f)).toString('base64');

// slug, the small label, the line, the picture on the right (and where its subject sits).
const CARDS = [
  ['default', 'Freelance designer + developer', 'Websites built for your industry', 'rakib-hero-golden.jpg', '70% 35%'],
  ['home', 'Freelance designer + developer', 'Websites built for your industry', 'rakib-hero-golden.jpg', '70% 35%'],
  ['about', 'About', 'One person plans, designs and builds your site', 'rakib-hero-teal.jpg', '78% 30%'],
  ['contact', 'Contact', 'Let’s talk about your website', 'rakib-headshot.jpg', '50% 28%'],
  ['work', 'Work', 'A web design portfolio, one site from every world', '3e90a1e3057c56feda9aa00c4dbcdd5f.webp', '50% 50%'],
  ['services', 'Services', 'Web design, development and SEO services', 'b131e2de14c018247fe2c08587aa79d2.webp', '50% 50%'],
  ['wordpress-websites', 'WordPress & Elementor websites', 'WordPress websites built for your business', 'e37702657907eb0bc2e36a82f2212ce8.jpg', '50% 50%'],
  ['custom-development', 'Custom WordPress development', 'Custom plugins and themes, built to your workflow', '3694c109ea579750e33aef9a769d2f94.webp', '50% 50%'],
  ['seo', 'SEO audit & fixes', 'Why your site is, or isn’t, on Google', '286bece3cd0f08b4ad4ec3f69420675b.png', '50% 50%'],
  ['apps', 'Web & mobile apps', 'Web and mobile apps for small businesses', 'd3f8c1d570d1e654e963ed3ab3bad18f.webp', '50% 50%'],
  ['platforms', 'Shopify, Wix & Squarespace', 'Shopify, Wix and Squarespace sites, built and fixed', '3d7dd89fe2d5f85018e84b655abb7040.jpg', '50% 50%'],
  ['branding', 'Branding, social media & video', 'Branding and content that support your website', '611fb132b1dbd5cec1b65c55e0a4ff7d.jpg', '50% 50%'],
  ['free-audit', 'Free website audit', 'Your top five fixes, reviewed by hand, free', '8bad9500d3d698ebbeff8b1348d8df76.webp', '50% 50%'],
  ['process', 'Process', 'Audit, staging, verified live', '25352af8e18f0bea31d93fe93145daa7.jpg', '50% 50%'],
  ['reviews', 'Reviews', '5.0 from 101 client reviews', 'b5ab7d08d5870755f32a3d0a43e7f82a.jpg', '50% 50%'],
  ['faq', 'FAQ', 'Cost, timelines and who owns what', '7769f266ba201a2928a63e223f18c068.jpg', '50% 50%'],
  ['construction', 'Construction & trades', 'Websites for builders that win more quote requests', 'e37702657907eb0bc2e36a82f2212ce8.jpg', '50% 50%'],
];

const FONTS = '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
  + '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Geist:wght@200;300;500&family=Geist+Mono:wght@500&display=block">';
const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;');

const card = ([, label, line, pic, pos]) => `<!doctype html><html><head><meta charset="utf-8">${FONTS}<style>
*{margin:0;box-sizing:border-box}body{width:1200px;height:630px;background:#0B0B0C;color:#EDE8DF;font-family:Geist,sans-serif;overflow:hidden;position:relative}
.pic{position:absolute;top:0;right:0;width:560px;height:630px;background:url(${img(pic)}) ${pos}/cover no-repeat}
.pic::after{content:"";position:absolute;inset:0;background:linear-gradient(90deg,#0B0B0C 0%,rgba(11,11,12,.55) 38%,rgba(11,11,12,0) 70%),linear-gradient(0deg,rgba(11,11,12,.6),rgba(11,11,12,0) 40%)}
.glow{position:absolute;left:-180px;top:-220px;width:620px;height:620px;border-radius:50%;background:radial-gradient(circle,rgba(255,90,31,.22),rgba(255,90,31,0) 65%)}
.txt{position:absolute;left:72px;top:64px;bottom:60px;width:700px;display:flex;flex-direction:column}
.brand{display:flex;align-items:center;gap:14px;font-size:28px;font-weight:500;letter-spacing:-.4px}.dot{width:16px;height:16px;border-radius:50%;background:#FF5A1F}
.label{margin-top:auto;font-family:'Geist Mono',monospace;font-size:20px;font-weight:500;letter-spacing:2.4px;text-transform:uppercase;color:#FF5A1F}
h1{margin-top:20px;font-size:68px;font-weight:200;line-height:1.02;letter-spacing:-2.8px;max-width:660px}
.foot{margin-top:40px;display:flex;gap:26px;white-space:nowrap;font-family:'Geist Mono',monospace;font-size:15px;letter-spacing:1.4px;text-transform:uppercase;color:#8F8A82}.foot b{color:#EDE8DF;font-weight:500}
</style></head><body><div class="pic"></div><div class="glow"></div><div class="txt"><div class="brand"><span class="dot"></span>Rakib Hasan</div>
<div class="label">${esc(label)}</div><h1>${esc(line)}</h1>
<div class="foot"><span><b>5.0</b> · 101 reviews</span><span><b>250+</b> sites · 14 countries</span><span>rakibhasaan.com</span></div></div></body></html>`;

const mark = (size, round) => `<!doctype html><html><head><meta charset="utf-8">${FONTS}<style>*{margin:0}html,body{width:${size}px;height:${size}px;background:transparent}
.m{width:${size}px;height:${size}px;border-radius:${round}px;background:#0B0B0C;display:flex;align-items:center;justify-content:center;gap:${size >= 512 ? size * 0.04 : 0}px;font-family:Geist,sans-serif;color:#EDE8DF}
.dot{width:${size * (size >= 512 ? 0.16 : 0.4)}px;height:${size * (size >= 512 ? 0.16 : 0.4)}px;border-radius:50%;background:#FF5A1F}.rh{font-size:${size * 0.34}px;font-weight:500;letter-spacing:-${size * 0.012}px}</style></head>
<body><div class="m"><span class="dot"></span><span class="rh">${size >= 512 ? 'RH' : ''}</span></div></body></html>`;

(async () => {
  const br = await chromium.launch();
  const shot = async (html, w, h, file, transparent) => {
    const pg = await br.newPage({ viewport: { width: w, height: h } });
    await pg.setContent(html, { waitUntil: 'networkidle' });
    await pg.evaluate(() => document.fonts.ready);
    await pg.screenshot({ path: path.join(MEDIA, file), omitBackground: !!transparent });
    await pg.close();
    console.log('  + ' + file);
  };
  for (const c of CARDS) await shot(card(c), 1200, 630, 'og-' + c[0] + '.png');
  await shot(mark(512, 0), 512, 512, 'rakib-logo.png');
  await shot(mark(256, 56), 256, 256, 'rakib-favicon.png', true);
  await br.close();
})();
