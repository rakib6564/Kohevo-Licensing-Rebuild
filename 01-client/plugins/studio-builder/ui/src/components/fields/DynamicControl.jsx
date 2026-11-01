// DynamicControl — the database button beside a setting, as in the reference editor. It opens the tag list; once a tag is
// chosen the setting shows the tag's name in place of its own control, with a wrench for Before, After, Fallback and the
// tag's own options. The block's typed value stays underneath: clearing the tag brings it back.

import { createContext, useContext, useEffect, useId, useMemo, useRef, useState } from 'react';
import { dynamicKind, getDynamicTags, tagGroups, tagOf } from '../../core/dynamicTags.mjs';
import { getMetaFields, metaFieldGroups } from '../../core/metaFields.mjs';
import { t } from '../../core/messages.mjs';

/** { map, set(path, config|null), moveRows(path, order) } for the block being edited; null where tags do not apply. */
export const DynamicContext = createContext(null);

/** The database button, for a text input to draw inside itself as the reference editor does. */
export const DynamicSlot = createContext(null);

/** A text input with room for the database button. */
export function SlotInput({ children }) {
  const slot = useContext(DynamicSlot);
  return slot ? <span className="sbx-dyn__input">{children}{slot}</span> : children;
}

const DATE_FORMATS = ['', 'F j, Y', 'M j, Y', 'j F Y', 'Y-m-d', 'm/d/Y', 'd/m/Y'];
const TIME_FORMATS = ['', 'g:i a', 'H:i'];

const DbIcon = () => (
  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
    <ellipse cx="12" cy="5" rx="9" ry="3" /><path d="M3 5v14c0 1.7 4 3 9 3s9-1.3 9-3V5" /><path d="M3 12c0 1.7 4 3 9 3s9-1.3 9-3" />
  </svg>
);

const WrenchIcon = () => (
  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
    <path d="M14.7 6.3a4 4 0 0 0-5.4 5.2L3 17.8V21h3.2l6.3-6.3a4 4 0 0 0 5.2-5.4l-2.6 2.6-2.5-.6-.6-2.5z" />
  </svg>
);

/** The tag list: search, the tags this setting can take, and each custom field by content type. */
function TagPicker({ kind, onPick, onClose }) {
  const [query, setQuery] = useState('');
  const root = useRef(null);
  const input = useRef(null);
  useEffect(() => {
    if (input.current) input.current.focus();
    const away = (e) => { if (root.current && !root.current.parentElement.contains(e.target)) onClose(); };
    document.addEventListener('pointerdown', away, true);
    return () => document.removeEventListener('pointerdown', away, true);
  }, [onClose]);
  const groups = tagGroups(kind, query);
  const q = query.trim().toLowerCase();
  const fieldGroups = tagOf('field') && tagOf('field').kinds.includes(kind)
    ? metaFieldGroups(getMetaFields().filter((f) => !q || `${f.label} ${f.key}`.toLowerCase().includes(q)))
    : [];
  return (
    <div className="sbx-dyn__picker" ref={root} role="dialog" aria-label={t('dyn_tags')} data-testid="dyn-picker" onKeyDown={(e) => { if (e.key === 'Escape') { e.stopPropagation(); onClose(); } }}>
      <input ref={input} type="search" className="sbx-dyn__search" placeholder={t('dyn_search')} aria-label={t('dyn_search')} value={query} onChange={(e) => setQuery(e.target.value)} />
      <div className="sbx-dyn__list">
        {groups.map((g) => (
          <div key={g.label}>
            <p className="sbx-dyn__group">{g.label}</p>
            {g.tags.filter((tag) => tag.tag !== 'field').map((tag) => <button key={tag.tag} type="button" className="sbx-dyn__item" onClick={() => onPick({ tag: tag.tag })}>{tag.label}</button>)}
          </div>
        ))}
        {fieldGroups.map((g) => (
          <div key={g.label}>
            <p className="sbx-dyn__group">{t('dyn_fields_of', { type: g.label })}</p>
            {g.fields.map((f) => <button key={`${f.postType}:${f.key}`} type="button" className="sbx-dyn__item" onClick={() => onPick({ tag: 'field', key: f.key })}>{f.label}</button>)}
          </div>
        ))}
        {groups.length === 0 && fieldGroups.length === 0 ? <p className="sbx-hint">{t('dyn_none')}</p> : null}
      </div>
    </div>
  );
}

/** Before, After, Fallback and the tag's own setting. */
function TagSettings({ kind, config, def, onChange }) {
  const id = useId();
  const set = (key, value) => {
    const { [key]: _old, ...rest } = config;
    onChange(value === '' ? rest : { ...rest, [key]: value });
  };
  const text = (key, label) => (
    <label className="sbx-field" htmlFor={`${id}-${key}`}>
      <span className="sbx-field__label">{label}</span>
      <input id={`${id}-${key}`} type="text" maxLength={200} value={config[key] ?? ''} onChange={(e) => set(key, e.target.value)} />
    </label>
  );
  const formats = def && def.setting === 'date' ? DATE_FORMATS : def && def.setting === 'time' ? TIME_FORMATS : null;
  return (
    <div className="sbx-dyn__settings" data-testid="dyn-settings">
      {def && def.setting === 'field' ? (
        <label className="sbx-field" htmlFor={`${id}-key`}>
          <span className="sbx-field__label">{t('dyn_field')}</span>
          <select id={`${id}-key`} value={config.key ?? ''} onChange={(e) => set('key', e.target.value)}>
            {config.key && !getMetaFields().some((f) => f.key === config.key) ? <option value={config.key}>{config.key}</option> : null}
            {metaFieldGroups().map((g) => <optgroup key={g.label} label={g.label}>{g.fields.map((f) => <option key={`${f.postType}:${f.key}`} value={f.key}>{f.label}</option>)}</optgroup>)}
          </select>
        </label>
      ) : null}
      {formats ? (
        <label className="sbx-field" htmlFor={`${id}-format`}>
          <span className="sbx-field__label">{t('dyn_format')}</span>
          <select id={`${id}-format`} value={config.format ?? ''} onChange={(e) => set('format', e.target.value)}>
            {formats.map((f) => <option key={f} value={f}>{f === '' ? t('dyn_format_default') : f}</option>)}
          </select>
        </label>
      ) : null}
      {kind === 'text' ? text('before', t('dyn_before')) : null}
      {kind === 'text' ? text('after', t('dyn_after')) : null}
      {kind === 'text' || kind === 'url' ? text('fallback', t('dyn_fallback')) : null}
      {kind !== 'text' && kind !== 'url' ? <p className="sbx-hint">{t('dyn_own_fallback')}</p> : null}
    </div>
  );
}

/** Wraps one setting's control. `path` is the setting's full path; `kind` overrides the field's own (a Container's background). */
export function DynamicField({ field, path, label = null, kind = null, inline = false, children }) {
  const ctx = useContext(DynamicContext);
  const [picking, setPicking] = useState(false);
  const [editing, setEditing] = useState(false);
  const close = useMemo(() => () => setPicking(false), []);
  const k = kind || dynamicKind(field);
  if (!ctx || !k || getDynamicTags().length === 0) return children;
  const config = ctx.map[path];
  const pick = (next) => { ctx.set(path, next); setPicking(false); };
  if (config) {
    const def = tagOf(config.tag);
    const name = config.tag === 'field' ? (getMetaFields().find((f) => f.key === config.key) || {}).label || config.key : (def ? def.label : config.tag);
    return (
      <div className="sbx-field sbx-dyn sbx-dyn--set" data-testid="dyn-set" data-dyn-path={path}>
        <span className="sbx-field__label">{label || field.label}</span>
        <div className="sbx-dyn__chip">
          <button type="button" className="sbx-dyn__name" onClick={() => setPicking((v) => !v)} aria-expanded={picking} title={t('dyn_change')}><DbIcon /><span>{name}</span></button>
          <button type="button" className="sbx-iconbtn" onClick={() => setEditing((v) => !v)} aria-expanded={editing} aria-label={t('dyn_settings')} title={t('dyn_settings')}><WrenchIcon /></button>
          <button type="button" className="sbx-iconbtn" onClick={() => ctx.set(path, null)} aria-label={t('dyn_clear')} title={t('dyn_clear')}>×</button>
        </div>
        {picking ? <TagPicker kind={k} onPick={pick} onClose={close} /> : null}
        {editing ? <TagSettings kind={k} config={config} def={def} onChange={(next) => ctx.set(path, next)} /> : null}
      </div>
    );
  }
  const button = <button type="button" className="sbx-iconbtn sbx-dyn__open" onClick={() => setPicking((v) => !v)} aria-expanded={picking} aria-label={t('dyn_tags')} title={t('dyn_tags')} data-testid="dyn-open"><DbIcon /></button>;
  return (
    <div className={`sbx-dyn${inline ? ' sbx-dyn--inline' : ''}`} data-dyn-path={path}>
      <div className="sbx-dyn__control">{inline ? <DynamicSlot.Provider value={button}>{children}</DynamicSlot.Provider> : children}</div>
      {inline ? null : button}
      {picking ? <TagPicker kind={k} onPick={pick} onClose={close} /> : null}
    </div>
  );
}
