// MenuList — the list of a WAI-ARIA menu: role="menu" with role="menuitem" buttons.
// Arrow keys move over the enabled entries (wrapping), Home / End jump, Escape asks to close and return focus, Tab
// asks to close. Placement, outside-click handling and what opens it belong to the caller (the Layers row menu and
// the canvas right-click menu share this list).

import { forwardRef, useCallback, useEffect, useRef, useState } from 'react';
import { t } from '../../core/messages.mjs';
import { nextEnabled } from '../../core/rowMenu.mjs';

export const MenuList = forwardRef(function MenuList({ items, label, id, style, onChoose, onClose, start = 'first', testId }, ref) {
  const [active, setActive] = useState(() => (start === 'last' ? items.length - 1 : nextEnabled(items, -1, 1) ?? 0));
  const own = useRef(null);
  const setRefs = useCallback((el) => {
    own.current = el;
    if (typeof ref === 'function') ref(el);
    else if (ref) ref.current = el;
  }, [ref]);

  useEffect(() => {
    const el = own.current && own.current.querySelector(`[data-index="${active}"]`);
    if (el) el.focus();
  }, [active]);

  const onKeyDown = (e) => {
    e.stopPropagation(); // the row's or the canvas's own shortcuts (Delete, arrows) must not fire while a menu is open
    if (e.key === 'Escape') { e.preventDefault(); onClose(true); return; }
    if (e.key === 'Tab') { onClose(false); return; }
    if (e.key === 'ArrowDown') { e.preventDefault(); setActive((i) => nextEnabled(items, i, 1) ?? i); return; }
    if (e.key === 'ArrowUp') { e.preventDefault(); setActive((i) => nextEnabled(items, i, -1) ?? i); return; }
    if (e.key === 'Home') { e.preventDefault(); setActive(nextEnabled(items, -1, 1) ?? 0); return; }
    if (e.key === 'End') { e.preventDefault(); setActive(nextEnabled(items, items.length, -1) ?? 0); }
  };

  return (
    <ul
      ref={setRefs}
      id={id}
      role="menu"
      aria-label={label}
      className="sbx-row-menu"
      style={style}
      data-testid={testId}
      onKeyDown={onKeyDown}
      onClick={(e) => e.stopPropagation()}
      onContextMenu={(e) => e.preventDefault()}
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
            onClick={() => { if (!item.disabled) onChoose(item); }}
            onFocus={() => setActive(i)}
          >
            {t(item.labelKey)}
          </button>
        </li>
      ))}
    </ul>
  );
});
