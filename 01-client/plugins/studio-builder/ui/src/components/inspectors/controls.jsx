// Shared inspector controls built on the canonical vocabulary from the
// server manifest (breakpoints, spacing scale, alignments, auth states …).

import { useId } from 'react';
import { asList, asObject } from '../../core/doc.mjs';
import { BREAKPOINTS, VIEWPORTS } from '../../core/viewport.mjs';
import { useEditor } from '../EditorContext.jsx';
import { Icon, choiceIcon } from './InspectorIcons.jsx';
import { IconMonitor, IconTablet, IconSmartphone } from '../Icons.jsx';
import { t } from '../../core/messages.mjs';
import { optionLabel } from '../../core/optionLabels.mjs';

const DEVICE_ICONS = { desktop: IconMonitor, tablet: IconTablet, mobile: IconSmartphone };

/**
 * One value per canonical breakpoint, edited for ONE device at a time:
 * Desktop = lg, Tablet = md, Mobile = base (the device buttons also switch the
 * canvas). `sm` stays reachable as an advanced override. Unset devices inherit
 * mobile-first; the inherited value is shown as the placeholder. `base` is
 * required when `requireBase`.
 */
export function ResponsiveSelect({ label, value, options, onChange, activeBreakpoint, requireBase = false, numeric = false, icons = null }) {
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
          {!(requireBase && bp === 'base') && <option value="">{inh === undefined ? t('inherit') : `${t('inherit')} · ${String(inh)}`}</option>}
          {options.map((o) => <option key={String(o)} value={String(o)}>{optionLabel(o)}</option>)}
        </select>
      </div>
    );
  };
  const device = VIEWPORTS.find((v) => v.breakpoint === current);
  // The device buttons only cover base/md/lg; on sm-wide frames (never produced by the canvas) fall back to base.
  const editing = device ? device.breakpoint : 'base';
  return (
    <fieldset className="sbx-fieldset sbx-responsive">
      <legend>{label}</legend>
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
            <span>{t(`vis_dev_${bp}`)}</span>
          </label>
        ))}
      </fieldset>
      <div className="sbx-field">
        <label className="sbx-field__label" htmlFor={id}>{t('audience')}</label>
        <select id={id} value={vis.auth_state || 'any'} onChange={(e) => onChange({ auth_state: e.target.value, devices })}>
          {authStates.map((s) => <option key={s} value={s}>{optionLabel(s)}</option>)}
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
