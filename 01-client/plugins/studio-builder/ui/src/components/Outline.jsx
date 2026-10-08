// Outline — the page structure as an accessible tree.
//
// Selection, reordering and moving across containers. Every move is checked
// against the same structural rules as the server (doc.mjs) before it is
// offered, and is then sent as a canonical move_section / move_block /
// insert_block operation. Drag & drop is never the only way: every move has
// a keyboard equivalent (Alt+↑/↓ move, Alt+→ into the container above,
// Alt+← out of the container, Delete removes).

import { memo, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useEditor, useEngineState, useSelection } from './EditorContext.jsx';
import { DRAG_TYPE_NEW } from './BlockPalette.jsx';
import { RowMenu } from './RowMenu.jsx';
import { PartialRows, useChromeBindings } from './PartialRows.jsx';
import { rowMenuItems } from '../core/rowMenu.mjs';
import { autoScrollDelta, dropPositionAt, isSelfOrDescendant } from '../core/outlineDrag.mjs';
import { t } from '../core/messages.mjs';
import {
  asList, blockDefinition, blockIndentTarget, blockMoveTarget, blockOutdentTarget,
  canInsertBlock, canInsertSection, canMoveBlock, nodeLabel,
} from '../core/doc.mjs';
import { isProvisionalId, updateBlockVisibility, updateSectionVisibility } from '../core/operations.mjs';
import { topLevelIds } from '../core/shellState.mjs';
import { effectivelyLocked, isLocked, lockIndex } from '../core/layerLock.mjs';

const DRAG_TYPE_NODE = 'application/x-kohevo-studio-node';

/** Flatten the tree into rows in visual order. */
export function outlineRows(doc, manifest) {
  const rows = [];
  asList(doc && doc.sections).forEach((section, sIndex, sections) => {
    const hasBlocks = asList(section.blocks).length > 0;
    rows.push({
      id: section.id,
      kind: 'section',
      level: 1,
      parentId: null,
      index: sIndex,
      setSize: sections.length,
      node: section,
      container: true,
      hasChildren: hasBlocks,
    });
    const addBlocks = (blocks, parentId, level) => {
      asList(blocks).forEach((block, index, list) => {
        const def = blockDefinition(manifest, block.type);
        const allowsKids = !!(def && def.allows_children);
        const hasKids = asList(block.children).length > 0;
        rows.push({
          id: block.id,
          kind: 'block',
          level,
          parentId,
          index,
          setSize: list.length,
          node: block,
          container: allowsKids,
          hasChildren: hasKids,
        });
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
  const {
    manifest, insertBlock, insertSection,
    duplicateNode, renameNode, setLocked, removeNode, moveBlockTo, moveSectionTo, applyOp, openSaveTemplate,
  } = useEditor();
  const { selection, selectedIds, select, pick } = useSelection();
  const working = useEngineState((s) => s.working);
  const chrome = useChromeBindings();
  const pageType = useEngineState((s) => (s.page ? s.page.page_type : 'page'));
  const allRows = useMemo(() => outlineRows(working, manifest), [working, manifest]);
  const locks = useMemo(() => lockIndex(working), [working]);
  const [collapsed, setCollapsed] = useState(() => new Set());
  const [editingId, setEditingId] = useState(null);
  const [editLabel, setEditLabel] = useState('');
  const [searchQuery, setSearchQuery] = useState('');
  const [focusId, setFocusId] = useState(null);
  const [dropHint, setDropHint] = useState(null);
  // Several rows selected = the shared selection holds more than one id (canvas and Layers stay in sync).
  const picked = useMemo(() => new Set(selectedIds), [selectedIds]);
  const dragRef = useRef(null);
  const listRef = useRef(null);

  const toggleCollapse = useCallback((id) => {
    setCollapsed((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }, []);

  const visibleRows = useMemo(() => {
    const hiddenAncestorIds = new Set();
    const result = [];
    const q = searchQuery.trim().toLowerCase();
    for (const row of allRows) {
      if (row.parentId && (hiddenAncestorIds.has(row.parentId) || (!q && collapsed.has(row.parentId)))) {
        hiddenAncestorIds.add(row.id);
        continue;
      }
      if (q) {
        const label = row.kind === 'section' ? (row.node.label || t('section')) : nodeLabel(row.node, manifest, 'block');
        if (!label.toLowerCase().includes(q)) {
          continue;
        }
      }
      result.push(row);
    }
    return result;
  }, [allRows, collapsed, searchQuery, manifest]);

  const activeId = visibleRows.some((r) => r.id === focusId)
    ? focusId
    : (selection && visibleRows.some((r) => r.id === selection) ? selection : (visibleRows[0] && visibleRows[0].id));

  const focusRow = useCallback((id) => {
    setFocusId(id);
    requestAnimationFrame(() => {
      const el = listRef.current && listRef.current.querySelector(`[data-row="${id}"]`);
      if (el) el.focus();
    });
  }, []);

  const toggleVisibility = useCallback((row) => {
    const isHidden = (row.node.visibility && Array.isArray(row.node.visibility.devices) && row.node.visibility.devices.length === 0);
    const newDevices = isHidden ? ['base', 'sm', 'md', 'lg'] : [];
    const newVis = { ...(row.node.visibility || {}), devices: newDevices };
    if (row.kind === 'section') {
      applyOp(updateSectionVisibility(row.id, newVis));
    } else {
      applyOp(updateBlockVisibility(row.id, newVis));
    }
  }, [applyOp]);

  const finishRename = useCallback((id) => {
    if (renameNode) renameNode(id, editLabel);
    setEditingId(null);
  }, [editLabel, renameNode]);

  const startRename = useCallback((row) => {
    if (effectivelyLocked(locks, row.id)) return; // a locked layer cannot be renamed
    setEditingId(row.id);
    setEditLabel(row.kind === 'section' ? (row.node.label || '') : ((row.node.metadata && row.node.metadata.label) || ''));
  }, [locks]);

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

  const runRowAction = useCallback((row, key) => {
    switch (key) {
      case 'rename': startRename(row); return;
      case 'duplicate': duplicateNode(row.id); break;
      case 'lock': setLocked(row.id, true); break;
      case 'unlock': setLocked(row.id, false); break;
      case 'hide': case 'show': toggleVisibility(row); break;
      case 'move_up': keyboardMove(row, 'ArrowUp'); break;
      case 'move_down': keyboardMove(row, 'ArrowDown'); break;
      case 'save_library': select(row.id); if (openSaveTemplate) openSaveTemplate(); return;
      case 'delete': removeNode(row.id); return;
      default: break;
    }
    focusRow(row.id);
  }, [startRename, duplicateNode, setLocked, toggleVisibility, keyboardMove, select, openSaveTemplate, removeNode, focusRow]);

  const onKeyDown = (e, row, i) => {
    if (e.altKey && ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(e.key)) {
      e.preventDefault();
      keyboardMove(row, e.key);
      focusRow(row.id);
      return;
    }
    switch (e.key) {
      case 'ArrowDown': e.preventDefault(); if (visibleRows[i + 1]) focusRow(visibleRows[i + 1].id); break;
      case 'ArrowUp': e.preventDefault(); if (visibleRows[i - 1]) focusRow(visibleRows[i - 1].id); break;
      case 'Home': e.preventDefault(); if (visibleRows[0]) focusRow(visibleRows[0].id); break;
      case 'End': e.preventDefault(); if (visibleRows.length) focusRow(visibleRows[visibleRows.length - 1].id); break;
      case 'Enter': case ' ': e.preventDefault(); select(row.id); break;
      case 'Delete': case 'Backspace': e.preventDefault(); removeNode(row.id); break;
      case 'F2': e.preventDefault(); startRename(row); break;
      case 'ContextMenu': e.preventDefault(); { const btn = e.currentTarget.querySelector('.sbx-tree__more'); if (btn) btn.click(); } break;
      case 'F10': if (e.shiftKey) { e.preventDefault(); const btn = e.currentTarget.querySelector('.sbx-tree__more'); if (btn) btn.click(); } break;
      default: break;
    }
  };

  // ── Drag & drop ─────────────────────────────────────────────────────────
  const positionFor = (e, row, dragged) => {
    const rect = e.currentTarget.getBoundingClientRect();
    return dropPositionAt((e.clientY - rect.top) / Math.max(1, rect.height), row, dragged);
  };

  const isValidDrop = (dragged, dest) => {
    if (!dest) return false;
    if (dest.kind === 'section') return dragged.kind === 'section';
    if (dragged.type) return canInsertBlock(working, manifest, dest.parentId, dragged.type);
    return canMoveBlock(working, manifest, dragged.id, dest.parentId);
  };

  // ── Handle drag (pointer events: mouse, pen and touch) ────────────────────
  // HTML5 drag & drop does not exist on touch screens, so each row has a handle that drives the same
  // drop rules with pointer events. Arrow keys on the handle, Alt+arrows on the row and the row menu are the
  // keyboard routes; none of them depends on dragging.
  const [dragId, setDragId] = useState(null);
  const sessionRef = useRef(null);
  const latest = useRef({});
  latest.current = { visibleRows, working, manifest, isValidDrop: null };

  const scrollParent = () => {
    let el = listRef.current;
    while (el && el !== document.body) {
      const oy = window.getComputedStyle(el).overflowY;
      if ((oy === 'auto' || oy === 'scroll') && el.scrollHeight > el.clientHeight) return el;
      el = el.parentElement;
    }
    return null;
  };

  const updatePointerTarget = (session) => {
    const { visibleRows: rows } = latest.current;
    const hit = document.elementFromPoint(session.x, session.y);
    const li = hit && hit.closest ? hit.closest('[data-row]') : null;
    const target = li ? rows.find((r) => r.id === li.getAttribute('data-row')) : null;
    let hint = null;
    if (target && !isSelfOrDescendant(rows, session.dragged.id, target.id)) {
      const rect = li.getBoundingClientRect();
      const position = dropPositionAt((session.y - rect.top) / Math.max(1, rect.height), target, session.dragged);
      const dest = dropDestination(latest.current.working, rows, session.dragged, target, position);
      if (isValidDrop(session.dragged, dest)) hint = { id: target.id, position };
    }
    session.hint = hint;
    setDropHint((h) => (h && hint && h.id === hint.id && h.position === hint.position ? h : hint));
  };

  const endPointerDrag = (commit) => {
    const session = sessionRef.current;
    if (!session) return;
    sessionRef.current = null;
    cancelAnimationFrame(session.raf);
    setDragId(null);
    setDropHint(null);
    try { session.handle.releasePointerCapture(session.pointerId); } catch { /* already released */ }
    if (!commit || !session.hint) return;
    const { visibleRows: rows } = latest.current;
    const target = rows.find((r) => r.id === session.hint.id);
    if (!target) return;
    const dest = dropDestination(latest.current.working, rows, session.dragged, target, session.hint.position);
    if (!isValidDrop(session.dragged, dest)) return;
    if (dest.kind === 'section') moveSectionTo(session.dragged.id, dest.toIndex);
    else moveBlockTo(session.dragged.id, { parentId: dest.parentId, index: dest.index });
  };

  const startPointerDrag = (e, row) => {
    if (e.pointerType === 'mouse' && e.button !== 0) return;
    if (effectivelyLocked(locks, row.id) || sessionRef.current) return;
    e.preventDefault();
    e.stopPropagation();
    const session = { dragged: { kind: row.kind, id: row.id, type: null }, handle: e.currentTarget, pointerId: e.pointerId, x: e.clientX, y: e.clientY, hint: null, raf: 0 };
    sessionRef.current = session;
    try { e.currentTarget.setPointerCapture(e.pointerId); } catch { /* synthetic pointer */ }
    setDragId(row.id);
    const container = scrollParent();
    const tick = () => {
      if (sessionRef.current !== session) return;
      if (container) {
        const box = container.getBoundingClientRect();
        const dy = autoScrollDelta(session.y, box.top, box.bottom);
        if (dy) { container.scrollTop += dy; updatePointerTarget(session); }
      }
      session.raf = requestAnimationFrame(tick);
    };
    session.raf = requestAnimationFrame(tick);
  };

  const movePointerDrag = (e) => {
    const session = sessionRef.current;
    if (!session || e.pointerId !== session.pointerId) return;
    session.x = e.clientX;
    session.y = e.clientY;
    updatePointerTarget(session);
  };

  useEffect(() => {
    if (dragId === null) return undefined;
    const onKey = (e) => { if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); endPointerDrag(false); } };
    window.addEventListener('keydown', onKey, true);
    return () => window.removeEventListener('keydown', onKey, true);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [dragId]);
  useEffect(() => () => { if (sessionRef.current) { cancelAnimationFrame(sessionRef.current.raf); sessionRef.current = null; } }, []);

  const onDragOver = (e, row) => {
    const dragged = dragRef.current || (e.dataTransfer.types.includes(DRAG_TYPE_NEW) ? { kind: 'block', type: null } : null);
    if (!dragged) return;
    const position = positionFor(e, row, dragged);
    const dest = dropDestination(working, visibleRows, dragged, row, position);
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
    const dest = dropDestination(working, visibleRows, dragged, row, positionFor(e, row, dragged));
    if (!isValidDrop(dragged, dest)) return;
    if (dest.kind === 'section') moveSectionTo(dragged.id, dest.toIndex);
    else if (dragged.type) insertBlock(dragged.type, { parentId: dest.parentId, index: dest.index });
    else moveBlockTo(dragged.id, { parentId: dest.parentId, index: dest.index });
  };

  if (!allRows.length) {
    return (
      <div className="sbx-outline sbx-outline--empty">
        <p className="sbx-muted">{t('empty_page')}</p>
        <button type="button" className="sbx-btn sbx-btn--primary" onClick={() => insertSection(0)} disabled={!canInsertSection(working, manifest)}>{t('add_section')}</button>
      </div>
    );
  }

  const allCollapsed = collapsed.size > 0;
  const bulkIds = picked.size > 1 ? topLevelIds(allRows, picked) : [];

  const bulkLock = (locked) => {
    bulkIds.forEach((id) => { if (isLocked(allRows.find((r) => r.id === id).node) !== locked) setLocked(id, locked); });
  };
  const bulkDuplicate = () => {
    bulkIds.forEach((id) => duplicateNode(id));
    select(selection, { announceIt: false });
  };
  const bulkRemove = () => {
    if (!window.confirm(t('bulk_remove_confirm', { count: bulkIds.length }))) return;
    // Removed nodes leave the shared selection on their own (the shell prunes it).
    bulkIds.forEach((id) => removeNode(id, { confirmed: true }));
  };

  return (
    <div className="sbx-outline">
      <div className="sbx-tree__nav-header">
        <input
          type="search"
          className="sbx-tree__search"
          placeholder={t('search_blocks')}
          value={searchQuery}
          onChange={(e) => setSearchQuery(e.target.value)}
          aria-label={t('search_blocks')}
        />
        <button
          type="button"
          className="sbx-tree__collapse-btn"
          title={allCollapsed ? t('expand_all') : t('collapse_all')}
          aria-label={allCollapsed ? t('expand_all') : t('collapse_all')}
          onClick={() => {
            if (allCollapsed) {
              setCollapsed(new Set());
            } else {
              setCollapsed(new Set(allRows.filter((r) => r.hasChildren).map((r) => r.id)));
            }
          }}
        >
          {allCollapsed ? '⊞' : '⊟'}
        </button>
      </div>

      {bulkIds.length > 1 && (
        <div className="sbx-tree__bulk" role="toolbar" aria-label={t('bulk_actions')} data-testid="outline-bulk">
          <span className="sbx-tree__bulk-count">{t('bulk_selected', { count: bulkIds.length })}</span>
          <button type="button" className="sbx-btn sbx-btn--seg" onClick={() => bulkLock(true)}>{t('lock_layer')}</button>
          <button type="button" className="sbx-btn sbx-btn--seg" onClick={() => bulkLock(false)}>{t('unlock_layer')}</button>
          <button type="button" className="sbx-btn sbx-btn--seg" onClick={bulkDuplicate}>{t('duplicate')}</button>
          <button type="button" className="sbx-btn sbx-btn--seg sbx-btn--danger" onClick={bulkRemove}>{t('remove_item')}</button>
          <button type="button" className="sbx-btn sbx-btn--seg" onClick={() => select(selection, { announceIt: false })}>{t('clear_selection')}</button>
        </div>
      )}

      <PartialRows region="header" chrome={chrome} />
      <ul className={`sbx-tree${dragId !== null ? ' is-sorting' : ''}`} role="tree" aria-label={t('outline_label')} ref={listRef}>
        {visibleRows.map((row, i) => {
          const label = row.kind === 'section' ? (row.node.label || t('section')) : nodeLabel(row.node, manifest, 'block');
          const hint = dropHint && dropHint.id === row.id ? ` is-drop-${dropHint.position}` : '';
          const isRowCollapsed = collapsed.has(row.id);
          const isHidden = row.node.visibility && Array.isArray(row.node.visibility.devices) && row.node.visibility.devices.length === 0;
          const ownLock = isLocked(row.node);
          const lockedByAncestor = !ownLock && effectivelyLocked(locks, row.id);

          return (
            <li
              key={row.id}
              data-row={row.id}
              role="treeitem"
              aria-label={row.kind === 'section' ? `${label}, ${t('section')}` : label}
              aria-level={row.level}
              aria-setsize={row.setSize}
              aria-posinset={row.index + 1}
              aria-selected={selection === row.id || picked.has(row.id)}
              aria-expanded={row.container ? !isRowCollapsed : undefined}
              aria-keyshortcuts="Alt+ArrowUp Alt+ArrowDown Alt+ArrowLeft Alt+ArrowRight Delete F2 Shift+F10"
              tabIndex={row.id === activeId ? 0 : -1}
              className={`sbx-tree__row sbx-tree__row--${row.kind}${selection === row.id || picked.has(row.id) ? ' is-selected' : ''}${picked.size > 1 && picked.has(row.id) ? ' is-picked' : ''}${isProvisionalId(row.id) ? ' is-pending' : ''}${isHidden ? ' is-hidden' : ''}${dragId === row.id ? ' is-dragging' : ''}${ownLock ? ' is-locked' : ''}${lockedByAncestor ? ' is-locked-inherited' : ''}${hint}`}
              style={{ paddingLeft: `${(row.level - 1) * 14 + 6}px` }}
              draggable={dragId === null}
              onDragStart={(e) => {
                dragRef.current = { kind: row.kind, id: row.id, type: null };
                e.dataTransfer.setData(DRAG_TYPE_NODE, row.id);
                e.dataTransfer.effectAllowed = 'move';
              }}
              onDragEnd={() => { dragRef.current = null; setDropHint(null); }}
              onDragOver={(e) => onDragOver(e, row)}
              onDragLeave={() => setDropHint((h) => (h && h.id === row.id ? null : h))}
              onDrop={(e) => onDrop(e, row)}
              onClick={(e) => {
                setFocusId(row.id);
                const toggle = e.metaKey || e.ctrlKey;
                if (e.shiftKey || toggle) e.preventDefault();
                pick(row.id, { shift: e.shiftKey, toggle }, visibleRows);
              }}
              onFocus={() => setFocusId(row.id)}
              onKeyDown={(e) => onKeyDown(e, row, i)}
            >
              {row.hasChildren ? (
                <button
                  type="button"
                  className="sbx-tree__caret"
                  aria-label={isRowCollapsed ? t('expand') : t('collapse')}
                  onClick={(e) => {
                    e.stopPropagation();
                    toggleCollapse(row.id);
                  }}
                >
                  {isRowCollapsed ? '▸' : '▾'}
                </button>
              ) : (
                <span className="sbx-tree__caret-spacer" aria-hidden="true" />
              )}

              <span className="sbx-tree__kind" aria-hidden="true">
                {row.kind === 'section' ? '▦' : row.container ? '◫' : '▪'}
              </span>

              {editingId === row.id ? (
                <input
                  type="text"
                  className="sbx-tree__rename-input"
                  autoFocus
                  value={editLabel}
                  onChange={(e) => setEditLabel(e.target.value)}
                  onBlur={() => finishRename(row.id)}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter') finishRename(row.id);
                    if (e.key === 'Escape') setEditingId(null);
                  }}
                  onClick={(e) => e.stopPropagation()}
                />
              ) : (
                <span
                  className="sbx-tree__label"
                  onDoubleClick={(e) => {
                    e.stopPropagation();
                    startRename(row);
                  }}
                >
                  {ownLock && <span className="sbx-tree__lock-badge" aria-hidden="true">🔒</span>}
                  {label}
                </span>
              )}

              <div className="sbx-tree__actions">
                {isHidden && <span className="sbx-tree__badge" title={t('show')} aria-hidden="true">⊘</span>}
                <button
                  type="button"
                  className="sbx-tree__handle"
                  aria-label={t('row_drag_handle', { label })}
                  title={t('row_drag_hint')}
                  disabled={ownLock || lockedByAncestor}
                  data-testid={`row-handle-${row.id}`}
                  onPointerDown={(e) => startPointerDrag(e, row)}
                  onPointerMove={movePointerDrag}
                  onPointerUp={() => endPointerDrag(true)}
                  onPointerCancel={() => endPointerDrag(false)}
                  onClick={(e) => e.stopPropagation()}
                  onKeyDown={(e) => {
                    if (e.key === 'ArrowUp' || e.key === 'ArrowDown') { e.preventDefault(); e.stopPropagation(); keyboardMove(row, e.key); } else if (e.key === 'Enter' || e.key === ' ') e.stopPropagation();
                  }}
                >
                  ⠿
                </button>
                <RowMenu
                  label={label}
                  testId={`row-menu-${row.id}`}
                  items={rowMenuItems({ row, doc: working, manifest, locks, pageType, canSaveToLibrary: !!(manifest.permissions && manifest.permissions.admin) })}
                  onChoose={(key) => runRowAction(row, key)}
                />
              </div>
            </li>
          );
        })}
      </ul>
      <PartialRows region="footer" chrome={chrome} />
      <button type="button" className="sbx-btn sbx-btn--block" onClick={() => insertSection()} disabled={!canInsertSection(working, manifest)}>+ {t('add_section')}</button>
    </div>
  );
});
