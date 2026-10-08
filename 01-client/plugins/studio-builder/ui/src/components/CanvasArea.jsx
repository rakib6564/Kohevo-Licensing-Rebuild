// CanvasArea — the server-rendered interactive visual canvas.
//
// Shows `renderForEditor()` output (admin/canvas.php) in a same-origin iframe
// with live direct inline text editing (WYSIWYG), universal on-canvas floating
// action toolbar, responsive viewport scaling, and multi-mode zoom controls.

import { memo, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useEditor, useEngineState } from './EditorContext.jsx';
import { attachCanvas, markSelected } from '../core/canvas.mjs';
import { patchCanvas } from '../core/canvasPatch.mjs';
import { isStructuralChange, syncLiveDOM } from '../core/canvasLiveSync.mjs';
import { STATUS } from '../core/sync.mjs';
import { t } from '../core/messages.mjs';
import { ancestorPath, asList, canInsertBlock, canMoveBlock, findNode } from '../core/doc.mjs';
import * as ops from '../core/operations.mjs';
import { DRAG_TYPE_NEW } from './BlockPalette.jsx';

const RELOAD_DEBOUNCE_MS = 250;

/** Read-only canvas: links and forms must not navigate the frame away from the page being edited. */
function blockNavigation(doc) {
  const stop = (e) => {
    const el = e.target && e.target.closest ? e.target.closest('a[href],form') : null;
    if (el) e.preventDefault();
  };
  doc.addEventListener('click', stop, true);
  doc.addEventListener('submit', stop, true);
  return () => {
    doc.removeEventListener('click', stop, true);
    doc.removeEventListener('submit', stop, true);
  };
}

export const CanvasArea = memo(function CanvasArea({ interactive = true, collapsed = false, onToggleCollapse = null, onReloadCanvas = null }) {
  const { boot, selection, select, viewport, canvasVersion = 0, manifest, insertBlock, moveBlockTo, moveSectionTo, duplicateNode, removeNode, applyOp } = useEditor();
  const working = useEngineState((s) => s.working);
  const base = useEngineState((s) => s.base);
  const baseRef = useRef(null);
  baseRef.current = base;
  const path = useMemo(() => ancestorPath(working, selection, manifest), [working, selection, manifest]);
  const revisionId = useEngineState((s) => (s.revision ? s.revision.id : 0));
  const status = useEngineState((s) => s.status);
  const canvasSrc = `${boot.canvasUrl}?page=${boot.pageId}&v=${revisionId}${canvasVersion ? `-${canvasVersion}` : ''}`;
  const [src, setSrc] = useState(() => canvasSrc);
  const [loading, setLoading] = useState(true);
  const [zoomMode, setZoomMode] = useState('fit'); // 'fit' | '100' | '75' | '50'
  const [showGrid, setShowGrid] = useState(false);
  const loadedRef = useRef(false);
  const interactiveRef = useRef(interactive);
  interactiveRef.current = interactive;
  const frameRef = useRef(null);
  const detachRef = useRef(() => {});
  const scrollRef = useRef(0);
  const selectionRef = useRef(selection);
  selectionRef.current = selection;
  const selectRef = useRef(select);
  selectRef.current = select;
  const stageRef = useRef(null);
  const [stage, setStage] = useState({ width: 0, height: 0 });
  const paintedRef = useRef(null);
  const lastLoadedRevRef = useRef(revisionId);

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

  const fitScale = stage.width > 0 ? Math.min(1, Math.max(0.35, (stage.width - 48) / viewport.width)) : 1;
  const scale = useMemo(() => {
    if (zoomMode === '100') return 1;
    if (zoomMode === '75') return 0.75;
    if (zoomMode === '50') return 0.5;
    return fitScale;
  }, [zoomMode, fitScale]);

  const frameHeight = stage.height > 0 ? Math.max(640, (stage.height - 48) / scale) : 900;

  // Direct Inline WYSIWYG text update handler
  const onInlineText = useCallback((nodeId, newText) => {
    const info = findNode(working, nodeId);
    if (!info) return;
    const node = info.node;
    if (info.kind === 'section') {
      applyOp && applyOp(ops.updateSectionLabel(nodeId, newText), { label: 'Update section' });
    } else {
      const props = { ...(node.props || {}) };
      if ('text' in props || ['core.heading', 'core.paragraph', 'core.button', 'core.text'].includes(node.type)) {
        props.text = newText;
      } else if ('title' in props) {
        props.title = newText;
      } else if ('label' in props) {
        props.label = newText;
      } else if ('buttonText' in props) {
        props.buttonText = newText;
      } else if ('content' in props) {
        props.content = newText;
      } else {
        props.text = newText;
      }
      applyOp && applyOp(ops.updateBlockProps(nodeId, props), { label: `Edit ${node.type}` });
    }
  }, [working, applyOp]);

  const onInlineTextRef = useRef(onInlineText);
  onInlineTextRef.current = onInlineText;

  // Smart iframe reload: skip expensive full reloads when DOM was already synced live!
  useEffect(() => {
    const next = canvasSrc;
    if (next === src) return undefined;

    const structural = isStructuralChange(paintedRef.current, working);

    if (!structural && revisionId !== lastLoadedRevRef.current) {
      // The canvas DOM is already updated in 0ms via syncLiveDOM!
      lastLoadedRevRef.current = revisionId;
      paintedRef.current = working;
      setLoading(false);
      return undefined;
    }

    const h = setTimeout(() => {
      try {
        const win = frameRef.current && frameRef.current.contentWindow;
        scrollRef.current = win ? win.scrollY : 0;
      } catch { scrollRef.current = 0; }
      setLoading(true);
      setSrc(next);
      lastLoadedRevRef.current = revisionId;
    }, RELOAD_DEBOUNCE_MS);
    return () => clearTimeout(h);
  }, [canvasSrc, src, revisionId, working]);

  // 0ms Real-time live canvas DOM sync on every keystroke and property change!
  useEffect(() => {
    if (!working || loading) return;
    const prev = paintedRef.current;
    if (!prev || prev === working) return;

    let doc = null;
    try { doc = frameRef.current && frameRef.current.contentDocument; } catch { doc = null; }
    if (!doc || doc.readyState === 'loading') return;

    // Run conservative patchCanvas
    try { patchCanvas(doc, prev, working); } catch (_) {}

    // Synchronize all live DOM elements (props, text, styles, typography, colors, surfaces, cards) in 0ms!
    syncLiveDOM(doc, prev, working, viewport.key);

    paintedRef.current = working;
    markSelected(doc, selectionRef.current, { scroll: false });
  }, [working, loading, viewport.key]);

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

  const actionRef = useRef(() => {});
  actionRef.current = (action, nodeId) => {
    const info = nodeId ? findNode(working, nodeId) : null;
    if (action === 'duplicate') { duplicateNode && duplicateNode(nodeId); return; }
    if (action === 'remove') { removeNode && removeNode(nodeId); return; }
    if (action === 'edit') {
      select && select(nodeId);
      try {
        const doc = frameRef.current && frameRef.current.contentDocument;
        const el = doc && doc.querySelector(`[data-sb-node="${nodeId}"]`);
        if (el && doc.defaultView && doc.defaultView.sbxStartInlineEdit) {
          const textEl = el.querySelector('h1, h2, h3, h4, h5, h6, p, a, button, span') || el;
          doc.defaultView.sbxStartInlineEdit(textEl, el, nodeId);
        }
      } catch (_) {}
      return;
    }
    const delta = action === 'up' ? -1 : action === 'down' ? 1 : 0;
    if (!delta) return;
    if (info && info.kind === 'section') {
      const to = info.index + delta;
      if (to >= 0 && to < asList(working && working.sections).length && moveSectionTo) moveSectionTo(nodeId, to);
    } else if (info && moveBlockTo) {
      const to = info.index + delta;
      if (to >= 0) moveBlockTo(nodeId, { parentId: info.parentId, index: delta > 0 ? to + 1 : to });
    }
  };

  const onCanvasDropRef = useRef(onCanvasDrop);
  onCanvasDropRef.current = onCanvasDrop;

  /** Attach editing handlers (edit mode) or only the navigation guard (preview) to the loaded canvas. */
  const bindDoc = () => {
    detachRef.current();
    detachRef.current = () => {};
    let doc = null;
    try { doc = frameRef.current.contentDocument; } catch { doc = null; }
    if (!doc) return;
    if (!interactiveRef.current) {
      markSelected(doc, null, { scroll: false });
      detachRef.current = blockNavigation(doc);
      return;
    }
    detachRef.current = attachCanvas(doc, {
      onSelect: (id) => selectRef.current(id),
      onDrop: (drop) => onCanvasDropRef.current(drop),
      onAction: (action, id) => actionRef.current(action, id),
      onInlineText: (id, text) => onInlineTextRef.current(id, text),
    });
    markSelected(doc, selectionRef.current, { scroll: false });
  };

  const onLoad = () => {
    setLoading(false);
    loadedRef.current = true;
    paintedRef.current = baseRef.current;
    bindDoc();
    try { frameRef.current.contentWindow.scrollTo(0, scrollRef.current); } catch { /* ignore */ }
  };

  // Entering / leaving preview re-binds the already-loaded canvas without a reload.
  useEffect(() => {
    if (loadedRef.current) bindDoc();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [interactive]);

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
        <div className="sbx-canvas__meta-left">
          <span className="sbx-canvas__meta-pill">
            <strong>{t(viewport.key)}</strong> · {viewport.width}px · <span className="sbx-canvas__scale-badge">{Math.round(scale * 100)}%</span>
          </span>
          {interactive && (
            <nav className="sbx-breadcrumb" aria-label={t('breadcrumb')}>
              <button type="button" className="sbx-breadcrumb__item" onClick={() => select(null)}>{t('page')}</button>
              {path.map((p, i) => (
                <span key={p.id} className="sbx-breadcrumb__seg">
                  <span className="sbx-breadcrumb__sep" aria-hidden="true">›</span>
                  <button
                    type="button"
                    className="sbx-breadcrumb__item"
                    aria-current={i === path.length - 1 ? 'location' : undefined}
                    onClick={() => select(p.id)}
                  >
                    {p.label}
                  </button>
                </span>
              ))}
            </nav>
          )}
          {!interactive && (
            <span className="sbx-canvas__meta-pill sbx-canvas__preview-pill">{t('mode_preview')}</span>
          )}
          {unsaved && (
            <span className="sbx-canvas__save-hint" title="canvas updates after save">
              <span className="sbx-status__dot" aria-hidden="true" />
              <span>canvas updates after save</span>
            </span>
          )}
        </div>

        <div className="sbx-canvas__meta-center" role="group" aria-label="Zoom controls">
          <button
            type="button"
            className={`sbx-canvas__zoom-btn ${zoomMode === 'fit' ? 'is-active' : ''}`}
            onClick={() => setZoomMode('fit')}
            title="Auto-fit canvas to stage"
          >
            Fit
          </button>
          <button
            type="button"
            className={`sbx-canvas__zoom-btn ${zoomMode === '100' ? 'is-active' : ''}`}
            onClick={() => setZoomMode('100')}
            title="Actual 1:1 pixel size (100%)"
          >
            100%
          </button>
          <button
            type="button"
            className={`sbx-canvas__zoom-btn ${zoomMode === '75' ? 'is-active' : ''}`}
            onClick={() => setZoomMode('75')}
            title="75% zoom"
          >
            75%
          </button>
          <button
            type="button"
            className={`sbx-canvas__zoom-btn ${zoomMode === '50' ? 'is-active' : ''}`}
            onClick={() => setZoomMode('50')}
            title="50% zoom"
          >
            50%
          </button>
        </div>

        <div className="sbx-canvas__meta-right">
          <button
            type="button"
            className={`sbx-canvas__tool-btn${showGrid ? ' is-active' : ''}`}
            aria-pressed={showGrid}
            data-testid="canvas-grid-toggle"
            onClick={() => setShowGrid((g) => !g)}
            title={t('grid_toggle')}
          >
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
            <span>{t('grid')}</span>
          </button>
          {onToggleCollapse && (
            <button
              type="button"
              className="sbx-canvas__tool-btn"
              onClick={onToggleCollapse}
              title={collapsed ? 'Show editor sidebar' : 'Hide editor sidebar for full-width canvas'}
            >
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="9" y1="3" x2="9" y2="21"/></svg>
              <span>{collapsed ? 'Show Panel' : 'Full Canvas'}</span>
            </button>
          )}
          <button
            type="button"
            className="sbx-canvas__tool-btn"
            onClick={() => {
              setLoading(true);
              setSrc(`${boot.canvasUrl}?page=${boot.pageId}&v=${Date.now()}`);
              onReloadCanvas && onReloadCanvas();
            }}
            title="Reload canvas"
          >
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/></svg>
            <span>Reload</span>
          </button>
        </div>
      </div>

      <div className="sbx-canvas__stage" ref={stageRef}>
        <div
          className="sbx-canvas__fit"
          style={{
            width: `${Math.floor(viewport.width * scale)}px`,
            height: `${Math.floor(frameHeight * scale)}px`,
            margin: '0 auto',
          }}
        >
          <div
            className="sbx-canvas__device"
            data-scale={scale.toFixed(3)}
            style={{
              width: `${viewport.width}px`,
              height: `${frameHeight}px`,
              transform: scale < 1 ? `scale(${scale})` : undefined,
              transformOrigin: '0 0',
              top: 0,
              left: 0,
            }}
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
            {showGrid && (
              <div className="sbx-canvas__grid" data-cols={viewport.key === 'mobile' ? 4 : viewport.key === 'tablet' ? 8 : 12} aria-hidden="true">
                {Array.from({ length: viewport.key === 'mobile' ? 4 : viewport.key === 'tablet' ? 8 : 12 }, (_, i) => <span key={i} />)}
              </div>
            )}
            {loading && <div className="sbx-canvas__loading" aria-hidden="true" />}
          </div>
        </div>
      </div>
    </main>
  );
});
