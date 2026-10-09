// SurfaceControls — the Inspector panes for the closed style surface (B2-P3): layout, spacing, position,
// effects, interaction states, and the extras of border / shadow / dimensions / typography.
//
// Every pane takes `style` and `onChange(nextStyle)`, reads and writes by dotted path (core/styleSurface.mjs),
// and validates a typed value with the same table the server uses, so nothing it offers can make a block
// "unavailable". A cleared field is removed, and a group left empty is removed with it.

import { useId, useState } from 'react';
import { ColorField, DraftText, unitOf } from './StyleControls.jsx';
import { Icon, choiceIcon } from './InspectorIcons.jsx';
import { asObject } from '../../core/doc.mjs';
import { CORNERS, FILTER, OPTIONS, SIDES, TRANSFORM, getPath, setPath, setPaths } from '../../core/styleSurface.mjs';
import { t } from '../../core/messages.mjs';
import { optionLabel } from '../../core/optionLabels.mjs';

const optLabel = optionLabel;
const pathId = (id, path) => `${id}-${path.replace(/\./g, '-')}`;

/** get / put helpers over one style (or state) object. */
function useFields(style, onChange) {
  return {
    get: (path) => getPath(style, path),
    put: (path, value) => onChange(setPath(style, path, value)),
    putAll: (pairs) => onChange(setPaths(style, pairs)),
  };
}

// ── Primitives ──────────────────────────────────────────────────────────────

/** A row of exclusive choices. Choosing the active one again clears it (back to the default). */
export function Segmented({ label, value, options, onChange }) {
  return (
    <div className="sbx-field">
      <span className="sbx-field__label">{label}</span>
      <div className="sbx-segmented-pills" role="group" aria-label={label}>
        {options.map((o) => (
          <button
            key={o}
            type="button"
            className={`sbx-segmented-pill${value === o ? ' is-active' : ''}`}
            aria-pressed={value === o}
            onClick={() => onChange(value === o ? undefined : o)}
          >
            {optLabel(o)}
          </button>
        ))}
      </div>
    </div>
  );
}

/**
 * A row of icon-only toggles (direction, justify, align…): the name of each option is its tooltip and accessible
 * name, so a screen reader hears "Column" while a sighted author sees an arrow. Pressing the active one clears it.
 */
export function IconChoice({ label, kind, value, options, onChange }) {
  return (
    <div className="sbx-field sbx-field--choice">
      <span className="sbx-field__label">{label}</span>
      <div className="sbx-iconchoice" role="group" aria-label={label}>
        {options.map((o) => (
          <button
            key={o}
            type="button"
            className={`sbx-iconchoice__btn${value === o ? ' is-active' : ''}`}
            aria-pressed={value === o}
            aria-label={optLabel(o)}
            title={optLabel(o)}
            onClick={() => onChange(value === o ? undefined : o)}
          >
            <Icon name={choiceIcon(kind, o)} size={16} />
          </button>
        ))}
      </div>
    </div>
  );
}

export function SelectField({ id, label, value, options, onChange, emptyLabel }) {
  return (
    <div className="sbx-field">
      <label className="sbx-field__label" htmlFor={id}>{label}</label>
      <select id={id} value={value ?? ''} onChange={(e) => onChange(e.target.value || undefined)}>
        <option value="">{emptyLabel ?? t('inherit')}</option>
        {options.map((o) => <option key={o} value={o}>{optLabel(o)}</option>)}
      </select>
    </div>
  );
}

/** A range with a readout. The value equal to `neutral` means "not set" and is removed from the style. */
export function SliderField({ id, label, value, min, max, step = 1, unit = '', neutral, onChange }) {
  const shown = value ?? neutral;
  const commit = (raw) => {
    const v = Math.max(min, Math.min(max, Number(raw)));
    if (Number.isFinite(v)) onChange(v === neutral ? undefined : v);
  };
  return (
    <div className="sbx-field">
      <label className="sbx-field__label" htmlFor={id}>{label}</label>
      <div className="sbx-slider">
        <input id={id} type="range" min={min} max={max} step={step} value={shown} onChange={(e) => commit(e.target.value)} />
        <span className="sbx-slider__num">
          <input
            type="number"
            className="sbx-input"
            aria-label={`${label} (${t('field_value')})`}
            min={min}
            max={max}
            step={step}
            value={shown}
            onChange={(e) => { if (e.target.value !== '') commit(e.target.value); }}
          />
          {unit ? <span className="sbx-slider__unit" aria-hidden="true">{unit}</span> : null}
        </span>
      </div>
    </div>
  );
}

/** A whole-number input within bounds; empty clears it. */
export function IntField({ id, label, value, min, max, onChange }) {
  return (
    <div className="sbx-field">
      <label className="sbx-field__label" htmlFor={id}>{label}</label>
      <input
        id={id}
        type="number"
        className="sbx-input"
        min={min}
        max={max}
        step="1"
        value={value ?? ''}
        onChange={(e) => {
          if (e.target.value === '') { onChange(undefined); return; }
          const n = parseInt(e.target.value, 10);
          if (!Number.isNaN(n)) onChange(Math.max(min, Math.min(max, n)));
        }}
      />
    </div>
  );
}

/** A length typed by the author and checked against the server's table for `path`. */
/** Units offered next to a length: the ones a layout gap or size is normally written in. */
const LENGTH_UNITS = ['px', 'rem', 'em', '%'];

function Length({ id, path, label, get, put, placeholder, units }) {
  return <DraftText id={pathId(id, path)} path={path} label={label} value={get(path)} placeholder={placeholder} units={units} onCommit={(v) => put(path, v)} />;
}

/** Four sides of margin or padding, optionally linked so one value sets all four. */
export function SpacingBox({ id, group, label, get, putAll, placeholder = '0' }) {
  const values = SIDES.map((s) => get(`${group}.${s}`));
  const [linked, setLinked] = useState(() => values.every((v) => v === values[0]));
  const shown = values.map(unitOf).find(Boolean);
  const [unit, setUnit] = useState(shown || 'px');
  // One unit for all four sides, like the reference: a bare number takes it, and choosing another converts what is set.
  const chooseUnit = (next) => {
    setUnit(next);
    const converted = SIDES.map((side, i) => [`${group}.${side}`, unitOf(values[i]) ? values[i].replace(/[a-z%]+$/i, next) : values[i]]);
    if (converted.some(([, v], i) => v !== values[i])) putAll(converted);
  };
  return (
    <fieldset className="sbx-fieldset sbx-spacing sbx-spacing--box">
      <legend>{label}</legend>
      <div className="sbx-spacing__units" role="group" aria-label={`${label} (${t('unit')})`}>
        {['px', '%', 'em', 'rem'].map((u) => (
          <button key={u} type="button" className={`sbx-spacing__unit${unit === u ? ' is-active' : ''}`} aria-pressed={unit === u} onClick={() => chooseUnit(u)}>{u}</button>
        ))}
      </div>
      <div className="sbx-spacing__grid">
        {SIDES.map((side) => (
          <DraftText
            key={side}
            id={pathId(id, `${group}.${side}`)}
            path={`${group}.${side}`}
            label={t(`side_${side}`)}
            value={get(`${group}.${side}`)}
            placeholder={placeholder}
            bareUnit={unit}
            onCommit={(v) => putAll((linked ? SIDES : [side]).map((s) => [`${group}.${s}`, v]))}
          />
        ))}
        <button
          type="button"
          className="sbx-iconbtn sbx-spacing__link"
          aria-pressed={linked}
          onClick={() => setLinked((v) => !v)}
        >
          <Icon name="link" size={14} />
          <span className="sbx-sr-only">{linked ? t('sides_linked') : t('sides_unlinked')}</span>
        </button>
      </div>
    </fieldset>
  );
}

// ── Layout ──────────────────────────────────────────────────────────────────

export function LayoutPane({ style, onChange }) {
  const id = useId();
  const f = useFields(style, onChange);
  const display = f.get('layout.display');
  const flex = display === 'flex' || display === 'inline-flex';
  const grid = display === 'grid';
  return (
    <>
      <Segmented label={t('layout_display')} value={display} options={OPTIONS.display} onChange={(v) => f.put('layout.display', v)} />
      {flex && (
        <>
          <IconChoice label={t('layout_direction')} kind="dir" value={f.get('layout.direction')} options={OPTIONS.direction} onChange={(v) => f.put('layout.direction', v)} />
          <IconChoice label={t('layout_wrap')} kind="wrap" value={f.get('layout.wrap')} options={OPTIONS.wrap} onChange={(v) => f.put('layout.wrap', v)} />
          <IconChoice label={t('layout_justify')} kind="justify" value={f.get('layout.justify')} options={OPTIONS.justify} onChange={(v) => f.put('layout.justify', v)} />
          <IconChoice label={t('layout_align')} kind="items" value={f.get('layout.align')} options={OPTIONS.align} onChange={(v) => f.put('layout.align', v)} />
        </>
      )}
      {grid && (
        <>
          <IntField id={pathId(id, 'layout.columns')} label={t('layout_columns')} value={f.get('layout.columns')} min={1} max={12} onChange={(v) => f.put('layout.columns', v)} />
          <IntField id={pathId(id, 'layout.rows')} label={t('layout_rows')} value={f.get('layout.rows')} min={1} max={12} onChange={(v) => f.put('layout.rows', v)} />
          <IconChoice label={t('layout_align')} kind="items" value={f.get('layout.align')} options={OPTIONS.align} onChange={(v) => f.put('layout.align', v)} />
        </>
      )}
      {(flex || grid) && (
        <>
          <Length id={id} units={LENGTH_UNITS} path="layout.gap" label={t('layout_gap')} get={f.get} put={f.put} placeholder="1rem" />
          <Length id={id} units={LENGTH_UNITS} path="layout.row_gap" label={t('layout_row_gap')} get={f.get} put={f.put} placeholder="1rem" />
          <Length id={id} units={LENGTH_UNITS} path="layout.column_gap" label={t('layout_column_gap')} get={f.get} put={f.put} placeholder="1rem" />
        </>
      )}
      <fieldset className="sbx-fieldset">
        <legend>{t('layout_as_item')}</legend>
        <IntField id={pathId(id, 'layout.order')} label={t('layout_order')} value={f.get('layout.order')} min={-99} max={99} onChange={(v) => f.put('layout.order', v)} />
        <IntField id={pathId(id, 'layout.grow')} label={t('layout_grow')} value={f.get('layout.grow')} min={0} max={10} onChange={(v) => f.put('layout.grow', v)} />
        <IntField id={pathId(id, 'layout.shrink')} label={t('layout_shrink')} value={f.get('layout.shrink')} min={0} max={10} onChange={(v) => f.put('layout.shrink', v)} />
        <Length id={id} units={LENGTH_UNITS} path="layout.basis" label={t('layout_basis')} get={f.get} put={f.put} placeholder={'auto'} />
      </fieldset>
    </>
  );
}

// ── Spacing and position ────────────────────────────────────────────────────

export function SpacingPane({ style, onChange }) {
  const id = useId();
  const f = useFields(style, onChange);
  return (
    <>
      <SpacingBox id={id} group="margin" label={t('margin_label')} get={f.get} putAll={f.putAll} />
      <SpacingBox id={id} group="padding" label={t('padding_label')} get={f.get} putAll={f.putAll} />
    </>
  );
}

export function PositionPane({ style, onChange }) {
  const id = useId();
  const f = useFields(style, onChange);
  const mode = f.get('position.mode');
  return (
    <>
      <SelectField id={pathId(id, 'position.mode')} label={t('position_mode')} value={mode} options={OPTIONS.position.filter((m) => m !== 'static')} emptyLabel={optLabel('static')} onChange={(v) => f.put('position.mode', v)} />
      {mode && mode !== 'static' && (
        <fieldset className="sbx-fieldset sbx-spacing">
          <legend>{t('position_offsets')}</legend>
          <div className="sbx-spacing__grid">
            {SIDES.map((s) => <Length key={s} id={id} path={`position.${s}`} label={t(`side_${s}`)} get={f.get} put={f.put} placeholder={'auto'} />)}
          </div>
        </fieldset>
      )}
      <p className="sbx-hint">{t('position_hint')}</p>
    </>
  );
}

// ── Effects ─────────────────────────────────────────────────────────────────

const FILTER_NEUTRAL = { blur: 0, brightness: 100, contrast: 100, saturate: 100, grayscale: 0 };

export function EffectsPane({ style, onChange }) {
  const id = useId();
  const f = useFields(style, onChange);
  const slider = (path, label, [min, max, unit], neutral, step = 1) => (
    <SliderField key={path} id={pathId(id, path)} label={label} value={f.get(path)} min={min} max={max} step={step} unit={unit} neutral={neutral} onChange={(v) => f.put(path, v)} />
  );
  return (
    <>
      <fieldset className="sbx-fieldset">
        <legend>{t('effects_transform')}</legend>
        {slider('effects.transform.rotate', t('fx_rotate'), TRANSFORM.rotate, 0)}
        {slider('effects.transform.scale', t('fx_scale'), TRANSFORM.scale, 1, 0.05)}
        {slider('effects.transform.skew_x', t('fx_skew_x'), TRANSFORM.skew_x, 0)}
        {slider('effects.transform.skew_y', t('fx_skew_y'), TRANSFORM.skew_y, 0)}
        <Length id={id} path="effects.transform.translate_x" label={t('fx_translate_x')} get={f.get} put={f.put} placeholder="0" />
        <Length id={id} path="effects.transform.translate_y" label={t('fx_translate_y')} get={f.get} put={f.put} placeholder="0" />
      </fieldset>
      <fieldset className="sbx-fieldset">
        <legend>{t('effects_filter')}</legend>
        {Object.entries(FILTER).map(([name, range]) => slider(`effects.filter.${name}`, t(`fx_${name}`), range, FILTER_NEUTRAL[name]))}
        {slider('effects.backdrop_blur', t('fx_backdrop_blur'), [0, 50, 'px'], 0)}
      </fieldset>
      <SelectField id={pathId(id, 'effects.blend')} label={t('fx_blend')} value={f.get('effects.blend')} options={OPTIONS.blend} onChange={(v) => f.put('effects.blend', v === 'normal' ? undefined : v)} />
      <SelectField id={pathId(id, 'effects.cursor')} label={t('fx_cursor')} value={f.get('effects.cursor')} options={OPTIONS.cursor} onChange={(v) => f.put('effects.cursor', v)} />
      <fieldset className="sbx-fieldset">
        <legend>{t('effects_transition')}</legend>
        <SliderField id={pathId(id, 'effects.transition.duration_ms')} label={t('fx_duration')} value={f.get('effects.transition.duration_ms')} min={0} max={2000} step={50} unit="ms" neutral={0} onChange={(v) => f.put('effects.transition.duration_ms', v)} />
        <SelectField id={pathId(id, 'effects.transition.easing')} label={t('fx_easing')} value={f.get('effects.transition.easing')} options={OPTIONS.easing} onChange={(v) => f.put('effects.transition.easing', v)} />
      </fieldset>
    </>
  );
}

// ── Extras that sit inside existing sections ────────────────────────────────

export function BorderExtras({ style, onChange }) {
  const id = useId();
  const f = useFields(style, onChange);
  return (
    <>
      <fieldset className="sbx-fieldset sbx-spacing">
        <legend>{t('border_corners')}</legend>
        <div className="sbx-spacing__grid">
          {CORNERS.map((c) => <Length key={c} id={id} path={`border.radius_corners.${c}`} label={t(`corner_${c}`)} get={f.get} put={f.put} placeholder="0" />)}
        </div>
      </fieldset>
      <details className="sbx-more">
        <summary>{t('border_per_side')}</summary>
        {SIDES.map((s) => (
          <fieldset key={s} className="sbx-fieldset">
            <legend>{t(`side_${s}`)}</legend>
            <Length id={id} path={`border.${s}.width`} label={t('border_width')} get={f.get} put={f.put} placeholder="1px" />
            <SelectField id={pathId(id, `border.${s}.style`)} label={t('border_style')} value={f.get(`border.${s}.style`)} options={OPTIONS.borderStyle} onChange={(v) => f.put(`border.${s}.style`, v)} />
            <ColorField id={pathId(id, `border.${s}.color`)} label={t('border_color')} value={f.get(`border.${s}.color`)} onChange={(v) => f.put(`border.${s}.color`, v)} />
          </fieldset>
        ))}
      </details>
    </>
  );
}

const NEW_SHADOW = Object.freeze({ x: '0', y: '4px', blur: '12px', color: 'rgba(0,0,0,0.2)' });

/** The custom shadow object ({x, y, blur, spread, color, inset}); presets stay in the existing select. */
export function ShadowExtras({ style, onChange }) {
  const id = useId();
  const shadow = style.shadow;
  const custom = shadow && typeof shadow === 'object' && !Array.isArray(shadow);
  const f = useFields(style, onChange);
  if (!custom) {
    return (
      <button type="button" className="sbx-btn sbx-btn--xs" onClick={() => onChange({ ...style, shadow: { ...NEW_SHADOW } })}>{t('shadow_custom')}</button>
    );
  }
  // x, y and colour are required by the server; clearing one resets it rather than leaving a shadow it would refuse.
  const required = (path, fallback) => (v) => f.put(path, v ?? fallback);
  return (
    <fieldset className="sbx-fieldset">
      <legend>{t('shadow_custom')}</legend>
      <Length id={id} path="shadow.x" label={t('shadow_x')} get={f.get} put={(p, v) => required(p, NEW_SHADOW.x)(v)} />
      <Length id={id} path="shadow.y" label={t('shadow_y')} get={f.get} put={(p, v) => required(p, NEW_SHADOW.y)(v)} />
      <Length id={id} path="shadow.blur" label={t('shadow_blur')} get={f.get} put={f.put} />
      <Length id={id} path="shadow.spread" label={t('shadow_spread')} get={f.get} put={f.put} />
      <ColorField id={pathId(id, 'shadow.color')} label={t('shadow_color')} value={shadow.color} onChange={required('shadow.color', NEW_SHADOW.color)} />
      <label className="sbx-field sbx-field--check">
        <input type="checkbox" checked={shadow.inset === true} onChange={(e) => f.put('shadow.inset', e.target.checked ? true : undefined)} />
        <span>{t('shadow_inset')}</span>
      </label>
      <button type="button" className="sbx-btn sbx-btn--xs" onClick={() => onChange(setPath(style, 'shadow', undefined))}>{t('shadow_back')}</button>
    </fieldset>
  );
}

export function DimensionsExtras({ style, onChange, media }) {
  const id = useId();
  const f = useFields(style, onChange);
  return (
    <>
      <Length id={id} path="dimensions.min_width" label={t('dim_min_width')} get={f.get} put={f.put} placeholder="10rem" />
      <Length id={id} path="dimensions.max_height" label={t('dim_max_height')} get={f.get} put={f.put} placeholder="40rem" />
      <DraftText id={pathId(id, 'dimensions.aspect_ratio')} path="dimensions.aspect_ratio" label={t('dim_aspect_ratio')} value={f.get('dimensions.aspect_ratio')} placeholder="16/9" onCommit={(v) => f.put('dimensions.aspect_ratio', v)} />
      <SelectField id={pathId(id, 'dimensions.overflow')} label={t('dim_overflow')} value={f.get('dimensions.overflow')} options={OPTIONS.overflow} onChange={(v) => f.put('dimensions.overflow', v)} />
      {media && (
        <>
          <SelectField id={pathId(id, 'dimensions.object_fit')} label={t('dim_object_fit')} value={f.get('dimensions.object_fit')} options={OPTIONS.objectFit} onChange={(v) => f.put('dimensions.object_fit', v)} />
          <SelectField id={pathId(id, 'dimensions.object_position')} label={t('dim_object_position')} value={f.get('dimensions.object_position')} options={OPTIONS.objectPosition} onChange={(v) => f.put('dimensions.object_position', v)} />
        </>
      )}
    </>
  );
}

export function TypographyExtras({ style, onChange }) {
  const id = useId();
  const f = useFields(style, onChange);
  const deco = f.get('typography.decoration');
  return (
    <>
      <Segmented label={t('font_style')} value={f.get('typography.style')} options={OPTIONS.fontStyle} onChange={(v) => f.put('typography.style', v === 'normal' ? undefined : v)} />
      <SelectField id={pathId(id, 'typography.decoration')} label={t('text_decoration')} value={deco} options={OPTIONS.decoration} onChange={(v) => f.put('typography.decoration', v)} />
      {deco && deco !== 'none' && (
        <>
          <SelectField id={pathId(id, 'typography.decoration_style')} label={t('deco_style')} value={f.get('typography.decoration_style')} options={OPTIONS.decorationStyle} onChange={(v) => f.put('typography.decoration_style', v)} />
          <ColorField id={pathId(id, 'typography.decoration_color')} label={t('deco_color')} value={f.get('typography.decoration_color')} onChange={(v) => f.put('typography.decoration_color', v)} />
          <Length id={id} path="typography.decoration_thickness" label={t('deco_thickness')} get={f.get} put={f.put} placeholder="2px" />
          <Length id={id} path="typography.decoration_offset" label={t('deco_offset')} get={f.get} put={f.put} placeholder="3px" />
        </>
      )}
    </>
  );
}

// ── Interaction states ──────────────────────────────────────────────────────

const STATE_TABS = ['normal', ...OPTIONS.states];

/**
 * Hover / focus / active / disabled. "Normal" is the base style (the sections above); a state holds only what
 * differs: text colour, background, border colour, opacity and a transform. Transition and cursor belong to the
 * base style, so they are not offered here.
 */
export function StatesPane({ states, onChange }) {
  const id = useId();
  const [tab, setTab] = useState('normal');
  const all = asObject(states);
  const state = asObject(all[tab]);
  const f = {
    get: (path) => getPath(state, path),
    put: (path, value) => {
      const next = setPath(state, path, value);
      const merged = { ...all };
      if (Object.keys(next).length === 0) delete merged[tab]; else merged[tab] = next;
      onChange(merged);
    },
  };
  return (
    <>
      <div className="sbx-segmented-pills" role="tablist" aria-label={t('section_states')}>
        {STATE_TABS.map((s) => (
          <button
            key={s}
            type="button"
            role="tab"
            aria-selected={tab === s}
            className={`sbx-segmented-pill${tab === s ? ' is-active' : ''}${s !== 'normal' && all[s] ? ' has-value' : ''}`}
            onClick={() => setTab(s)}
          >
            {t(`state_${s}`)}
          </button>
        ))}
      </div>
      {tab === 'normal' ? (
        <p className="sbx-hint">{t('state_normal_hint')}</p>
      ) : (
        <>
          <ColorField id={pathId(id, `${tab}.color`)} label={t('state_text_color')} value={f.get('color')} onChange={(v) => f.put('color', v)} />
          <ColorField id={pathId(id, `${tab}.bg`)} label={t('background_color')} value={f.get('background.color')} onChange={(v) => f.put('background.color', v)} />
          <ColorField id={pathId(id, `${tab}.border`)} label={t('border_color')} value={f.get('border.color')} onChange={(v) => f.put('border.color', v)} />
          <SliderField id={pathId(id, `${tab}.opacity`)} label={t('opacity_label')} value={f.get('opacity')} min={0} max={1} step={0.05} neutral={1} onChange={(v) => f.put('opacity', v)} />
          <SliderField id={pathId(id, `${tab}.scale`)} label={t('fx_scale')} value={f.get('effects.transform.scale')} min={0} max={5} step={0.05} neutral={1} onChange={(v) => f.put('effects.transform.scale', v)} />
          <SliderField id={pathId(id, `${tab}.rotate`)} label={t('fx_rotate')} value={f.get('effects.transform.rotate')} min={-360} max={360} step={1} unit="°" neutral={0} onChange={(v) => f.put('effects.transform.rotate', v)} />
          {tab === 'disabled' && <p className="sbx-hint">{t('state_disabled_hint')}</p>}
          {all[tab] && (
            <button type="button" className="sbx-btn sbx-btn--xs" onClick={() => { const m = { ...all }; delete m[tab]; onChange(m); }}>{t('state_clear')}</button>
          )}
        </>
      )}
    </>
  );
}
