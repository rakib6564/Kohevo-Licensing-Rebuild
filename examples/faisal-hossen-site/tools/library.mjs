// Installs the Kohevo template library into a site: design tokens, Custom CSS and fonts, a placeholder image,
// then every template (pages, sections, blocks, header, footer) saved through the builder's own `save_template`.
//
//   node tools/library.mjs                                   → local sandbox (http://localhost:8200)
//   FH_BASE=https://example.com/site node tools/library.mjs  → a real site: a browser opens, YOU sign in
//
// Re-running replaces the kh-* templates in place. Nothing is published and no page is left behind: each template is
// cut from a temporary draft page that is archived afterwards.
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { chromium } from 'playwright';
import { session } from './session.mjs';
import { uploadFiles } from './media.mjs';
import { applySiteSetup } from './setup.mjs';
import { catalogue, LIB } from '../src/library.mjs';

const base = process.env.FH_BASE || 'http://localhost:8200';
const stamp = Date.now().toString(36);

async function placeholderPng() {
  const file = path.join(os.tmpdir(), 'kh-placeholder.png');
  const b = await chromium.launch();
  const pg = await b.newPage({ viewport: { width: 1600, height: 1000 } });
  await pg.setContent('<body style="margin:0;display:grid;place-items:center;height:100vh;background:#dcdad4;color:#6f716b;font:500 44px Inter,system-ui,sans-serif">Placeholder image</body>');
  await pg.screenshot({ path: file });
  await b.close();
  return file;
}

const { browser, page, api, local } = await session(base);
const must = (r, what) => { if (r.s >= 300 || !r.j?.ok) throw new Error(`${what} failed: HTTP ${r.s} ${JSON.stringify(r.j).slice(0, 700)}`); return r.j.data; };
const staged = [];
async function stage(slug, title, page_type, sections) {
  const row = must(await api('POST', 'create_page', { title, slug: `${slug}-${stamp}`, page_type, route_mode: 'standalone' }), `create ${slug}`).page;
  staged.push(row.id);
  const cur = must(await api('GET', 'document', null, `&page=${row.id}`), `document ${slug}`);
  const doc = cur.document;
  doc.sections = sections;
  must(await api('POST', 'save_draft', { page_id: row.id, expected_revision_id: cur.page.active_draft_revision_id, revision_kind: 'manual', document: doc, summary: 'Template source' }), `save ${slug}`);
  return row.id;
}
async function save(entry, page_id, node_id) {
  await api('POST', 'delete_template', { template_key: entry.key });
  const r = await api('POST', 'save_template', { page_id, node_id, template_key: entry.key, template_type: entry.type, category: entry.category, name: entry.name, description: entry.description || null, thumbnail_media_id: null });
  console.log(entry.type.padEnd(15), entry.key.padEnd(28), r.s < 300 && r.j?.ok ? 'saved' : `FAILED ${r.s} ${JSON.stringify(r.j).slice(0, 400)}`);
  return r.s < 300 && !!r.j?.ok;
}

let failed = 0;
try {
  await page.goto('plugins/studio-builder/admin/pages.php');
  await applySiteSetup(page, api, must);
  LIB.placeholderMedia = process.env.FH_PLACEHOLDER ? Number(process.env.FH_PLACEHOLDER) : (await uploadFiles(page, [await placeholderPng()]))[0];
  console.log('placeholder media', LIB.placeholderMedia);
  await page.goto('plugins/studio-builder/admin/pages.php');

  const cat = catalogue();                       // built after the placeholder id is known
  const ok = [];

  for (const e of cat.pages) ok.push(await save(e, await stage(`kh-src-${e.key.slice(8)}`, e.name, 'page', e.sections), null));
  for (const e of cat.chrome) ok.push(await save(e, await stage(`kh-src-${e.key.slice(3)}`, e.name, e.type === 'header_preset' ? 'header_partial' : 'footer_partial', e.sections), null));

  // Sections and blocks are cut from draft pages too; a document holds at most 250 blocks, so they are packed into several.
  const count = (n) => 1 + (n.children || []).reduce((a, c) => a + count(c), 0);
  const shell = (e, node) => ({ id: cat.reid({ blocks: [] }, e.key).id, label: `Block: ${e.name}`, global_ref: null, layout: { background_token: 'surface.primary', columns: { base: 1, md: 1 }, gap: 'md', padding_y: { base: 'md', md: 'md' }, width: 'wide' }, visibility: { auth_state: 'any', devices: ['base', 'sm', 'md', 'lg'] }, blocks: [node] });
  const items = [
    ...cat.sections.map((e) => { const node = cat.reid(e.section, e.key); return { e, section: node, nodeId: node.id, size: node.blocks.reduce((a, b) => a + count(b), 0) }; }),
    ...cat.blocks.map((e) => { const node = cat.reid(e.block, e.key); return { e, section: shell(e, node), nodeId: node.id, size: count(node) }; }),
  ];
  const batches = [[]]; let used = 0;
  for (const it of items) { if (used + it.size > 230) { batches.push([]); used = 0; } batches[batches.length - 1].push(it); used += it.size; }
  for (const [n, batch] of batches.entries()) {
    const pageId = await stage(`kh-src-parts-${n + 1}`, `Template source ${n + 1}`, 'page', batch.map((it) => it.section));
    for (const it of batch) ok.push(await save(it.e, pageId, it.nodeId));
  }
  failed = ok.filter((x) => !x).length;
} finally {
  for (const id of staged) { const r = await api('POST', 'archive_page', { page_id: id }); if (r.s === 200) await api('POST', 'update_page', { page_id: id, slug: `kh-src-archived-${id}` }); }
  await browser.close();
}
console.log(failed ? `${failed} template(s) failed.` : 'Library installed. Open the builder → Library.');
process.exit(failed ? 1 : 0);
