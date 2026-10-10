// Archives every page (and renames their slugs so the old URLs are free) of a LOCAL sandbox (pages, templates, header and footer), so the site can be rebuilt from the library.
// Studio has no hard delete: archived pages leave the site and the Pages list. Refuses any non-local site.
//
//   node tools/wipe.mjs            → http://localhost:8200
import { session } from './session.mjs';

const base = process.env.FH_BASE || 'http://localhost:8200';
const { browser, api, local } = await session(base);
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
