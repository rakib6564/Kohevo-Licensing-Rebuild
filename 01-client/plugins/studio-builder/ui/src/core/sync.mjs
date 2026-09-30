// Kohevo Studio builder — optimistic editing with server reconciliation.
//
//   server document + revision id (base, authoritative)
//     └─ in-flight operations (sent, awaiting the server)
//         └─ pending operations (local only, not yet sent)
//             = working document shown in the UI (transient)
//
// Rules this engine enforces:
//  - Nothing is ever persisted in the browser. The only durable copy is the
//    server revision; `base` is replaced by the server's document after every
//    confirmed command, and pending edits are re-applied on top of it.
//  - One command in flight at a time, always carrying `expected_revision_id`
//    = the base revision the edits were made against.
//  - A 409 enters the `conflict` state: nothing more is sent, local edits are
//    kept (and counted) but never forced over the newer server revision. The
//    only way out is an explicit reload from the server. No auto-merge.
//  - Autosave: property edits are debounced and coalesced; structural edits
//    (insert/move/remove) flush immediately. Automatic flushes are recorded
//    as `autosave` revisions (the server deduplicates identical ones); an
//    explicit save flushes as `manual`.
//  - A batch never contains more than one insert, so the single id the
//    server mints can be matched to the provisional `tmp_` id unambiguously.
//  - Undo/redo never stores documents: each confirmed batch records only the
//    revision ids before/after it, and undo/redo are server `rollback`
//    commands to those immutable revisions (bounded stack).

import { nodeIds, findNode } from './doc.mjs';
import { applyLocal, enqueueCoalesced, isProvisionalId, referencedIds, remapIds, STRUCTURAL, OPS } from './operations.mjs';

export const STATUS = Object.freeze({
  LOADING: 'loading', IDLE: 'idle', DIRTY: 'dirty', SAVING: 'saving',
  SAVED: 'saved', ERROR: 'error', CONFLICT: 'conflict',
});

const MAX_BATCH = 50;
const MAX_HISTORY = 50;
const RETRY_DELAYS = [2000, 5000, 10000, 30000];

export class SyncEngine {
  /**
   * @param {object} deps
   * @param {object} deps.transport  {operations, rollback, publish, document}
   * @param {number} deps.pageId
   * @param {number} [deps.debounceMs]
   * @param {Function} [deps.schedule] (fn, ms) => handle
   * @param {Function} [deps.cancel]   (handle) => void
   * @param {Function} [deps.onRemap]  (provisionalId, realId) => void
   * @param {Function} [deps.onEvent]  (event) => void  — announcements
   */
  constructor({ transport, pageId, debounceMs = 900, schedule, cancel, onRemap, onEvent }) {
    this.transport = transport;
    this.pageId = pageId;
    this.debounceMs = debounceMs;
    this.schedule = schedule || ((fn, ms) => setTimeout(fn, ms));
    this.cancel = cancel || ((h) => clearTimeout(h));
    this.onRemap = onRemap || (() => {});
    this.onEvent = onEvent || (() => {});
    this.manifest = null;
    this.listeners = new Set();
    this.timer = null;
    this.retryIndex = 0;
    this.busy = null;
    this.state = {
      status: STATUS.LOADING, base: null, page: null, revision: null,
      pending: [], inflight: [], working: null,
      error: null, conflict: null, undo: [], redo: [], lastSavedAt: null,
    };
  }

  // ── Store plumbing (useSyncExternalStore-compatible) ─────────────────────

  subscribe = (fn) => { this.listeners.add(fn); return () => this.listeners.delete(fn); };
  getSnapshot = () => this.state;

  set(patch) {
    this.state = { ...this.state, ...patch };
    this.listeners.forEach((fn) => fn());
  }

  /** Recompute the working document: base + in-flight + pending. */
  rebuild(patch = {}) {
    const next = { ...this.state, ...patch };
    let working = next.base;
    const keep = [];
    for (const entry of [...next.inflight, ...next.pending]) {
      try {
        working = applyLocal(working, entry.op, { manifest: this.manifest, provisionalId: entry.provisionalId });
        keep.push(entry);
      } catch {
        // The target vanished (e.g. its insert was rejected) — drop the edit.
        this.onEvent({ type: 'dropped', entry });
      }
    }
    const pending = next.pending.filter((e) => keep.includes(e));
    this.set({ ...patch, pending, working });
  }

  // ── Loading ─────────────────────────────────────────────────────────────

  /** Load (or reload) the authoritative server state; discards local edits. */
  load({ document, page, revision }, manifest) {
    if (manifest) this.manifest = manifest;
    this.clearTimer();
    this.set({
      status: STATUS.IDLE, base: document, working: document, page, revision,
      pending: [], inflight: [], error: null, conflict: null, undo: [], redo: [],
    });
  }

  async reloadFromServer() {
    this.clearTimer();
    const res = await this.transport.document(this.pageId);
    if (!res.ok) {
      this.set({ status: STATUS.ERROR, error: res.error });
      return false;
    }
    this.load(res.data);
    this.onEvent({ type: 'reloaded' });
    return true;
  }

  get localChangeCount() {
    return this.state.pending.length + this.state.inflight.length;
  }

  get hasUnsavedChanges() {
    return this.localChangeCount > 0;
  }

  // ── Editing ─────────────────────────────────────────────────────────────

  /**
   * Record one canonical operation: applied optimistically now, sent later.
   * @returns {boolean} false when the edit could not be applied locally
   */
  apply(operation, { provisionalId = null, label = '' } = {}) {
    if (this.state.status === STATUS.CONFLICT || this.state.status === STATUS.LOADING) return false;
    let working;
    try {
      working = applyLocal(this.state.working, operation, { manifest: this.manifest, provisionalId });
    } catch {
      return false;
    }
    const entry = { op: operation, provisionalId, label };
    const pending = enqueueCoalesced(this.state.pending, entry);
    this.set({ pending, working, redo: [], status: this.state.inflight.length ? STATUS.SAVING : STATUS.DIRTY, error: null });
    this.scheduleFlush(STRUCTURAL.has(operation.op) ? 0 : this.debounceMs);
    return true;
  }

  scheduleFlush(ms) {
    this.clearTimer();
    this.timer = this.schedule(() => { this.timer = null; this.flush('autosave'); }, ms);
  }

  clearTimer() {
    if (this.timer !== null) {
      this.cancel(this.timer);
      this.timer = null;
    }
  }

  /** Run one server command at a time. */
  exclusive(task) {
    const run = async () => {
      try { return await task(); } finally { this.busy = null; }
    };
    this.busy = (this.busy || Promise.resolve()).then(run, run);
    return this.busy;
  }

  /** Send pending operations; `kind` is `autosave` (automatic) or `manual` (explicit save). */
  flush(kind = 'autosave') {
    return this.exclusive(() => this.flushNow(kind));
  }

  async flushNow(kind) {
    const s = this.state;
    if (s.status === STATUS.CONFLICT || !s.pending.length || s.inflight.length) return true;

    // Batch: up to MAX_BATCH operations, ending at (and including) the first insert.
    const batch = [];
    for (const entry of s.pending) {
      if (batch.length >= MAX_BATCH) break;
      // An op whose target is still provisional (its insert failed or is not
      // yet confirmed) cannot be sent; stop the batch before it.
      if (referencedIds(entry.op).some(isProvisionalId)) break;
      batch.push(entry);
      if (entry.op.op === OPS.INSERT_BLOCK || entry.op.op === OPS.INSERT_SECTION) break;
    }
    if (!batch.length) {
      // Only edits of a node whose insert never got a server id remain: they
      // can never be sent, so drop them rather than stay "unsaved" forever.
      this.rebuild({ pending: s.pending.filter((e) => !referencedIds(e.op).some(isProvisionalId)) });
      if (!this.state.pending.length && this.state.status === STATUS.DIRTY) this.set({ status: STATUS.SAVED });
      return true;
    }

    const before = s.revision ? s.revision.id : null;
    const beforeIds = nodeIds(s.base);
    this.set({ inflight: batch, pending: s.pending.slice(batch.length), status: STATUS.SAVING });

    const res = await this.transport.operations({
      page_id: this.pageId,
      expected_revision_id: before,
      revision_kind: kind,
      operations: batch.map((e) => e.op),
    });

    if (res.ok) {
      this.retryIndex = 0;
      const data = res.data;
      // Match the one server-minted id to the provisional id of the batch's insert.
      const insert = batch.find((e) => e.provisionalId);
      const map = new Map();
      if (insert) {
        const created = [...nodeIds(data.document)].filter((id) => !beforeIds.has(id));
        if (created.length === 1) map.set(insert.provisionalId, created[0]);
      }
      const pending = this.state.pending.map((e) => ({ ...e, op: map.size ? remapIds(e.op, map) : e.op }));
      map.forEach((real, tmp) => this.onRemap(tmp, real));

      const after = data.revision ? data.revision.id : before;
      const undo = !data.deduplicated && before && after && after !== before
        ? [...this.state.undo, { before, after, label: batch.map((e) => e.label).filter(Boolean).pop() || '' }].slice(-MAX_HISTORY)
        : this.state.undo;
      this.rebuild({
        base: data.document, page: data.page, revision: data.revision || this.state.revision,
        inflight: [], pending, undo,
        status: pending.length ? STATUS.DIRTY : STATUS.SAVED,
        lastSavedAt: Date.now(), error: null,
      });
      this.onEvent({ type: 'saved', revision: data.revision, deduplicated: !!data.deduplicated });
      if (this.state.pending.length) this.scheduleFlush(this.state.pending.some((e) => STRUCTURAL.has(e.op.op)) ? 0 : this.debounceMs);
      return true;
    }

    return this.handleFailure(res, batch);
  }

  handleFailure(res, batch) {
    const code = res.error && res.error.code;
    if (code === 'concurrency_conflict') {
      this.clearTimer();
      this.set({
        inflight: [], pending: [...batch, ...this.state.pending], status: STATUS.CONFLICT,
        conflict: {
          currentRevisionId: res.error.details ? res.error.details.current_revision_id : null,
          expectedRevisionId: this.state.revision ? this.state.revision.id : null,
          localChangeCount: batch.length + this.state.pending.length,
        },
        error: res.error,
      });
      this.onEvent({ type: 'conflict' });
      return false;
    }
    if (code === 'validation_error') {
      // The server rejected these edits: drop them, keep everything else.
      this.rebuild({ inflight: [], status: STATUS.ERROR, error: res.error });
      this.onEvent({ type: 'rejected', error: res.error });
      if (this.state.pending.length) this.scheduleFlush(this.debounceMs);
      return false;
    }
    // Transient (network, 5xx, rate limit) or fatal (auth/permission): keep the
    // edits as pending. Only transient failures retry automatically.
    const transient = !code || ['network_error', 'server_error', 'rate_limited', 'timeout'].includes(code);
    this.set({ inflight: [], pending: [...batch, ...this.state.pending], status: STATUS.ERROR, error: res.error });
    this.onEvent({ type: 'failed', error: res.error });
    if (transient) {
      const delay = RETRY_DELAYS[Math.min(this.retryIndex, RETRY_DELAYS.length - 1)];
      this.retryIndex += 1;
      this.scheduleFlush(delay);
    }
    return false;
  }

  /** Flush everything (repeatedly) — used before publish / undo / leaving. */
  async drain(kind = 'autosave') {
    for (let i = 0; i < 20 && this.state.pending.length && this.state.status !== STATUS.CONFLICT; i += 1) {
      const ok = await this.flush(kind);
      if (!ok) return false;
    }
    return !this.state.pending.length && this.state.status !== STATUS.CONFLICT;
  }

  /** Explicit save (Ctrl+S): pending edits become a `manual` revision. */
  save() {
    this.clearTimer();
    return this.drain('manual');
  }

  // ── Undo / redo via rollback to immutable revisions ────────────────────

  get canUndo() { return this.state.undo.length > 0 && this.state.status !== STATUS.CONFLICT; }
  get canRedo() { return this.state.redo.length > 0 && this.state.status !== STATUS.CONFLICT; }

  async undo() {
    if (!(await this.drain())) return false;
    const entry = this.state.undo[this.state.undo.length - 1];
    if (!entry) return false;
    const ok = await this.rollbackTo(entry.before, 'undo');
    if (ok) this.set({ undo: this.state.undo.slice(0, -1), redo: [...this.state.redo, entry].slice(-MAX_HISTORY) });
    return ok;
  }

  async redo() {
    if (!(await this.drain())) return false;
    const entry = this.state.redo[this.state.redo.length - 1];
    if (!entry) return false;
    const ok = await this.rollbackTo(entry.after, 'redo');
    if (ok) this.set({ redo: this.state.redo.slice(0, -1), undo: [...this.state.undo, entry].slice(-MAX_HISTORY) });
    return ok;
  }

  /** Restore an earlier revision (history panel, undo, redo) — a server `rollback`. */
  rollbackTo(targetRevisionId, reason = 'restore') {
    return this.exclusive(async () => {
      if (this.state.status === STATUS.CONFLICT) return false;
      const before = this.state.revision ? this.state.revision.id : null;
      this.set({ status: STATUS.SAVING });
      const res = await this.transport.rollback({
        page_id: this.pageId,
        target_revision_id: targetRevisionId,
        expected_revision_id: this.state.revision ? this.state.revision.id : null,
      });
      if (!res.ok) return this.handleFailure(res, []);
      const d = res.data;
      // A history restore is itself undoable (undo/redo manage their own stacks).
      const undo = reason === 'restore' && before && d.revision
        ? [...this.state.undo, { before, after: d.revision.id, label: 'restore' }].slice(-MAX_HISTORY)
        : this.state.undo;
      this.rebuild({ base: d.document, page: d.page, revision: d.revision, undo, redo: reason === 'restore' ? [] : this.state.redo, status: STATUS.SAVED, error: null, lastSavedAt: Date.now() });
      this.onEvent({ type: reason, revision: d.revision });
      return true;
    });
  }

  async publish() {
    if (!(await this.drain('manual'))) return false;
    return this.exclusive(async () => {
      if (this.state.status === STATUS.CONFLICT) return false;
      this.set({ status: STATUS.SAVING });
      const res = await this.transport.publish({
        page_id: this.pageId,
        expected_revision_id: this.state.revision ? this.state.revision.id : null,
      });
      if (!res.ok) return this.handleFailure(res, []);
      const d = res.data;
      this.rebuild({ base: d.document || this.state.base, page: d.page, revision: d.revision, status: STATUS.SAVED, error: null });
      this.onEvent({ type: 'published', revision: d.revision });
      return true;
    });
  }

  /** The node for an id in the working document (provisional ids included). */
  node(id) {
    return findNode(this.state.working, id);
  }
}
