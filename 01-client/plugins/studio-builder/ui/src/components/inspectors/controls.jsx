// Shared inspector controls built on the canonical vocabulary from the
// server manifest (breakpoints, spacing scale, alignments, auth states …).

import { useId, useState } from 'react';
import { asList, asObject } from '../../core/doc.mjs';
import { BREAKPOINTS, VIEWPORTS } from '../../core/viewport.mjs';
import { useEditor } from '../EditorContext.jsx';
import { Icon, choiceIcon } from './InspectorIcons.jsx';
import { IconMonitor, IconTablet, IconSmartphone } from '../Icons.jsx';
import { t } from '../../core/messages.mjs';
import { optionLabel } from '../../core/optionLabels.mjs';
import { Field, Check } from '../ui/index.js';
import { acceptsDraft } from '../../core/styleValues.mjs';

const DEVICE_ICONS = { desktop: IconMonitor, tablet: IconTablet, mobile: IconSmartphone };

/**
 * One value per canonical breakpoint, edited for ONE device at a time:
 * Desktop = lg, Tablet = md, Mobile = base (the device buttons also switch the
 * canvas). `sm` stays reachable as an advanced override. Unset devices inherit
 * mobile-first; the inherited value is shown as the placeholder. `base` is
 * required when `requireBase`.
 */
export function ResponsiveSelect({ label, value, options, onChange, activeBreakpoint, requireBase = false, numeric = false, icons = null, labelOf = null, hideLegend = false }) {
  const id = useId();
  const { viewport, setViewport } = useEditor();
  const map = typeof value === 'object' && value !== null && !Array.isArray(value) ? value : (value === null || value === undefined ? {} : { base: value });
  const current = (viewport && viewport.breakpoint) || activeBreakpoint || 'base';
  const set = (bp, raw) => {
    const next = { ...map };
    if (raw === '') delete next[bp];
    else next[bp] = numeric ? Number(raw) : raw;
    onChange(next);
  };
  const inherited = (bp) => {
    const lower = BREAKPOINTS.slice(0, BREAKPOINTS.indexOf(bp));
    for (let i = lower.length - 1; i >= 0; i -= 1) if (map[lower[i]] !== undefined) return map[lower[i]];
    return undefined;
  };
  const cell = (bp, cellLabel) => {
    const inh = inherited(bp);
    return (
      <div className="sbx-field sbx-responsive__cell" key={bp}>
        {cellLabel && <label className="sbx-field__label" htmlFor={`${id}-${bp}`}>{cellLabel}</label>}
        <select id={`${id}-${bp}`} aria-label={cellLabel ? undefined : `${label} (${t(VIEWPORTS.find((v) => v.breakpoint === bp)?.key || bp)})`} value={map[bp] === undefined ? '' : String(map[bp])} onChange={(e) => set(bp, e.target.value)}>
          {!(requireBase && bp === 'base') && <option value="">{inh === undefined ? t('inherit') : `${t('inherit')} · ${labelOf ? labelOf(inh) : optionLabel(inh)}`}</option>}
          {options.map((o) => <option key={String(o)} value={String(o)}>{labelOf ? labelOf(o) : optionLabel(o)}</option>)}
        </select>
      </div>
    );
  };
  const device = VIEWPORTS.find((v) => v.breakpoint === current);
  // The device buttons only cover base/md/lg; on sm-wide frames (never produced by the canvas) fall back to base.
  const editing = device ? device.breakpoint : 'base';
  return (
    <fieldset className="sbx-fieldset sbx-responsive">
      <legend className={hideLegend ? 'sbx-sr-only' : undefined}>{label}</legend>
      <div className="sbx-responsive__devices" role="group" aria-label={`${label} — ${t('viewport')}`}>
        {VIEWPORTS.map((v) => {
          const Dev = DEVICE_ICONS[v.key];
          const set_ = map[v.breakpoint] !== undefined;
          return (
            <button
              key={v.key}
              type="button"
              className={`sbx-responsive__device${editing === v.breakpoint ? ' is-active' : ''}${set_ ? ' is-set' : ''}`}
              aria-pressed={editing === v.breakpoint}
              aria-label={t(v.key)}
              title={`${t(v.key)} (${v.breakpoint})`}
              onClick={() => setViewport && setViewport(v.key)}
            >
              <Dev size={14} />
            </button>
          );
        })}
      </div>
      {icons ? (
        <div className="sbx-iconchoice" role="group" aria-label={label}>
          {options.map((o) => {
            const shown = map[editing] !== undefined ? map[editing] : inherited(editing);
            return (
              <button
                key={String(o)}
                type="button"
                className={`sbx-iconchoice__btn${shown === o ? ' is-active' : ''}${map[editing] === undefined && shown === o ? ' is-inherited' : ''}`}
                aria-pressed={map[editing] === o}
                aria-label={String(o)}
                title={String(o)}
                onClick={() => set(editing, map[editing] === o ? '' : String(o))}
              >
                <Icon name={choiceIcon(icons, o)} size={16} />
              </button>
            );
          })}
        </div>
      ) : cell(editing)}
      <details className="sbx-responsive__more">
        <summary>{t('responsive_sm')}</summary>
        {cell('sm', t('responsive_sm_label'))}
      </details>
    </fieldset>
  );
}

/**
 * A size per device (a breakpoint map of sizes), edited for the device the canvas shows, like ResponsiveSelect: the
 * device buttons switch the canvas, and a device with no size of its own shows the one it inherits as the placeholder.
 * Each size is checked as the other size fields are (`size`: px, %, rem, vw... never negative); a bad one stays in the box.
 */
export function ResponsiveSize({ label, value, onChange, placeholder = '', unit = 'px', hint = null }) {
  const id = useId();
  const { viewport, setViewport } = useEditor();
  const map = asObject(value);
  const device = VIEWPORTS.find((v) => v.breakpoint === ((viewport && viewport.breakpoint) || 'base'));
  const editing = device ? device.breakpoint : 'base';
  const lower = BREAKPOINTS.slice(0, BREAKPOINTS.indexOf(editing));
  let inherited;
  for (let i = lower.length - 1; i >= 0 && inherited === undefined; i -= 1) inherited = map[lower[i]];
  const set = (v) => {
    const next = { ...map };
    if (v === undefined || v === '') delete next[editing];
    else next[editing] = v;
    onChange(next);
  };
  return (
    <fieldset className="sbx-fieldset sbx-responsive" data-testid="responsive-size">
      <legend>{label}</legend>
      <div className="sbx-responsive__devices" role="group" aria-label={`${label} — ${t('viewport')}`}>
        {VIEWPORTS.map((v) => {
          const Dev = DEVICE_ICONS[v.key];
          return (
            <button
              key={v.key}
              type="button"
              className={`sbx-responsive__device${editing === v.breakpoint ? ' is-active' : ''}${map[v.breakpoint] !== undefined ? ' is-set' : ''}`}
              aria-pressed={editing === v.breakpoint}
              aria-label={t(v.key)}
              title={`${t(v.key)} (${v.breakpoint})`}
              onClick={() => setViewport && setViewport(v.key)}
            >
              <Dev size={14} />
            </button>
          );
        })}
      </div>
      <SizeInput
        key={`${editing}:${map[editing] ?? ''}`}
        id={`${id}-${editing}`}
        label={`${label} (${t(device ? device.key : 'mobile')})`}
        value={map[editing]}
        placeholder={inherited !== undefined ? String(inherited) : placeholder}
        unit={unit}
        onCommit={set}
      />
      {hint && <p className="sbx-hint">{hint}</p>}
    </fieldset>
  );
}

/** One size: a bare number takes `unit`; an invalid draft stays in the box with an error and is never committed. */
function SizeInput({ id, label, value, placeholder, unit, onCommit }) {
  const [error, setError] = useState(false);
  const commit = (raw) => {
    let next = raw.trim();
    if (/^\d*\.?\d+$/.test(next) && Number(next) !== 0) next += unit;
    if (!acceptsDraft('size', next)) { setError(true); return; }
    setError(false);
    if (next !== (value ?? '')) onCommit(next);
  };
  return (
    <Field label={label} htmlFor={id}>
      <input
        id={id}
        type="text"
        className="sbx-input"
        defaultValue={value ?? ''}
        placeholder={placeholder}
        aria-invalid={error || undefined}
        aria-describedby={error ? `${id}-err` : undefined}
        onChange={() => { if (error) setError(false); }}
        onBlur={(e) => commit(e.target.value)}
        onKeyDown={(e) => { if (e.key === 'Enter') e.currentTarget.blur(); }}
      />
      {error && <p className="sbx-field__error" id={`${id}-err`} role="alert">{t('custom_width_invalid')}</p>}
    </Field>
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
          <Check key={bp} label={t(`vis_dev_${bp}`)} checked={devices.includes(bp)} disabled={devices.length === 1 && devices.includes(bp)} onChange={(e) => toggle(bp, e.target.checked)} />
        ))}
      </fieldset>
      <Field label={t('audience')} htmlFor={id}>
        <select id={id} value={vis.auth_state || 'any'} onChange={(e) => onChange({ auth_state: e.target.value, devices })}>
          {authStates.map((s) => <option key={s} value={s}>{optionLabel(s)}</option>)}
        </select>
      </Field>
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
