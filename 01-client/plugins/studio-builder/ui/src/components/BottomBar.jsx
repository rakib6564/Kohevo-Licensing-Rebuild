// BottomBar — breadcrumb, canvas zoom, guides toggle and the shortcut help.
//
// Presentational: the canvas owns zoom and grid state; the selection path comes
// from the document. Zoom steps come from core/zoom.mjs.

import { memo, useEffect, useId, useRef, useState } from 'react';
import { canZoomIn, canZoomOut } from '../core/zoom.mjs';
import { t } from '../core/messages.mjs';

const MOD = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform || '') ? '⌘' : 'Ctrl';

/** [keys, message key] — kept in step with the shell's real handlers. */
const SHORTCUTS = [
  [`${MOD} Z`, 'shortcut_undo'],
  [`${MOD} ⇧ Z`, 'shortcut_redo'],
  [`${MOD} S`, 'shortcut_save'],
  [`${MOD} D`, 'shortcut_duplicate'],
  [`${MOD} click`, 'shortcut_multi'],
  ['⇧ click', 'shortcut_range'],
  ['F2', 'shortcut_rename'],
  ['Delete', 'shortcut_delete'],
  ['Esc', 'shortcut_deselect'],
];

function ShortcutHelp() {
  const [open, setOpen] = useState(false);
  const id = useId();
  const wrapRef = useRef(null);

  useEffect(() => {
    if (!open) return undefined;
    const onKey = (e) => { if (e.key === 'Escape') { e.stopPropagation(); setOpen(false); } };
    const onDown = (e) => { if (wrapRef.current && !wrapRef.current.contains(e.target)) setOpen(false); };
    document.addEventListener('keydown', onKey, true);
    document.addEventListener('mousedown', onDown);
    return () => { document.removeEventListener('keydown', onKey, true); document.removeEventListener('mousedown', onDown); };
  }, [open]);

  return (
    <span className="sbx-bottombar__help" ref={wrapRef}>
      <button
        type="button"
        className="sbx-bottombar__btn"
        aria-haspopup="dialog"
        aria-expanded={open}
        aria-controls={open ? id : undefined}
        data-testid="shortcut-help-toggle"
        title={t('shortcuts_help')}
        aria-label={t('shortcuts_help')}
        onClick={() => setOpen((o) => !o)}
      >
        <span aria-hidden="true">?</span>
      </button>
      {open && (
        <div id={id} role="dialog" aria-label={t('shortcuts_title')} className="sbx-bottombar__popover" data-testid="shortcut-help">
          <strong>{t('shortcuts_title')}</strong>
          <dl>
            {SHORTCUTS.map(([keys, key]) => (
              <div key={key} className="sbx-bottombar__shortcut">
                <dt><kbd>{keys}</kbd></dt>
                <dd>{t(key)}</dd>
              </div>
            ))}
          </dl>
        </div>
      )}
    </span>
  );
}

export const BottomBar = memo(function BottomBar({
  path, onSelectPath, percent, fitActive, onFit, onZoomIn, onZoomOut, showGrid, onToggleGrid, showPath = true,
}) {
  return (
    <div className="sbx-bottombar" data-testid="bottom-bar">
      <div className="sbx-bottombar__left">
        {showPath && (
          <nav className="sbx-breadcrumb" aria-label={t('breadcrumb')}>
            <button type="button" className="sbx-breadcrumb__item" onClick={() => onSelectPath(null)}>{t('page')}</button>
            {path.map((p, i) => (
              <span key={p.id} className="sbx-breadcrumb__seg">
                <span className="sbx-breadcrumb__sep" aria-hidden="true">›</span>
                <button
                  type="button"
                  className="sbx-breadcrumb__item"
                  aria-current={i === path.length - 1 ? 'location' : undefined}
                  onClick={() => onSelectPath(p.id)}
                >
                  {p.label}
                </button>
              </span>
            ))}
          </nav>
        )}
      </div>

      <div className="sbx-bottombar__center" role="group" aria-label={t('zoom_level')}>
        <button type="button" className="sbx-bottombar__btn" data-testid="zoom-out" aria-label={t('zoom_out')} title={t('zoom_out')} disabled={!canZoomOut(percent)} onClick={onZoomOut}>−</button>
        <output className="sbx-bottombar__percent" data-testid="zoom-percent" aria-live="polite">{percent}%</output>
        <button type="button" className="sbx-bottombar__btn" data-testid="zoom-in" aria-label={t('zoom_in')} title={t('zoom_in')} disabled={!canZoomIn(percent)} onClick={onZoomIn}>+</button>
        <button type="button" className={`sbx-bottombar__btn sbx-bottombar__btn--text${fitActive ? ' is-active' : ''}`} data-testid="zoom-fit" aria-pressed={fitActive} title={t('zoom_fit')} onClick={onFit}>{t('fit')}</button>
      </div>

      <div className="sbx-bottombar__right">
        <button
          type="button"
          className={`sbx-bottombar__btn sbx-bottombar__btn--text${showGrid ? ' is-active' : ''}`}
          aria-pressed={showGrid}
          data-testid="canvas-grid-toggle"
          onClick={onToggleGrid}
          title={t('grid_toggle')}
        >
          {t('grid')}
        </button>
        <ShortcutHelp />
      </div>
    </div>
  );
});
