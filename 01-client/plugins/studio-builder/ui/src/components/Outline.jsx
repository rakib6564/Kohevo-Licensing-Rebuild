// Outline — the page structure as an accessible tree.
//
// Selection, reordering and moving across containers. Every move is checked
// against the same structural rules as the server (doc.mjs) before it is
// offered, and is then sent as a canonical move_section / move_block /
// insert_block operation. Drag & drop is never the only way: every move has
// a keyboard equivalent (Alt+↑/↓ move, Alt+→ into the container above,
// Alt+← out of the container, Delete removes).

import { memo, useCallback, useMemo, useRef, useState } from 'react';
import { useEditor, useEngineState } from './EditorContext.jsx';
import { DRAG_TYPE_NEW } from './BlockPalette.jsx';
import { t } from '../core/messages.mjs';
import {
  asList, blockDefinition, blockIndentTarget, blockMoveTarget, blockOutdentTarget,
  canInsertBlock, canInsertSection, canMoveBlock, nodeLabel,
} from '../core/doc.mjs';
import { isProvisionalId } from '../core/operations.mjs';

const DRAG_TYPE_NODE = 'application/x-kohevo-studio-node';

/** Flatten the tree into rows in visual order. */
export function outlineRows(doc, manifest) {
  const rows = [];
  asList(doc && doc.sections).forEach((section, sIndex, sections) => {
    rows.push({ id: section.id, kind: 'section', level: 1, parentId: null, index: sIndex, setSize: sections.length, node: section, container: true });
    const addBlocks = (blocks, parentId, level) => {
      asList(blocks).forEach((block, index, list) => {
        const def = blockDefinition(manifest, block.type);
        rows.push({ id: block.id, kind: 'block', level, parentId, index, setSize: list.length, node: block, container: !!(def && def.allows_children) });
        addBlocks(block.children, block.id, level + 1);
      });
    };
    addBlocks(section.blocks, section.id, 2);
  });
  return rows;
}

/** Resolve a drop to a canonical destination (move semantics: index after extraction). */
export function dropDestination(doc, rows, dragged, row, position) {
  if (dragged.kind === 'section') {
    if (row.kind !== 'section' || position === 'inside') return null;
    const from = rows.find((r) => r.id === dragged.id);
    if (!from) return null;
    let to = row.index + (position === 'after' ? 1 : 0);
    if (from.index < to) to -= 1;
    return { kind: 'section', toIndex: to };
  }
  // Block (existing or new) — onto a section row means "into that section, at the end".
  if (row.kind === 'section') return { kind: 'block', parentId: row.id, index: asList(row.node.blocks).length };
  if (position === 'inside') return { kind: 'block', parentId: row.id, index: asList(row.node.children).length };
  let index = row.index + (position === 'after' ? 1 : 0);
  if (dragged.id) {
    const from = rows.find((r) => r.id === dragged.id);
    if (from && from.parentId === row.parentId && from.index < index) index -= 1;
  }
  return { kind: 'block', parentId: row.parentId, index };
}

export const Outline = memo(function Outline() {
  const { manifest, selection, select, insertBlock, insertSection, removeNode, moveBlockTo, moveSectionTo } = useEditor();
  const working = useEngineState((s) => s.working);
  const rows = useMemo(() => outlineRows(working, manifest), [working, manifest]);
  const [focusId, setFocusId] = useState(null);
  const [dropHint, setDropHint] = useState(null);
  const dragRef = useRef(null);
  const listRef = useRef(null);

  const activeId = rows.some((r) => r.id === focusId) ? focusId : (selection && rows.some((r) => r.id === selection) ? selection : rows[0] && rows[0].id);

  const focusRow = useCallback((id) => {
    setFocusId(id);
    requestAnimationFrame(() => {
      const el = listRef.current && listRef.current.querySelector(`[data-row="${id}"]`);
      if (el) el.focus();
    });
  }, []);

  const keyboardMove = useCallback((row, key) => {
    if (row.kind === 'section') {
      const to = row.index + (key === 'ArrowUp' ? -1 : 1);
      if (to >= 0 && to < row.setSize) moveSectionTo(row.id, to);
      return;
    }
    let target = null;
    if (key === 'ArrowUp' || key === 'ArrowDown') target = blockMoveTarget(working, manifest, row.id, key === 'ArrowUp' ? 'up' : 'down');
    if (key === 'ArrowRight') target = blockIndentTarget(working, manifest, row.id);
    if (key === 'ArrowLeft') target = blockOutdentTarget(working, manifest, row.id);
    if (target && canMoveBlock(working, manifest, row.id, target.parentId)) moveBlockTo(row.id, target);
  }, [working, manifest, moveBlockTo, moveSectionTo]);

  const onKeyDown = (e, row, i) => {
    if (e.altKey && ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(e.key)) {
      e.preventDefault();
      keyboardMove(row, e.key);
      focusRow(row.id);
      return;
    }
    switch (e.key) {
      case 'ArrowDown': e.preventDefault(); if (rows[i + 1]) focusRow(rows[i + 1].id); break;
      case 'ArrowUp': e.preventDefault(); if (rows[i - 1]) focusRow(rows[i - 1].id); break;
      case 'Home': e.preventDefault(); if (rows[0]) focusRow(rows[0].id); break;
      case 'End': e.preventDefault(); if (rows.length) focusRow(rows[rows.length - 1].id); break;
      case 'Enter': case ' ': e.preventDefault(); select(row.id); break;
      case 'Delete': case 'Backspace': e.preventDefault(); removeNode(row.id); break;
      default: break;
    }
  };

  // ── Drag & drop ─────────────────────────────────────────────────────────
  const positionFor = (e, row, dragged) => {
    const rect = e.currentTarget.getBoundingClientRect();
    const y = (e.clientY - rect.top) / Math.max(1, rect.height);
    if (dragged.kind === 'section' || row.kind === 'section') return row.kind === 'section' && dragged.kind !== 'section' ? 'inside' : (y < 0.5 ? 'before' : 'after');
    if (row.container && y > 0.3 && y < 0.7) return 'inside';
    return y < 0.5 ? 'before' : 'after';
  };

  const isValidDrop = (dragged, dest) => {
    if (!dest) return false;
    if (dest.kind === 'section') return dragged.kind === 'section';
    if (dragged.type) return canInsertBlock(working, manifest, dest.parentId, dragged.type);
    return canMoveBlock(working, manifest, dragged.id, dest.parentId);
  };

  const onDragOver = (e, row) => {
    const dragged = dragRef.current || (e.dataTransfer.types.includes(DRAG_TYPE_NEW) ? { kind: 'block', type: null } : null);
    if (!dragged) return;
    const position = positionFor(e, row, dragged);
    const dest = dropDestination(working, rows, dragged, row, position);
    const valid = dragged.type === null ? !!dest && dest.kind === 'block' : isValidDrop(dragged, dest);
    if (!valid) { setDropHint(null); return; }
    e.preventDefault();
    e.dataTransfer.dropEffect = dragged.id ? 'move' : 'copy';
    if (!dropHint || dropHint.id !== row.id || dropHint.position !== position) setDropHint({ id: row.id, position });
  };

  const onDrop = (e, row) => {
    e.preventDefault();
    const newType = e.dataTransfer.getData(DRAG_TYPE_NEW);
    const dragged = dragRef.current || (newType ? { kind: 'block', type: newType } : null);
    setDropHint(null);
    dragRef.current = null;
    if (!dragged) return;
    const dest = dropDestination(working, rows, dragged, row, positionFor(e, row, dragged));
    if (!isValidDrop(dragged, dest)) return;
    if (dest.kind === 'section') moveSectionTo(dragged.id, dest.toIndex);
    else if (dragged.type) insertBlock(dragged.type, { parentId: dest.parentId, index: dest.index });
    else moveBlockTo(dragged.id, { parentId: dest.parentId, index: dest.index });
  };

  if (!rows.length) {
    return (
      <div className="sbx-outline sbx-outline--empty">
        <p className="sbx-muted">{t('empty_page')}</p>
        <button type="button" className="sbx-btn sbx-btn--primary" onClick={() => insertSection(0)} disabled={!canInsertSection(working, manifest)}>{t('add_section')}</button>
      </div>
    );
  }

  return (
    <div className="sbx-outline">
      <ul className="sbx-tree" role="tree" aria-label={t('outline_label')} ref={listRef}>
        {rows.map((row, i) => {
          const label = row.kind === 'section' ? (row.node.label || t('section')) : nodeLabel(row.node, manifest, 'block');
          const hint = dropHint && dropHint.id === row.id ? ` is-drop-${dropHint.position}` : '';
          return (
            <li
              key={row.id}
              data-row={row.id}
              role="treeitem"
              aria-label={row.kind === 'section' ? `${label}, ${t('section')}` : label}
              aria-level={row.level}
              aria-setsize={row.setSize}
              aria-posinset={row.index + 1}
              aria-selected={selection === row.id}
              aria-expanded={row.container ? true : undefined}
              aria-keyshortcuts="Alt+ArrowUp Alt+ArrowDown Alt+ArrowLeft Alt+ArrowRight Delete"
              tabIndex={row.id === activeId ? 0 : -1}
              className={`sbx-tree__row sbx-tree__row--${row.kind}${selection === row.id ? ' is-selected' : ''}${isProvisionalId(row.id) ? ' is-pending' : ''}${hint}`}
              style={{ paddingLeft: `${(row.level - 1) * 14 + 8}px` }}
              draggable
              onDragStart={(e) => {
                dragRef.current = { kind: row.kind, id: row.id, type: null };
                e.dataTransfer.setData(DRAG_TYPE_NODE, row.id);
                e.dataTransfer.effectAllowed = 'move';
              }}
              onDragEnd={() => { dragRef.current = null; setDropHint(null); }}
              onDragOver={(e) => onDragOver(e, row)}
              onDragLeave={() => setDropHint((h) => (h && h.id === row.id ? null : h))}
              onDrop={(e) => onDrop(e, row)}
              onClick={() => { setFocusId(row.id); select(row.id); }}
              onFocus={() => setFocusId(row.id)}
              onKeyDown={(e) => onKeyDown(e, row, i)}
            >
              <span className="sbx-tree__kind" aria-hidden="true">{row.kind === 'section' ? '▦' : row.container ? '▣' : '▪'}</span>
              <span className="sbx-tree__label">{label}</span>
            </li>
          );
        })}
      </ul>
      <button type="button" className="sbx-btn sbx-btn--block" onClick={() => insertSection()} disabled={!canInsertSection(working, manifest)}>+ {t('add_section')}</button>
    </div>
  );
});
