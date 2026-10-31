// StudioShell — the builder's top-level layout and action hub.
//
//   StudioShell
//   ├── TopBar        page, save state, undo/redo, viewport, preview, publish
//   ├── LeftPanel     block palette, structure outline, and the Edit tab for the selection
//   └── CanvasArea    sandboxed iframe of the server's renderForEditor() output
//       (its Edit tab holds the metadata-driven property panels for the selection)
//
// The shell owns transient UI state only (selection, viewport, dialogs). The
// document lives in the SyncEngine (server revision + optimistic operations);
// every change is a canonical operation sent through the command API.

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { EditorContext, SelectionContext, useEditor, useSelection } from './EditorContext.jsx';
import { LibraryModal } from './LibraryModal.jsx';
import { StructureWindow } from './StructureWindow.jsx';
import { TopBar } from './TopBar.jsx';
import { LeftPanel } from './LeftPanel.jsx';
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
import { DuplicatePageDialog } from './PagesPanel.jsx';
import { MediaHost } from './MediaDialog.jsx';
import { createMediaApi } from '../core/mediaApi.mjs';
import { ResponsiveViewSheet } from './sheets/ResponsiveViewSheet.jsx';
import { AiReviewDialog } from './AiReviewDialog.jsx';
import { createTransport } from '../core/api.mjs';
import { SyncEngine, STATUS } from '../core/sync.mjs';
import { EditLock } from '../core/lock.mjs';
import { t, errorMessage } from '../core/messages.mjs';
import { CONTAINER_TYPE, blockDefinition, containersOf, documentRows, findNode, insertionPoint, nodeLabel } from '../core/doc.mjs';
import {
  EMPTY_SELECTION, actionIds, clickSelect, pruneSelection, remapSelection, selectOnly, selectionCount, setFocus as focusNode, setHover as hoverNode,
} from '../core/selection.mjs';
import { defaultBindings, setupFields } from '../core/fields.mjs';
import { insertTargetFor, mergeTemplates, referenceIndexFor, slugify } from '../core/library.mjs';
import * as ops from '../core/operations.mjs';
import { lockViolation } from '../core/layerLock.mjs';
import { buildEnvelope, peekMemory, planPaste, readClipboard, regenerateIds, writeClipboard } from '../core/clipboard.mjs';
import { viewportByKey } from '../core/viewport.mjs';
import { useIsMobileShell, isMobileShellNow } from '../hooks/useIsMobileShell.mjs';
import { isEditing, keyboardInset, normalizeViewMode } from '../core/shellState.mjs';
import { isTyping, shortcutFor } from '../core/shortcuts.mjs';
import { zoomPercent } from '../core/zoom.mjs';

export function StudioShell({ boot, transport: injectedTransport = null, lockEnabled = true }) {
  const transport = useMemo(
    () => injectedTransport || createTransport({ apiUrl: boot.apiUrl, csrfToken: boot.csrfToken }),
    [boot.apiUrl, boot.csrfToken, injectedTransport],
  );
  const mediaApi = useMemo(() => createMediaApi({ url: boot.mediaApiUrl || '', csrfToken: boot.csrfToken }), [boot.mediaApiUrl, boot.csrfToken]);
  // The selection model (primary, ids, hover, focus). `selection` stays the primary id for
  // every caller that only cares about "the" selected node.
  const [sel, setSel] = useState(EMPTY_SELECTION);
  const selModelRef = useRef(sel);
  selModelRef.current = sel;
  const selection = sel.primary;
  const selectionRef = useRef(null);
  selectionRef.current = selection;
  const setSelection = useCallback((id) => setSel((s) => selectOnly(s, id)), []);
  // A pick on the canvas, a new element, or a button on an empty container asks the left panel (below, in Workspace) to
  // show a tab (and an Add sub-tab): `n` counts requests so the same one can be asked twice.
  const [panelRequest, setPanelRequest] = useState({ tab: null, sub: null, n: 0 });
  const requestPanel = useCallback((tab, sub = null) => setPanelRequest((r) => ({ tab, sub, n: r.n + 1 })), []);
  const requestEdit = useCallback(() => requestPanel('inspector'), [requestPanel]);
  // A Container just added asks, on the canvas, which layout it should use (Flexbox or Grid): the id of that block.
  const [layoutChooser, setLayoutChooser] = useState(null);
  // The floating Structure window (desktop): the page outline over the canvas.
  const [structureOpen, setStructureOpen] = useState(false);
  const toggleStructure = useCallback(() => setStructureOpen((o) => !o), []);
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
      setLayoutChooser((id) => (id === tmp ? real : id));
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
    // The built-in headers are offered only while a header is open (the first call seeds them for this tenant).
    const snapshot = engine.getSnapshot();
    const wantsHeaders = snapshot.page && snapshot.page.page_type === 'header_partial' && typeof transport.headerPresets === 'function';
    const [tpl, cmp, hdr] = await Promise.all([transport.templates(), transport.components(), wantsHeaders ? transport.headerPresets() : null]);
    setLibrary({
      templates: mergeTemplates(tpl.ok ? tpl.data.templates : [], hdr && hdr.ok ? hdr.data.presets : []),
      components: cmp.ok ? cmp.data.components : [],
      error: tpl.ok && cmp.ok ? null : errorMessage((tpl.ok ? cmp : tpl).error),
    });
  }, [transport, engine]);

  useEffect(() => { if (manifest) refreshLibrary(); }, [manifest, refreshLibrary]);

  // Built-in Container presets load the first time the Add panel needs them (the first call also seeds them
  // for this tenant), not on every page load: a phone with the Add sheet closed never pays for it.
  const [presets, setPresets] = useState(null);
  const presetsRequested = useRef(false);
  const ensurePresets = useCallback(async () => {
    if (presetsRequested.current || typeof transport.containerPresets !== 'function') return;
    presetsRequested.current = true;
    const res = await transport.containerPresets();
    setPresets(res.ok ? res.data.presets : []);
  }, [transport]);

  // ── Advisory edit lock ─────────────────────────────────────────────────
  // Held once the builder has loaded, for as long as it stays open: a refreshed manifest (tokens saved, a field added)
  // is a new object and must not release and re-acquire it.
  const loaded = !!manifest;
  useEffect(() => {
    if (!lockEnabled || !loaded) return undefined;
    const lock = new EditLock({ transport, pageId: boot.pageId, onChange: setLockState });
    lock.start();
    const release = () => { lock.stop(); };
    window.addEventListener('pagehide', release);
    return () => {
      window.removeEventListener('pagehide', release);
      lock.stop();
    };
  }, [lockEnabled, loaded, transport, boot.pageId]);

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
    return info ? nodeLabel(info.node, manifest) : '';
  }, [engine, manifest]);

  const select = useCallback((id, { announceIt = true } = {}) => {
    setSelection(id);
    if (id && announceIt) announce(t('announce_selected', { label: labelOf(id) }));
  }, [announce, labelOf]);

  /** A click with modifiers: plain replaces, Ctrl/Cmd toggles, Shift selects the range from the primary. */
  const lastResyncRef = useRef(0);
  const pick = useCallback((clicked, mods = {}, rows = null, chain = null) => {
    // The canvas can show a node the editor does not have (content a template or shared part draws, or a repaint that
    // lagged). Selecting it would leave an empty Inspector, so select the nearest enclosing node the editor does own;
    // only when there is none is the click refused, with a note (never a reload: that would make every click flicker).
    const working = engine.getSnapshot().working;
    let id = clicked;
    if (id && !findNode(working, id)) {
      id = (chain || []).find((c) => c && findNode(working, c)) || null;
      if (!id) {
        selModelRef.current = EMPTY_SELECTION;
        setSel(EMPTY_SELECTION);
        announce(t('selection_stale'));
        // The editor's copy may simply be behind the server's (what the canvas paints). With nothing unsaved to lose,
        // fetch the current draft once in a while; the next click then finds its node.
        const now = Date.now();
        if (!engine.hasUnsavedChanges && now - lastResyncRef.current > 15000) {
          lastResyncRef.current = now;
          engine.reloadFromServer();
        }
        return;
      }
    }
    const next = clickSelect(selModelRef.current, rows || documentRows(working), id, mods);
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

  /** A new top-level Container (with the server's defaults) at `index`, or at the end of the page; it asks for its layout on the canvas. */
  const insertContainer = useCallback((index) => {
    const working = engine.getSnapshot().working;
    const at = Number.isInteger(index) ? index : containersOf(working).length;
    const provisionalId = ops.provisionalId('blk');
    const label = t('container');
    if (engine.apply(ops.insertContainer(at), { provisionalId, label })) {
      setSelection(provisionalId);
      requestEdit();
      setLayoutChooser(provisionalId);
      announce(t('announce_inserted', { label }));
    }
    return provisionalId;
  }, [engine, announce, requestEdit]);

  const commitInsert = useCallback((type, target, props = null, bindings = null) => {
    const def = blockDefinition(manifest, type);
    const label = def ? def.label : type;
    const provisionalId = ops.provisionalId('blk');
    const block = { type };
    if (props) block.props = { ...(def ? def.default_props : {}), ...props };
    if (bindings && Object.keys(bindings).length) block.bindings = bindings;
    // A setup dialog can stay open while the parent remembered when it opened is saved and given its real id, or removed:
    // look for the insertion point again instead of dropping the block.
    const working = engine.getSnapshot().working;
    const stale = target.parentId && !findNode(working, target.parentId);
    const where = stale ? (insertionPoint(working, manifest, selectionRef.current, type) || { parentId: null, index: containersOf(working).length }) : target;
    if (engine.apply(ops.insertOperation(def, where, block), { provisionalId, label })) {
      setSelection(ops.insertedId(where, type, provisionalId));
      requestEdit();
      if (type === CONTAINER_TYPE) setLayoutChooser(provisionalId);
      announce(t('announce_inserted', { label }));
    }
  }, [engine, manifest, announce, requestEdit]);

  /** Insert a block: at `target` or the natural insertion point; asks for required setup first. */
  const insertBlock = useCallback((type, target = null) => {
    const def = blockDefinition(manifest, type);
    if (!def) return;
    const working = engine.getSnapshot().working;
    const where = target || insertionPoint(working, manifest, selectionRef.current, type) || { parentId: null, index: containersOf(working).length };
    const setup = setupFields(def);
    const { bindings, needsParams } = defaultBindings(def, manifest);
    if (setup.length || needsParams.length) {
      setPendingInsert({ type, def, target: where, setup, bindings, needsParams });
      return;
    }
    commitInsert(type, where, null, bindings);
  }, [engine, manifest, commitInsert]);

  /** Insert a block pre-filled with props (e.g. an Image picked from the media library). */
  const insertBlockWithProps = useCallback((type, props) => {
    const def = blockDefinition(manifest, type);
    if (!def) return;
    const working = engine.getSnapshot().working;
    const where = insertionPoint(working, manifest, selectionRef.current, type) || { parentId: null, index: containersOf(working).length };
    commitInsert(type, where, props, defaultBindings(def, manifest).bindings);
  }, [engine, manifest, commitInsert]);

  const removeNode = useCallback((id, { confirmed = false } = {}) => {
    const info = findNode(engine.getSnapshot().working, id);
    if (!info) return;
    const label = nodeLabel(info.node, manifest);
    const operation = ops.removeBlock(id);
    // Locked layers are refused before any "are you sure?" prompt.
    if (lockViolation(engine.getSnapshot().working, operation)) { announce(t('announce_locked')); return; }
    if (!confirmed && info.parentId === null && info.node.children && info.node.children.length && !window.confirm(t('confirm_remove_container'))) return;
    if (engine.apply(operation, { label })) {
      if (selectionRef.current === id) setSelection(info.parentId);
      announce(t('announce_removed', { label }));
    }
  }, [engine, manifest, announce]);

  const duplicateNode = useCallback((id) => {
    const info = findNode(engine.getSnapshot().working, id);
    if (!info) return;
    const label = nodeLabel(info.node, manifest);
    // The copy (and every node in it) gets a provisional id that holds until the server mints the real ones.
    const copyLabel = `${label}${t('nav_page_copy_suffix')}`;
    if (engine.apply(ops.duplicateBlock(id), { provisionalId: ops.provisionalId('blk'), label: copyLabel })) {
      announce(t('announce_inserted', { label: copyLabel }));
    }
  }, [engine, manifest, announce]);

  // ── Clipboard: Copy / Cut / Paste of a block, across pages ─────────
  const [notice, setNotice] = useState('');
  const noticeTimer = useRef(0);
  /** Say it twice: to screen readers, and as a short note on screen (a refused paste must not be silent). */
  const notify = useCallback((text) => {
    announce(text);
    setNotice(text);
    clearTimeout(noticeTimer.current);
    noticeTimer.current = setTimeout(() => setNotice(''), 4500);
  }, [announce]);
  useEffect(() => () => clearTimeout(noticeTimer.current), []);

  const copyNode = useCallback(async (id, view = null) => {
    const info = findNode(engine.getSnapshot().working, id);
    if (!info) return false;
    const envelope = buildEnvelope(info.node);
    if (!envelope) { notify(t('clip_invalid')); return false; }
    const res = await writeClipboard(envelope, view ? view.navigator : undefined);
    if (!res.ok) { notify(t(res.reasonKey)); return false; }
    announce(t('announce_copied', { label: nodeLabel(info.node, manifest) }));
    return true;
  }, [engine, manifest, announce, notify]);

  const cutNode = useCallback(async (id, view = null) => {
    const working = engine.getSnapshot().working;
    const info = findNode(working, id);
    if (!info) return false;
    // A locked layer (or one holding a locked layer) cannot be removed, so it is not copied away either.
    if (lockViolation(working, ops.removeBlock(id))) { announce(t('announce_locked')); return false; }
    if (!(await copyNode(id, view))) return false;
    removeNode(id, { confirmed: true });
    announce(t('announce_cut', { label: nodeLabel(info.node, manifest) }));
    return true;
  }, [engine, manifest, announce, copyNode, removeNode]);

  /** Paste what the clipboard holds next to (`after`) or inside the target; every id is new, the server validates it again. */
  const pasteNode = useCallback(async (targetId = null, mode = 'after', view = null) => {
    const got = await readClipboard(view ? view.navigator : undefined);
    if (!got.ok) { notify(t(got.reasonKey)); return false; }
    const { envelope } = got;
    const working = engine.getSnapshot().working;
    const plan = planPaste({ doc: working, manifest, envelope, targetId: targetId && findNode(working, targetId) ? targetId : null, mode });
    if (!plan.ok) { notify(t(plan.reasonKey)); return false; }
    const { id: blockId, ...block } = regenerateIds(envelope.node);
    const label = nodeLabel(block, manifest);
    const operation = plan.newContainer ? ops.insertContainer(plan.index, { children: [block] }) : ops.insertBlock(plan.parentId, plan.index, block);
    if (!engine.apply(operation, { provisionalId: blockId, label })) { notify(t('clip_rejected')); return false; }
    setSelection(plan.newContainer ? `${blockId}_0` : blockId);
    announce(t('announce_pasted', { label }));
    return true;
  }, [engine, manifest, announce, notify]);

  const toggleHidden = useCallback((id) => {
    const info = findNode(engine.getSnapshot().working, id);
    if (!info) return;
    const hidden = !!(info.node.visibility && Array.isArray(info.node.visibility.devices) && info.node.visibility.devices.length === 0);
    const visibility = { ...(info.node.visibility || {}), devices: hidden ? ['base', 'sm', 'md', 'lg'] : [] };
    engine.apply(ops.updateBlockVisibility(id, visibility), { label: labelOf(id) });
  }, [engine, labelOf]);

  /**
   * Cmd/Ctrl+C, X and V on the canvas or in Layers. Handles the key only when no text is being edited and no text is
   * selected (the browser's own copy keeps working there). `view` is the window the key came from (the canvas frame
   * has its own, and its own clipboard focus). Returns true when it took the key.
   */
  const clipboardShortcut = useCallback((e, id = null, view = null) => {
    if (!(e.metaKey || e.ctrlKey) || e.altKey || e.shiftKey) return false;
    const key = String(e.key || '').toLowerCase();
    if (key !== 'c' && key !== 'x' && key !== 'v') return false;
    const target = e.target;
    if (target && (target.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName))) return false;
    try {
      const sel = ((target && target.ownerDocument && target.ownerDocument.defaultView) || window).getSelection();
      if (sel && !sel.isCollapsed && String(sel).trim() !== '') return false;
    } catch { /* no selection API: carry on */ }
    const at = id || selectionRef.current;
    if (key !== 'v' && !at) return false;
    e.preventDefault();
    if (key === 'c') copyNode(at, view);
    else if (key === 'x') cutNode(at, view);
    else pasteNode(at, 'after', view);
    return true;
  }, [copyNode, cutNode, pasteNode]);

  /** Name a layer (empty clears it). */
  const renameNode = useCallback((id, label) => {
    const info = findNode(engine.getSnapshot().working, id);
    if (!info) return;
    const name = String(label || '').trim();
    if (engine.apply(ops.updateBlockMeta(id, { label: name || null }), { label: name || t('layer_name_cleared') })) {
      announce(t('announce_renamed', { label: name || labelOf(id) }));
    }
  }, [engine, announce, labelOf]);

  /** Lock or unlock a layer (a block and everything inside it). */
  const setLocked = useCallback((id, locked) => {
    const info = findNode(engine.getSnapshot().working, id);
    if (!info) return;
    if (engine.apply(ops.updateBlockMeta(id, { locked }), { label: labelOf(id) })) {
      announce(t(locked ? 'announce_layer_locked' : 'announce_layer_unlocked', { label: labelOf(id) }));
    }
  }, [engine, announce, labelOf]);

  const moveBlockTo = useCallback((id, target) => {
    if (!target) return;
    if (engine.apply(ops.moveBlock(id, target.parentId, target.index), { label: labelOf(id) })) {
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
  // The canvas Reload button always repaints the frame (CanvasArea does that). It
  // refetches the document only when nothing local is waiting to be saved:
  // throwing unsaved edits away is the conflict banner's job, never a repaint's.
  const reloadCanvas = useCallback(() => {
    if (!engine.hasUnsavedChanges) reload();
  }, [engine, reload]);

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
    const target = insertTargetFor(working, tpl, selectionRef.current, (d, sel) => insertionPoint(d, manifest, sel, CONTAINER_TYPE));
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

  /** Insert a LIVE reference to a global component (a top-level Container with global_ref; no children of its own). */
  const insertComponentRef = useCallback((component) => {
    const working = engine.getSnapshot().working;
    const index = referenceIndexFor(working, selectionRef.current);
    const provisionalId = ops.provisionalId('blk');
    if (engine.apply(ops.insertGlobalContainer(index, component.ref, component.title), { provisionalId, label: component.title })) {
      setSelection(provisionalId);
      announce(t('announce_component_inserted'));
      return true;
    }
    return false;
  }, [engine, announce]);

  const detachComponent = useCallback(async (containerId) => {
    if (!window.confirm(t('global_container_detach_confirm'))) return false;
    const ok = await engine.command((base) => transport.detachComponent({ ...base, container_id: containerId }), { label: t('announce_component_detached') });
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

  const createComponent = useCallback(async ({ title, slug, containerId, openAfter }) => {
    let created = null;
    if (containerId) {
      const ok = await engine.command(async (base) => {
        const res = await transport.createComponent({ ...base, title, slug, container_id: containerId });
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
  // The canvas frame forwards its keys here too (`editorShortcut`), so they work with the focus in the page.
  /** Run the shortcut a key means; returns true when it took the key. */
  const editorShortcut = useCallback((e) => {
    const action = shortcutFor(e, { hasSelection: !!selectionRef.current });
    if (!action || (action === 'structure' && isMobileShellNow())) return false;
    if (action !== 'deselect') e.preventDefault();
    switch (action) {
      case 'save': save(); break;
      case 'structure': toggleStructure(); break;
      case 'duplicate': actionIds(selModelRef.current, documentRows(engine.getSnapshot().working)).forEach((id) => duplicateNode(id)); break;
      case 'undo': undo(); break;
      case 'redo': redo(); break;
      case 'deselect': setSelection(null); break;
      default: return false;
    }
    return true;
  }, [engine, save, undo, redo, duplicateNode, toggleStructure]);

  useEffect(() => {
    const onKey = (e) => {
      const mod = e.metaKey || e.ctrlKey;
      // Copy / Cut / Paste when the canvas side has focus (Layers rows handle their own keys, on the focused row).
      if (mod && !isTyping(e.target) && /^[cxv]$/i.test(e.key) && e.target && (e.target === document.body || (e.target.closest && e.target.closest('.sbx-canvas'))) && clipboardShortcut(e)) return;
      editorShortcut(e);
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [clipboardShortcut, editorShortcut]);

  const libraryWithPresets = useMemo(() => (library ? { ...library, presets: presets === null ? undefined : presets } : library), [library, presets]);

  const ctx = useMemo(() => ({
    boot, engine, manifest, transport, mediaApi, announce, applyOp, canvasView, setCanvasView,
    insertBlock, insertBlockWithProps, insertContainer, duplicateNode, renameNode, setLocked, removeNode, moveBlockTo, labelOf,
    copyNode, cutNode, pasteNode, toggleHidden, clipboardShortcut, editorShortcut, peekClipboard: peekMemory,
    viewport: viewportByKey(viewportKey), setViewport: changeViewport, tokensSaved: onTokensSaved, refreshManifest, panelRequest, requestPanel, requestEdit, layoutChooser, setLayoutChooser, structureOpen, setStructureOpen, toggleStructure,
    library: libraryWithPresets, refreshLibrary, ensurePresets, applyTemplate, insertTemplate, deleteTemplate,
    insertComponentRef, detachComponent, publishComponent, createPartial, canvasVersion,
    openSaveTemplate: () => setDialog('save_template'),
    openLibraryModal: () => setDialog('library'),
    openComponentDialog: () => setDialog('component'),
    openAiReview: () => setDialog('ai_review'),
    // Entry points the Pages view's quick tools reuse (null when the person may not use them).
    openHistory: () => setHistoryOpen(true),
    openTheme: manifest && manifest.permissions && (manifest.permissions.tokens || manifest.permissions.view) ? () => setDialog('theme') : null,
    openPackages: manifest && manifest.permissions && manifest.permissions.view ? () => setDialog('package') : null,
  }), [boot, engine, manifest, transport, mediaApi, announce, applyOp, canvasView, setCanvasView, insertBlock, insertBlockWithProps, insertContainer, duplicateNode, renameNode, setLocked, removeNode, moveBlockTo, labelOf, copyNode, cutNode, pasteNode, toggleHidden, clipboardShortcut, editorShortcut, viewportKey, changeViewport, onTokensSaved, refreshManifest, panelRequest, requestPanel, requestEdit, layoutChooser, setLayoutChooser, structureOpen, setStructureOpen, toggleStructure,
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
        onReloadCanvas={reloadCanvas}
        announcement={announcement}
        notice={notice}
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
        onDuplicatePage={() => setDialog('duplicate_page')}
        dialogs={(
          <>
            {dialog === 'library' && <LibraryModal onClose={() => setDialog(null)} />}
            {dialog === 'save_template' && (
              <SaveTemplateDialog
                onClose={() => setDialog(null)}
                onSaved={() => { setDialog(null); announce(t('announce_template_saved')); refreshLibrary(); }}
              />
            )}
            {boot.mediaPicker && boot.mediaApiUrl ? <MediaHost api={mediaApi} /> : null}
            {dialog === 'component' && <ComponentDialog onClose={() => setDialog(null)} onCreate={createComponent} />}
            {dialog === 'theme' && <ThemeDialog onClose={() => setDialog(null)} onSaved={onTokensSaved} />}
            {dialog === 'ai_review' && <AiReviewDialog onClose={() => setDialog(null)} onPublish={publishReviewed} />}
            {dialog === 'duplicate_page' && <DuplicatePageDialog onClose={() => setDialog(null)} />}
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
  viewportKey, onViewport, onSave, onUndo, onRedo, onPublish, onReload, onReloadCanvas = null, announcement, notice = '', lockState,
  historyOpen = false, setHistoryOpen = () => {}, pendingInsert = null, onCancelInsert = () => {}, onConfirmInsert = () => {},
  onTheme = null, dialogs = null, onAiReview = null, onPackages = null, onDuplicatePage = null,
}) {
  const { selection, select } = useSelection();
  const { manifest, canvasView, structureOpen, setStructureOpen, toggleStructure, openLibraryModal, tokensSaved } = useEditor();
  const [settingsView, setSettingsView] = useState('page');
  // A phone keeps Layers as the first sheet; the docked desktop panel opens on Add.
  const [activeTab, setActiveTab] = useState(() => (selection ? 'inspector' : (isMobileShellNow() ? 'structure' : 'blocks')));
  const isMobile = useIsMobileShell();
  const [mobileOpen, setMobileOpen] = useState(false);
  const [responsiveOpen, setResponsiveOpen] = useState(false);
  const [collapsed, setCollapsed] = useState(false);
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

  // Selecting a node points the panel at its controls. On a phone it does NOT open the sheet: the canvas stays
  // visible with the contextual bar, and Edit (dock) opens the sheet. On a desktop the one left panel swaps to its
  // Edit tab only when the author picks on the canvas (`openEdit`); a selection made inside the panel itself (a Layers
  // row, an element just added from Add) leaves the panel where the author is working.
  useEffect(() => {
    if (selection && editing && isMobile) setActiveTab('inspector');
  }, [selection, editing, isMobile]);
  const { panelRequest } = useEditor();
  useEffect(() => {
    if (!panelRequest.n || !panelRequest.tab || isMobile) return;
    setCollapsed(false);
    setActiveTab(panelRequest.tab);
  }, [panelRequest, isMobile]);

  // Edit toggle: open the left panel on its Edit tab; a second press collapses the panel for a full-width canvas.
  const inspectorOpen = isMobile ? (mobileOpen && activeTab === 'inspector') : (!collapsed && activeTab === 'inspector');
  const toggleInspector = useCallback(() => {
    if (isMobile) {
      setActiveTab('inspector');
      setMobileOpen((open) => !(open && activeTab === 'inspector'));
      return;
    }
    if (!collapsed && activeTab === 'inspector') { setCollapsed(true); return; }
    setCollapsed(false);
    setActiveTab('inspector');
  }, [activeTab, collapsed, isMobile]);

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
        structureOpen={structureOpen}
        onToggleStructure={isMobile ? null : toggleStructure}
        onLibrary={isMobile ? null : openLibraryModal}
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
          settingsView={settingsView}
          onSettingsViewChange={setSettingsView}
          mobileOpen={mobileOpen && !SHEET_TABS.includes(activeTab)}
          onCloseMobile={() => setMobileOpen(false)}
        />
        <CanvasArea
          interactive={editing}
          collapsed={collapsed}
          onToggleCollapse={() => setCollapsed((c) => !c)}
          onReloadCanvas={onReloadCanvas}
        />
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
      {structureOpen && !isMobile && <StructureWindow onClose={() => setStructureOpen(false)} />}
      <LiveRegion text={announcement} />
      {notice && <div className="sbx-toast" aria-hidden="true" data-testid="toast">{notice}</div>}
      {pendingInsert && <InsertDialog request={pendingInsert} onCancel={onCancelInsert} onConfirm={onConfirmInsert} />}
      {historyOpen && <HistoryDialog onClose={() => setHistoryOpen(false)} />}
      {mobileOpen && activeTab === 'theme' && (
        <ThemeBottomSheet
          onClose={() => setMobileOpen(false)}
          onSaved={() => tokensSaved && tokensSaved()}
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
            setSettingsView('page');
            setActiveTab('settings');
            setMobileOpen(true);
          }}
          onOpenLibrary={() => {
            setActiveTab('library');
            setMobileOpen(true);
          }}
          onDuplicatePage={onDuplicatePage}
          onOpenHistory={() => setHistoryOpen(true)}
          onOpenPackages={onPackages}
          onOpenCustomCode={manifest && manifest.permissions && manifest.permissions.admin ? () => {
            setSettingsView('site');
            setActiveTab('settings');
            setMobileOpen(true);
          } : null}
        />
      )}
      {dialogs}
    </div>
  );
}

export { STATUS };
