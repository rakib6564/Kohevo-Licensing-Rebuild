// StudioShell — the builder's top-level layout and action hub.
//
//   StudioShell
//   ├── TopBar        page, save state, undo/redo, viewport, preview, publish
//   ├── LeftPanel     block palette + structure outline (insert / reorder)
//   ├── CanvasArea    sandboxed iframe of the server's renderForEditor() output
//   └── RightPanel    metadata-driven property panels for the selection
//
// The shell owns transient UI state only (selection, viewport, dialogs). The
// document lives in the SyncEngine (server revision + optimistic operations);
// every change is a canonical operation sent through the command API.

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { EditorContext } from './EditorContext.jsx';
import { TopBar } from './TopBar.jsx';
import { LeftPanel } from './LeftPanel.jsx';
import { CanvasArea } from './CanvasArea.jsx';
import { RightPanel } from './RightPanel.jsx';
import { LiveRegion } from './LiveRegion.jsx';
import { ConflictBanner } from './ConflictBanner.jsx';
import { InsertDialog } from './InsertDialog.jsx';
import { HistoryDialog } from './HistoryDialog.jsx';
import { createTransport } from '../core/api.mjs';
import { SyncEngine, STATUS } from '../core/sync.mjs';
import { EditLock } from '../core/lock.mjs';
import { t, errorMessage } from '../core/messages.mjs';
import { blockDefinition, findNode, insertionPoint, nodeLabel, sectionsOf } from '../core/doc.mjs';
import { defaultBindings, setupFields } from '../core/fields.mjs';
import * as ops from '../core/operations.mjs';
import { viewportByKey } from '../core/viewport.mjs';

export function StudioShell({ boot, transport: injectedTransport = null, lockEnabled = true }) {
  const transport = useMemo(
    () => injectedTransport || createTransport({ apiUrl: boot.apiUrl, csrfToken: boot.csrfToken }),
    [boot.apiUrl, boot.csrfToken, injectedTransport],
  );
  const [selection, setSelection] = useState(null);
  const selectionRef = useRef(null);
  selectionRef.current = selection;
  const [announcement, setAnnouncement] = useState('');
  const [viewportKey, setViewportKey] = useState('desktop');
  const [manifest, setManifest] = useState(null);
  const [loadError, setLoadError] = useState(null);
  const [pendingInsert, setPendingInsert] = useState(null);
  const [historyOpen, setHistoryOpen] = useState(false);
  const [lockState, setLockState] = useState({ held: false, otherEditor: false });

  const announce = useCallback((text) => {
    // Re-announce identical text by clearing first.
    setAnnouncement('');
    setTimeout(() => setAnnouncement(text), 30);
  }, []);

  const engine = useMemo(() => new SyncEngine({
    transport,
    pageId: boot.pageId,
    onRemap: (tmp, real) => {
      if (selectionRef.current === tmp) setSelection(real);
    },
    onEvent: (e) => {
      switch (e.type) {
        case 'saved': announce(t('announce_saved')); break;
        case 'conflict': announce(t('announce_conflict')); break;
        case 'rejected': announce(t('announce_rejected')); break;
        case 'failed': announce(errorMessage(e.error)); break;
        case 'undo': announce(t('announce_undo')); break;
        case 'redo': announce(t('announce_redo')); break;
        case 'published': announce(t('announce_published')); break;
        default: break;
      }
    },
  }), [transport, boot.pageId, announce]);

  // ── Load from the server (the only source of the document) ─────────────
  const load = useCallback(async () => {
    setLoadError(null);
    const res = await transport.bootstrap(boot.pageId);
    if (!res.ok) {
      setLoadError(res.error);
      return;
    }
    setManifest(res.data.manifest);
    engine.load(res.data, res.data.manifest);
  }, [transport, boot.pageId, engine]);

  useEffect(() => { load(); }, [load]);

  // ── Advisory edit lock ─────────────────────────────────────────────────
  useEffect(() => {
    if (!lockEnabled || !manifest) return undefined;
    const lock = new EditLock({ transport, pageId: boot.pageId, onChange: setLockState });
    lock.start();
    const release = () => { lock.stop(); };
    window.addEventListener('pagehide', release);
    return () => {
      window.removeEventListener('pagehide', release);
      lock.stop();
    };
  }, [lockEnabled, manifest, transport, boot.pageId]);

  // ── Warn before leaving with unsent edits (nothing is kept in the browser) ─
  useEffect(() => {
    const onBeforeUnload = (e) => {
      if (engine.hasUnsavedChanges) {
        e.preventDefault();
        e.returnValue = t('unsaved_leave');
        return t('unsaved_leave');
      }
      return undefined;
    };
    window.addEventListener('beforeunload', onBeforeUnload);
    return () => window.removeEventListener('beforeunload', onBeforeUnload);
  }, [engine]);

  // ── Actions ────────────────────────────────────────────────────────────
  const labelOf = useCallback((id) => {
    const info = findNode(engine.getSnapshot().working, id);
    return info ? nodeLabel(info.node, manifest, info.kind) : '';
  }, [engine, manifest]);

  const select = useCallback((id, { announceIt = true } = {}) => {
    setSelection(id);
    if (id && announceIt) announce(t('announce_selected', { label: labelOf(id) }));
  }, [announce, labelOf]);

  const applyOp = useCallback((operation, options = {}) => engine.apply(operation, options), [engine]);

  const insertSection = useCallback((index) => {
    const working = engine.getSnapshot().working;
    const at = Number.isInteger(index) ? index : sectionsOf(working).length;
    const provisionalId = ops.provisionalId('sec');
    if (engine.apply(ops.insertSection(at, { label: t('section') }), { provisionalId, label: t('section') })) {
      setSelection(provisionalId);
      announce(t('announce_inserted', { label: t('section') }));
    }
    return provisionalId;
  }, [engine, announce]);

  const commitInsert = useCallback((type, target, props = null, bindings = null) => {
    const def = blockDefinition(manifest, type);
    const provisionalId = ops.provisionalId('blk');
    const block = { type };
    if (props) block.props = { ...(def ? def.default_props : {}), ...props };
    if (bindings && Object.keys(bindings).length) block.bindings = bindings;
    if (engine.apply(ops.insertBlock(target.parentId, target.index, block), { provisionalId, label: def ? def.label : type })) {
      setSelection(provisionalId);
      announce(t('announce_inserted', { label: def ? def.label : type }));
    }
  }, [engine, manifest, announce]);

  /** Insert a block: at `target` or the natural insertion point; asks for required setup first. */
  const insertBlock = useCallback((type, target = null) => {
    const def = blockDefinition(manifest, type);
    if (!def) return;
    let where = target || insertionPoint(engine.getSnapshot().working, manifest, selectionRef.current, type);
    if (!where) {
      const sectionId = insertSection();
      where = { parentId: sectionId, index: 0 };
    }
    const setup = setupFields(def);
    const { bindings, needsParams } = defaultBindings(def, manifest);
    if (setup.length || needsParams.length) {
      setPendingInsert({ type, def, target: where, setup, bindings, needsParams });
      return;
    }
    commitInsert(type, where, null, bindings);
  }, [engine, manifest, insertSection, commitInsert]);

  const removeNode = useCallback((id) => {
    const info = findNode(engine.getSnapshot().working, id);
    if (!info) return;
    const label = nodeLabel(info.node, manifest, info.kind);
    if (info.kind === 'section' && info.node.blocks && info.node.blocks.length && !window.confirm(t('confirm_remove_section'))) return;
    const operation = info.kind === 'section' ? ops.removeSection(id) : ops.removeBlock(id);
    if (engine.apply(operation, { label })) {
      if (selectionRef.current === id) setSelection(info.kind === 'block' ? info.parentId : null);
      announce(t('announce_removed', { label }));
    }
  }, [engine, manifest, announce]);

  const moveBlockTo = useCallback((id, target) => {
    if (!target) return;
    if (engine.apply(ops.moveBlock(id, target.parentId, target.index), { label: labelOf(id) })) {
      announce(t('announce_moved', { label: labelOf(id) }));
    }
  }, [engine, labelOf, announce]);

  const moveSectionTo = useCallback((id, toIndex) => {
    if (engine.apply(ops.moveSection(id, toIndex), { label: labelOf(id) })) {
      announce(t('announce_moved', { label: labelOf(id) }));
    }
  }, [engine, labelOf, announce]);

  const save = useCallback(() => engine.save(), [engine]);
  const undo = useCallback(() => engine.undo(), [engine]);
  const redo = useCallback(() => engine.redo(), [engine]);
  const publish = useCallback(() => engine.publish(), [engine]);
  const reload = useCallback(async () => {
    const ok = await engine.reloadFromServer();
    if (ok) {
      const working = engine.getSnapshot().working;
      if (selectionRef.current && !findNode(working, selectionRef.current)) setSelection(null);
    }
  }, [engine]);

  const changeViewport = useCallback((key) => {
    setViewportKey(key);
    announce(t('announce_viewport', { label: t(key) }));
  }, [announce]);

  // ── Keyboard shortcuts (never while typing in a field, except save) ────
  useEffect(() => {
    const onKey = (e) => {
      const mod = e.metaKey || e.ctrlKey;
      const typing = e.target && (e.target.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName));
      if (mod && e.key.toLowerCase() === 's') { e.preventDefault(); save(); return; }
      if (typing) return;
      if (mod && e.key.toLowerCase() === 'z' && !e.shiftKey) { e.preventDefault(); undo(); return; }
      if (mod && ((e.key.toLowerCase() === 'z' && e.shiftKey) || e.key.toLowerCase() === 'y')) { e.preventDefault(); redo(); return; }
      if (e.key === 'Escape' && selectionRef.current) { setSelection(null); }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [save, undo, redo]);

  const ctx = useMemo(() => ({
    boot, engine, manifest, transport, selection, select, announce, applyOp,
    insertBlock, insertSection, removeNode, moveBlockTo, moveSectionTo, labelOf,
    viewport: viewportByKey(viewportKey),
  }), [boot, engine, manifest, transport, selection, select, announce, applyOp, insertBlock, insertSection, removeNode, moveBlockTo, moveSectionTo, labelOf, viewportKey]);

  if (loadError) {
    return (
      <div className="sbx-fatal" role="alert">
        <h1>{t('app_name')}</h1>
        <p>{t('load_failed')} {errorMessage(loadError)}</p>
        <p>
          <button type="button" className="sbx-btn sbx-btn--primary" onClick={load}>{t('retry')}</button>{' '}
          <a className="sbx-btn" href={boot.pagesUrl}>{t('back_to_pages')}</a>
        </p>
      </div>
    );
  }
  if (!manifest) {
    return <p className="sbx-boot-loading" role="status">{t('loading')}</p>;
  }

  return (
    <EditorContext.Provider value={ctx}>
      <ShellLayout
        viewportKey={viewportKey}
        onViewport={changeViewport}
        onSave={save}
        onUndo={undo}
        onRedo={redo}
        onPublish={publish}
        onReload={reload}
        announcement={announcement}
        lockState={lockState}
        historyOpen={historyOpen}
        setHistoryOpen={setHistoryOpen}
        pendingInsert={pendingInsert}
        onCancelInsert={() => setPendingInsert(null)}
        onConfirmInsert={(props, bindings) => {
          const req = pendingInsert;
          setPendingInsert(null);
          commitInsert(req.type, req.target, props, bindings);
        }}
      />
    </EditorContext.Provider>
  );
}

/** The four explicit regions of the builder (rendered inside an EditorContext). */
export function ShellLayout({
  viewportKey, onViewport, onSave, onUndo, onRedo, onPublish, onReload, announcement, lockState,
  historyOpen = false, setHistoryOpen = () => {}, pendingInsert = null, onCancelInsert = () => {}, onConfirmInsert = () => {},
}) {
  return (
    <div className="sbx-shell" data-viewport={viewportKey}>
      <TopBar
        viewportKey={viewportKey}
        onViewport={onViewport}
        onSave={onSave}
        onUndo={onUndo}
        onRedo={onRedo}
        onPublish={onPublish}
        onHistory={() => setHistoryOpen(true)}
      />
      <ConflictBanner onReload={onReload} lockState={lockState} />
      <div className="sbx-workspace">
        <LeftPanel />
        <CanvasArea />
        <RightPanel />
      </div>
      <LiveRegion text={announcement} />
      {pendingInsert && <InsertDialog request={pendingInsert} onCancel={onCancelInsert} onConfirm={onConfirmInsert} />}
      {historyOpen && <HistoryDialog onClose={() => setHistoryOpen(false)} />}
    </div>
  );
}

export { STATUS };
