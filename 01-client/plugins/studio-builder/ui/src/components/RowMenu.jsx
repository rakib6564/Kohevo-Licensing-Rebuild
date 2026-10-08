// RowMenu — the "⋯" button of a Layers row and the menu it opens (WAI-ARIA menu button pattern):
// Enter / Space / ArrowDown opens, arrows move, Home / End jump, Escape closes and returns focus to the button,
// Tab and clicking elsewhere close it. The menu is positioned from the button with `position: fixed`, so the
// scrolling Layers panel and the phone sheet never clip it. It is portalled to <body>: a sheet is transformed while
// it slides, and a transformed ancestor would turn `fixed` into "relative to the sheet".

import { useEffect, useId, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { t } from '../core/messages.mjs';
import { nextEnabled } from '../core/rowMenu.mjs';

export function RowMenu({ label, items, onChoose, testId }) {
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(0);
  const [place, setPlace] = useState({ top: 0, left: 0, up: false });
  const buttonRef = useRef(null);
  const menuRef = useRef(null);
  const menuId = useId();

  const close = (refocus) => {
    setOpen(false);
    if (refocus && buttonRef.current) buttonRef.current.focus();
  };

  const openMenu = (focusLast = false) => {
    setActive(focusLast ? items.length - 1 : nextEnabled(items, -1, 1) ?? 0);
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
  }, [open, items.length]);

  useEffect(() => {
    if (!open) return undefined;
    const el = menuRef.current && menuRef.current.querySelector(`[data-index="${active}"]`);
    if (el) el.focus();
    return undefined;
  }, [open, active]);

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

  const onMenuKey = (e) => {
    e.stopPropagation(); // the row's own shortcuts (Delete, arrows) must not fire while a menu is open
    if (e.key === 'Escape') { e.preventDefault(); close(true); return; }
    if (e.key === 'Tab') { setOpen(false); return; }
    if (e.key === 'ArrowDown') { e.preventDefault(); setActive((i) => nextEnabled(items, i, 1) ?? i); return; }
    if (e.key === 'ArrowUp') { e.preventDefault(); setActive((i) => nextEnabled(items, i, -1) ?? i); return; }
    if (e.key === 'Home') { e.preventDefault(); setActive(nextEnabled(items, -1, 1) ?? 0); return; }
    if (e.key === 'End') { e.preventDefault(); setActive(nextEnabled(items, items.length, -1) ?? 0); return; }
  };

  const choose = (item) => {
    if (item.disabled) return;
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
        <ul
          ref={menuRef}
          id={menuId}
          role="menu"
          aria-label={t('row_menu', { label })}
          className="sbx-row-menu"
          style={{ top: place.top, left: place.left, maxHeight: 'calc(100vh - 16px)' }}
          onKeyDown={onMenuKey}
          onClick={(e) => e.stopPropagation()}
        >
          {items.map((item, i) => (
            <li key={item.key} role="none">
              <button
                type="button"
                role="menuitem"
                tabIndex={i === active ? 0 : -1}
                data-index={i}
                data-action={item.key}
                aria-disabled={item.disabled || undefined}
                title={item.disabled && item.reasonKey ? t(item.reasonKey) : undefined}
                className={`sbx-row-menu__item${item.danger ? ' is-danger' : ''}${item.disabled ? ' is-disabled' : ''}`}
                onClick={() => choose(item)}
                onFocus={() => setActive(i)}
              >
                {t(item.labelKey)}
              </button>
            </li>
          ))}
        </ul>,
        document.body,
      )}
    </>
  );
}
