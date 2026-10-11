// Archives every page (and renames their slugs so the old URLs are free) of a LOCAL sandbox (pages, templates, header and footer), so the site can be rebuilt from the library.
// Studio has no hard delete: archived pages leave the site and the Pages list. Refuses any non-local site.
//
//   node tools/wipe.mjs            → http://localhost:8201
import { session } from './session.mjs';

const base = process.env.FN_BASE || 'http://localhost:8201';
const { browser, api: rawApi, local } = await session(base);
// the API rate-limits bursts: pause between calls and retry a 429 after a wait
const api = async (...a) => { for (let i = 0; i < 12; i++) { const r = await rawApi(...a); if (r.s !== 429) { await new Promise((ok) => setTimeout(ok, 250)); return r; } await new Promise((ok) => setTimeout(ok, 4000)); } return rawApi(...a); };
try {
  if (!local) throw new Error('wipe.mjs only runs against a local sandbox.');
  const rows = (await api('GET', 'pages', null, '')).j?.data?.pages || [];
  for (const p of rows) {
    const r = await api('POST', 'archive_page', { page_id: p.id });
    // an archived page keeps its slug, which would block a new page with the same one: free it
    if (r.s === 200) await api('POST', 'update_page', { page_id: p.id, slug: `${p.slug}-archived-${p.id}`.slice(0, 190) });
    console.log(String(p.id).padStart(4), (p.page_type || '').padEnd(15), (p.slug || '').padEnd(28), r.s === 200 ? 'archived' : `FAILED ${r.s} ${JSON.stringify(r.j).slice(0, 160)}`);
  }
  const left = (await api('GET', 'pages', null, '')).j?.data?.pages || [];
  console.log(`${rows.length} page(s) archived, ${left.length} left.`);
} finally {
  await browser.close();
}
