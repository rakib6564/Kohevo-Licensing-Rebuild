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
import { EditorContext, SelectionContext, useEditor, useSelection } from './EditorContext.jsx';
import { TopBar } from './TopBar.jsx';
import { LeftPanel } from './LeftPanel.jsx';
import { RightPanel } from './RightPanel.jsx';
import { CanvasArea } from './CanvasArea.jsx';
import { MobileDock } from './MobileDock.jsx';
import { VisitorPreview } from './VisitorPreview.jsx';
import { LiveRegion } from './LiveRegion.jsx';
import { ConflictBanner } from './ConflictBanner.jsx';
import { InsertDialog } from './InsertDialog.jsx';
import { HistoryDialog } from './HistoryDialog.jsx';
import { SaveTemplateDialog } from './SaveTemplateDialog.jsx';
import { ComponentDialog } from './ComponentDialog.jsx';
import { ThemeDialog } from './ThemeDialog.jsx';
import { ThemeBottomSheet } from './ThemeBottomSheet.jsx';
import { MoreBottomSheet } from './MoreBottomSheet.jsx';
import { PackageDialog } from './PackageDialog.jsx';
import { ResponsiveViewSheet } from './sheets/ResponsiveViewSheet.jsx';
import { AiReviewDialog } from './AiReviewDialog.jsx';
import { createTransport } from '../core/api.mjs';
import { SyncEngine, STATUS } from '../core/sync.mjs';
import { EditLock } from '../core/lock.mjs';
import { t, errorMessage } from '../core/messages.mjs';
import { blockDefinition, documentRows, findNode, insertionPoint, nodeLabel, sectionsOf } from '../core/doc.mjs';
import {
  EMPTY_SELECTION, actionIds, clickSelect, pruneSelection, remapSelection, selectOnly, selectionCount, setFocus as focusNode, setHover as hoverNode,
} from '../core/selection.mjs';
import { defaultBindings, setupFields } from '../core/fields.mjs';
import { insertTargetFor, referenceIndexFor, slugify } from '../core/library.mjs';
import * as ops from '../core/operations.mjs';
import { lockViolation } from '../core/layerLock.mjs';
import { viewportByKey } from '../core/viewport.mjs';
import { useIsMobileShell, isMobileShellNow } from '../hooks/useIsMobileShell.mjs';
import { isEditing, keyboardInset, normalizeViewMode } from '../core/shellState.mjs';
import { zoomPercent } from '../core/zoom.mjs';

export function StudioShell({ boot, transport: injectedTransport = null, lockEnabled = true }) {
  const transport = useMemo(
    () => injectedTransport || createTransport({ apiUrl: boot.apiUrl, csrfToken: boot.csrfToken }),
    [boot.apiUrl, boot.csrfToken, injectedTransport],
  );
  // The selection model (primary, ids, hover, focus). `selection` stays the primary id for
  // every caller that only cares about "the" selected node.
  const [sel, setSel] = useState(EMPTY_SELECTION);
  const selModelRef = useRef(sel);
  selModelRef.current = sel;
  const selection = sel.primary;
  const selectionRef = useRef(null);
  selectionRef.current = selection;
  const setSelection = useCallback((id) => setSel((s) => selectOnly(s, id)), []);
  const [announcement, setAnnouncement] = useState('');
  // A phone starts on the Mobile device so the canvas is not a shrunken desktop page.
  const [viewportKey, setViewportKey] = useState(() => (isMobileShellNow() ? 'mobile' : 'desktop'));
  // Canvas view aids shared by the bottom bar and the mobile Responsive-view sheet.
  const [canvasView, setCanvasViewState] = useState({ zoom: 'fit', grid: false, outlines: false, labels: false, fitPercent: 100 });
  const setCanvasView = useCallback((patch) => setCanvasViewState((v) => ({ ...v, ...patch })), []);
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
      setSel((s) => remapSelection(s, new Map([[tmp, real]])));
    },
    onEvent: (e) => {
      switch (e.type) {
        case 'saved': announce(t('announce_saved')); break;
        case 'conflict': announce(t('announce_conflict')); break;
        case 'rejected': announce(t('announce_rejected')); break;
        case 'locked': announce(t('announce_locked')); break;
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

  // Built-in section presets load the first time the Add panel needs them (the first call also seeds them
  // for this tenant), not on every page load: a phone with the Add sheet closed never pays for it.
  const [presets, setPresets] = useState(null);
  const presetsRequested = useRef(false);
  const ensurePresets = useCallback(async () => {
    if (presetsRequested.current || typeof transport.sectionPresets !== 'function') return;
    presetsRequested.current = true;
    const res = await transport.sectionPresets();
    setPresets(res.ok ? res.data.presets : []);
  }, [transport]);

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

  /** A click with modifiers: plain replaces, Ctrl/Cmd toggles, Shift selects the range from the primary. */
  const pick = useCallback((id, mods = {}, rows = null) => {
    // The canvas can show a node the editor no longer has (a repaint that lagged an undo or a reload): a selection
    // nothing can edit would leave an empty Inspector, so drop it and repaint the canvas instead.
    if (id && !findNode(engine.getSnapshot().working, id)) {
      selModelRef.current = EMPTY_SELECTION;
      setSel(EMPTY_SELECTION);
      setCanvasVersion((v) => v + 1);
      announce(t('selection_stale'));
      return;
    }
    const next = clickSelect(selModelRef.current, rows || documentRows(engine.getSnapshot().working), id, mods);
    selModelRef.current = next;
    setSel(next);
    if (!id) return;
    if (selectionCount(next) > 1) announce(t('bulk_selected', { count: selectionCount(next) }));
    else announce(t('announce_selected', { label: labelOf(id) }));
  }, [engine, announce, labelOf]);

  const setHover = useCallback((id) => setSel((s) => hoverNode(s, id)), []);
  const setFocus = useCallback((id) => setSel((s) => focusNode(s, id)), []);

  // Undo, a server rollback or a reload can remove nodes that are selected: drop them.
  useEffect(() => engine.subscribe(() => {
    const working = engine.getSnapshot().working;
    setSel((s) => pruneSelection(s, (id) => !!findNode(working, id)));
  }), [engine]);

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

  /** Insert a block pre-filled with props (e.g. an Image picked from the media library). */
  const insertBlockWithProps = useCallback((type, props) => {
    const def = blockDefinition(manifest, type);
    if (!def) return;
    let where = insertionPoint(engine.getSnapshot().working, manifest, selectionRef.current, type);
    if (!where) {
      const sectionId = insertSection();
      where = { parentId: sectionId, index: 0 };
    }
    commitInsert(type, where, props, defaultBindings(def, manifest).bindings);
  }, [engine, manifest, insertSection, commitInsert]);

  const removeNode = useCallback((id, { confirmed = false } = {}) => {
    const info = findNode(engine.getSnapshot().working, id);
    if (!info) return;
    const label = nodeLabel(info.node, manifest, info.kind);
    const operation = info.kind === 'section' ? ops.removeSection(id) : ops.removeBlock(id);
    // Locked layers are refused before any "are you sure?" prompt.
    if (lockViolation(engine.getSnapshot().working, operation)) { announce(t('announce_locked')); return; }
    if (!confirmed && info.kind === 'section' && info.node.blocks && info.node.blocks.length && !window.confirm(t('confirm_remove_section'))) return;
    if (engine.apply(operation, { label })) {
      if (selectionRef.current === id) setSelection(info.kind === 'block' ? info.parentId : null);
      announce(t('announce_removed', { label }));
    }
  }, [engine, manifest, announce]);

  const duplicateNode = useCallback((id) => {
    const info = findNode(engine.getSnapshot().working, id);
    if (!info) return;
    const label = nodeLabel(info.node, manifest, info.kind);
    const operation = info.kind === 'section' ? ops.duplicateSection(id) : ops.duplicateBlock(id);
    if (engine.apply(operation, { label: `${label} (Copy)` })) {
      announce(t('announce_inserted', { label: `${label} (Copy)` }));
    }
  }, [engine, manifest, announce]);

  const updateSectionLabel = useCallback((id, label) => {
    const info = findNode(engine.getSnapshot().working, id);
    if (!info || info.kind !== 'section') return;
    engine.apply(ops.updateSectionLabel(id, label), { label });
  }, [engine]);

  /** Name a layer: a section's label, or a block's display name (empty clears it). */
  const renameNode = useCallback((id, label) => {
    const info = findNode(engine.getSnapshot().working, id);
    if (!info) return;
    const name = String(label || '').trim();
    if (info.kind === 'section') {
      if (name) engine.apply(ops.updateSectionLabel(id, name), { label: name });
      return;
    }
    if (engine.apply(ops.updateBlockMeta(id, { label: name || null }), { label: name || t('layer_name_cleared') })) {
      announce(t('announce_renamed', { label: name || labelOf(id) }));
    }
  }, [engine, announce, labelOf]);

  /** Lock or unlock a layer (a section, or a block and everything inside it). */
  const setLocked = useCallback((id, locked) => {
    const info = findNode(engine.getSnapshot().working, id);
    if (!info) return;
    const operation = info.kind === 'section' ? ops.updateSectionLocked(id, locked) : ops.updateBlockMeta(id, { locked });
    if (engine.apply(operation, { label: labelOf(id) })) {
      announce(t(locked ? 'announce_layer_locked' : 'announce_layer_unlocked', { label: labelOf(id) }));
    }
  }, [engine, announce, labelOf]);

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

  const refreshManifest = useCallback(async () => {
    if (typeof transport.manifest !== 'function') return;
    const res = await transport.manifest();
    if (res.ok) { setManifest(res.data.manifest); engine.manifest = res.data.manifest; }
  }, [transport, engine]);

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
      if (mod && e.key.toLowerCase() === 'd' && selectionRef.current) {
        e.preventDefault();
        actionIds(selModelRef.current, documentRows(engine.getSnapshot().working)).forEach((id) => duplicateNode(id));
        return;
      }
      if (mod && e.key.toLowerCase() === 'z' && !e.shiftKey) { e.preventDefault(); undo(); return; }
      if (mod && ((e.key.toLowerCase() === 'z' && e.shiftKey) || e.key.toLowerCase() === 'y')) { e.preventDefault(); redo(); return; }
      if (e.key === 'Escape' && selectionRef.current) { setSelection(null); }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [engine, save, undo, redo, duplicateNode]);

  const libraryWithPresets = useMemo(() => (library ? { ...library, presets: presets === null ? undefined : presets } : library), [library, presets]);

  const ctx = useMemo(() => ({
    boot, engine, manifest, transport, announce, applyOp, canvasView, setCanvasView,
    insertBlock, insertBlockWithProps, insertSection, duplicateNode, updateSectionLabel, renameNode, setLocked, removeNode, moveBlockTo, moveSectionTo, labelOf,
    viewport: viewportByKey(viewportKey), setViewport: changeViewport, tokensSaved: onTokensSaved, refreshManifest,
    library: libraryWithPresets, refreshLibrary, ensurePresets, applyTemplate, insertTemplate, deleteTemplate,
    insertComponentRef, detachComponent, publishComponent, createPartial, canvasVersion,
    openSaveTemplate: () => setDialog('save_template'),
    openComponentDialog: () => setDialog('component'),
    openAiReview: () => setDialog('ai_review'),
    // Entry points the Pages view's quick tools reuse (null when the person may not use them).
    openHistory: () => setHistoryOpen(true),
    openTheme: manifest && manifest.permissions && (manifest.permissions.tokens || manifest.permissions.view) ? () => setDialog('theme') : null,
    openPackages: manifest && manifest.permissions && manifest.permissions.view ? () => setDialog('package') : null,
  }), [boot, engine, manifest, transport, announce, applyOp, canvasView, setCanvasView, insertBlock, insertBlockWithProps, insertSection, duplicateNode, updateSectionLabel, renameNode, setLocked, removeNode, moveBlockTo, moveSectionTo, labelOf, viewportKey, changeViewport, onTokensSaved, refreshManifest,
    libraryWithPresets, refreshLibrary, ensurePresets, applyTemplate, insertTemplate, deleteTemplate, insertComponentRef, detachComponent, publishComponent, createPartial, canvasVersion]);

  const selectionCtx = useMemo(() => ({
    sel, selection: sel.primary, selectedIds: sel.ids, select, pick, setHover, setFocus,
  }), [sel, select, pick, setHover, setFocus]);

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
      <SelectionContext.Provider value={selectionCtx}>
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
      </SelectionContext.Provider>
    </EditorContext.Provider>
  );
}

/** Dock tabs that open their own sheet; the Add/Layers/Style panel must stay closed behind them. */
const SHEET_TABS = ['theme', 'more'];

/** The unified one-sided builder shell (rendered inside an EditorContext). */
export function ShellLayout({
  viewportKey, onViewport, onSave, onUndo, onRedo, onPublish, onReload, announcement, lockState,
  historyOpen = false, setHistoryOpen = () => {}, pendingInsert = null, onCancelInsert = () => {}, onConfirmInsert = () => {},
  onTheme = null, dialogs = null, onAiReview = null, onPackages = null,
}) {
  const { selection, select } = useSelection();
  const { canvasView } = useEditor();
  const [activeTab, setActiveTab] = useState(() => (selection ? 'inspector' : 'structure'));
  const isMobile = useIsMobileShell();
  const [mobileOpen, setMobileOpen] = useState(false);
  const [responsiveOpen, setResponsiveOpen] = useState(false);
  const [collapsed, setCollapsed] = useState(false);
  // Desktop: the docked Inspector on the right, shown unless the author hides it for a wider canvas. A narrow
  // desktop window starts with it hidden, so the canvas keeps a usable width next to the left panel.
  const [inspectorShown, setInspectorShown] = useState(() => typeof window === 'undefined' || window.innerWidth > 1000);
  const [viewMode, setViewModeRaw] = useState('edit');
  const shellRef = useRef(null);
  const editing = isEditing(viewMode);
  const visitor = viewMode === 'visitor';

  const setViewMode = useCallback((mode) => {
    const next = normalizeViewMode(mode);
    setViewModeRaw(next);
    if (next !== 'edit') {
      // Nothing is selectable while previewing; leaving the sheet open would cover the page.
      select(null, { announceIt: false });
      setMobileOpen(false);
    }
  }, [select]);

  // Selecting a node points the panel at Style; on a phone it does NOT open the sheet:
  // the canvas stays visible with the contextual bar, and Edit (dock) opens the sheet.
  useEffect(() => {
    if (selection && editing && isMobile) setActiveTab('inspector');
  }, [selection, editing, isMobile]);

  // Inspector toggle: open the docked panel on Style; a second press collapses it for a full-width canvas.
  const inspectorOpen = isMobile ? (mobileOpen && activeTab === 'inspector') : inspectorShown;
  const toggleInspector = useCallback(() => {
    if (isMobile) {
      setActiveTab('inspector');
      setMobileOpen((open) => !(open && activeTab === 'inspector'));
      return;
    }
    setInspectorShown((shown) => !shown);
  }, [activeTab, isMobile]);

  // Cmd/Ctrl+K: open Add and focus its search (the "⌘ K" badge in the palette promises this).
  useEffect(() => {
    const onKey = (e) => {
      if (!(e.metaKey || e.ctrlKey) || e.key.toLowerCase() !== 'k' || !editing) return;
      e.preventDefault();
      setCollapsed(false);
      setActiveTab('blocks');
      if (isMobile) setMobileOpen(true);
      requestAnimationFrame(() => {
        // The palette renders twice (Add tab and under Layers): focus the one on screen.
        const inputs = [...document.querySelectorAll('.sbx-palette__search-input')];
        const input = inputs.find((el) => el.offsetParent !== null) || inputs[0];
        if (input) input.focus();
      });
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [editing, isMobile]);

  // Keyboard-safe sheets: publish how much of the screen the on-screen keyboard covers.
  useEffect(() => {
    const vv = typeof window !== 'undefined' ? window.visualViewport : null;
    const el = shellRef.current;
    if (!vv || !el) return undefined;
    const update = () => {
      const inset = keyboardInset(window.innerHeight, vv);
      el.style.setProperty('--sbx-kb-inset', `${inset}px`);
      el.dataset.keyboard = inset > 0 ? 'open' : 'closed';
    };
    update();
    vv.addEventListener('resize', update);
    vv.addEventListener('scroll', update);
    return () => {
      vv.removeEventListener('resize', update);
      vv.removeEventListener('scroll', update);
    };
  }, []);

  return (
    <div className="sbx-shell" data-viewport={viewportKey} data-view-mode={viewMode} ref={shellRef}>
      <TopBar
        viewMode={viewMode}
        onViewMode={setViewMode}
        inspectorOpen={inspectorOpen}
        onToggleInspector={toggleInspector}
        viewportKey={viewportKey}
        onViewport={onViewport}
        onSave={onSave}
        onUndo={onUndo}
        onRedo={onRedo}
        onPublish={onPublish}
        onHistory={() => setHistoryOpen(true)}
        onResponsiveView={isMobile ? () => setResponsiveOpen(true) : null}
        onTheme={onTheme}
        onAiReview={onAiReview}
        onPackages={onPackages}
        onOpenMobileDock={() => {
          setActiveTab('blocks');
          setMobileOpen(true);
        }}
      />
      <ConflictBanner onReload={onReload} lockState={lockState} />
      <div className="sbx-workspace">
        <LeftPanel
          tab={activeTab}
          onTabChange={setActiveTab}
          collapsed={collapsed}
          onToggleCollapse={() => setCollapsed((c) => !c)}
          mobileOpen={mobileOpen && !SHEET_TABS.includes(activeTab)}
          onCloseMobile={() => setMobileOpen(false)}
        />
        <CanvasArea
          interactive={editing}
          collapsed={collapsed}
          onToggleCollapse={() => setCollapsed((c) => !c)}
          onReloadCanvas={onReload}
        />
        {!isMobile && inspectorShown && editing && <RightPanel onNavigate={(tab) => { setCollapsed(false); setActiveTab(tab); }} />}
      </div>
      {visitor && (
        <VisitorPreview
          viewportKey={viewportKey}
          onViewport={onViewport}
          onExit={() => setViewMode('edit')}
          onSave={onSave}
        />
      )}
      <MobileDock
        activeTab={activeTab}
        mobileSheetOpen={mobileOpen}
        onSelectTab={(key) => {
          if (key === 'preview') {
            setViewMode('visitor');
            return;
          }
          if (mobileOpen && activeTab === key) {
            setMobileOpen(false);
          } else {
            setActiveTab(key);
            setMobileOpen(true);
          }
        }}
        hasSelection={!!selection}
      />
      <LiveRegion text={announcement} />
      {pendingInsert && <InsertDialog request={pendingInsert} onCancel={onCancelInsert} onConfirm={onConfirmInsert} />}
      {historyOpen && <HistoryDialog onClose={() => setHistoryOpen(false)} />}
      {mobileOpen && activeTab === 'theme' && (
        <ThemeBottomSheet
          onClose={() => setMobileOpen(false)}
          onSaved={() => onReload && onReload()}
        />
      )}
      {isMobile && responsiveOpen && (
        <ResponsiveViewSheet
          viewportKey={viewportKey}
          onViewport={onViewport}
          percent={zoomPercent(canvasView.zoom, canvasView.fitPercent / 100)}
          onClose={() => setResponsiveOpen(false)}
        />
      )}
      {mobileOpen && activeTab === 'more' && (
        <MoreBottomSheet
          onClose={() => setMobileOpen(false)}
          onOpenLayers={() => {
            setActiveTab('structure');
            setMobileOpen(true);
          }}
          onOpenSettings={() => {
            setActiveTab('settings');
            setMobileOpen(true);
          }}
          onOpenHistory={() => setHistoryOpen(true)}
          onOpenPackages={onPackages}
        />
      )}
      {dialogs}
    </div>
  );
}

export { STATUS };
