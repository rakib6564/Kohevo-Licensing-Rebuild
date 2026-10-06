// CanvasArea — the server-rendered canvas.
//
// Shows `renderForEditor()` output (admin/canvas.php) in a same-origin iframe
// sandboxed WITHOUT scripts. The builder never renders blocks itself: after
// each server-confirmed revision the frame is reloaded (debounced, scroll
// position kept), and selection is resolved from `data-sb-node` attributes.
// The viewport buttons only change the frame width, so the canonical
// breakpoints (base/sm/md/lg) of the server CSS apply naturally. The frame
// always has the EXACT device width and is scaled down to fit the stage, so
// "Desktop · lg" really renders at the lg breakpoint on a small screen.

import { memo, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useEditor, useEngineState } from './EditorContext.jsx';
import { attachCanvas, markSelected } from '../core/canvas.mjs';
import { patchCanvas } from '../core/canvasPatch.mjs';
import { STATUS } from '../core/sync.mjs';
import { t } from '../core/messages.mjs';
import { ancestorPath, asList, canInsertBlock, canMoveBlock, findNode } from '../core/doc.mjs';
import { DRAG_TYPE_NEW } from './BlockPalette.jsx';

const RELOAD_DEBOUNCE_MS = 250;

export const CanvasArea = memo(function CanvasArea() {
  const { boot, selection, select, viewport, canvasVersion = 0, manifest, insertBlock, moveBlockTo, moveSectionTo, duplicateNode, removeNode } = useEditor();
  const working = useEngineState((s) => s.working);
  // The frame is rendered from the server-confirmed revision, so `base` is the
  // document its DOM corresponds to — not `working`, which may already carry
  // unsaved edits.
  const base = useEngineState((s) => s.base);
  const baseRef = useRef(null);
  baseRef.current = base;
  const path = useMemo(() => ancestorPath(working, selection, manifest), [working, selection, manifest]);
  const revisionId = useEngineState((s) => (s.revision ? s.revision.id : 0));
  const status = useEngineState((s) => s.status);
  // `canvasVersion` bumps when shared inputs of the render change without a new
  // revision of THIS page (design tokens saved, a global component published).
  const canvasSrc = `${boot.canvasUrl}?page=${boot.pageId}&v=${revisionId}${canvasVersion ? `-${canvasVersion}` : ''}`;
  const [src, setSrc] = useState(() => canvasSrc);
  const [loading, setLoading] = useState(true);
  const frameRef = useRef(null);
  const detachRef = useRef(() => {});
  const scrollRef = useRef(0);
  const selectionRef = useRef(selection);
  selectionRef.current = selection;
  const selectRef = useRef(select);
  selectRef.current = select;
  const stageRef = useRef(null);
  const [stage, setStage] = useState({ width: 0, height: 0 });

  // ── Optimistic canvas patching (Phase 3) ─────────────────────────────────
  //
  // The working document changes on every keystroke, long before the server
  // confirms a revision. Rather than reload the frame each time, diff the
  // working document against the one the canvas was last painted from and patch
  // the frame in place — for the changes that can be patched provably. Anything
  // else simply falls through to the existing reload effect above, so the
  // worst case is exactly what the builder did before this change.
  //
  // `paintedRef` is the document the CURRENT frame content corresponds to. It is
  // set on load (from the engine's base document) and after every patch, so the
  // diff is always against what is actually on screen.
  const paintedRef = useRef(null);

  useEffect(() => {
    const el = stageRef.current;
    if (!el || typeof ResizeObserver === 'undefined') return undefined;
    const ro = new ResizeObserver(([entry]) => {
      const r = entry.contentRect;
      setStage({ width: r.width, height: r.height });
    });
    ro.observe(el);
    return () => ro.disconnect();
  }, []);
  const scale = stage.width > 0 ? Math.min(1, stage.width / viewport.width) : 1;
  const frameHeight = stage.height > 0 ? Math.max(480, stage.height / scale) : 800;

  // Reload only when the server revision (or a shared input) changes — never on local keystrokes.
  useEffect(() => {
    const next = canvasSrc;
    if (next === src) return undefined;
    const h = setTimeout(() => {
      try {
        const win = frameRef.current && frameRef.current.contentWindow;
        scrollRef.current = win ? win.scrollY : 0;
      } catch { scrollRef.current = 0; }
      setLoading(true);
      setSrc(next);
    }, RELOAD_DEBOUNCE_MS);
    return () => clearTimeout(h);
  }, [canvasSrc, src]);

  // Patch the frame as soon as the working document changes, without waiting
  // for a server-confirmed revision. Runs on every `working` change; if the
  // patch engine declines (structural or provider-driven), we do nothing here
  // and the reload effect above handles it once the server confirms.
  useEffect(() => {
    if (!working || loading) return;
    const prev = paintedRef.current;
    if (!prev || prev === working) return;

    let doc = null;
    try { doc = frameRef.current && frameRef.current.contentDocument; } catch { doc = null; }
    if (!doc || doc.readyState === 'loading') return;

    const result = patchCanvas(doc, prev, working);
    if (result.reload) {
      // Do NOT advance paintedRef: the frame still shows `prev`, and the reload
      // effect will re-render it and set paintedRef from the fresh base.
      return;
    }
    paintedRef.current = working;
    // A patched node may have gained or lost the hover/selected outlines, so
    // repaint the selection over the new DOM.
    markSelected(doc, selectionRef.current, { scroll: false });
  }, [working, loading]);

  const onCanvasDrop = useCallback(({ targetId, targetType, position, dataTransfer }) => {
    if (!dataTransfer) return;
    const newType = dataTransfer.getData(DRAG_TYPE_NEW);
    const draggedId = dataTransfer.getData('application/x-kohevo-studio-node');
    if (!newType && !draggedId) return;

    const targetInfo = findNode(working, targetId);
    if (!targetInfo) return;

    let dest = null;
    if (targetInfo.kind === 'section') {
      dest = { parentId: targetId, index: asList(targetInfo.node.blocks).length };
    } else if (position === 'inside') {
      dest = { parentId: targetId, index: asList(targetInfo.node.children).length };
    } else {
      const idx = targetInfo.index + (position === 'after' ? 1 : 0);
      dest = { parentId: targetInfo.parentId, index: idx };
    }

    if (!dest) return;

    if (newType) {
      if (canInsertBlock(working, manifest, dest.parentId, newType)) {
        insertBlock(newType, dest);
      }
    } else if (draggedId) {
      if (canMoveBlock(working, manifest, draggedId, dest.parentId)) {
        moveBlockTo(draggedId, dest);
      }
    }
  }, [working, manifest, insertBlock, moveBlockTo]);

  // Floating section bar inside the canvas: the listener is attached once per
  // frame load, so it reads the latest handler through a ref.
  const actionRef = useRef(() => {});
  actionRef.current = (action, nodeId) => {
    const info = nodeId ? findNode(working, nodeId) : null;
    if (!info) return;
    if (action === 'duplicate') { duplicateNode && duplicateNode(nodeId); return; }
    if (action === 'remove') { removeNode && removeNode(nodeId); return; }
    const delta = action === 'up' ? -1 : action === 'down' ? 1 : 0;
    if (!delta) return;
    if (info.kind === 'section') {
      const to = info.index + delta;
      if (to >= 0 && to < asList(working && working.sections).length && moveSectionTo) moveSectionTo(nodeId, to);
    } else if (moveBlockTo) {
      const to = info.index + delta;
      if (to >= 0) moveBlockTo(nodeId, { parentId: info.parentId, index: delta > 0 ? to + 1 : to });
    }
  };

  const onLoad = () => {
    setLoading(false);
    detachRef.current();
    let doc = null;
    try { doc = frameRef.current.contentDocument; } catch { doc = null; }
    if (!doc) return;
    // Fresh server render: the DOM now corresponds to the confirmed document.
    paintedRef.current = baseRef.current;
    detachRef.current = attachCanvas(doc, {
      onSelect: (id) => selectRef.current(id),
      onDrop: onCanvasDrop,
      onAction: (action, id) => actionRef.current(action, id),
    });
    try { frameRef.current.contentWindow.scrollTo(0, scrollRef.current); } catch { /* ignore */ }
    markSelected(doc, selectionRef.current, { scroll: false });
  };

  useEffect(() => () => detachRef.current(), []);

  useEffect(() => {
    let doc = null;
    try { doc = frameRef.current && frameRef.current.contentDocument; } catch { doc = null; }
    if (doc && doc.readyState !== 'loading') markSelected(doc, selection);
  }, [selection]);

  const unsaved = status === STATUS.DIRTY || status === STATUS.SAVING;

  return (
    <main className="sbx-canvas" aria-label="Canvas">
      <div className="sbx-canvas__meta">
        <span className="sbx-canvas__meta-pill">
          {t('editing_at')} <strong>{t(viewport.key)}</strong> · <code>{viewport.breakpoint}</code> · {viewport.width}px{scale < 1 ? ` · ${Math.round(scale * 100)}%` : ''}
        </span>
        {unsaved && (
          <span className="sbx-canvas__save-hint" title="canvas updates after save">
            <span className="sbx-status__dot" aria-hidden="true" />
            <span>canvas updates after save</span>
          </span>
        )}
      </div>

      {path.length > 0 && (
        <nav className="sbx-breadcrumbs" aria-label="Selection hierarchy">
          <button type="button" className="sbx-breadcrumbs__item" onClick={() => select(null)}>Page</button>
          {path.map((item, idx) => (
            <span key={item.id} className="sbx-breadcrumbs__crumb">
              <span className="sbx-breadcrumbs__sep" aria-hidden="true">›</span>
              <button
                type="button"
                className={`sbx-breadcrumbs__item${idx === path.length - 1 ? ' is-active' : ''}`}
                onClick={() => select(item.id)}
              >
                {item.label}
              </button>
            </span>
          ))}
        </nav>
      )}

      <div className="sbx-canvas__stage" ref={stageRef}>
        <div className="sbx-canvas__fit" style={{ width: `${Math.floor(viewport.width * scale)}px`, height: `${Math.floor(frameHeight * scale)}px` }}>
        <div
          className="sbx-canvas__device"
          data-scale={scale.toFixed(3)}
          style={{ width: `${viewport.width}px`, height: `${frameHeight}px`, transform: scale < 1 ? `scale(${scale})` : undefined }}
        >
          <iframe
            ref={frameRef}
            title={t('app_name')}
            src={src}
            sandbox={boot.canvasSandbox || 'allow-same-origin'}
            referrerPolicy="same-origin"
            onLoad={onLoad}
            className="sbx-canvas__frame"
          />
          {loading && <div className="sbx-canvas__loading" aria-hidden="true" />}
        </div>
        </div>
      </div>
    </main>
  );
});
