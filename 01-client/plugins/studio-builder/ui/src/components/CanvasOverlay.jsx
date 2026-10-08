// CanvasOverlay — the selection box, name chip and contextual toolbar.
//
// Drawn in the PARENT document above the canvas stage, positioned from the
// rectangles of `[data-sb-node]` elements inside the script-less canvas iframe.
// Nothing is written into the frame: the toolbar can never be clipped by a
// node's overflow, is reachable by keyboard, and renders names as plain text.

import { memo, useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { useEditor, useEngineState, useSelection } from './EditorContext.jsx';
import { childrenOf, findNode, sectionsOf } from '../core/doc.mjs';
import { effectivelyLocked, lockIndex } from '../core/layerLock.mjs';
import { clipTo, frameScale, placeFloating, rect, selectionCorners, stepId, toOverlayRect, unionRect } from '../core/overlayGeometry.mjs';
import { t } from '../core/messages.mjs';

const NODE_ATTR = 'data-sb-node';
const FALLBACK_BAR = { width: 280, height: 30 };

const cssEscape = (id) => (typeof CSS !== 'undefined' && CSS.escape ? CSS.escape(id) : String(id).replace(/[^a-zA-Z0-9_-]/g, (c) => `\\${c}`));

const ICONS = {
  up: <path d="M12 19V5M5 12l7-7 7 7" />,
  down: <path d="M12 5v14M19 12l-7 7-7-7" />,
  edit: <path d="M12 20h9M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z" />,
  duplicate: <><rect x="9" y="9" width="13" height="13" rx="2" ry="2" /><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" /></>,
  remove: <><polyline points="3 6 5 6 21 6" /><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" /></>,
};

function ToolbarButton({ action, label, disabled, tabIndex, onClick, onFocus, refCb }) {
  return (
    <button
      type="button"
      ref={refCb}
      className="sbx-overlay__btn"
      data-action={action}
      data-testid={`overlay-${action}`}
      aria-label={label}
      title={label}
      disabled={disabled}
      tabIndex={tabIndex}
      onClick={onClick}
      onFocus={onFocus}
    >
      <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">{ICONS[action]}</svg>
    </button>
  );
}

/**
 * @param {object} props
 * @param {React.RefObject<HTMLIFrameElement>} props.frameRef   the canvas iframe
 * @param {React.RefObject<HTMLElement>}       props.stageRef   the scrolling stage (re-measure on scroll)
 * @param {number} props.scale                  canvas zoom (re-measure on change)
 * @param {boolean} props.enabled               false in preview modes: nothing is drawn
 * @param {(action: string, id: string) => void} props.onAction
 */
export const CanvasOverlay = memo(function CanvasOverlay({ frameRef, stageRef, scale, enabled, onAction }) {
  const { labelOf } = useEditor();
  const { selection, selectedIds } = useSelection();
  const working = useEngineState((s) => s.working);
  const rootRef = useRef(null);
  const barRef = useRef(null);
  const buttonRefs = useRef([]);
  const [geo, setGeo] = useState({ items: [], bounds: rect(0, 0, 0, 0) });
  const [barSize, setBarSize] = useState(FALLBACK_BAR);
  const [activeIdx, setActiveIdx] = useState(0);
  const lastKey = useRef('');
  const frameRaf = useRef(0);

  const measure = useCallback(() => {
    const root = rootRef.current;
    const frame = frameRef.current;
    if (!root || !frame || !enabled || !selectedIds.length) {
      if (lastKey.current !== '') { lastKey.current = ''; setGeo({ items: [], bounds: rect(0, 0, 0, 0) }); }
      return;
    }
    let doc = null;
    try { doc = frame.contentDocument; } catch { doc = null; }
    if (!doc || doc.readyState === 'loading') return;
    const container = root.getBoundingClientRect();
    const fr = frame.getBoundingClientRect();
    const s = frameScale(fr, frame.clientWidth);
    const bounds = rect(0, 0, container.width, container.height);
    const items = [];
    for (const id of selectedIds) {
      const el = doc.querySelector(`[${NODE_ATTR}="${cssEscape(id)}"]`);
      if (!el) continue;
      const r = el.getBoundingClientRect();
      const full = toOverlayRect({ left: r.left, top: r.top, width: r.width, height: r.height }, fr, container, s);
      const visible = clipTo(full, bounds);
      if (visible) items.push({ id, rect: full, visible });
    }
    const key = JSON.stringify([items.map((i) => [i.id, i.visible]), bounds]);
    if (key === lastKey.current) return;
    lastKey.current = key;
    setGeo({ items, bounds });
  }, [frameRef, enabled, selectedIds]);

  const schedule = useCallback(() => {
    if (frameRaf.current) return;
    frameRaf.current = requestAnimationFrame(() => { frameRaf.current = 0; measure(); });
  }, [measure]);

  // Re-measure whenever what we draw from can have changed.
  useEffect(() => { lastKey.current = ''; schedule(); }, [schedule, working, scale, selectedIds]);

  useEffect(() => {
    const frame = frameRef.current;
    const stage = stageRef.current;
    const root = rootRef.current;
    if (!frame || !root) return undefined;
    let win = null;
    let observer = null;
    const attach = () => {
      try {
        win = frame.contentWindow;
        if (win) win.addEventListener('scroll', schedule, { passive: true });
        const doc = frame.contentDocument;
        if (doc && doc.body && typeof MutationObserver !== 'undefined') {
          observer = new MutationObserver(schedule);
          observer.observe(doc.body, { childList: true, subtree: true, attributes: true, characterData: true });
        }
      } catch { /* cross-origin frame: nothing to measure */ }
      schedule();
    };
    const detach = () => {
      try { if (win) win.removeEventListener('scroll', schedule); } catch { /* ignore */ }
      if (observer) observer.disconnect();
      win = null;
      observer = null;
    };
    const onLoad = () => { detach(); attach(); };
    frame.addEventListener('load', onLoad);
    if (stage) {
      stage.addEventListener('scroll', schedule, { passive: true });
      // Zoom and fit animate with CSS transitions, which ResizeObserver does not see: measure again when they end.
      stage.addEventListener('transitionend', schedule);
    }
    window.addEventListener('resize', schedule);
    const ro = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(schedule) : null;
    if (ro) { ro.observe(root); ro.observe(frame); }
    attach();
    return () => {
      frame.removeEventListener('load', onLoad);
      if (stage) {
        stage.removeEventListener('scroll', schedule);
        stage.removeEventListener('transitionend', schedule);
      }
      window.removeEventListener('resize', schedule);
      if (ro) ro.disconnect();
      detach();
      if (frameRaf.current) { cancelAnimationFrame(frameRaf.current); frameRaf.current = 0; }
    };
  }, [frameRef, stageRef, schedule]);

  const single = selectedIds.length === 1;
  const primary = useMemo(() => geo.items.find((i) => i.id === selection) || null, [geo.items, selection]);
  const multiUnion = useMemo(() => (selectedIds.length > 1 ? unionRect(geo.items.map((i) => i.visible)) : null), [geo.items, selectedIds.length]);

  // The toolbar needs its real size to be placed without leaving the stage.
  useLayoutEffect(() => {
    const bar = barRef.current;
    if (!bar) return;
    const w = bar.offsetWidth;
    const h = bar.offsetHeight;
    if (w && h && (w !== barSize.width || h !== barSize.height)) setBarSize({ width: w, height: h });
  });

  const info = single && selection ? findNode(working, selection) : null;
  const locked = !!info && effectivelyLocked(lockIndex(working), info.node.id);
  const siblings = info ? (info.kind === 'section' ? sectionsOf(working) : childrenOf(working, info.parentId)) : [];
  const buttons = info ? [
    { action: 'up', label: t('move_up'), disabled: locked || info.index <= 0 },
    { action: 'down', label: t('move_down'), disabled: locked || info.index >= siblings.length - 1 },
    { action: 'edit', label: t('edit_inline'), disabled: locked },
    { action: 'duplicate', label: t('duplicate'), disabled: false },
    { action: 'remove', label: t('remove_item'), disabled: locked },
  ] : [];
  const enabledIdx = buttons.map((b, i) => (b.disabled ? -1 : i)).filter((i) => i >= 0);
  const current = enabledIdx.includes(activeIdx) ? activeIdx : (enabledIdx[0] ?? 0);

  const focusButton = (idx) => {
    setActiveIdx(idx);
    const el = buttonRefs.current[idx];
    if (el) el.focus();
  };
  const onBarKeyDown = (e) => {
    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(e.key)) return;
    e.preventDefault();
    if (!enabledIdx.length) return;
    if (e.key === 'Home') focusButton(enabledIdx[0]);
    else if (e.key === 'End') focusButton(enabledIdx[enabledIdx.length - 1]);
    else focusButton(stepId(enabledIdx, current, e.key === 'ArrowRight' ? 1 : -1));
  };

  const barPlacement = primary && single ? placeFloating(primary.visible, barSize, geo.bounds, { gap: 6, align: 'left' }) : null;
  const chipText = single && info ? labelOf(info.node.id) : '';
  const chipPlacement = multiUnion ? placeFloating(multiUnion, { width: 96, height: 24 }, geo.bounds, { gap: 6, align: 'left' }) : null;

  return (
    <div className="sbx-overlay" ref={rootRef} data-testid="canvas-overlay" data-active={enabled && selectedIds.length ? 'true' : 'false'}>
      {enabled && geo.items.map((item) => (
        <div
          key={item.id}
          className={`sbx-overlay__box${item.id === selection ? ' is-primary' : ''}${selectedIds.length > 1 ? ' is-multi' : ''}`}
          data-overlay-for={item.id}
          style={{ left: item.visible.left, top: item.visible.top, width: item.visible.width, height: item.visible.height }}
          aria-hidden="true"
        />
      ))}
      {enabled && primary && single && selectionCorners(primary.visible).map((c, i) => (
        <span key={i} className="sbx-overlay__corner" style={{ left: c.x, top: c.y }} aria-hidden="true" />
      ))}
      {enabled && single && info && barPlacement && (
        <div
          ref={barRef}
          className="sbx-overlay__bar"
          role="toolbar"
          aria-label={t('selection_toolbar')}
          aria-orientation="horizontal"
          data-placement={barPlacement.placement}
          data-testid="overlay-toolbar"
          style={{ left: barPlacement.left, top: barPlacement.top }}
          onKeyDown={onBarKeyDown}
        >
          <span className="sbx-overlay__name" data-testid="overlay-name">{chipText}</span>
          <span className="sbx-overlay__actions">
            {buttons.map((b, i) => (
              <ToolbarButton
                key={b.action}
                action={b.action}
                label={b.label}
                disabled={b.disabled}
                tabIndex={i === current ? 0 : -1}
                refCb={(el) => { buttonRefs.current[i] = el; }}
                onFocus={() => setActiveIdx(i)}
                onClick={() => onAction(b.action, info.node.id)}
              />
            ))}
          </span>
        </div>
      )}
      {enabled && multiUnion && chipPlacement && (
        <div className="sbx-overlay__chip" style={{ left: chipPlacement.left, top: chipPlacement.top }} data-testid="overlay-count">
          {t('bulk_selected', { count: selectedIds.length })}
        </div>
      )}
    </div>
  );
});
