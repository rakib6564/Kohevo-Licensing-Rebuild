// FieldControl — one property control generated from a FieldSchema manifest entry.
//
// The control is chosen from the field's canonical `type` (string, text,
// rich_text, number, boolean, enum, url, media_ref, token_ref, link, repeater,
// object) and constrained by its declared limits. Each control keeps a local
// draft and only commits a value to the document when it is well-formed, so
// half-typed links/URLs are never autosaved (and never rejected by the
// server mid-typing). Labels come from the server manifest and are text only.

import { lazy, memo, Suspense, useEffect, useId, useRef, useState } from 'react';
import { coerce, controlFor, hint, newRepeaterItem, tokensFor, clone } from '../../core/fields.mjs';
import { asList, asObject } from '../../core/doc.mjs';
import { t } from '../../core/messages.mjs';
import { MediaControl } from './MediaControl.jsx';
import { UrlControl } from './UrlControl.jsx';

const RichTextEditor = lazy(() => import('./RichTextEditor.jsx'));

/** Local draft that follows the committed value, and commits only valid values. */
function useDraft(value, field, onChange) {
  const [draft, setDraft] = useState(value);
  const [external, setExternal] = useState(0);
  const committed = useRef(JSON.stringify(value ?? null));
  useEffect(() => {
    // Follow the document only when the COMMITTED value really changed (undo,
    // reload, server normalization) — a reconcile that returns the same value
    // must not wipe a half-typed draft.
    const next = JSON.stringify(value ?? null);
    if (next !== committed.current) {
      committed.current = next;
      setDraft(value);
      setExternal((n) => n + 1);
    }
  }, [value]);
  const problem = hint(field, draft);
  const update = (next) => {
    setDraft(next);
    const empty = next === null || next === '' || next === undefined;
    if (hint(field, next) === null || (empty && !field.required)) {
      const out = empty && field.type !== 'string' && field.type !== 'text' && field.type !== 'rich_text' ? null : next;
      committed.current = JSON.stringify(out ?? null);
      onChange(out);
    }
  };
  return [draft, update, problem, external];
}

export const FieldRow = ({ id, label, required, problem, children, wide = false }) => (
  <div className={`sbx-field${wide ? ' sbx-field--wide' : ''}`}>
    <label className="sbx-field__label" htmlFor={id}>
      {label}{required ? <span aria-hidden="true"> *</span> : null}
    </label>
    {children}
    {problem ? <p className="sbx-field__problem" id={`${id}-problem`} role="alert">{t(problem)}</p> : null}
  </div>
);

export const FieldControl = memo(function FieldControl({ field, value, onChange, manifest, mediaPicker }) {
  const id = useId();
  const kind = controlFor(field);
  const [draft, update, problem, external] = useDraft(value, field, onChange);
  const described = problem ? `${id}-problem` : undefined;
  const common = { id, 'aria-describedby': described, 'aria-invalid': problem ? true : undefined, 'aria-required': field.required || undefined };

  switch (kind) {
    case 'text':
      return (
        <FieldRow id={id} label={field.label} required={field.required} problem={problem}>
          <input type="text" {...common} value={draft ?? ''} maxLength={field.max_length || undefined} onChange={(e) => update(coerce(field, e.target.value))} />
        </FieldRow>
      );
    case 'textarea':
      return (
        <FieldRow id={id} label={field.label} required={field.required} problem={problem}>
          <textarea {...common} rows={4} value={draft ?? ''} maxLength={field.max_length || undefined} onChange={(e) => update(e.target.value)} />
        </FieldRow>
      );
    case 'number':
      return (
        <FieldRow id={id} label={field.label} required={field.required} problem={problem}>
          <input
            type="number" {...common}
            value={draft ?? ''} min={field.min ?? undefined} max={field.max ?? undefined}
            step={field.integer_only ? 1 : 'any'}
            onChange={(e) => update(coerce(field, e.target.value))}
          />
        </FieldRow>
      );
    case 'checkbox':
      return (
        <div className="sbx-field sbx-field--check">
          <input type="checkbox" {...common} checked={!!draft} onChange={(e) => update(e.target.checked)} />
          <label htmlFor={id}>{field.label}</label>
        </div>
      );
    case 'select':
      return (
        <FieldRow id={id} label={field.label} required={field.required} problem={problem}>
          <select {...common} value={draft ?? ''} onChange={(e) => update(e.target.value)}>
            {asList(field.allowed_values).map((v) => <option key={String(v)} value={v}>{String(v)}</option>)}
          </select>
        </FieldRow>
      );
    case 'url':
      return (
        <UrlControl
          field={field}
          draft={draft}
          update={update}
          problem={problem}
          common={common}
          id={id}
          mediaPicker={mediaPicker}
        />
      );
    case 'token':
      return (
        <FieldRow id={id} label={field.label} required={field.required} problem={problem}>
          <TokenSelect id={id} value={draft} tokens={tokensFor(manifest)} onChange={update} />
        </FieldRow>
      );
    case 'link':
      return <LinkControl field={field} value={draft} onChange={update} problem={problem} />;
    case 'media':
      return <MediaControl field={field} value={draft} onChange={update} problem={problem} mediaPicker={mediaPicker} />;
    case 'richtext':
      return (
        <FieldRow id={id} label={field.label} required={field.required} problem={problem} wide>
          <Suspense fallback={<div className="sbx-muted">…</div>}>
            <RichTextEditor key={external} id={id} value={draft ?? ''} onChange={update} />
          </Suspense>
        </FieldRow>
      );
    case 'repeater':
      return <RepeaterControl field={field} value={asList(value)} onChange={onChange} manifest={manifest} mediaPicker={mediaPicker} />;
    case 'object':
      return (
        <fieldset className="sbx-fieldset">
          <legend>{field.label}</legend>
          <ObjectFields schema={field.properties} value={asObject(value)} onChange={onChange} manifest={manifest} mediaPicker={mediaPicker} />
        </fieldset>
      );
    default:
      return null;
  }
});

/** Controls for a map of fields (object properties, repeater items, block props). */
export function ObjectFields({ schema, value, onChange, manifest, mediaPicker }) {
  return asList(schema).map((f) => (
    <FieldControl
      key={f.key}
      field={f}
      value={value[f.key] ?? null}
      manifest={manifest}
      mediaPicker={mediaPicker}
      onChange={(v) => onChange({ ...value, [f.key]: v })}
    />
  ));
}

export function TokenSelect({ id, value, tokens, onChange, allowNone = true, noneLabel }) {
  const groups = new Map();
  tokens.forEach((tk) => { if (!groups.has(tk.category)) groups.set(tk.category, []); groups.get(tk.category).push(tk); });
  return (
    <select id={id} value={value ?? ''} onChange={(e) => onChange(e.target.value === '' ? null : e.target.value)}>
      {allowNone && <option value="">{noneLabel || t('none')}</option>}
      {[...groups.entries()].map(([cat, list]) => (
        <optgroup key={cat} label={cat}>
          {list.map((tk) => <option key={tk.ref} value={tk.ref}>{tk.ref}</option>)}
        </optgroup>
      ))}
      {value && !tokens.some((tk) => tk.ref === value) ? <option value={value}>{value}</option> : null}
    </select>
  );
}

function LinkControl({ field, value, onChange, problem }) {
  const id = useId();
  const link = asObject(value);
  const set = (patch) => {
    const next = { label: link.label ?? '', href: link.href ?? '', target: link.target ?? '_self', ...patch };
    if (next.target !== '_blank') delete next.rel;
    const blank = !next.label && !next.href;
    onChange(blank && !field.required ? null : next);
  };
  return (
    <fieldset className="sbx-fieldset" aria-describedby={problem ? `${id}-problem` : undefined}>
      <legend>{field.label}{field.required ? ' *' : ''}</legend>
      <label className="sbx-field">
        <span className="sbx-field__label">{t('link_label')}</span>
        <input type="text" value={link.label ?? ''} maxLength={255} onChange={(e) => set({ label: e.target.value.replace(/[\r\n]+/g, ' ') })} />
      </label>
      <label className="sbx-field">
        <span className="sbx-field__label">{t('link_href')}</span>
        <input type="text" inputMode="url" value={link.href ?? ''} placeholder="https://… or /path" onChange={(e) => set({ href: e.target.value.trim() })} />
      </label>
      <label className="sbx-field sbx-field--check">
        <input type="checkbox" checked={link.target === '_blank'} onChange={(e) => set({ target: e.target.checked ? '_blank' : '_self' })} />
        <span>{t('link_new_tab')}</span>
      </label>
      {problem ? <p className="sbx-field__problem" id={`${id}-problem`} role="alert">{t(problem)}</p> : null}
    </fieldset>
  );
}

function RepeaterControl({ field, value, onChange, manifest, mediaPicker }) {
  const max = field.max_items || 50;
  const move = (i, d) => {
    const next = [...value];
    const [item] = next.splice(i, 1);
    next.splice(i + d, 0, item);
    onChange(next);
  };
  return (
    <fieldset className="sbx-fieldset sbx-repeater">
      <legend>{field.label} ({value.length}/{max})</legend>
      {value.map((item, i) => (
        <div key={i} className="sbx-repeater__item" role="group" aria-label={`${t('item')} ${i + 1}`}>
          <div className="sbx-repeater__bar">
            <span>{t('item')} {i + 1}</span>
            <span>
              <button type="button" className="sbx-btn sbx-btn--xs" onClick={() => move(i, -1)} disabled={i === 0} aria-label={`${t('move_up')} ${i + 1}`}>↑</button>
              <button type="button" className="sbx-btn sbx-btn--xs" onClick={() => move(i, 1)} disabled={i === value.length - 1} aria-label={`${t('move_down')} ${i + 1}`}>↓</button>
              <button type="button" className="sbx-btn sbx-btn--xs" onClick={() => onChange(value.filter((_, j) => j !== i))} aria-label={`${t('remove_item')} ${i + 1}`}>✕</button>
            </span>
          </div>
          <ObjectFields
            schema={field.item_schema}
            value={asObject(item)}
            manifest={manifest}
            mediaPicker={mediaPicker}
            onChange={(v) => onChange(value.map((x, j) => (j === i ? v : x)))}
          />
        </div>
      ))}
      <button type="button" className="sbx-btn" onClick={() => onChange([...value, newRepeaterItem(field.item_schema)])} disabled={value.length >= max}>
        + {t('add_item')}
      </button>
    </fieldset>
  );
}

export { clone };
