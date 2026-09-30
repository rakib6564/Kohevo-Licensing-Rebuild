// Shared fixtures for the builder's node:test suites.
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));

/** The REAL editor manifest shape, generated from the PHP block registry. */
export const manifest = JSON.parse(readFileSync(process.env.SBX_MANIFEST_FIXTURE || join(here, 'fixtures', 'manifest.json'), 'utf8'));

let n = 0;
const id = (p) => `${p}_${String(++n).padStart(24, '0')}`;

export function block(type, props = {}, children = []) {
  return {
    bindings: [], children, id: id('blk'), props, type, version: 1,
    style: { align: { base: 'left' }, font_token: null, radius_token: null, shadow_token: null, spacing_token: null, surface_token: null, text_token: null },
    visibility: { auth_state: 'any', devices: ['base', 'sm', 'md', 'lg'] },
  };
}

export function section(blocks = [], label = 'Main') {
  return {
    blocks, global_ref: null, id: id('sec'), label,
    layout: { background_token: 'surface.primary', columns: { base: 1, md: 12 }, gap: 'md', padding_y: { base: 'md', md: 'lg' }, width: 'wide' },
    visibility: { auth_state: 'any', devices: ['base', 'sm', 'md', 'lg'] },
  };
}

export function doc(sections = []) {
  return {
    document_type: 'page', schema_version: '1.0', sections,
    seo: { canonical_url: null, description: '', og_image_media_id: null, robots: 'index,follow', title: 'T' },
    settings: { container_width: 'wide', footer_mode: 'inherit', header_mode: 'inherit', token_group: 'default' },
    template_key: 'default',
  };
}

export const heading = (text) => block('core.heading', { level: 'h2', text });
export const container = (children = []) => block('core.container', { direction: 'vertical', gap: 'md' }, children);

/** A deterministic scheduler: timers run only when flushed by the test. */
export function manualScheduler() {
  const timers = new Map();
  let seq = 0;
  return {
    schedule: (fn, ms) => { seq += 1; timers.set(seq, { fn, ms }); return seq; },
    cancel: (h) => { timers.delete(h); },
    pending: () => [...timers.values()],
    async runAll() {
      const list = [...timers.entries()];
      timers.clear();
      for (const [, t] of list) t.fn();
      await new Promise((r) => setTimeout(r, 0));
    },
  };
}

/**
 * An in-memory stand-in for the server command API with the SAME contract as
 * admin/api.php: expected_revision_id is enforced (409), ids are minted
 * server-side, the stored document is authoritative, rollback creates a new
 * revision from an immutable old one.
 */
export function fakeServer(initialDoc, applyServer) {
  const revisions = new Map();
  let nextId = 100;
  let current = null;
  const page = () => ({ id: 1, title: 'P', public_path: '/p', is_published: false, has_unpublished_changes: true, active_draft_revision_id: current });
  const commit = (d, kind) => {
    nextId += 1;
    revisions.set(nextId, { doc: JSON.parse(JSON.stringify(d)), kind, number: revisions.size + 1 });
    current = nextId;
    return nextId;
  };
  commit(initialDoc, 'manual');
  const snapshot = () => ({ document: JSON.parse(JSON.stringify(revisions.get(current).doc)), page: page(), revision: { id: current, revision_kind: revisions.get(current).kind, revision_number: revisions.get(current).number } });
  const calls = [];
  const conflict = () => ({ ok: false, status: 409, error: { code: 'concurrency_conflict', message: '', details: { current_revision_id: current, expected_revision_id: null } } });
  const server = {
    calls,
    failNext: null,
    get currentId() { return current; },
    get currentDoc() { return revisions.get(current).doc; },
    /** Another editor saves a change behind our back. */
    externalEdit(mutator) {
      const d = JSON.parse(JSON.stringify(revisions.get(current).doc));
      mutator(d);
      commit(d, 'manual');
    },
    transport: {
      bootstrap: async () => ({ ok: true, status: 200, data: { ...snapshot(), manifest: {} } }),
      document: async () => ({ ok: true, status: 200, data: snapshot() }),
      async operations(body) {
        calls.push(['operations', JSON.parse(JSON.stringify(body))]);
        if (server.failNext) { const f = server.failNext; server.failNext = null; return f; }
        if (body.expected_revision_id !== current) return conflict();
        let d = JSON.parse(JSON.stringify(revisions.get(current).doc));
        try {
          for (const o of body.operations) d = applyServer(d, o);
        } catch (e) {
          return { ok: false, status: 422, error: { code: 'validation_error', message: '', details: { errors: [{ path: '$', code: 'x', message: String(e.message) }] } } };
        }
        const same = JSON.stringify(d) === JSON.stringify(revisions.get(current).doc);
        if (body.revision_kind === 'autosave' && same) return { ok: true, status: 200, data: { ...snapshot(), deduplicated: true } };
        commit(d, body.revision_kind);
        return { ok: true, status: 200, data: { ...snapshot(), deduplicated: false } };
      },
      async rollback(body) {
        calls.push(['rollback', { ...body }]);
        if (body.expected_revision_id !== current) return conflict();
        const target = revisions.get(body.target_revision_id);
        if (!target) return { ok: false, status: 404, error: { code: 'not_found', message: '' } };
        commit(target.doc, 'rollback');
        return { ok: true, status: 200, data: { ...snapshot(), deduplicated: false } };
      },
      async publish(body) {
        calls.push(['publish', { ...body }]);
        if (body.expected_revision_id !== current) return conflict();
        commit(revisions.get(current).doc, 'publish');
        return { ok: true, status: 200, data: { ...snapshot(), deduplicated: false } };
      },
    },
  };
  return server;
}
