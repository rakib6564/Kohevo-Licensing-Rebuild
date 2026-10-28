// SurfaceControls — the Inspector panes for the closed style surface (B2-P3): layout, spacing, position,
// effects, interaction states, and the extras of border / shadow / dimensions / typography.
//
// Every pane takes `style` and `onChange(nextStyle)`, reads and writes by dotted path (core/styleSurface.mjs),
// and validates a typed value with the same table the server uses, so nothing it offers can make a block
// "unavailable". A cleared field is removed, and a group left empty is removed with it.

import { useId, useState } from 'react';
import { ColorField, DraftText, unitOf } from './StyleControls.jsx';
import { Icon, choiceIcon } from './InspectorIcons.jsx';
import { Field, Pills, Check } from '../ui/index.js';
import { asObject } from '../../core/doc.mjs';
import { RESPONSIVE_SCOPES } from '../../core/responsiveStyle.mjs';
import { DeviceStyle } from './DeviceStyle.jsx';
import { CORNERS, FILTER, OPTIONS, SIDES, TRANSFORM, getPath, isColourToken, setPath, setPaths } from '../../core/styleSurface.mjs';
import { t } from '../../core/messages.mjs';
import { statesFor } from '../../core/inspectorSections.mjs';
import { optionLabel } from '../../core/optionLabels.mjs';
import { setSpacingToken } from '../../core/tokenStyle.mjs';
import { TokenPicker } from '../fields/TokenPicker.jsx';

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
    <Field label={label}>
      <Pills
        label={label}
        value={value}
        options={options.map((o) => ({ value: o, label: optLabel(o) }))}
        onChange={(o) => onChange(value === o ? undefined : o)}
      />
    </Field>
  );
}

/**
 * A row of icon-only toggles (direction, justify, align…): the name of each option is its tooltip and accessible
 * name, so a screen reader hears "Column" while a sighted author sees an arrow. Pressing the active one clears it.
 */
export function IconChoice({ label, kind, value, options, onChange }) {
  return (
    <Field label={label} variant="choice">
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
    </Field>
  );
}

/** `emptyLabel: null` offers no empty choice (the field always has a value); `optionLabels` names options differently. */
export function SelectField({ id, label, value, options, onChange, emptyLabel, optionLabels = null }) {
  return (
    <Field label={label} htmlFor={id}>
      <select id={id} value={value ?? ''} onChange={(e) => onChange(e.target.value || undefined)}>
        {emptyLabel !== null && <option value="">{emptyLabel ?? t('inherit')}</option>}
        {options.map((o) => <option key={o} value={o}>{(optionLabels && optionLabels[o]) || optLabel(o)}</option>)}
      </select>
    </Field>
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
    <Field label={label} htmlFor={id}>
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
    </Field>
  );
}

/** A whole-number input within bounds; empty clears it. */
export function IntField({ id, label, value, min, max, onChange }) {
  return (
    <Field label={label} htmlFor={id}>
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
    </Field>
  );
}

/** A length typed by the author and checked against the server's table for `path`. */
/** Units offered next to a length: the ones a layout gap or size is normally written in. */
const LENGTH_UNITS = ['px', 'rem', 'em', '%'];

function Length({ id, path, label, get, put, placeholder, units }) {
  return <DraftText id={pathId(id, path)} path={path} label={label} value={get(path)} placeholder={placeholder} units={units} onCommit={(v) => put(path, v)} />;
}

/**
 * A colour field that can also take a theme colour. The stored value is either a literal colour or the token's ref, in the
 * same path (the server writes a token out as the theme's custom property), so a pick replaces the literal and the reverse.
 * With no `tokens` it is the plain colour field.
 */
export function ThemeColor({ id, label, value, onChange, tokens, fallback }) {
  const token = isColourToken(value) ? value : null;
  return (
    <ColorField
      id={id}
      label={label}
      value={token ? undefined : value}
      onChange={onChange}
      tokens={tokens}
      token={token}
      onToken={(ref) => onChange(ref ?? fallback)}
    />
  );
}

/**
 * Four values (the sides of margin or padding; or, with `keys` and `pathOf`, a border's side widths or its corners),
 * optionally linked so one value sets all four.
 */
export function SpacingBox({ id, group, label, get, putAll, placeholder = '0', keys = SIDES, pathOf = null, labelOf = null }) {
  const at = pathOf || ((k) => `${group}.${k}`);
  const nameOf = labelOf || ((k) => t(`side_${k}`));
  const values = keys.map((k) => get(at(k)));
  const [linked, setLinked] = useState(() => values.every((v) => v === values[0]));
  const shown = values.map(unitOf).find(Boolean);
  const [unit, setUnit] = useState(shown || 'px');
  // One unit for all four sides, like the reference: a bare number takes it, and choosing another converts what is set.
  const chooseUnit = (next) => {
    setUnit(next);
    const converted = keys.map((k, i) => [at(k), unitOf(values[i]) ? values[i].replace(/[a-z%]+$/i, next) : values[i]]);
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
        {keys.map((k) => (
          <DraftText
            key={k}
            id={pathId(id, at(k))}
            path={at(k)}
            label={nameOf(k)}
            value={get(at(k))}
            placeholder={placeholder}
            bareUnit={unit}
            onCommit={(v) => putAll((linked ? keys : [k]).map((x) => [at(x), v]))}
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

/**
 * The flex / grid fields of a container: the part of the layout that can differ per device. `style` is the view for the
 * device being edited (the block's own style on desktop, only that device's values on tablet and mobile).
 */
function ContainerFields({ id, style, onChange, flex, grid }) {
  const f = useFields(style, onChange);
  return (
    <>
      {flex && (
        <>
          <IconChoice label={t('layout_direction')} kind="dir" value={f.get('layout.direction')} options={OPTIONS.direction} onChange={(v) => f.put('layout.direction', v)} />
          <IconChoice label={t('layout_wrap')} kind="wrap" value={f.get('layout.wrap')} options={OPTIONS.wrap} onChange={(v) => f.put('layout.wrap', v)} />
          <IconChoice label={t('layout_justify')} kind="justify" value={f.get('layout.justify')} options={OPTIONS.justify} onChange={(v) => f.put('layout.justify', v)} />
          <IconChoice label={t('layout_align')} kind="items" value={f.get('layout.align')} options={OPTIONS.align} onChange={(v) => f.put('layout.align', v)} />
          <SelectField id={pathId(id, 'layout.align_content')} label={t('layout_align_content')} value={f.get('layout.align_content')} options={OPTIONS.alignContent} onChange={(v) => f.put('layout.align_content', v)} />
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
    </>
  );
}

/** How the block sits inside a flex parent: order, grow, shrink, basis and its own alignment. Also per device. */
function ItemFields({ id, style, onChange }) {
  const f = useFields(style, onChange);
  return (
    <>
      <IntField id={pathId(id, 'layout.order')} label={t('layout_order')} value={f.get('layout.order')} min={-99} max={99} onChange={(v) => f.put('layout.order', v)} />
      <IntField id={pathId(id, 'layout.grow')} label={t('layout_grow')} value={f.get('layout.grow')} min={0} max={10} onChange={(v) => f.put('layout.grow', v)} />
      <IntField id={pathId(id, 'layout.shrink')} label={t('layout_shrink')} value={f.get('layout.shrink')} min={0} max={10} onChange={(v) => f.put('layout.shrink', v)} />
      <Length id={id} units={LENGTH_UNITS} path="layout.basis" label={t('layout_basis')} get={f.get} put={f.put} placeholder={'auto'} />
      <SelectField id={pathId(id, 'layout.align_self')} label={t('layout_align_self')} value={f.get('layout.align_self')} options={OPTIONS.alignSelf} onChange={(v) => f.put('layout.align_self', v)} />
    </>
  );
}

/**
 * `defaultDisplay`: what the block is when it has no display of its own (a Container is a flex box from the start).
 * `itemOnly`: only how the block sits in its parent (a Container sets its own layout in its Container section).
 */
export function LayoutPane({ style, onChange, deviceScope, defaultDisplay, itemOnly = false }) {
  const id = useId();
  const f = useFields(style, onChange);
  const display = f.get('layout.display') || defaultDisplay;
  const flex = display === 'flex' || display === 'inline-flex';
  const grid = display === 'grid';
  if (itemOnly) {
    return deviceScope ? (
      <DeviceStyle scope={deviceScope} paths={RESPONSIVE_SCOPES.item} label={t('layout_as_item')}>
        {(view) => <ItemFields id={id} style={view.style} onChange={view.onChange} />}
      </DeviceStyle>
    ) : <ItemFields id={id} style={style} onChange={onChange} />;
  }
  return (
    <>
      <Segmented label={t('layout_display')} value={display} options={OPTIONS.display} onChange={(v) => f.put('layout.display', v)} />
      {(flex || grid) && (
        deviceScope ? (
          <DeviceStyle scope={deviceScope} paths={RESPONSIVE_SCOPES.layout} label={t('layout_container')}>
            {(view) => <ContainerFields id={id} style={view.style} onChange={view.onChange} flex={flex} grid={grid} />}
          </DeviceStyle>
        ) : <ContainerFields id={id} style={style} onChange={onChange} flex={flex} grid={grid} />
      )}
      <fieldset className="sbx-fieldset">
        <legend>{t('layout_as_item')}</legend>
        {deviceScope ? (
          <DeviceStyle scope={deviceScope} paths={RESPONSIVE_SCOPES.item} label={t('layout_as_item')}>
            {(view) => <ItemFields id={id} style={view.style} onChange={view.onChange} />}
          </DeviceStyle>
        ) : <ItemFields id={id} style={style} onChange={onChange} />}
      </fieldset>
    </>
  );
}

// ── Spacing and position ────────────────────────────────────────────────────

export function SpacingPane({ style, onChange, tokens }) {
  const id = useId();
  const f = useFields(style, onChange);
  return (
    <>
      {tokens && tokens.length > 0 && (
        <Field label={t('tok_theme_spacing')} htmlFor={pathId(id, 'spacing_token')}>
          <TokenPicker id={pathId(id, 'spacing_token')} label={t('tok_theme_spacing')} value={style.spacing_token ?? null} tokens={tokens} noneLabel={t('tok_custom')} onChange={(ref) => onChange(setSpacingToken(style, ref))} />
        </Field>
      )}
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

export function BorderExtras({ style, onChange, tokens }) {
  const id = useId();
  const f = useFields(style, onChange);
  return (
    <>
      {/* Each side's width and each corner's radius, linked by default like margin and padding. */}
      <SpacingBox id={`${id}-bw`} label={t('border_side_widths')} get={f.get} putAll={f.putAll} placeholder="0" pathOf={(s) => `border.${s}.width`} />
      <SpacingBox id={`${id}-br`} label={t('border_corners')} get={f.get} putAll={f.putAll} placeholder="0" keys={CORNERS} pathOf={(c) => `border.radius_corners.${c}`} labelOf={(c) => t(`corner_${c}`)} />
      <details className="sbx-more">
        <summary>{t('border_per_side')}</summary>
        {SIDES.map((s) => (
          <fieldset key={s} className="sbx-fieldset">
            <legend>{t(`side_${s}`)}</legend>
            <SelectField id={pathId(id, `border.${s}.style`)} label={t('border_style')} value={f.get(`border.${s}.style`)} options={OPTIONS.borderStyle} onChange={(v) => f.put(`border.${s}.style`, v)} />
            <ThemeColor id={pathId(id, `border.${s}.color`)} label={t('border_color')} value={f.get(`border.${s}.color`)} onChange={(v) => f.put(`border.${s}.color`, v)} tokens={tokens} />
          </fieldset>
        ))}
      </details>
    </>
  );
}

const RADIUS_PRESETS = ['none', 'sm', 'md', 'lg', 'xl', '2xl', 'full'];

/** A section's border (StyleSurface::sectionBorderFields): style, colour and radius for every side, then the side and corner boxes. */
export function SectionBorder({ style, onChange, tokens }) {
  const id = useId();
  const f = useFields(style, onChange);
  const radiusLabels = { none: t('none'), sm: t('size_sm'), md: t('size_md'), lg: t('size_lg'), xl: t('size_xl'), '2xl': t('size_2xl'), full: t('size_full_pill') };
  return (
    <>
      <SelectField id={pathId(id, 'border.style')} label={t('border_style')} value={f.get('border.style')} options={OPTIONS.borderStyle} onChange={(v) => f.put('border.style', v)} />
      <ThemeColor id={pathId(id, 'border.color')} label={t('border_color')} value={f.get('border.color')} onChange={(v) => f.put('border.color', v)} tokens={tokens} />
      <SelectField id={pathId(id, 'border.radius')} label={t('border_radius')} value={f.get('border.radius')} options={RADIUS_PRESETS} optionLabels={radiusLabels} onChange={(v) => f.put('border.radius', v)} />
      <BorderExtras style={style} onChange={onChange} tokens={tokens} />
    </>
  );
}

const NEW_SHADOW = Object.freeze({ x: '0', y: '4px', blur: '12px', color: 'rgba(0,0,0,0.2)' });

/** The custom shadow object ({x, y, blur, spread, color, inset}); presets stay in the existing select. */
export function ShadowExtras({ style, onChange, tokens }) {
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
      <ThemeColor id={pathId(id, 'shadow.color')} label={t('shadow_color')} value={shadow.color} onChange={required('shadow.color', NEW_SHADOW.color)} tokens={tokens} fallback={NEW_SHADOW.color} />
      <Check label={t('shadow_inset')} checked={shadow.inset === true} onChange={(e) => f.put('shadow.inset', e.target.checked ? true : undefined)} />
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

export function TypographyExtras({ style, onChange, tokens }) {
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
          <ThemeColor id={pathId(id, 'typography.decoration_color')} label={t('deco_color')} value={f.get('typography.decoration_color')} onChange={(v) => f.put('typography.decoration_color', v)} tokens={tokens} />
          <Length id={id} path="typography.decoration_thickness" label={t('deco_thickness')} get={f.get} put={f.put} placeholder="2px" />
          <Length id={id} path="typography.decoration_offset" label={t('deco_offset')} get={f.get} put={f.put} placeholder="3px" />
        </>
      )}
    </>
  );
}

// ── Interaction states ──────────────────────────────────────────────────────


/**
 * Hover / focus / active / disabled. "Normal" is the base style (the sections above); a state holds only what
 * differs: text colour, background, border colour, opacity and a transform. Transition and cursor belong to the
 * base style, so they are not offered here.
 */
export function StatesPane({ type = null, states, onChange }) {
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
      <Pills
        role="tablist"
        label={t('section_states')}
        value={tab}
        options={['normal', ...statesFor(type, states)].map((s) => ({ value: s, label: t(`state_${s}`), hasValue: s !== 'normal' && !!all[s] }))}
        onChange={setTab}
      />
      {tab === 'normal' ? (
        <p className="sbx-hint">{t('state_normal_hint')}</p>
      ) : (
        <>
          <p className="sbx-hint">{t(`state_${tab}_when`)}</p>
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
