// SheetPrimitive — the mobile bottom sheet used by More, Theme, Responsive view and
// the page-tool sheets.
//
//   - a handle you can DRAG: the sheet follows the finger, settles on a snap point
//     (core/sheetSnap.mjs) and closes when pulled well below the lowest one
//   - dialog semantics: aria-modal, focus moves in on open and back to the opener on
//     close, Tab stays inside, Escape and the backdrop close it
//   - safe-area aware (bottom inset) and never taller than the visible viewport, so
//     the on-screen keyboard cannot push its fields out of view
//
// Markup keeps the existing `sbx-bottom-sheet*` classes so sheets look unchanged.

import { useCallback, useEffect, useId, useRef, useState } from 'react';
import { IconX } from '../Icons.jsx';
import { dragHeight, settle, snapHeights } from '../../core/sheetSnap.mjs';
import { t } from '../../core/messages.mjs';

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

function visibleHeight() {
  if (typeof window === 'undefined') return 800;
  return Math.round(window.visualViewport ? window.visualViewport.height : window.innerHeight);
}

/**
 * Drag-to-resize for any bottom sheet. Spread `handlers` on the grab area: the sheet follows
 * the finger, then settles on a snap point or closes. `height` is null until the first drag
 * (the sheet hugs its content until then).
 */
export function useSheetDrag(panelRef, onClose) {
  const drag = useRef(null);
  const [height, setHeight] = useState(null);
  const [dragging, setDragging] = useState(false);
  const reset = useCallback(() => setHeight(null), []);

  const onPointerDown = (e) => {
    if (e.button !== undefined && e.button !== 0) return;
    const panel = panelRef.current;
    if (!panel) return;
    const snaps = snapHeights(visibleHeight(), undefined, { maxHeight: visibleHeight() - 24 });
    drag.current = { y: e.clientY, h: panel.offsetHeight, snaps, last: { y: e.clientY, t: performance.now() }, v: 0 };
    e.currentTarget.setPointerCapture?.(e.pointerId);
    setDragging(true);
  };
  const onPointerMove = (e) => {
    const d = drag.current;
    if (!d) return;
    const now = performance.now();
    d.v = (e.clientY - d.last.y) / Math.max(1, now - d.last.t); // px/ms, positive = moving down
    d.last = { y: e.clientY, t: now };
    setHeight(dragHeight(d.h, e.clientY - d.y, d.snaps));
  };
  const onPointerUp = (e) => {
    const d = drag.current;
    if (!d) return;
    drag.current = null;
    setDragging(false);
    e.currentTarget.releasePointerCapture?.(e.pointerId);
    const current = panelRef.current ? panelRef.current.offsetHeight : d.h;
    const to = settle(current, d.v, d.snaps);
    if (to === null) { setHeight(null); onClose(); } else setHeight(to);
  };

  return {
    height,
    dragging,
    reset,
    handlers: { onPointerDown, onPointerMove, onPointerUp, onPointerCancel: onPointerUp },
  };
}

export function SheetPrimitive({ title, subtitle = null, onClose, className = '', label = null, closeLabel = null, testId = 'sheet', children }) {
  const panelRef = useRef(null);
  const headingId = useId();
  const { height, dragging, handlers } = useSheetDrag(panelRef, onClose);

  // Focus in on open; back to whatever opened the sheet on close.
  useEffect(() => {
    const opener = typeof document !== 'undefined' ? document.activeElement : null;
    const panel = panelRef.current;
    if (panel) {
      const first = panel.querySelector(FOCUSABLE);
      (first || panel).focus({ preventScroll: true });
    }
    return () => { if (opener && typeof opener.focus === 'function' && document.contains(opener)) opener.focus({ preventScroll: true }); };
  }, []);

  const onKeyDown = useCallback((e) => {
    if (e.key === 'Escape') { e.stopPropagation(); onClose(); return; }
    if (e.key !== 'Tab') return;
    const nodes = [...panelRef.current.querySelectorAll(FOCUSABLE)].filter((el) => el.offsetParent !== null || el === document.activeElement);
    if (!nodes.length) { e.preventDefault(); return; }
    const first = nodes[0];
    const last = nodes[nodes.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  }, [onClose]);

  const style = height === null ? undefined : { height: `${height}px`, maxHeight: `${height}px` };

  return (
    <>
      <div className="sbx-sheet-scrim" data-testid={`${testId}-scrim`} onClick={onClose} aria-hidden="true" />
      <div
        ref={panelRef}
        className={`sbx-bottom-sheet ${className}${dragging ? ' is-dragging' : ''}`}
        role="dialog"
        aria-modal="true"
        aria-label={label || undefined}
        aria-labelledby={label ? undefined : headingId}
        tabIndex={-1}
        data-testid={testId}
        style={style}
        onKeyDown={onKeyDown}
      >
        <div className="sbx-bottom-sheet__drag-zone" data-testid={`${testId}-handle`} {...handlers}>
          <div className="sbx-bottom-sheet__drag-handle" aria-hidden="true" />
          <div className="sbx-bottom-sheet__header">
            <div className="sbx-bottom-sheet__title-group">
              <h2 className="sbx-bottom-sheet__title" id={headingId}>{title}</h2>
              {subtitle && <p className="sbx-bottom-sheet__subtitle">{subtitle}</p>}
            </div>
            <button
              type="button"
              className="sbx-bottom-sheet__close"
              data-testid={`${testId}-close`}
              onClick={onClose}
              onPointerDown={(e) => e.stopPropagation()}
              aria-label={closeLabel || t('close')}
            >
              <IconX size={16} />
            </button>
          </div>
        </div>
        {children}
      </div>
    </>
  );
}
