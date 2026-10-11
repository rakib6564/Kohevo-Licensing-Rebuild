// Installs the Fieldnote site into a Kohevo Studio tenant through the builder's own authoring API:
// design tokens, custom CSS, the fonts <link>, a shared header and footer, and the pages.
//
//   FN_BASE=http://localhost:8201 node tools/install.mjs         → a local sandbox (signs in with its throw-away credentials)
//   FN_BASE=https://example.com/site node tools/install.mjs      → a real site: a browser opens, YOU sign in
//   node tools/install.mjs home work                             → only those slugs
import { session } from './session.mjs';
import { applySiteSetup } from './setup.mjs';
import * as P from '../src/pages.mjs';

const base = process.env.FN_BASE || 'http://localhost:8201';
const only = process.argv.slice(2);
const PAGES = [
  { slug: 'default', title: 'Header', page_type: 'header_partial', route_mode: 'standalone', doc: P.headerDoc },
  { slug: 'default', title: 'Footer', page_type: 'footer_partial', route_mode: 'standalone', doc: P.footerDoc },
  { slug: 'home', title: 'Fieldnote — Websites and brands people remember', page_type: 'page', route_mode: 'homepage', doc: P.homeDoc, seo: { description: 'Fieldnote is a small, senior design and web studio in Antwerp.' } },
  ...P.EXTRA_PAGES.map((p) => ({ ...p, page_type: 'page', route_mode: 'standalone' })),
];

const { browser, page, api: rawApi, local } = await session(base);
const api = async (...a) => { for (let i = 0; i < 12; i++) { const r = await rawApi(...a); if (r.s !== 429) return r; await new Promise((ok) => setTimeout(ok, 4000)); } return rawApi(...a); };
const must = (r, what) => { if (r.s >= 300 || !r.j?.ok) throw new Error(`${what} failed: HTTP ${r.s} ${JSON.stringify(r.j).slice(0, 600)}`); return r.j.data; };
try {
  await page.goto('plugins/studio-builder/admin/pages.php');
  await applySiteSetup(page, api, must);
  const existing = must(await api('GET', 'pages', null, ''), 'pages').pages || [];
  console.log('pages on the site now:', existing.map((p) => `${p.slug} (${p.page_type})`).join(', ') || 'none');
  const failed = [];
  for (const spec of PAGES) {
    if (only.length && !only.includes(spec.slug)) continue;
    try {
    let row = existing.find((p) => p.slug === spec.slug && p.page_type === spec.page_type);
    if (!row) row = must(await api('POST', 'create_page', { title: spec.title, slug: spec.slug, page_type: spec.page_type, route_mode: spec.route_mode }), `create ${spec.slug}`).page;
    const cur = must(await api('GET', 'document', null, `&page=${row.id}`), `document ${spec.slug}`);
    const doc = cur.document;
    doc.sections = spec.doc();
    doc.seo = { ...doc.seo, title: spec.title, ...(spec.seo || {}) };
    const saved = must(await api('POST', 'save_draft', { page_id: row.id, expected_revision_id: cur.page.active_draft_revision_id, revision_kind: 'manual', document: doc, summary: 'Fieldnote site' }), `save ${spec.slug}`);
    const rev = saved.revision?.id ?? saved.page?.active_draft_revision_id;
    const pub = await api('POST', 'publish', { page_id: row.id, expected_revision_id: rev, summary: 'Publish' });
    console.log(spec.page_type.padEnd(15), spec.slug.padEnd(24), 'published', pub.s === 200 ? 'ok' : `FAILED ${pub.s} ${JSON.stringify(pub.j).slice(0, 300)}`);
    } catch (e) { failed.push(spec.slug); console.log(spec.page_type.padEnd(15), spec.slug.padEnd(24), 'SKIPPED:', String(e.message).slice(0, 300)); }
  }
  if (failed.length) console.log('Not installed:', failed.join(', '));
  console.log(local ? `Done. Open ${base}/` : 'Done. Open your site’s home page.');
} finally {
  await browser.close();
}
