// Shared inspector controls built on the canonical vocabulary from the
// server manifest (breakpoints, spacing scale, alignments, auth states …).

import { useId } from 'react';
import { asList, asObject } from '../../core/doc.mjs';
import { BREAKPOINTS } from '../../core/viewport.mjs';
import { t } from '../../core/messages.mjs';

/**
 * One select per canonical breakpoint (base, sm, md, lg). Unset breakpoints
 * inherit (mobile-first); `base` is required when `requireBase`.
 */
export function ResponsiveSelect({ label, value, options, onChange, activeBreakpoint, requireBase = false, numeric = false }) {
  const id = useId();
  const map = typeof value === 'object' && value !== null && !Array.isArray(value) ? value : (value === null || value === undefined ? {} : { base: value });
  const set = (bp, raw) => {
    const next = { ...map };
    if (raw === '') delete next[bp];
    else next[bp] = numeric ? Number(raw) : raw;
    onChange(next);
  };
  return (
    <fieldset className="sbx-fieldset sbx-responsive">
      <legend>{label}</legend>
      <div className="sbx-responsive__grid">
        {BREAKPOINTS.map((bp) => (
          <label key={bp} className={`sbx-responsive__cell${bp === activeBreakpoint ? ' is-active' : ''}`} htmlFor={`${id}-${bp}`}>
            <span className="sbx-field__label">{bp}{bp === activeBreakpoint ? ' ●' : ''}</span>
            <select id={`${id}-${bp}`} value={map[bp] === undefined ? '' : String(map[bp])} onChange={(e) => set(bp, e.target.value)}>
              {!(requireBase && bp === 'base') && <option value="">{t('inherit')}</option>}
              {options.map((o) => <option key={String(o)} value={String(o)}>{String(o)}</option>)}
            </select>
          </label>
        ))}
      </div>
    </fieldset>
  );
}

/** Visibility: devices (canonical breakpoints, at least one) + audience. */
export function VisibilityControls({ value, onChange, manifest }) {
  const id = useId();
  const vis = asObject(value);
  const devices = asList(vis.devices).length ? asList(vis.devices) : BREAKPOINTS;
  const authStates = asList(manifest.vocabulary && manifest.vocabulary.auth_states);
  const toggle = (bp, on) => {
    const next = on ? BREAKPOINTS.filter((b) => b === bp || devices.includes(b)) : devices.filter((b) => b !== bp);
    if (next.length) onChange({ auth_state: vis.auth_state || 'any', devices: next });
  };
  return (
    <>
      <fieldset className="sbx-fieldset">
        <legend>{t('devices')}</legend>
        {BREAKPOINTS.map((bp) => (
          <label key={bp} className="sbx-field sbx-field--check">
            <input type="checkbox" checked={devices.includes(bp)} disabled={devices.length === 1 && devices.includes(bp)} onChange={(e) => toggle(bp, e.target.checked)} />
            <span>{bp}</span>
          </label>
        ))}
      </fieldset>
      <div className="sbx-field">
        <label className="sbx-field__label" htmlFor={id}>{t('audience')}</label>
        <select id={id} value={vis.auth_state || 'any'} onChange={(e) => onChange({ auth_state: e.target.value, devices })}>
          {authStates.map((s) => <option key={s} value={s}>{s}</option>)}
        </select>
      </div>
    </>
  );
}

export function Tabs({ tabs, active, onChange, idPrefix }) {
  return (
    <div className="sbx-tabs" role="tablist">
      {tabs.map((x) => (
        <button
          key={x.key}
          id={`${idPrefix}-tab-${x.key}`}
          type="button"
          role="tab"
          aria-selected={active === x.key}
          aria-controls={`${idPrefix}-panel-${x.key}`}
          className={`sbx-tab${active === x.key ? ' is-active' : ''}`}
          onClick={() => onChange(x.key)}
        >
          {x.icon || null}
          <span className="sbx-tab__text">{x.label}</span>
        </button>
      ))}
    </div>
  );
}
