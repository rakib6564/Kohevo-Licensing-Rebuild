// AdvancedControls — the Advanced tab's fields: class names, ID and accessibility, and data attributes.
//
// Each one validates with the editor's copy of the server's rules (core/blockAttributes.mjs) and keeps a refused
// draft in the box with a message instead of committing it, so nothing here can make a block "unavailable".

import { useId, useState } from 'react';
import { asList } from '../../core/doc.mjs';
import { ROLES, attributeIssue, classTokenOk, groupAttributes, idFormatOk, idOwner, withAttribute } from '../../core/blockAttributes.mjs';
import { t } from '../../core/messages.mjs';
import { Field } from '../ui/index.js';

/** A text input that commits on blur (or Enter) only when `check` returns null; otherwise it keeps the draft and shows the message. */
function CommitInput({ id, label, value, placeholder, hint, check, onCommit }) {
  const [problem, setProblem] = useState(null);
  return (
    <Field label={label} htmlFor={id}>
      <input
        id={id}
        type="text"
        className="sbx-input"
        defaultValue={value}
        key={value}
        placeholder={placeholder}
        aria-invalid={problem ? true : undefined}
        aria-describedby={problem ? `${id}-err` : undefined}
        onChange={() => { if (problem) setProblem(null); }}
        onBlur={(e) => {
          const next = e.target.value.trim();
          if (next === value) { setProblem(null); return; }
          const issue = next === '' ? null : check(next);
          setProblem(issue);
          if (!issue) onCommit(next);
        }}
        onKeyDown={(e) => { if (e.key === 'Enter') e.currentTarget.blur(); }}
      />
      {problem && <p className="sbx-field__error" id={`${id}-err`} role="alert">{problem}</p>}
      {hint && <p className="sbx-hint">{hint}</p>}
    </Field>
  );
}

export function ClassNamesField({ id, value, onChange }) {
  const list = asList(value);
  return (
    <CommitInput
      id={id}
      label={t('classes_label')}
      value={list.join(' ')}
      placeholder={t('classes_placeholder')}
      hint={t('classes_hint')}
      check={(text) => (text.split(/\s+/).every(classTokenOk) ? null : t('class_invalid'))}
      onCommit={(text) => onChange(text.split(/\s+/).filter(Boolean))}
    />
  );
}

/** ID, ARIA label and role. `doc` is the working document, for the duplicate-id check. */
/** `part`: 'id' shows only the CSS ID; 'a11y' only the aria-label and role; omitted shows all three. */
export function IdentityFields({ block, doc, onChange, part }) {
  const id = useId();
  const g = groupAttributes(block.attributes);
  const roleKnown = g.role === '' || ROLES.includes(g.role);
  const set = (name, value) => onChange(withAttribute(block.attributes, name, value));
  const showId = part !== 'a11y';
  const showA11y = part !== 'id';
  return (
    <>
      {showId && (
      <CommitInput
        id={`${id}-id`}
        label={t('attr_id')}
        value={g.id}
        placeholder={'pricing'}
        hint={t('attr_id_hint')}
        check={(text) => {
          if (!idFormatOk(text)) return t('attr_id_bad');
          return idOwner(doc, text, block.id) ? t('attr_id_taken') : null;
        }}
        onCommit={(text) => set('id', text)}
      />
      )}
      {showA11y && (
      <>
      <CommitInput
        id={`${id}-label`}
        label={t('attr_aria_label')}
        value={g.ariaLabel}
        hint={t('attr_aria_label_hint')}
        check={(text) => (attributeIssue('aria-label', text) ? t('attr_invalid') : null)}
        onCommit={(text) => set('aria-label', text)}
      />
      <Field label={t('attr_role')} htmlFor={`${id}-role`}>
        <select id={`${id}-role`} value={g.role} onChange={(e) => set('role', e.target.value)}>
          <option value="">{t('attr_role_none')}</option>
          {!roleKnown && <option value={g.role}>{g.role}</option>}
          {ROLES.map((r) => <option key={r} value={r}>{r}</option>)}
        </select>
      </Field>
      </>
      )}
    </>
  );
}

/** `data-*` attributes as name/value rows, plus the attributes the dedicated fields do not show. */
export function DataAttributes({ block, onChange }) {
  const id = useId();
  const g = groupAttributes(block.attributes);
  const [name, setName] = useState('');
  const [value, setValue] = useState('');
  const [problem, setProblem] = useState(null);
  const set = (key, v) => onChange(withAttribute(block.attributes, key, v));
  const add = () => {
    const key = `data-${name.trim().toLowerCase()}`;
    const issue = name.trim() === '' ? t('attr_invalid') : (attributeIssue(key, value) ? t('attr_invalid') : null);
    setProblem(issue);
    if (issue) return;
    set(key, value);
    setName('');
    setValue('');
  };
  return (
    <>
      <p className="sbx-hint">{t('attributes_hint')}</p>
      <ul className="sbx-attr-list" aria-label={t('section_data_attributes')}>
        {g.data.map(([key, v]) => (
          <li key={key} className="sbx-attr-row">
            <code>{key}</code>
            <input
              type="text"
              className="sbx-input"
              aria-label={`${key} ${t('attr_value')}`}
              defaultValue={v}
              key={v}
              onBlur={(e) => { if (e.target.value !== v && !attributeIssue(key, e.target.value)) set(key, e.target.value); }}
              onKeyDown={(e) => { if (e.key === 'Enter') e.currentTarget.blur(); }}
            />
            <button type="button" className="sbx-btn sbx-btn--xs" aria-label={`${t('attr_remove')} ${key}`} onClick={() => set(key, undefined)}>×</button>
          </li>
        ))}
      </ul>
      <div className="sbx-attr-add">
        <Field label={t('attr_data_name')} htmlFor={`${id}-name`}>
          <div className="sbx-attr-add__name"><span aria-hidden="true">data-</span><input id={`${id}-name`} type="text" className="sbx-input" value={name} placeholder={'track'} aria-invalid={problem ? true : undefined} onChange={(e) => { setName(e.target.value); setProblem(null); }} /></div>
        </Field>
        <Field label={t('attr_value')} htmlFor={`${id}-value`}>
          <input id={`${id}-value`} type="text" className="sbx-input" value={value} onChange={(e) => { setValue(e.target.value); setProblem(null); }} onKeyDown={(e) => { if (e.key === 'Enter') add(); }} />
        </Field>
        <button type="button" className="sbx-btn sbx-btn--xs" onClick={add}>{t('attr_add')}</button>
        {problem && <p className="sbx-field__error" role="alert">{problem}</p>}
      </div>
      {g.other.length > 0 && (
        <fieldset className="sbx-fieldset">
          <legend>{t('attr_other')}</legend>
          <ul className="sbx-attr-list">
            {g.other.map(([key, v]) => (
              <li key={key} className="sbx-attr-row">
                <code>{key}</code>
                <span className="sbx-muted">{v}</span>
                <button type="button" className="sbx-btn sbx-btn--xs" aria-label={`${t('attr_remove')} ${key}`} onClick={() => set(key, undefined)}>×</button>
              </li>
            ))}
          </ul>
        </fieldset>
      )}
    </>
  );
}
