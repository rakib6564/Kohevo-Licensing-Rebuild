// RowMenu — the "⋯" button of a Layers row and the menu it opens (WAI-ARIA menu button pattern):
// Enter / Space / ArrowDown opens, arrows move, Home / End jump, Escape closes and returns focus to the button,
// Tab and clicking elsewhere close it (the list itself is ui/MenuList). `items` may be a function: the entries are
// then worked out when the menu opens, not for every row on every render. The menu is positioned from the button
// with `position: fixed`, so the scrolling Layers panel and the phone sheet never clip it. It is portalled to <body>:
// a sheet is transformed while it slides, and a transformed ancestor would turn `fixed` into "relative to the sheet".

import { useEffect, useId, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { t } from '../core/messages.mjs';
import { MenuList } from './ui/index.js';

export function RowMenu({ label, items, onChoose, testId }) {
  const [open, setOpen] = useState(false);
  const [list, setList] = useState([]);
  const [start, setStart] = useState('first');
  const [place, setPlace] = useState({ top: 0, left: 0, up: false });
  const buttonRef = useRef(null);
  const menuRef = useRef(null);
  const menuId = useId();

  const close = (refocus) => {
    setOpen(false);
    if (refocus && buttonRef.current) buttonRef.current.focus();
  };

  const openMenu = (fromEnd = false) => {
    setList(typeof items === 'function' ? items() : items);
    setStart(fromEnd ? 'last' : 'first');
    setOpen(true);
  };

  // Place under the button, or above it when the bottom of the window is too close; keep inside the window sideways.
  useLayoutEffect(() => {
    if (!open || !buttonRef.current || !menuRef.current) return;
    const b = buttonRef.current.getBoundingClientRect();
    const m = menuRef.current.getBoundingClientRect();
    const up = b.bottom + m.height + 8 > window.innerHeight && b.top - m.height - 8 > 0;
    const left = Math.max(8, Math.min(b.right - m.width, window.innerWidth - m.width - 8));
    // Under the button, above it, or - when neither fits (a phone near the bottom) - pinned inside the window.
    const wanted = up ? b.top - m.height - 4 : b.bottom + 4;
    const top = Math.max(8, Math.min(wanted, window.innerHeight - m.height - 8));
    setPlace({ top, left, up });
  }, [open, list.length]);

  useEffect(() => {
    if (!open) return undefined;
    const away = (e) => {
      if (menuRef.current && menuRef.current.contains(e.target)) return;
      if (buttonRef.current && buttonRef.current.contains(e.target)) return;
      setOpen(false);
    };
    document.addEventListener('pointerdown', away, true);
    window.addEventListener('resize', () => setOpen(false), { once: true });
    return () => document.removeEventListener('pointerdown', away, true);
  }, [open]);

  const choose = (item) => {
    close(false);
    onChoose(item.key);
  };

  return (
    <>
      <button
        ref={buttonRef}
        type="button"
        className="sbx-tree__action sbx-tree__more"
        aria-haspopup="menu"
        aria-expanded={open}
        aria-controls={open ? menuId : undefined}
        aria-label={t('row_menu', { label })}
        title={t('row_menu', { label })}
        data-testid={testId}
        onClick={(e) => { e.stopPropagation(); if (open) close(false); else openMenu(); }}
        onKeyDown={(e) => {
          if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); e.stopPropagation(); openMenu(e.key === 'ArrowUp'); }
          else if (e.key === 'Enter' || e.key === ' ') e.stopPropagation(); // activate the button, not the row
        }}
      >
        ⋯
      </button>
      {open && createPortal(
        <MenuList
          ref={menuRef}
          id={menuId}
          label={t('row_menu', { label })}
          items={list}
          start={start}
          style={{ top: place.top, left: place.left, maxHeight: 'calc(100vh - 16px)' }}
          onChoose={choose}
          onClose={close}
        />,
        document.body,
      )}
    </>
  );
}
