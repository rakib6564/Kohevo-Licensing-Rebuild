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
import { STATUS } from '../core/sync.mjs';
import { t } from '../core/messages.mjs';
import { ancestorPath, asList, canInsertBlock, canMoveBlock, findNode } from '../core/doc.mjs';
import { DRAG_TYPE_NEW } from './BlockPalette.jsx';

const RELOAD_DEBOUNCE_MS = 250;

export const CanvasArea = memo(function CanvasArea() {
  const { boot, selection, select, viewport, canvasVersion = 0, manifest, insertBlock, moveBlockTo } = useEditor();
  const working = useEngineState((s) => s.working);
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

  const onLoad = () => {
    setLoading(false);
    detachRef.current();
    let doc = null;
    try { doc = frameRef.current.contentDocument; } catch { doc = null; }
    if (!doc) return;
    detachRef.current = attachCanvas(doc, {
      onSelect: (id) => selectRef.current(id),
      onDrop: onCanvasDrop,
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
        <span>{t('editing_at')} <strong>{t(viewport.key)}</strong> · <code>{viewport.breakpoint}</code> · {viewport.width}px{scale < 1 ? ` · ${Math.round(scale * 100)}%` : ''}</span>
        {unsaved && <span className="sbx-muted" aria-hidden="true"> · canvas updates after save</span>}
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
