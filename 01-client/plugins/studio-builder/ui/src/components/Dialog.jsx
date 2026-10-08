// Dialog — accessible dialog with its own backdrop and focus trap.
//
// Deliberately NOT `showModal()`: a native modal dialog lives in the browser's
// top layer and makes the rest of the page inert — which would also bury the
// platform's existing media picker overlay (media-library, z-index 1000) that
// a dialog field may open. So the dialog is opened with `show()`, stacked
// below that overlay, and provides modality itself: backdrop, focus trapped
// inside, Escape closes, focus restored to the opener.

import { t } from '../core/messages.mjs';
import { useEffect, useId, useRef } from 'react';

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"]), [contenteditable="true"]';

export function Dialog({ title, onClose, children, footer }) {
  const ref = useRef(null);
  const titleId = useId();
  const opener = useRef(typeof document !== 'undefined' ? document.activeElement : null);
  const onCloseRef = useRef(onClose);
  onCloseRef.current = onClose;

  useEffect(() => {
    const el = ref.current;
    if (el && typeof el.show === 'function' && !el.open) el.show();
    const first = el && el.querySelector(`.sbx-dialog__body ${FOCUSABLE}`);
    if (first) first.focus(); else if (el) el.focus();
    const restore = opener.current;
    return () => { if (restore && typeof restore.focus === 'function') restore.focus(); };
  }, []);

  const onKeyDown = (e) => {
    if (e.key === 'Escape') {
      e.preventDefault();
      e.stopPropagation();
      onCloseRef.current();
      return;
    }
    if (e.key !== 'Tab') return;
    const items = [...ref.current.querySelectorAll(FOCUSABLE)].filter((n) => n.offsetParent !== null || n === document.activeElement);
    if (!items.length) return;
    const first = items[0];
    const last = items[items.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  };

  return (
    <>
      <div className="sbx-dialog-backdrop" aria-hidden="true" onClick={() => onCloseRef.current()} />
      <dialog
        ref={ref}
        className="sbx-dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        tabIndex={-1}
        onKeyDown={onKeyDown}
      >
        <div className="sbx-dialog__head">
          <h2 id={titleId}>{title}</h2>
          <button type="button" className="sbx-btn sbx-btn--ghost" onClick={() => onCloseRef.current()} aria-label={t('close')}>✕</button>
        </div>
        <div className="sbx-dialog__body">{children}</div>
        {footer ? <div className="sbx-dialog__foot">{footer}</div> : null}
      </dialog>
    </>
  );
}
