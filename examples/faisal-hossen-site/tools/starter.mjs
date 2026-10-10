// Builds a starter site from the installed library, the way the builder does: create a page, apply a template, publish.
//
//   node tools/starter.mjs                    → header, footer and Home (the homepage)
//   node tools/starter.mjs work about contact → also those pages (slugs: work, about, contact; case-study → /case-study)
import { session } from './session.mjs';

const base = process.env.FH_BASE || 'http://localhost:8200';
const want = process.argv.slice(2);
const PLAN = [
  { slug: 'default', title: 'Header', page_type: 'header_partial', route_mode: 'standalone', tpl: 'kh-header' },
  { slug: 'default', title: 'Footer', page_type: 'footer_partial', route_mode: 'standalone', tpl: 'kh-footer' },
  { slug: 'home', title: 'Home', page_type: 'page', route_mode: 'homepage', tpl: 'kh-page-home' },
  ...['work', 'about', 'contact', 'case-study'].filter((s) => want.includes(s)).map((s) => ({ slug: s, title: s.replace('-', ' ').replace(/^./, (c) => c.toUpperCase()), page_type: 'page', route_mode: 'standalone', tpl: `kh-page-${s}` })),
];
const { browser, api } = await session(base);
const must = (r, what) => { if (r.s >= 300 || !r.j?.ok) throw new Error(`${what} failed: HTTP ${r.s} ${JSON.stringify(r.j).slice(0, 600)}`); return r.j.data; };
try {
  for (const p of PLAN) {
    const row = must(await api('POST', 'create_page', { title: p.title, slug: p.slug, page_type: p.page_type, route_mode: p.route_mode }), `create ${p.slug}`).page;
    const cur = must(await api('GET', 'document', null, `&page=${row.id}`), `document ${p.slug}`);
    const applied = must(await api('POST', 'apply_template', { page_id: row.id, template_key: p.tpl, expected_revision_id: cur.page.active_draft_revision_id }), `apply ${p.tpl}`);
    const rev = applied.revision?.id ?? applied.page?.active_draft_revision_id;
    const pub = await api('POST', 'publish', { page_id: row.id, expected_revision_id: rev, summary: 'Publish' });
    console.log(p.page_type.padEnd(15), p.slug.padEnd(12), p.tpl.padEnd(20), pub.s < 300 && pub.j?.ok ? 'published' : `FAILED ${pub.s} ${JSON.stringify(pub.j).slice(0, 300)}`);
  }
} finally {
  await browser.close();
}
