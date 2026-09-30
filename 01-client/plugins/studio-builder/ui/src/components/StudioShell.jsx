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
import { SaveTemplateDialog } from './SaveTemplateDialog.jsx';
import { ComponentDialog } from './ComponentDialog.jsx';
import { ThemeDialog } from './ThemeDialog.jsx';
import { PackageDialog } from './PackageDialog.jsx';
import { AiReviewDialog } from './AiReviewDialog.jsx';
import { createTransport } from '../core/api.mjs';
import { SyncEngine, STATUS } from '../core/sync.mjs';
import { EditLock } from '../core/lock.mjs';
import { t, errorMessage } from '../core/messages.mjs';
import { blockDefinition, findNode, insertionPoint, nodeLabel, sectionsOf } from '../core/doc.mjs';
import { defaultBindings, setupFields } from '../core/fields.mjs';
import { insertTargetFor, referenceIndexFor, slugify } from '../core/library.mjs';
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
  // Phase 6: library data (templates + global components), dialogs, and a
  // canvas version that bumps when a shared render input changes.
  const [library, setLibrary] = useState(null);
  const [dialog, setDialog] = useState(null); // 'save_template' | 'component' | 'theme' | 'ai_review' | 'package'
  const [canvasVersion, setCanvasVersion] = useState(0);

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
        case 'command': if (e.label) announce(e.label); break;
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

  // ── Library (templates + global components): server data, refreshed on demand ─
  const refreshLibrary = useCallback(async () => {
    if (typeof transport.templates !== 'function') return;
    const [tpl, cmp] = await Promise.all([transport.templates(), transport.components()]);
    setLibrary({
      templates: tpl.ok ? tpl.data.templates : [],
      components: cmp.ok ? cmp.data.components : [],
      error: tpl.ok && cmp.ok ? null : errorMessage((tpl.ok ? cmp : tpl).error),
    });
  }, [transport]);

  useEffect(() => { if (manifest) refreshLibrary(); }, [manifest, refreshLibrary]);

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
  /** Phase 7: publish the EXACT reviewed revision; a draft that moved on is a 409 → conflict state. */
  const publishReviewed = useCallback(async (binding) => {
    const current = engine.getSnapshot().revision;
    if (!binding || !current || current.id !== binding.expected_revision_id) {
      announce(t('ai_review_stale'));
      return false;
    }
    const ok = await engine.publish();
    if (ok) setDialog(null);
    return ok;
  }, [engine, announce]);
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

  // ── Phase 6 actions: templates (copy), global components (reference), chrome, theme ─
  const templateName = (tpl) => (tpl && tpl.name) || (tpl && tpl.template_key) || '';

  const applyTemplate = useCallback(async (tpl) => {
    if (!window.confirm(t('library_confirm_apply', { name: templateName(tpl) }))) return false;
    return engine.command((base) => transport.applyTemplate({ ...base, template_key: tpl.template_key }), { label: t('announce_template_applied') });
  }, [engine, transport]);

  const insertTemplate = useCallback(async (tpl) => {
    const working = engine.getSnapshot().working;
    const target = insertTargetFor(working, tpl, selectionRef.current, (d, sel) => insertionPoint(d, manifest, sel, 'core.container'));
    if (!target) {
      announce(t('empty_page'));
      return false;
    }
    const body = { template_key: tpl.template_key, index: target.index };
    if (target.parent_id) body.parent_id = target.parent_id;
    return engine.command((base) => transport.insertTemplate({ ...base, ...body }), { label: t('announce_template_inserted') });
  }, [engine, transport, manifest, announce]);

  const deleteTemplate = useCallback(async (tpl) => {
    if (!window.confirm(t('library_confirm_delete', { name: templateName(tpl) }))) return false;
    const res = await transport.deleteTemplate({ template_key: tpl.template_key });
    if (!res.ok) { announce(errorMessage(res.error)); return false; }
    announce(t('announce_template_deleted'));
    await refreshLibrary();
    return true;
  }, [transport, announce, refreshLibrary]);

  /** Insert a LIVE reference to a global component (a section with global_ref; no local blocks). */
  const insertComponentRef = useCallback((component) => {
    const working = engine.getSnapshot().working;
    const index = referenceIndexFor(working, selectionRef.current);
    const provisionalId = ops.provisionalId('sec');
    if (engine.apply(ops.insertGlobalSection(index, component.ref, component.title), { provisionalId, label: component.title })) {
      setSelection(provisionalId);
      announce(t('announce_component_inserted'));
      return true;
    }
    return false;
  }, [engine, announce]);

  const detachComponent = useCallback(async (sectionId) => {
    if (!window.confirm(t('global_section_detach_confirm'))) return false;
    const ok = await engine.command((base) => transport.detachComponent({ ...base, section_id: sectionId }), { label: t('announce_component_detached') });
    if (ok) { setSelection(null); refreshLibrary(); }
    return ok;
  }, [engine, transport, refreshLibrary]);

  const publishComponent = useCallback(async (component) => {
    const res = await transport.publish({ page_id: component.id, expected_revision_id: component.active_draft_revision_id });
    if (!res.ok) { announce(errorMessage(res.error)); return false; }
    announce(t('announce_component_published'));
    setCanvasVersion((v) => v + 1); // pages render the component's PUBLISHED version
    await refreshLibrary();
    return true;
  }, [transport, announce, refreshLibrary]);

  const createComponent = useCallback(async ({ title, slug, sectionId, openAfter }) => {
    let created = null;
    if (sectionId) {
      const ok = await engine.command(async (base) => {
        const res = await transport.createComponent({ ...base, title, slug, section_id: sectionId });
        if (res.ok) created = res.data.component;
        return res;
      }, { label: t('announce_component_created') });
      if (!ok) {
        const err = engine.getSnapshot().error;
        return { error: err ? errorMessage(err) : t('error_server_error') };
      }
    } else {
      const res = await transport.createComponent({ title, slug });
      if (!res.ok) {
        const detail = res.error && res.error.details && Array.isArray(res.error.details.errors) ? res.error.details.errors[0] : null;
        return { error: detail && detail.message ? detail.message : errorMessage(res.error) };
      }
      created = res.data.component;
      announce(t('announce_component_created'));
    }
    setDialog(null);
    setSelection(null);
    await refreshLibrary();
    if (openAfter && created && boot.builderUrl) window.open(`${boot.builderUrl}?page=${created.id}`, '_blank', 'noopener');
    return { component: created };
  }, [engine, transport, announce, refreshLibrary, boot.builderUrl]);

  /** Create the site (`default`) or this page's own header/footer partial and open it. */
  const createPartial = useCallback(async (region, slug) => {
    const pageType = region === 'header' ? 'header_partial' : 'footer_partial';
    const page = engine.getSnapshot().page;
    const title = `${page ? page.title : ''} ${region}`.trim();
    const res = await transport.createPage({ title, slug: slugify(slug, 191) || 'default', page_type: pageType, route_mode: 'standalone' });
    if (!res.ok) { announce(errorMessage(res.error)); return false; }
    if (boot.builderUrl) window.open(`${boot.builderUrl}?page=${res.data.page.id}`, '_blank', 'noopener');
    setCanvasVersion((v) => v + 1);
    return true;
  }, [engine, transport, announce, boot.builderUrl]);

  const onTokensSaved = useCallback(async () => {
    announce(t('announce_tokens_saved'));
    setCanvasVersion((v) => v + 1);
    if (typeof transport.manifest === 'function') {
      const res = await transport.manifest();
      if (res.ok) { setManifest(res.data.manifest); engine.manifest = res.data.manifest; }
    }
  }, [transport, announce, engine]);

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
    library, refreshLibrary, applyTemplate, insertTemplate, deleteTemplate,
    insertComponentRef, detachComponent, publishComponent, createPartial, canvasVersion,
    openSaveTemplate: () => setDialog('save_template'),
    openComponentDialog: () => setDialog('component'),
  }), [boot, engine, manifest, transport, selection, select, announce, applyOp, insertBlock, insertSection, removeNode, moveBlockTo, moveSectionTo, labelOf, viewportKey,
    library, refreshLibrary, applyTemplate, insertTemplate, deleteTemplate, insertComponentRef, detachComponent, publishComponent, createPartial, canvasVersion]);

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
        onTheme={manifest.permissions && (manifest.permissions.tokens || manifest.permissions.view) ? () => setDialog('theme') : null}
        onAiReview={() => setDialog('ai_review')}
        onPackages={manifest.permissions && manifest.permissions.view ? () => setDialog('package') : null}
        dialogs={(
          <>
            {dialog === 'save_template' && (
              <SaveTemplateDialog
                onClose={() => setDialog(null)}
                onSaved={() => { setDialog(null); announce(t('announce_template_saved')); refreshLibrary(); }}
              />
            )}
            {dialog === 'component' && <ComponentDialog onClose={() => setDialog(null)} onCreate={createComponent} />}
            {dialog === 'theme' && <ThemeDialog onClose={() => setDialog(null)} onSaved={onTokensSaved} />}
            {dialog === 'ai_review' && <AiReviewDialog onClose={() => setDialog(null)} onPublish={publishReviewed} />}
            {dialog === 'package' && (
              <PackageDialog
                onClose={() => setDialog(null)}
                onReplaced={() => { setSelection(null); announce(t('import_done')); refreshLibrary(); }}
              />
            )}
          </>
        )}
      />
    </EditorContext.Provider>
  );
}

/** The four explicit regions of the builder (rendered inside an EditorContext). */
export function ShellLayout({
  viewportKey, onViewport, onSave, onUndo, onRedo, onPublish, onReload, announcement, lockState,
  historyOpen = false, setHistoryOpen = () => {}, pendingInsert = null, onCancelInsert = () => {}, onConfirmInsert = () => {},
  onTheme = null, dialogs = null, onAiReview = null, onPackages = null,
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
        onTheme={onTheme}
        onAiReview={onAiReview}
        onPackages={onPackages}
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
      {dialogs}
    </div>
  );
}

export { STATUS };
