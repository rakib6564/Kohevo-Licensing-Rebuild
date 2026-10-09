// Shared pieces of the Add panel: the block card (with a visible reason when it cannot be inserted
// right now), the category chip rail, and the hook that reads what insertion rules need.

import { useEffect } from 'react';
import { useEditor, useEngineState, useSelection } from './EditorContext.jsx';
import { renderBlockIcon, DRAG_TYPE_NEW } from './blockIcons.jsx';
import { t } from '../core/messages.mjs';
import { Tile } from './ui/index.js';
import { blockInsertState, presetInsertState, reasonKey } from '../core/addPanel.mjs';

/** The working document, the manifest and the primary selection: what insertion rules depend on. */
export function useInsertContext() {
  const { manifest } = useEditor();
  const { selection } = useSelection();
  const doc = useEngineState((s) => s.working);
  return { doc, manifest, selection };
}

export const useBlockInsertState = (type) => {
  const { doc, manifest, selection } = useInsertContext();
  return doc ? blockInsertState(doc, manifest, selection, type) : { ok: true };
};

export const usePresetInsertState = () => {
  const { doc, manifest } = useInsertContext();
  return doc ? presetInsertState(doc, manifest) : { ok: true };
};

/**
 * Run `callback` once the element is really on screen (not display:none, not in a closed sheet or a
 * hidden tab). The palette is always mounted, so "mounted" is not the same as "needed".
 */
export function useWhenVisible(ref, callback) {
  useEffect(() => {
    const el = ref.current;
    if (!el || typeof IntersectionObserver === 'undefined') { callback(); return undefined; }
    const io = new IntersectionObserver((entries) => {
      if (entries.some((e) => e.isIntersecting)) { callback(); io.disconnect(); }
    });
    io.observe(el);
    return () => io.disconnect();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ref]);
}

export const blockCategoryLabel = (slug) => t(`block_cat_${slug}`);

/** A block tile: click or drag to insert, or disabled with the reason shown (not hidden in a tooltip). */
export function BlockCard({ def, onInsert }) {
  const { insertBlockWithProps } = useEditor();
  const state = useBlockInsertState(def.type);
  const disabled = !state.ok;
  const title = def.title || def.label;
  return (
    <Tile
      draggable={!disabled && !def.variantKey}
      disabled={disabled}
      reason={disabled ? t(reasonKey(state.reason)) : null}
      data-block-type={def.type}
      data-variant={def.variantKey || undefined}
      onDragStart={disabled || def.variantKey ? undefined : (e) => { e.dataTransfer.setData(DRAG_TYPE_NEW, def.type); e.dataTransfer.effectAllowed = 'copy'; }}
      onClick={disabled ? undefined : () => (def.variantKey ? insertBlockWithProps(def.type, def.variantProps) : onInsert(def.type))}
      aria-label={`${t('insert')} ${title}`}
      title={def.description || undefined}
      icon={renderBlockIcon(def.type, def.icon, def.label)}
      label={title}
      srText={def.description || t('pal_block_component_fallback')}
    />
  );
}

/** A horizontally scrollable category rail: "All" plus one chip per category, with counts. */
export function CategoryRail({ label, groups, value, onChange, labelOf }) {
  const total = groups.reduce((n, g) => n + g.items.length, 0);
  const chip = (key, text, count) => (
    <button key={key} type="button" className={`sbx-chip${value === key ? ' is-active' : ''}`} aria-pressed={value === key} data-chip={key} onClick={() => onChange(key)}>
      {text} <span className="sbx-chip__count" aria-hidden="true">{count}</span>
    </button>
  );
  return (
    <div className="sbx-chips" role="group" aria-label={label}>
      {chip('all', t('pal_all'), total)}
      {groups.map((g) => chip(g.category, labelOf(g.category), g.items.length))}
    </div>
  );
}
