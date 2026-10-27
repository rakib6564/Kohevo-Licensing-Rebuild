// Kohevo Studio builder — the editor's keyboard shortcuts, as one decision.
//
// The builder window and the canvas frame both ask here, so a key does the same thing wherever the focus is
// (a key pressed inside the canvas iframe never reaches the builder's own window).

/** True when the key goes into a text field or text being edited (only Save is handled there). */
export function isTyping(target) {
  return !!(target && (target.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName || '')));
}

/**
 * The shortcut a keydown means, or null.
 * @param {{key: string, metaKey?: boolean, ctrlKey?: boolean, shiftKey?: boolean, altKey?: boolean, target?: object}} e
 * @param {{hasSelection?: boolean}} [state]
 * @returns {'save'|'structure'|'duplicate'|'undo'|'redo'|'deselect'|null}
 */
export function shortcutFor(e, { hasSelection = false } = {}) {
  if (!e || typeof e.key !== 'string') return null;
  const mod = !!(e.metaKey || e.ctrlKey);
  const key = e.key.toLowerCase();
  if (mod && key === 's') return 'save';
  if (isTyping(e.target)) return null;
  if (mod && key === 'i') return 'structure';
  if (mod && key === 'd' && hasSelection) return 'duplicate';
  if (mod && key === 'z' && !e.shiftKey) return 'undo';
  if (mod && ((key === 'z' && e.shiftKey) || key === 'y')) return 'redo';
  if (e.key === 'Escape' && hasSelection) return 'deselect';
  return null;
}
