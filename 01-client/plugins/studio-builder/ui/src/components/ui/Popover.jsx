// Popover — a compact row (the common control) with a pencil that opens the rest of the group in a card under it,
// the way Elementor's group controls do. The card sits in the flow of the Inspector, over the controls below, and
// stays mounted only while open. Escape or a click outside closes it and focus goes back to the pencil.

import { useEffect, useId, useRef, useState } from 'react';
import { IconButton } from '../inspectors/InspectorIcons.jsx';
import { t } from '../../core/messages.mjs';

/**
 * @param {object} props
 * @param {string} props.label           what the pencil edits ("Edit shadow"); also names the card
 * @param {React.ReactNode} props.row    the control or summary shown beside the pencil
 * @param {React.ReactNode} props.children the rest of the group, shown in the card
 */
export function Popover({ label, row, children }) {
  const [open, setOpen] = useState(false);
  const root = useRef(null);
  const trigger = useRef(null);
  const card = useRef(null);
  const cardId = useId();

  useEffect(() => {
    if (!open) return undefined;
    const away = (e) => { if (root.current && !root.current.contains(e.target)) setOpen(false); };
    document.addEventListener('pointerdown', away);
    if (card.current) card.current.focus();
    return () => document.removeEventListener('pointerdown', away);
  }, [open]);

  const close = () => { setOpen(false); if (trigger.current) trigger.current.focus(); };

  return (
    <div className={`sbx-pop${open ? ' is-open' : ''}`} ref={root}>
      <div className="sbx-pop__row">
        <div className="sbx-pop__main">{row}</div>
        <IconButton
          ref={trigger}
          icon="edit"
          label={label}
          aria-expanded={open}
          aria-haspopup="dialog"
          aria-controls={open ? cardId : undefined}
          onClick={() => (open ? close() : setOpen(true))}
        />
      </div>
      {open && (
        <div
          id={cardId}
          ref={card}
          role="dialog"
          aria-label={label}
          tabIndex={-1}
          className="sbx-pop__card"
          onKeyDown={(e) => { if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); } }}
        >
          {children}
          <button type="button" className="sbx-btn sbx-btn--xs sbx-pop__done" onClick={close}>{t('popover_done')}</button>
        </div>
      )}
    </div>
  );
}
