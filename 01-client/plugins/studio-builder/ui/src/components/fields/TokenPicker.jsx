// TokenPicker — choose a theme token by name, with a sample, the way a global colour / font picker works:
// "Primary" under "Surfaces" with its colour chip, "Headings" with the font drawn in it. The stored value is
// still the token ref (`surface.primary`); the ref only shows as a tooltip.

import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { t } from '../../core/messages.mjs';
import { groupForPicker, sampleStyle, tokenLabel } from '../../core/tokenPicker.mjs';

function Sample({ category, value }) {
  const style = sampleStyle(category, value);
  const text = category === 'font' ? <span aria-hidden="true">Aa</span> : null;
  return <span className={`sbx-tp__sample sbx-tp__sample--${category}`} style={style} aria-hidden="true">{text}</span>;
}

export function TokenPicker({ id, value, tokens, onChange, allowNone = true, noneLabel, label }) {
  const groups = useMemo(() => groupForPicker(tokens), [tokens]);
  const flat = useMemo(() => groups.flatMap((g) => g.items), [groups]);
  const options = useMemo(() => [...(allowNone ? [{ ref: null, label: noneLabel || t('tok_default') }] : []), ...flat], [flat, allowNone, noneLabel]);
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(0);
  const [flip, setFlip] = useState(false);
  const root = useRef(null);
  const btn = useRef(null);
  const listId = useId();

  const current = flat.find((o) => o.ref === value) || null;
  const unknown = value && !current;
  const shownLabel = current ? current.label : unknown ? tokenLabel(value) : (noneLabel || t('tok_default'));

  useEffect(() => {
    if (!open) return undefined;
    const away = (e) => { if (root.current && !root.current.contains(e.target)) setOpen(false); };
    document.addEventListener('pointerdown', away);
    return () => document.removeEventListener('pointerdown', away);
  }, [open]);

  const openList = () => {
    const rect = btn.current ? btn.current.getBoundingClientRect() : null;
    setFlip(!!rect && window.innerHeight - rect.bottom < 280 && rect.top > 280);
    setActive(Math.max(0, options.findIndex((o) => o.ref === (value ?? null))));
    setOpen(true);
  };
  const choose = (opt) => { onChange(opt.ref); setOpen(false); if (btn.current) btn.current.focus(); };

  const onKey = (e) => {
    if (!open) {
      if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(e.key)) { e.preventDefault(); openList(); }
      return;
    }
    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); setOpen(false); }
    else if (e.key === 'ArrowDown') { e.preventDefault(); setActive((i) => Math.min(options.length - 1, i + 1)); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setActive((i) => Math.max(0, i - 1)); }
    else if (e.key === 'Home') { e.preventDefault(); setActive(0); }
    else if (e.key === 'End') { e.preventDefault(); setActive(options.length - 1); }
    else if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); choose(options[active]); }
    else if (e.key === 'Tab') setOpen(false);
  };

  useEffect(() => {
    if (open) {
      const el = root.current && root.current.querySelector('[data-active="true"]');
      if (el && el.scrollIntoView) el.scrollIntoView({ block: 'nearest' });
    }
  }, [open, active]);

  let optionIndex = allowNone ? 1 : 0;
  return (
    <div className="sbx-tp" ref={root}>
      <button
        type="button"
        id={id}
        ref={btn}
        className={`sbx-tp__btn${value ? ' is-set' : ''}`}
        role="combobox"
        aria-haspopup="listbox"
        aria-expanded={open}
        aria-controls={open ? listId : undefined}
        aria-label={label ? `${label}: ${shownLabel}` : undefined}
        title={value || undefined}
        onClick={() => (open ? setOpen(false) : openList())}
        onKeyDown={onKey}
      >
        {current ? <Sample category={current.category} value={current.value} /> : <span className="sbx-tp__sample sbx-tp__sample--none" aria-hidden="true" />}
        <span className="sbx-tp__label">{shownLabel}</span>
        <span className="sbx-tp__caret" aria-hidden="true">▾</span>
      </button>
      {open && (
        <ul id={listId} role="listbox" className={`sbx-tp__list${flip ? ' is-up' : ''}`} aria-label={label ? t('tok_list', { label }) : undefined} tabIndex={-1}>
          {allowNone && (
            <li role="option" aria-selected={!value} data-active={active === 0} className={`sbx-tp__opt${active === 0 ? ' is-active' : ''}${!value ? ' is-selected' : ''}`} onPointerDown={(e) => e.preventDefault()} onClick={() => choose(options[0])}>
              <span className="sbx-tp__sample sbx-tp__sample--none" aria-hidden="true" />
              <span className="sbx-tp__label">{noneLabel || t('tok_default')}</span>
            </li>
          )}
          {groups.map((g) => (
            <li key={g.category} role="presentation">
              <div className="sbx-tp__heading" role="presentation">{g.heading}</div>
              <ul role="group" aria-label={g.heading}>
                {g.items.map((o) => {
                  const idx = optionIndex++;
                  return (
                    <li
                      key={o.ref}
                      role="option"
                      aria-selected={o.ref === value}
                      data-active={active === idx}
                      title={o.ref}
                      className={`sbx-tp__opt${active === idx ? ' is-active' : ''}${o.ref === value ? ' is-selected' : ''}`}
                      onPointerDown={(e) => e.preventDefault()}
                      onClick={() => choose(o)}
                      onMouseEnter={() => setActive(idx)}
                    >
                      <Sample category={o.category} value={o.value} />
                      <span className="sbx-tp__label">{o.label}</span>
                      <span className="sbx-tp__hint">{o.value && o.category !== 'font' ? String(o.value).slice(0, 18) : ''}</span>
                    </li>
                  );
                })}
              </ul>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
