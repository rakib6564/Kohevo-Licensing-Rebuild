// CanvasContextMenu — the right-click menu of a canvas node, drawn in the builder (never inside the script-less frame).
// It opens at a point in window coordinates (the caller has already mapped the click through the canvas zoom and the
// frame's offset), is kept inside the window, and closes on Escape, Tab, a click elsewhere, a resize, or when the
// window loses focus (a click inside the canvas frame blurs the builder window).

import { useEffect, useId, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { t } from '../core/messages.mjs';
import { MenuList } from './ui/index.js';

export function CanvasContextMenu({ label, items, x, y, onChoose, onClose }) {
  const menuRef = useRef(null);
  const menuId = useId();
  const [place, setPlace] = useState({ top: y, left: x });

  useLayoutEffect(() => {
    const el = menuRef.current;
    if (!el) return;
    const m = el.getBoundingClientRect();
    const left = Math.max(8, Math.min(x, window.innerWidth - m.width - 8));
    const top = Math.max(8, Math.min(y, window.innerHeight - m.height - 8));
    setPlace({ top, left });
  }, [x, y, items.length]);

  useEffect(() => {
    const away = (e) => { if (!menuRef.current || !menuRef.current.contains(e.target)) onClose(false); };
    const leave = () => onClose(false);
    document.addEventListener('pointerdown', away, true);
    window.addEventListener('blur', leave);
    window.addEventListener('resize', leave);
    return () => {
      document.removeEventListener('pointerdown', away, true);
      window.removeEventListener('blur', leave);
      window.removeEventListener('resize', leave);
    };
  }, [onClose]);

  return createPortal(
    <MenuList
      ref={menuRef}
      id={menuId}
      testId="canvas-context-menu"
      label={t('row_menu', { label })}
      items={items}
      style={{ top: place.top, left: place.left, maxHeight: 'calc(100vh - 16px)' }}
      onChoose={(item) => onChoose(item.key)}
      onClose={onClose}
    />,
    document.body,
  );
}
