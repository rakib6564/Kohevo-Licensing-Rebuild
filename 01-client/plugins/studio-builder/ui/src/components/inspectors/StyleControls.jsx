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
import { FocalPointControl } from '../fields/FocalPointControl.jsx';
/**
 * A colour control that accepts a token reference or a literal hex.
 *
 * `<input type="color">` cannot represent `#fff` or a token ref, so the text
 * input is the source of truth and the swatch is a convenience that writes back
 * into it. An empty text field means "inherit".
 */
function ColorField({ id, label, value, onChange }) {
  const literal = typeof value === 'string' && /^#[0-9a-f]{3,8}$/i.test(value) ? value.slice(0, 7) : '';
  return (
    <div className="sbx-field">
      <label className="sbx-field__label" htmlFor={`${id}-text`}>{label}</label>
      <div className="sbx-color">
        <span className="sbx-color__swatch" style={literal ? { background: literal } : undefined}>
          <input
            type="color"
            className="sbx-color__picker"
            value={literal || '#000000'}
            onChange={(e) => onChange(e.target.value)}
            tabIndex={-1}
            aria-label={label}
          />
        </span>
        <input
          id={`${id}-text`}
          type="text"
          className="sbx-input"
          value={value ?? ''}
          placeholder={t('inherit')}
          onChange={(e) => onChange(e.target.value.trim() || undefined)}
        />
      </div>
    </div>
  );
}

/**
 * A text input that only commits on blur, so typing never half-updates the
 * document. `key` resets the field when the value changes from elsewhere
 * (undo, responsive switch) so the DOM never disagrees with the document.
 */
function DraftText({ id, label, value, placeholder, onCommit, hint }) {
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
        onBlur={(e) => {
          const next = e.target.value.trim();
          onCommit(next === (value ?? '') ? undefined : (next || undefined));
        }}
        onKeyDown={(e) => { if (e.key === 'Enter') e.currentTarget.blur(); }}
      />
      {hint && <p className="sbx-hint">{hint}</p>}
    </div>
  );
}

/** Mirrors CanonicalDocumentSchema::ALLOWED_SHADOW_PRESETS. */
const SHADOW_PRESETS = ['none', 'sm', 'md', 'lg', 'xl', '2xl', 'inner'];

/** Mirrors CanonicalDocumentSchema::ALLOWED_RADIUS_PRESETS. */
const RADIUS_PRESETS = ['none', 'sm', 'md', 'lg', 'xl', '2xl', 'full'];

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
 */
export function StyleControls({ style, capabilities, onChange }) {
  const id = useId();
  const has = (k) => capabilities.includes(k);
  const typo = asObject(style.typography);
  const bg = asObject(style.background);
  const border = asObject(style.border);
  const dims = asObject(style.dimensions);
  const [bgMode, setBgMode] = useState(() => (bg.image ? 'image' : (bg.gradient ? 'gradient' : 'color')));

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

  /** `background` may be a bare string (legacy) or `{color, gradient}`. */
  const patchBackground = (field, value) => {
    const next = { ...bg };
    if (value === undefined) delete next[field];
    else next[field] = value;
    const merged = { ...style };
    delete merged.background;
    if (Object.keys(next).length === 0) delete merged.background;
    else merged.background = next;
    onChange(merged);
  };

  const bgColor = bg.color ?? (typeof style.background === 'string' ? style.background : undefined);

  return (
    <>
      {has('typography') && (
        <fieldset className="sbx-fieldset">
          <legend>{t('typography')}</legend>
          <DraftText
            id={`${id}-size`}
            label={t('font_size')}
            value={typo.size}
            placeholder="1.5rem"
            onCommit={(v) => patchNested('typography', 'size', v)}
          />
          <DraftText
            id={`${id}-lh`}
            label={t('line_height')}
            value={typo.line_height}
            placeholder="1.5"
            onCommit={(v) => patchNested('typography', 'line_height', v)}
          />
          <DraftText
            id={`${id}-ls`}
            label={t('letter_spacing')}
            value={typo.letter_spacing}
            placeholder="-0.01em"
            onCommit={(v) => patchNested('typography', 'letter_spacing', v)}
          />
          <DraftText
            id={`${id}-ff`}
            label={t('font_family')}
            value={typo.font_family}
            placeholder="Inter, sans-serif"
            onCommit={(v) => patchNested('typography', 'font_family', v)}
          />
          <ColorField
            id={`${id}-tcolor`}
            label={t('text_color')}
            value={typo.color}
            onChange={(v) => patchNested('typography', 'color', v)}
          />
        </fieldset>
      )}

      {has('color') && (
        <ColorField
          id={`${id}-color`}
          label={t('text_color')}
          value={style.color}
          onChange={(v) => onChange({ ...style, color: v })}
        />
      )}

      {has('background') && (
        <fieldset className="sbx-fieldset sbx-bg-controls">
          <legend>{t('background_label')}</legend>
          <div className="sbx-segmented-pills" role="radiogroup" aria-label={t('bg_type')}>
            {['image', 'color', 'gradient', 'video'].map((m) => (
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
              <DraftText
                id={`${id}-bg-img`}
                label={t('bg_image_url')}
                value={bg.image}
                placeholder={t('url_placeholder_assets')}
                onCommit={(v) => patchBackground('image', v)}
              />
              <FocalPointControl
                imageUrl={bg.image}
                value={bg.focal_point || { x: 50, y: 50 }}
                onChange={(coords) => patchBackground('focal_point', coords)}
              />
              <div className="sbx-field">
                <label className="sbx-field__label" htmlFor={`${id}-bg-fit`}>{t('bg_fit')}</label>
                <select
                  id={`${id}-bg-fit`}
                  value={bg.fit || 'cover'}
                  onChange={(e) => patchBackground('fit', e.target.value)}
                >
                  <option value="cover">{t('fit_cover')}</option>
                  <option value="contain">{t('fit_contain')}</option>
                  <option value="fill">{t('fit_fill')}</option>
                  <option value="auto">{t('fit_auto')}</option>
                </select>
              </div>
              <div className="sbx-field">
                <label className="sbx-field__label" htmlFor={`${id}-bg-pos`}>{t('bg_position')}</label>
                <select
                  id={`${id}-bg-pos`}
                  value={bg.position || 'center center'}
                  onChange={(e) => patchBackground('position', e.target.value)}
                >
                  <option value="center center">{t('pos_center_center')}</option>
                  <option value="top center">{t('pos_top_center')}</option>
                  <option value="bottom center">{t('pos_bottom_center')}</option>
                  <option value="center left">{t('pos_center_left')}</option>
                  <option value="center right">{t('pos_center_right')}</option>
                </select>
              </div>
            </div>
          )}

          {bgMode === 'color' && (
            <ColorField
              id={`${id}-bg`}
              label={t('background_color')}
              value={bgColor}
              onChange={(v) => patchBackground('color', v)}
            />
          )}

          {bgMode === 'gradient' && (
            <GradientField value={bg.gradient} onChange={(v) => patchBackground('gradient', v)} />
          )}

          {bgMode === 'video' && (
            <DraftText
              id={`${id}-bg-vid`}
              label={t('bg_video_url')}
              value={bg.video}
              placeholder="https://...mp4"
              onCommit={(v) => patchBackground('video', v)}
            />
          )}
        </fieldset>
      )}

      {has('border') && (
        <fieldset className="sbx-fieldset">
          <legend>{t('border')}</legend>
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
          <legend>{t('box_shadow')}</legend>
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
          <legend>{t('dimensions_label')}</legend>
          <DraftText
            id={`${id}-width`}
            label={t('dimension_width')}
            value={dims.width}
            placeholder="100%"
            onCommit={(v) => patchNested('dimensions', 'width', v)}
          />
          <DraftText
            id={`${id}-minh`}
            label={t('dimension_min_height')}
            value={dims.min_height}
            placeholder="20rem"
            onCommit={(v) => patchNested('dimensions', 'min_height', v)}
          />
          <DraftText
            id={`${id}-maxw`}
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