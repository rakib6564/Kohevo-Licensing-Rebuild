// CanvasOverlay — the selection box, name chip and contextual toolbar, and the drop zone of an empty container.
//
// Drawn in the PARENT document above the canvas stage, positioned from the
// rectangles of `[data-sb-node]` elements inside the script-less canvas iframe.
// Nothing is written into the frame: the toolbar can never be clipped by a
// node's overflow, is reachable by keyboard, and renders names as plain text.

import { memo, useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { flushSync } from 'react-dom';
import { useEditor, useEngineState, useSelection } from './EditorContext.jsx';
import { asList, asObject, blockDefinition, childrenOf, containersOf, findNode, isGlobalContainer } from '../core/doc.mjs';
import * as ops from '../core/operations.mjs';
import { effectivelyLocked, lockIndex } from '../core/layerLock.mjs';
import { clipTo, frameScale, placeFloating, rect, selectionCorners, stepId, toOverlayRect, unionRect } from '../core/overlayGeometry.mjs';
import { t } from '../core/messages.mjs';

const NODE_ATTR = 'data-sb-node';
const FALLBACK_BAR = { width: 280, height: 30 };
const MAX_AID_NODES = 300;
/** Longest the box follows a moving canvas frame by frame after an animation or transition starts there. */
const FOLLOW_MS = 4000;
/** Layout blocks that are an invitation when empty: an empty one says "drag a widget here" and offers Add. */
const ZONE_TYPES = new Set(['core.container', 'layout.container', 'layout.flex', 'layout.grid']);
/** An empty box shorter than this only has room for the Add button. */
const ZONE_ROOMY = 96;
/** The chooser is asked for, so it shows even in a box smaller than this (a zoomed-out canvas): its content may overflow. */

function emptyZoneIds(doc, manifest) {
  const out = [];
  const walk = (blocks) => {
    for (const b of asList(blocks)) {
      if (!b || !b.id) continue;
      const kids = asList(b.children);
      if (ZONE_TYPES.has(b.type) && kids.length === 0 && !isGlobalContainer(b) && blockDefinition(manifest, b.type)) out.push(b.id);
      walk(kids);
    }
  };
  walk(containersOf(doc));
  return out;
}

const cssEscape = (id) => (typeof CSS !== 'undefined' && CSS.escape ? CSS.escape(id) : String(id).replace(/[^a-zA-Z0-9_-]/g, (c) => `\\${c}`));

const ICONS = {
  up: <path d="M12 19V5M5 12l7-7 7 7" />,
  down: <path d="M12 5v14M19 12l-7 7-7-7" />,
  edit: <path d="M12 20h9M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z" />,
  duplicate: <><rect x="9" y="9" width="13" height="13" rx="2" ry="2" /><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" /></>,
  remove: <><polyline points="3 6 5 6 21 6" /><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" /></>,
};

const ZONE_ICONS = {
  add: <path d="M12 5v14M5 12h14" />,
  templates: <path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z" />,
  ai: <path d="M12 3l1.8 4.7L18.5 9.5l-4.7 1.8L12 16l-1.8-4.7L5.5 9.5l4.7-1.8L12 3zM19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8L19 15z" />,
};

function ZoneButton({ action, label, onClick }) {
  return (
    <button type="button" className="sbx-overlay__zonebtn" data-zone-action={action} data-testid={`zone-${action}`} aria-label={label} title={label} onClick={onClick}>
      <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">{ZONE_ICONS[action]}</svg>
    </button>
  );
}

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
 * @param {(id: string, point: {x: number, y: number}) => void} [props.onZoneMenu]  right-click on an empty Container's own controls, in frame coordinates
 */
export const CanvasOverlay = memo(function CanvasOverlay({ frameRef, stageRef, scale, enabled, outlines = false, labels = false, onAction, onZoneMenu }) {
  const { labelOf, manifest, applyOp, requestPanel, openLibraryModal, layoutChooser, setLayoutChooser } = useEditor();
  const { selection, selectedIds, select } = useSelection();
  const working = useEngineState((s) => s.working);
  const rootRef = useRef(null);
  const barRef = useRef(null);
  const buttonRefs = useRef([]);
  const [geo, setGeo] = useState({ items: [], bounds: rect(0, 0, 0, 0), outlines: [], labels: [], zones: [] });
  const workingRef = useRef(null);
  workingRef.current = working;
  const zoneIds = useMemo(() => emptyZoneIds(working, manifest), [working, manifest]);
  const zoneIdsRef = useRef(zoneIds);
  zoneIdsRef.current = zoneIds;
  const zoneKey = zoneIds.join(',');
  const [barSize, setBarSize] = useState(FALLBACK_BAR);
  const [activeIdx, setActiveIdx] = useState(0);
  const lastKey = useRef('');
  const frameRaf = useRef(0);

  const measure = useCallback(() => {
    const root = rootRef.current;
    const frame = frameRef.current;
    if (!root || !frame || !enabled || (!selectedIds.length && !outlines && !labels && !zoneIdsRef.current.length)) {
      if (lastKey.current !== '') { lastKey.current = ''; setGeo({ items: [], bounds: rect(0, 0, 0, 0), outlines: [], labels: [], zones: [] }); }
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
    // Layout aids (phone Responsive view): thin outlines for every node, name tags for the top-level Containers.
    const place = (id) => {
      const el = doc.querySelector(`[${NODE_ATTR}="${cssEscape(id)}"]`);
      if (!el) return null;
      const r = el.getBoundingClientRect();
      const full = toOverlayRect({ left: r.left, top: r.top, width: r.width, height: r.height }, fr, container, s);
      return clipTo(full, bounds) ? { id, rect: full, visible: clipTo(full, bounds) } : null;
    };
    const picked = new Set(selectedIds);
    const outlineBoxes = [];
    if (outlines) {
      let n = 0;
      doc.querySelectorAll(`[${NODE_ATTR}]`).forEach((el) => {
        const id = el.getAttribute(NODE_ATTR);
        if (n >= MAX_AID_NODES || !id || picked.has(id)) return;
        const hit = place(id);
        if (hit) { outlineBoxes.push(hit); n += 1; }
      });
    }
    const labelTags = [];
    if (labels) {
      for (const top of containersOf(workingRef.current)) {
        const hit = place(top.id);
        if (hit) labelTags.push({ ...hit, text: asObject(top.metadata).label || t('container') });
      }
    }
    const zones = [];
    for (const id of zoneIdsRef.current) {
      const hit = place(id);
      if (hit) zones.push(hit);
    }
    const key = JSON.stringify([items.map((i) => [i.id, i.visible]), outlineBoxes.map((i) => [i.id, i.visible]), labelTags.map((i) => [i.id, i.text, i.visible]), zones.map((i) => [i.id, i.visible]), bounds]);
    if (key === lastKey.current) return;
    lastKey.current = key;
    // Commit before this frame paints: a deferred render leaves the box a frame behind the canvas while it scrolls.
    flushSync(() => setGeo({ items, bounds, outlines: outlineBoxes, labels: labelTags, zones }));
  }, [frameRef, enabled, selectedIds, outlines, labels, zoneKey]);

  const schedule = useCallback(() => {
    if (frameRaf.current) return;
    frameRaf.current = requestAnimationFrame(() => { frameRaf.current = 0; measure(); });
  }, [measure]);

  // Re-measure whenever what we draw from can have changed.
  useEffect(() => { lastKey.current = ''; schedule(); }, [schedule, working, scale, selectedIds, outlines, labels, zoneKey]);

  useEffect(() => {
    const frame = frameRef.current;
    const stage = stageRef.current;
    const root = rootRef.current;
    if (!frame || !root) return undefined;
    let win = null;
    let doc = null;
    let observer = null;
    // An entrance animation or a transition moves nodes without touching the DOM: measure every frame while one runs.
    let followRaf = 0;
    let followUntil = 0;
    const moving = () => {
      try {
        // A CSS animation is still `pending` on its first frame; an endless one (a spinner) is not followed.
        return doc.getAnimations().some((a) => (a.playState === 'running' || a.pending) && Number.isFinite(a.effect?.getComputedTiming?.().endTime));
      } catch { return false; }
    };
    const followStep = () => {
      followRaf = 0;
      measure();
      if (doc && performance.now() < followUntil && moving()) followRaf = requestAnimationFrame(followStep);
    };
    const follow = () => {
      followUntil = performance.now() + FOLLOW_MS;
      if (!followRaf) followRaf = requestAnimationFrame(followStep);
    };
    const MOTION_START = ['animationstart', 'transitionrun'];
    const MOTION_END = ['animationend', 'animationcancel', 'transitionend', 'transitioncancel'];
    const onFonts = () => schedule();
    const attach = () => {
      try {
        win = frame.contentWindow;
        if (win) win.addEventListener('scroll', schedule, { passive: true });
        doc = frame.contentDocument;
        if (doc && doc.body && typeof MutationObserver !== 'undefined') {
          observer = new MutationObserver(schedule);
          observer.observe(doc.body, { childList: true, subtree: true, attributes: true, characterData: true });
        }
        if (doc) {
          for (const type of MOTION_START) doc.addEventListener(type, follow, true);
          for (const type of MOTION_END) doc.addEventListener(type, schedule, true);
          doc.addEventListener('load', schedule, true); // an image arriving resizes the nodes around it
          if (doc.fonts && doc.fonts.addEventListener) doc.fonts.addEventListener('loadingdone', onFonts);
          if (moving()) follow();
        }
      } catch { /* cross-origin frame: nothing to measure */ }
      schedule();
    };
    const detach = () => {
      try { if (win) win.removeEventListener('scroll', schedule); } catch { /* ignore */ }
      try {
        if (doc) {
          for (const type of MOTION_START) doc.removeEventListener(type, follow, true);
          for (const type of MOTION_END) doc.removeEventListener(type, schedule, true);
          doc.removeEventListener('load', schedule, true);
          if (doc.fonts && doc.fonts.removeEventListener) doc.fonts.removeEventListener('loadingdone', onFonts);
        }
      } catch { /* ignore */ }
      if (observer) observer.disconnect();
      if (followRaf) { cancelAnimationFrame(followRaf); followRaf = 0; }
      win = null;
      doc = null;
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
  }, [frameRef, stageRef, schedule, measure]);

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
  const siblings = info ? childrenOf(working, info.parentId) : [];
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
      {enabled && geo.zones.map((z) => {
        const roomy = z.visible.height >= ZONE_ROOMY && z.visible.width >= 160;
        const choosing = layoutChooser === z.id;
        const pick = (layout) => {
          setLayoutChooser(null);
          if (layout === 'grid') {
            const info = findNode(working, z.id);
            const style = info && info.node.style && typeof info.node.style === 'object' ? info.node.style : {};
            applyOp(ops.updateBlockStyle(z.id, { ...style, layout: { ...(style.layout || {}), display: 'grid', columns: 2 } }), { label: t('chooser_grid') });
          }
        };
        const ask = (tab, sub) => { select(z.id); requestPanel(tab, sub); };
        return (
          <div
            key={`z-${z.id}`}
            className={`sbx-overlay__zone${roomy ? '' : ' is-compact'}`}
            data-testid="overlay-empty"
            data-zone-for={z.id}
            onContextMenu={(e) => {
              // The zone's buttons and chooser sit over the Container, so the frame never sees this right-click.
              const frame = frameRef.current;
              if (!frame || !onZoneMenu) return;
              e.preventDefault();
              const fr = frame.getBoundingClientRect();
              const s = fr.width / frame.clientWidth || 1;
              onZoneMenu(z.id, { x: (e.clientX - fr.left) / s, y: (e.clientY - fr.top) / s });
            }}
            style={{ left: z.visible.left, top: z.visible.top, width: z.visible.width, height: z.visible.height }}
          >
            {choosing ? (
              <div className="sbx-overlay__chooser" role="group" aria-label={t('chooser_title')} data-testid="layout-chooser">
                <button type="button" className="sbx-overlay__zoneclose" data-testid="chooser-close" aria-label={t('chooser_close')} title={t('chooser_close')} onClick={() => setLayoutChooser(null)}>×</button>
                <p className="sbx-overlay__zonetext">{t('chooser_title')}</p>
                <div className="sbx-overlay__choices">
                  <button type="button" className="sbx-overlay__choice" data-layout="flex" onClick={() => pick('flex')}>
                    <svg viewBox="0 0 48 48" width="48" height="48" aria-hidden="true"><rect x="4" y="4" width="18" height="40" /><rect x="24" y="4" width="20" height="19" /><rect x="24" y="25" width="20" height="19" /></svg>
                    <span>{t('chooser_flex')}</span>
                  </button>
                  <button type="button" className="sbx-overlay__choice" data-layout="grid" onClick={() => pick('grid')}>
                    <svg viewBox="0 0 48 48" width="48" height="48" aria-hidden="true"><rect className="is-dashed" x="4" y="4" width="18" height="18" /><rect className="is-dashed" x="24" y="4" width="20" height="18" /><rect className="is-dashed" x="4" y="24" width="18" height="20" /><rect className="is-dashed" x="24" y="24" width="20" height="20" /></svg>
                    <span>{t('chooser_grid')}</span>
                  </button>
                </div>
              </div>
            ) : (
              <div className="sbx-overlay__emptyhint">
                <span className="sbx-overlay__zonebtns">
                  <ZoneButton action="add" label={t('zone_add')} onClick={() => ask('blocks', 'elements')} />
                  {roomy && <ZoneButton action="templates" label={t('zone_templates')} onClick={() => { select(z.id); openLibraryModal(); }} />}
                  {roomy && <ZoneButton action="ai" label={t('zone_ai')} onClick={() => ask('blocks', 'ai')} />}
                </span>
                {roomy && <span className="sbx-overlay__zonetext">{t('zone_drag_here')}</span>}
              </div>
            )}
          </div>
        );
      })}
      {enabled && outlines && geo.outlines.map((o) => (
        <div
          key={`o-${o.id}`}
          className="sbx-overlay__outline"
          style={{ left: o.visible.left, top: o.visible.top, width: o.visible.width, height: o.visible.height }}
          aria-hidden="true"
        />
      ))}
      {enabled && labels && geo.labels.map((l) => (
        <span
          key={`l-${l.id}`}
          className="sbx-overlay__label"
          data-testid="overlay-container-label"
          style={{ left: l.visible.left + 4, top: l.visible.top + 4 }}
          aria-hidden="true"
        >
          {l.text}
        </span>
      ))}
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
