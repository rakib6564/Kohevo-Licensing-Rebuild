// ConditionDialog — where a header, footer or template applies (Theme Builder display conditions).
//
// Rows of Include / Exclude + a kind (+ a value for some kinds). The rules are saved in `settings.conditions` with the
// same update_settings operation as every other page setting; the server validates them again and its matcher reads them.
// A rule this dialog cannot express stays as a read-only "Custom rule" row and is saved back unchanged.

import { useId, useState } from 'react';
import { Dialog } from './Dialog.jsx';
import { t } from '../core/messages.mjs';
import { CONTENT_TYPES, KINDS, MAX_RULES, fromRows, rowProblem, setProblem } from '../core/conditions.mjs';

const kindInput = (kind) => (KINDS.find((k) => k.id === kind) || {}).input || null;

function Row({ row, index, onChange, onRemove }) {
  const id = useId();
  const input = kindInput(row.kind);
  const problem = rowProblem(row);
  if (row.kind === 'custom') {
    return (
      <li className="sbx-cond__row" data-testid="cond-row" data-kind="custom">
        <span className="sbx-cond__custom">{t('cond_custom')}</span>
        <button type="button" className="sbx-btn sbx-btn--ghost sbx-btn--xs" aria-label={t('cond_remove')} onClick={onRemove}>✕</button>
      </li>
    );
  }
  return (
    <li className="sbx-cond__row" data-testid="cond-row" data-kind={row.kind}>
      <select aria-label={t('cond_action')} data-cond="action" value={row.action} onChange={(e) => onChange({ ...row, action: e.target.value })}>
        <option value="include">{t('cond_include')}</option>
        <option value="exclude">{t('cond_exclude')}</option>
      </select>
      <select aria-label={t('cond_kind')} data-cond="kind" value={row.kind} onChange={(e) => onChange({ ...row, kind: e.target.value, value: e.target.value === 'singular' ? 'all' : '' })}>
        {KINDS.map((k) => <option key={k.id} value={k.id}>{t(`cond_kind_${k.id}`)}</option>)}
      </select>
      {input === 'content_type' && (
        <select aria-label={t('cond_value')} data-cond="value" value={CONTENT_TYPES.includes(row.value) ? row.value : 'all'} onChange={(e) => onChange({ ...row, value: e.target.value })}>
          {CONTENT_TYPES.map((c) => <option key={c} value={c}>{t(`cond_type_${c}`)}</option>)}
        </select>
      )}
      {(input === 'text' || input === 'number' || input === 'optional_text') && (
        <input
          id={`${id}-v`}
          type="text"
          inputMode={input === 'number' ? 'numeric' : undefined}
          data-cond="value"
          aria-label={t('cond_value')}
          aria-invalid={problem ? 'true' : undefined}
          placeholder={t(`cond_ph_${row.kind}`)}
          maxLength={100}
          value={row.value}
          onChange={(e) => onChange({ ...row, value: e.target.value })}
        />
      )}
      <button type="button" className="sbx-btn sbx-btn--ghost sbx-btn--xs" aria-label={t('cond_remove')} data-testid="cond-remove" onClick={onRemove}>✕</button>
      {problem && <p className="sbx-field__error sbx-cond__problem" role="alert">{t(problem)}</p>}
    </li>
  );
}

export function ConditionDialog({ initialRows, onClose, onSave }) {
  const [rows, setRows] = useState(initialRows);
  const problem = setProblem(rows);
  const update = (i, next) => setRows((list) => list.map((r, j) => (j === i ? next : r)));
  return (
    <Dialog
      title={t('cond_title')}
      onClose={onClose}
      className="sbx-dialog--conditions"
      footer={(
        <>
          <button type="button" className="sbx-btn" onClick={onClose}>{t('cond_cancel')}</button>
          <button type="button" className="sbx-btn sbx-btn--primary" data-testid="cond-save" disabled={!!problem} onClick={() => onSave(fromRows(rows))}>{t('cond_save')}</button>
        </>
      )}
    >
      <div className="sbx-cond" data-testid="condition-dialog">
        <p className="sbx-hint">{t('cond_intro')}</p>
        {rows.length === 0 && <p className="sbx-hint" data-testid="cond-empty">{t('cond_none')}</p>}
        <ul className="sbx-cond__list">
          {rows.map((row, i) => (
            <Row key={i} row={row} index={i} onChange={(next) => update(i, next)} onRemove={() => setRows((list) => list.filter((_, j) => j !== i))} />
          ))}
        </ul>
        {problem && rows.length > 0 && !rows.some((r) => rowProblem(r)) && <p className="sbx-field__error" role="alert">{t(problem)}</p>}
        <button type="button" className="sbx-btn sbx-btn--xs" data-testid="cond-add" disabled={rows.length >= MAX_RULES} onClick={() => setRows((list) => [...list, { action: 'include', kind: 'entire_site', value: '' }])}>{t('cond_add')}</button>
      </div>
    </Dialog>
  );
}
