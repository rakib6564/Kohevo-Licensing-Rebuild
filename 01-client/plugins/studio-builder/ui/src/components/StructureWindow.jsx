// StructureWindow — the page outline in a floating, draggable window over the editor (desktop).
//
// The same Outline the Layers tab shows, so every move and menu keeps one implementation. Position and size live
// in memory only (the builder never touches browser storage); Esc or the close button puts it away.

import { memo, useCallback, useEffect, useRef, useState } from 'react';
import { Outline } from './Outline.jsx';
import { IconX } from './Icons.jsx';
import { t } from '../core/messages.mjs';

const MARGIN = 8;

/** Keep the window's top-left corner where its title bar can still be grabbed. */
export function clampWindow(pos, size, view) {
  const maxX = Math.max(MARGIN, view.width - 80);
  const maxY = Math.max(MARGIN, view.height - 40);
  return {
    x: Math.min(Math.max(pos.x, MARGIN - size.width + 80), maxX),
    y: Math.min(Math.max(pos.y, MARGIN), maxY),
  };
}

export const StructureWindow = memo(function StructureWindow({ onClose }) {
  const ref = useRef(null);
  const drag = useRef(null);
  const [pos, setPos] = useState(() => ({ x: Math.max(MARGIN, (typeof window !== 'undefined' ? window.innerWidth : 1200) - 340), y: 72 }));

  const onDown = useCallback((e) => {
    if (e.button !== 0 || (e.target.closest && e.target.closest('button'))) return;
    e.preventDefault();
    drag.current = { dx: e.clientX - pos.x, dy: e.clientY - pos.y };
    e.currentTarget.setPointerCapture?.(e.pointerId);
  }, [pos]);

  const onMove = useCallback((e) => {
    if (!drag.current) return;
    const box = ref.current ? ref.current.getBoundingClientRect() : { width: 300 };
    setPos(clampWindow({ x: e.clientX - drag.current.dx, y: e.clientY - drag.current.dy }, { width: box.width }, { width: window.innerWidth, height: window.innerHeight }));
  }, []);

  const onUp = useCallback(() => { drag.current = null; }, []);

  useEffect(() => {
    const onKey = (e) => {
      if (e.key !== 'Escape' || !ref.current || !ref.current.contains(document.activeElement)) return;
      // Escape in a layer's rename box cancels the rename only; the window stays open.
      if (document.activeElement.classList && document.activeElement.classList.contains('sbx-tree__rename-input')) return;
      e.stopPropagation();
      onClose();
    };
    document.addEventListener('keydown', onKey, true);
    return () => document.removeEventListener('keydown', onKey, true);
  }, [onClose]);

  return (
    <section
      ref={ref}
      className="sbx-structure-window"
      role="dialog"
      aria-label={t('panel_structure')}
      data-testid="structure-window"
      style={{ left: pos.x, top: pos.y }}
    >
      <header
        className="sbx-structure-window__bar"
        data-testid="structure-window-bar"
        onPointerDown={onDown}
        onPointerMove={onMove}
        onPointerUp={onUp}
        onPointerCancel={onUp}
      >
        <strong>{t('panel_structure')}</strong>
        <button type="button" className="sbx-btn sbx-btn--icon" data-testid="structure-window-close" onClick={onClose} title={t('structure_close')} aria-label={t('structure_close')}>
          <IconX size={14} />
        </button>
      </header>
      <div className="sbx-structure-window__body">
        <Outline />
      </div>
    </section>
  );
});
