// Friendlier controls for a few container props (see core/propPresenters.mjs): icon toggles, pills and a
// column-count picker. Each writes exactly what the plain select would, so the server sees the same values.

import { useId } from 'react';
import { Icon, choiceIcon } from '../inspectors/InspectorIcons.jsx';
import { t } from '../../core/messages.mjs';
import { Field, Pills } from '../ui/index.js';

const labelOfDefault = (value) => {
  const key = `opt_${String(value).replace(/-/g, '_')}`;
  const text = t(key);
  return text && text !== key ? text : String(value).replace(/_/g, ' ');
};

/** A choice with a default: the default shows as active until another is picked; picking the active one resets it. */
function useChoice(field, value, onChange) {
  const options = Array.isArray(field.allowed_values) ? field.allowed_values : [];
  const current = value ?? field.default ?? null;
  const pick = (o) => onChange(o === field.default || o === value ? null : o);
  return { options, current, pick };
}

export function PropChoice({ field, value, onChange, presenter, labelOf = null }) {
  const nameOf = labelOf || labelOfDefault;
  const { options, current, pick } = useChoice(field, value, onChange);
  const asIcons = !!presenter.icons;
  return (
    <Field label={field.label} variant="choice">
      {asIcons ? (
        <div className="sbx-iconchoice" role="group" aria-label={field.label}>
          {options.map((o) => (
            <button
              key={o}
              type="button"
              className={`sbx-iconchoice__btn${current === o ? ' is-active' : ''}`}
              aria-pressed={current === o}
              aria-label={nameOf(o)}
              title={nameOf(o)}
              onClick={() => pick(o)}
            >
              <Icon name={choiceIcon(presenter.icons, presenter.map?.[o] ?? o)} size={16} />
            </button>
          ))}
        </div>
      ) : (
        <Pills label={field.label} value={current} onChange={pick} options={options.map((o) => ({ value: o, label: nameOf(o), title: nameOf(o) }))} />
      )}
    </Field>
  );
}

/** A few column drawings for the common counts, and a number box for the rest (up to the field's max). */
export function PropTiles({ field, value, onChange, presenter }) {
  const id = useId();
  const current = Number.isInteger(value) ? value : (field.default ?? 2);
  const min = field.min ?? 1;
  const max = field.max ?? 12;
  return (
    <div className="sbx-field sbx-field--stack">
      <span className="sbx-field__label" id={`${id}-l`}>{field.label}</span>
      <div className="sbx-coltiles" role="group" aria-labelledby={`${id}-l`}>
        {presenter.tiles.filter((n) => n >= min && n <= max).map((n) => (
          <button
            key={n}
            type="button"
            className={`sbx-coltile${current === n ? ' is-active' : ''}`}
            aria-pressed={current === n}
            aria-label={`${n}`}
            title={`${n}`}
            onClick={() => onChange(n === field.default ? null : n)}
          >
            <span className="sbx-coltile__cols" aria-hidden="true" style={{ gridTemplateColumns: `repeat(${n}, 1fr)` }}>
              {Array.from({ length: n }, (_, i) => <i key={i} />)}
            </span>
          </button>
        ))}
        <input
          type="number"
          className="sbx-coltiles__num"
          aria-label={`${field.label} (${t('field_value')})`}
          min={min}
          max={max}
          step={1}
          value={current}
          onChange={(e) => {
            const n = Math.round(Number(e.target.value));
            if (Number.isFinite(n) && n >= min && n <= max) onChange(n === field.default ? null : n);
          }}
        />
      </div>
    </div>
  );
}
