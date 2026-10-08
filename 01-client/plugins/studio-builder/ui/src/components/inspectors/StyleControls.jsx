// StyleControls — the Phase 5 authoring surface.
//
// WHY THIS FILE EXISTS
// The server already validates AND compiles every key handled here:
// `typography`, `color`, `background`, `border`, `shadow`, `dimensions`,
// `opacity` and `z_index` are all in `ALLOWED_STYLE_KEYS`, validated by
// `DocumentValidator::validateVisualStyles`, and emitted to CSS by
// `DocumentRenderer::buildInlineStyles`. None of that was reachable from the
// UI — an author could only pick token references, presets and z-index. The
// ceiling was never in the backend; it was in the inspector.
//
// SAFE VALUES ONLY
// Every control here emits either a preset, a number, or a value the client
// itself constrains (a colour input yields `#rrggbb`; the gradient builder
// composes two of those). There is deliberately no free-text CSS box: that is
// the escape hatch Phase 1 replaced with the audited tenant custom-CSS path,
// and re-adding it here would reopen the same hole.
//
// Token references (`color.foo`, `text.bar`) are preserved rather than
// overwritten — the renderer already skips emitting a literal when a token ref
// is present, so a literal and a ref never both reach the page.

import { useId, useState } from 'react';
import { asObject } from '../../core/doc.mjs';
import { t } from '../../core/messages.mjs';
import { acceptsDraft } from '../../core/styleValues.mjs';
import { acceptsSurfaceDraft } from '../../core/styleSurface.mjs';
import { MediaControl } from '../fields/MediaControl.jsx';
import { FocalPad, OverlayField } from './BackgroundWidgets.jsx';
/**
 * A colour control that accepts a token reference or a literal hex.
 *
 * `<input type="color">` cannot represent `#fff` or a token ref, so the text
 * input is the source of truth and the swatch is a convenience that writes back
 * into it. An empty text field means "inherit".
 */
export function ColorField({ id, label, value, onChange }) {
  const literal = typeof value === 'string' && /^#[0-9a-f]{3,8}$/i.test(value) ? value.slice(0, 7) : '';
  // Typing `#e8` is not yet a colour: keep the draft locally and commit only
  // once it is one (or empty), so a half-typed value never reaches the document.
  const [draft, setDraft] = useState(null);
  const shown = draft ?? value ?? '';
  const invalid = draft !== null && !acceptsDraft('color', draft);
  return (
    <div className="sbx-field">
      <label className="sbx-field__label" htmlFor={`${id}-text`}>{label}</label>
      <div className="sbx-color">
        <span className="sbx-color__swatch" style={literal ? { background: literal } : undefined}>
          <input
            type="color"
            className="sbx-color__picker"
            value={literal || '#000000'}
            onChange={(e) => { setDraft(null); onChange(e.target.value); }}
            tabIndex={-1}
            aria-label={label}
          />
        </span>
        <input
          id={`${id}-text`}
          type="text"
          className="sbx-input"
          value={shown}
          placeholder={t('inherit')}
          aria-invalid={invalid || undefined}
          aria-describedby={invalid ? `${id}-err` : undefined}
          onChange={(e) => {
            const next = e.target.value.trim();
            setDraft(e.target.value);
            if (acceptsDraft('color', next)) onChange(next || undefined);
          }}
          onBlur={() => { if (!invalid) setDraft(null); }}
        />
      </div>
      {invalid && <p className="sbx-field__error" id={`${id}-err`} role="alert">{t('invalid_css_value')}</p>}
    </div>
  );
}

/**
 * A text input that only commits on blur, so typing never half-updates the
 * document. `key` resets the field when the value changes from elsewhere
 * (undo, responsive switch) so the DOM never disagrees with the document.
 */
export function DraftText({ id, label, value, placeholder, onCommit, hint, kind, path }) {
  const [error, setError] = useState(false);
  return (
    <div className="sbx-field">
      <label className="sbx-field__label" htmlFor={id}>{label}</label>
      <input
        id={id}
        type="text"
        className="sbx-input"
        defaultValue={value ?? ''}
        placeholder={placeholder}
        key={value ?? ''}
        aria-invalid={error || undefined}
        aria-describedby={error ? `${id}-err` : undefined}
        onChange={() => { if (error) setError(false); }}
        onBlur={(e) => {
          const next = e.target.value.trim();
          // `kind` names the grammar the server enforces (core/styleValues.mjs);
          // an invalid draft stays in the box with an error and is never committed.
          // `path` names a field of the closed style surface (core/styleSurface.mjs), which is stricter than `kind`.
          if (path ? !acceptsSurfaceDraft(path, next) : (kind && !acceptsDraft(kind, next))) {
            setError(true);
            return;
          }
          setError(false);
          onCommit(next === (value ?? '') ? undefined : (next || undefined));
        }}
        onKeyDown={(e) => { if (e.key === 'Enter') e.currentTarget.blur(); }}
      />
      {error && <p className="sbx-field__error" id={`${id}-err`} role="alert">{t('invalid_css_value')}</p>}
      {hint && <p className="sbx-hint">{hint}</p>}
    </div>
  );
}

/** Mirrors CanonicalDocumentSchema::ALLOWED_SHADOW_PRESETS. */
const SHADOW_PRESETS = ['none', 'sm', 'md', 'lg', 'xl', '2xl', 'inner'];

/** Mirrors CanonicalDocumentSchema::ALLOWED_RADIUS_PRESETS. */
const RADIUS_PRESETS = ['none', 'sm', 'md', 'lg', 'xl', '2xl', 'full'];

/** Mirrors StyleSurface (server): the only background vocabulary the document accepts. */
const BG_FIT = ['cover', 'contain', 'auto'];
const BG_REPEAT = ['no-repeat', 'repeat', 'repeat-x', 'repeat-y'];
const BG_POSITIONS = ['center', 'top', 'bottom', 'left', 'right', 'top-left', 'top-right', 'bottom-left', 'bottom-right'];

/** A stored background image is a media reference ({media_id, alt, focal_point}); an older typed URL string is not. */
const isMediaRef = (v) => !!v && typeof v === 'object' && !Array.isArray(v);

/**
 * Gradient builder. Composes `linear-gradient(...)` from two colour inputs and
 * an angle. A free-text gradient string is never accepted — that would let any
 * CSS function through, not just the one this understands.
 */
function GradientField({ value, onChange }) {
  const id = useId();
  const raw = typeof value === 'string' ? value : '';
  const parsed = /linear-gradient\(\s*(\d+)deg\s*,\s*(#[0-9a-f]{3,8})\s*,\s*(#[0-9a-f]{3,8})\s*\)/i.exec(raw);
  const angle = parsed ? parsed[1] : '135';
  const from = parsed ? parsed[2].slice(0, 7) : '#e8734a';
  const to = parsed ? parsed[3].slice(0, 7) : '#8a3d24';

  const commit = (deg, a, b) => {
    const d = Math.max(0, Math.min(360, Number(deg) || 0));
    onChange(`linear-gradient(${d}deg, ${a}, ${b})`);
  };

  return (
    <fieldset className="sbx-fieldset">
      <legend>{t('gradient')}</legend>
      <div className="sbx-field sbx-field--inline">
        <label className="sbx-field__label" htmlFor={`${id}-from`}>{t('gradient_from')}</label>
        <input id={`${id}-from`} type="color" value={from} onChange={(e) => commit(angle, e.target.value, to)} />
        <label className="sbx-field__label" htmlFor={`${id}-to`}>{t('gradient_to')}</label>
        <input id={`${id}-to`} type="color" value={to} onChange={(e) => commit(angle, from, e.target.value)} />
      </div>
      <div className="sbx-field">
        <label className="sbx-field__label" htmlFor={`${id}-angle`}>{t('gradient_angle')}</label>
        <input
          id={`${id}-angle`}
          type="range"
          min="0"
          max="360"
          step="15"
          value={angle}
          onChange={(e) => commit(e.target.value, from, to)}
        />
        <output htmlFor={`${id}-angle`}>{angle}°</output>
      </div>
      <p className="sbx-hint">{t('gradient_hint')}</p>
    </fieldset>
  );
}

/**
 * The visible style surface for one block.
 *
 * @param {object} props
 * @param {Record<string, unknown>} props.style      current block style
 * @param {string[]} props.capabilities             the block's declared style keys
 * @param {(next: Record<string, unknown>) => void} props.onChange
 * @param {string} [props.only]                  render just this section (typography, background, border, shadow, dimensions, opacity)
 * @param {boolean} [props.mediaPicker]            whether the media library picker is available to this user
 */
export function StyleControls({ style, capabilities, onChange, mediaPicker, only }) {
  const id = useId();
  // `only` renders a single section of the stack (the Inspector shows each in its own collapsible section);
  // the text colour belongs with Typography.
  const has = (k) => capabilities.includes(k) && (!only || only === k || (only === 'typography' && k === 'color'));
  const typo = asObject(style.typography);
  const bg = asObject(style.background);
  const border = asObject(style.border);
  const dims = asObject(style.dimensions);
  const [bgMode, setBgMode] = useState(() => (isMediaRef(bg.image) ? 'image' : (bg.gradient ? 'gradient' : 'color')));

  /**
   * Merge one field into a nested style object. A field cleared to undefined
   * is deleted, and an object left empty is deleted entirely — otherwise the
   * document accumulates `"typography": {}` shells that the validator has to
   * reason about and that no renderer reads.
   */
  const patchNested = (key, field, value) => {
    const next = { ...asObject(style[key]) };
    if (value === undefined) delete next[field];
    else next[field] = value;
    const merged = { ...style };
    if (Object.keys(next).length === 0) delete merged[key];
    else merged[key] = next;
    onChange(merged);
  };

  /**
   * Change one or more fields of `background` (a field set to undefined is removed). The server accepts an
   * image OR a gradient, and only the vocabulary in BG_FIT / BG_REPEAT, so this also drops the values older
   * editors wrote that it now refuses (`focal_point`, `fit: fill`, a two-word `position`) the next time the
   * author touches the background.
   */
  const patchBackground = (changes) => {
    const next = { ...bg, ...changes };
    for (const key of Object.keys(next)) if (next[key] === undefined) delete next[key];
    delete next.focal_point;
    if (!BG_FIT.includes(next.fit)) delete next.fit;
    if (typeof next.position === 'string' && !BG_POSITIONS.includes(next.position)) delete next.position;
    if (isMediaRef(next.image) && next.gradient) {
      if ('gradient' in changes) delete next.image; else delete next.gradient;
    }
    const merged = { ...style };
    delete merged.background;
    if (Object.keys(next).length > 0) merged.background = next;
    onChange(merged);
  };

  const bgColor = bg.color ?? (typeof style.background === 'string' ? style.background : undefined);

  return (
    <>
      {has('typography') && (
        <fieldset className="sbx-fieldset">
          {!only && <legend>{t('typography')}</legend>}
          <DraftText
            id={`${id}-size`}
            kind="length"
            label={t('font_size')}
            value={typo.size}
            placeholder="1.5rem"
            onCommit={(v) => patchNested('typography', 'size', v)}
          />
          <DraftText
            id={`${id}-lh`}
            kind="lineHeight"
            label={t('line_height')}
            value={typo.line_height}
            placeholder="1.5"
            onCommit={(v) => patchNested('typography', 'line_height', v)}
          />
          <DraftText
            id={`${id}-ls`}
            kind="letterSpacing"
            label={t('letter_spacing')}
            value={typo.letter_spacing}
            placeholder="-0.01em"
            onCommit={(v) => patchNested('typography', 'letter_spacing', v)}
          />
          <DraftText
            id={`${id}-ff`}
            kind="fontFamily"
            label={t('font_family')}
            value={typo.font_family}
            placeholder="Inter, sans-serif"
            onCommit={(v) => patchNested('typography', 'font_family', v)}
          />
          <ColorField
            id={`${id}-tcolor`}
            label={t('text_color')}
            value={typo.color ?? style.color}
            onChange={(v) => {
              // One text colour: it lives in typography.color, and an older flat `color` is folded into it
              // (the server writes the flat one last, so leaving both would let it silently win).
              const merged = { ...style };
              delete merged.color;
              const nextTypo = { ...typo };
              if (v === undefined) delete nextTypo.color; else nextTypo.color = v;
              if (Object.keys(nextTypo).length === 0) delete merged.typography; else merged.typography = nextTypo;
              onChange(merged);
            }}
          />
        </fieldset>
      )}

      {has('color') && !capabilities.includes('typography') && (
        <ColorField
          id={`${id}-color`}
          label={t('text_color')}
          value={style.color}
          onChange={(v) => onChange({ ...style, color: v })}
        />
      )}

      {has('background') && (
        <fieldset className="sbx-fieldset sbx-bg-controls">
          {!only && <legend>{t('background_label')}</legend>}
          <div className="sbx-segmented-pills" role="radiogroup" aria-label={t('bg_type')}>
            {['image', 'color', 'gradient'].map((m) => (
              <button
                key={m}
                type="button"
                className={`sbx-segmented-pill${bgMode === m ? ' is-active' : ''}`}
                onClick={() => setBgMode(m)}
              >
                {t(`bg_mode_${m}`)}
              </button>
            ))}
          </div>

          {bgMode === 'image' && (
            <div className="sbx-bg-image-pane">
              <MediaControl
                field={{ key: 'bg-image', label: t('bg_image'), required: false }}
                value={isMediaRef(bg.image) ? bg.image : null}
                mediaPicker={mediaPicker}
                hideFocal
                onChange={(ref) => patchBackground({ image: ref ?? undefined })}
              />
              {isMediaRef(bg.image) && (
                <>
                  <div className="sbx-field">
                    <label className="sbx-field__label" htmlFor={`${id}-bg-fit`}>{t('bg_fit')}</label>
                    <select id={`${id}-bg-fit`} value={BG_FIT.includes(bg.fit) ? bg.fit : 'cover'} onChange={(e) => patchBackground({ fit: e.target.value })}>
                      <option value="cover">{t('fit_cover')}</option>
                      <option value="contain">{t('fit_contain')}</option>
                      <option value="auto">{t('fit_auto')}</option>
                    </select>
                  </div>
                  <div className="sbx-field">
                    <label className="sbx-field__label" htmlFor={`${id}-bg-repeat`}>{t('bg_repeat')}</label>
                    <select id={`${id}-bg-repeat`} value={BG_REPEAT.includes(bg.repeat) ? bg.repeat : 'no-repeat'} onChange={(e) => patchBackground({ repeat: e.target.value })}>
                      {BG_REPEAT.map((r) => <option key={r} value={r}>{t(`repeat_${r.replace('-', '_')}`)}</option>)}
                    </select>
                  </div>
                  <FocalPad
                    id={`${id}-bg`}
                    value={bg.image.focal_point}
                    onChange={(fp) => patchBackground({ image: { ...bg.image, focal_point: fp } })}
                  />
                  <OverlayField
                    id={`${id}-bg`}
                    value={asObject(bg.overlay).color}
                    onChange={(v) => patchBackground({ overlay: v ? { color: v } : undefined })}
                  />
                </>
              )}
            </div>
          )}

          {bgMode === 'color' && (
            <ColorField
              id={`${id}-bg`}
              label={t('background_color')}
              value={bgColor}
              onChange={(v) => patchBackground({ color: v })}
            />
          )}

          {bgMode === 'gradient' && (
            <GradientField value={bg.gradient} onChange={(v) => patchBackground({ gradient: v })} />
          )}
        </fieldset>
      )}

      {has('border') && (
        <fieldset className="sbx-fieldset">
          {!only && <legend>{t('border')}</legend>}
          <div className="sbx-field">
            <label className="sbx-field__label" htmlFor={`${id}-border-style`}>{t('border_style')}</label>
            <select
              id={`${id}-border-style`}
              value={border.style ?? ''}
              onChange={(e) => patchNested('border', 'style', e.target.value || undefined)}
            >
              <option value="">{t('none')}</option>
              <option value="solid">{t('bs_solid')}</option>
              <option value="dashed">{t('bs_dashed')}</option>
              <option value="dotted">{t('bs_dotted')}</option>
              <option value="double">{t('bs_double')}</option>
            </select>
          </div>
          <div className="sbx-field">
            <label className="sbx-field__label" htmlFor={`${id}-border-radius`}>{t('border_radius')}</label>
            <select
              id={`${id}-border-radius`}
              value={RADIUS_PRESETS.includes(border.radius) ? border.radius : ''}
              onChange={(e) => patchNested('border', 'radius', e.target.value || undefined)}
            >
              <option value="">{t('none')}</option>
              <option value="sm">{t('size_sm')}</option>
              <option value="md">{t('size_md')}</option>
              <option value="lg">{t('size_lg')}</option>
              <option value="xl">{t('size_xl')}</option>
              <option value="2xl">{t('size_2xl')}</option>
              <option value="full">{t('size_full_pill')}</option>
            </select>
          </div>
          <DraftText
            id={`${id}-border-width`}
            kind="borderWidth"
            label={t('border_width')}
            value={border.width}
            placeholder="1px"
            onCommit={(v) => patchNested('border', 'width', v)}
          />
          <ColorField
            id={`${id}-border-color`}
            label={t('border_color')}
            value={border.color}
            onChange={(v) => patchNested('border', 'color', v)}
          />
        </fieldset>
      )}

      {has('shadow') && (
        <fieldset className="sbx-fieldset">
          {!only && <legend>{t('box_shadow')}</legend>}
          <div className="sbx-field">
            <label className="sbx-field__label" htmlFor={`${id}-shadow-preset`}>{t('box_shadow')}</label>
            <select
              id={`${id}-shadow-preset`}
              value={SHADOW_PRESETS.includes(style.shadow) ? style.shadow : ''}
              onChange={(e) => onChange({ ...style, shadow: e.target.value || undefined })}
            >
              <option value="">{t('none')}</option>
              <option value="sm">{t('size_sm')}</option>
              <option value="md">{t('size_md')}</option>
              <option value="lg">{t('size_lg')}</option>
              <option value="xl">{t('size_xl')}</option>
              <option value="2xl">{t('size_2xl')}</option>
            </select>
          </div>
          {/* Once the author leaves the preset list the value is a custom CSS
              box-shadow, so only then does the free-text field apply. */}
          {typeof style.shadow === 'string' && !SHADOW_PRESETS.includes(style.shadow) && (
            <DraftText
              id={`${id}-shadow`}
              kind="shadow"
              label={t('box_shadow')}
              value={style.shadow}
              placeholder="0 10px 25px rgba(0,0,0,.15)"
              onCommit={(v) => onChange({ ...style, shadow: v })}
              hint={t('shadow_custom_hint')}
            />
          )}
        </fieldset>
      )}

      {has('dimensions') && (
        <fieldset className="sbx-fieldset">
          {!only && <legend>{t('dimensions_label')}</legend>}
          <DraftText
            id={`${id}-width`}
            kind="length"
            label={t('dimension_width')}
            value={dims.width}
            placeholder="100%"
            onCommit={(v) => patchNested('dimensions', 'width', v)}
          />
          <DraftText
            id={`${id}-minh`}
            kind="length"
            label={t('dimension_min_height')}
            value={dims.min_height}
            placeholder="20rem"
            onCommit={(v) => patchNested('dimensions', 'min_height', v)}
          />
          <DraftText
            id={`${id}-maxw`}
            kind="length"
            label={t('dimension_max_width')}
            value={dims.max_width}
            placeholder="60rem"
            onCommit={(v) => patchNested('dimensions', 'max_width', v)}
          />
        </fieldset>
      )}

      {has('opacity') && (
        <div className="sbx-field">
          <label className="sbx-field__label" htmlFor={`${id}-opacity`}>{t('opacity_label')}</label>
          <input
            id={`${id}-opacity`}
            type="range"
            min="0"
            max="1"
            step="0.05"
            value={style.opacity ?? 1}
            onChange={(e) => {
              const v = Number(e.target.value);
              onChange({ ...style, opacity: v === 1 ? undefined : v });
            }}
          />
          <output htmlFor={`${id}-opacity`}>{Math.round((style.opacity ?? 1) * 100)}%</output>
        </div>
      )}
    </>
  );
}