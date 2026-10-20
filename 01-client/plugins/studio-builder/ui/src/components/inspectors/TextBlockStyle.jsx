// TextBlockStyle — the pieces of a Button's and a Heading's one Style section, laid out like the reference editor:
// Text Shadow and Text Stroke behind a pencil, a blend mode, Normal / Hover tabs for the colours, and the corner radius
// and padding as linked boxes. Each edits the block's ordinary style (or its hover state), validated by the same table
// the server uses (core/styleSurface.mjs).

import { useId, useState } from 'react';
import { Pills, Popover } from '../ui/index.js';
import { ColorField, DraftText } from './StyleControls.jsx';
import { SelectField, SliderField, SpacingBox } from './SurfaceControls.jsx';
import { DeviceStyle } from './DeviceStyle.jsx';
import { asObject } from '../../core/doc.mjs';
import { CORNERS, OPTIONS, getPath, isColourToken, setPath, setPaths } from '../../core/styleSurface.mjs';
import { setTextLiteral, setTextToken } from '../../core/tokenStyle.mjs';
import { t } from '../../core/messages.mjs';

const fields = (style, onChange) => ({
  get: (path) => getPath(style, path),
  put: (path, value) => onChange(setPath(style, path, value)),
  putAll: (pairs) => onChange(setPaths(style, pairs)),
});

/** A theme colour or a literal one, in the same path. */
function AnyColor({ id, label, value, onChange, tokens }) {
  const token = isColourToken(value) ? value : null;
  return <ColorField id={id} label={label} value={token ? undefined : value} onChange={onChange} tokens={tokens} token={token} onToken={(ref) => onChange(ref ?? undefined)} />;
}

const NEW_TEXT_SHADOW = Object.freeze({ x: '0px', y: '2px', blur: '4px', color: 'rgba(0,0,0,0.3)' });

/** Text Shadow: a pencil that opens horizontal, vertical, blur and colour. x, y and the colour are required by the server. */
export function TextShadowField({ style, onChange, tokens }) {
  const id = useId();
  const ts = style.text_shadow && typeof style.text_shadow === 'object' ? style.text_shadow : null;
  const put = (field, value, fallback) => {
    const next = { ...(ts || NEW_TEXT_SHADOW), [field]: value ?? fallback };
    if (next[field] === undefined) delete next[field];
    onChange({ ...style, text_shadow: next });
  };
  const summary = ts ? [ts.x, ts.y, ts.blur].filter(Boolean).join(' ') : t('none');
  return (
    <div className="sbx-textfx" data-testid="text-shadow">
      <span className="sbx-field__label">{t('text_shadow')}</span>
      <Popover label={t('edit_text_shadow')} row={<span className="sbx-pop__summary">{summary}</span>}>
        <DraftText id={`${id}-x`} path="text_shadow.x" label={t('shadow_x')} value={ts && ts.x} placeholder="0px" bareUnit="px" onCommit={(v) => put('x', v, NEW_TEXT_SHADOW.x)} />
        <DraftText id={`${id}-y`} path="text_shadow.y" label={t('shadow_y')} value={ts && ts.y} placeholder="2px" bareUnit="px" onCommit={(v) => put('y', v, NEW_TEXT_SHADOW.y)} />
        <DraftText id={`${id}-blur`} path="text_shadow.blur" label={t('shadow_blur')} value={ts && ts.blur} placeholder="4px" bareUnit="px" onCommit={(v) => put('blur', v, undefined)} />
        <AnyColor id={`${id}-color`} label={t('shadow_color')} value={ts ? ts.color : undefined} tokens={tokens} onChange={(v) => put('color', v, NEW_TEXT_SHADOW.color)} />
        {ts && <button type="button" className="sbx-btn sbx-btn--xs" onClick={() => { const next = { ...style }; delete next.text_shadow; onChange(next); }}>{t('text_shadow_remove')}</button>}
      </Popover>
    </div>
  );
}

/** Text Stroke: the outline around each letter, its width and colour. */
export function TextStrokeField({ style, onChange, tokens }) {
  const id = useId();
  const f = fields(style, onChange);
  const width = f.get('typography.stroke_width');
  return (
    <div className="sbx-textfx" data-testid="text-stroke">
      <span className="sbx-field__label">{t('text_stroke')}</span>
      <Popover label={t('edit_text_stroke')} row={<span className="sbx-pop__summary">{width || t('none')}</span>}>
        <DraftText id={`${id}-w`} path="typography.stroke_width" label={t('text_stroke_width')} value={width} placeholder="1px" bareUnit="px" onCommit={(v) => f.put('typography.stroke_width', v)} />
        <AnyColor id={`${id}-c`} label={t('text_stroke_color')} value={f.get('typography.stroke_color')} tokens={tokens} onChange={(v) => f.put('typography.stroke_color', v)} />
      </Popover>
    </div>
  );
}

/** Blend Mode: how the text mixes with what is behind it. */
export function BlendField({ style, onChange }) {
  const id = useId();
  const f = fields(style, onChange);
  return <SelectField id={`${id}-blend`} label={t('fx_blend')} value={f.get('effects.blend')} options={OPTIONS.blend} emptyLabel={t('tag_default')} onChange={(v) => f.put('effects.blend', v === 'normal' ? undefined : v)} />;
}

/** The text colour (one value: typography.color, or a theme text token). */
export function TextColorField({ style, onChange, tokens }) {
  const id = useId();
  const typo = asObject(style.typography);
  return (
    <ColorField
      id={`${id}-text`}
      label={t('text_color')}
      value={typo.color ?? style.color}
      onChange={(v) => onChange(setTextLiteral(style, v))}
      tokens={tokens}
      token={style.text_token}
      onToken={(ref) => onChange(setTextToken(style, ref))}
    />
  );
}

/**
 * Normal / Hover. Normal is the children (the block's own colours). Hover edits the hover state: the text colour, and with
 * `withSurface` the background and border colours, beside the transition duration that eases into it.
 */
export function NormalHover({ label, style, states, onStyle, onStates, withSurface = false, children }) {
  const id = useId();
  const [tab, setTab] = useState('normal');
  const all = asObject(states);
  const hover = asObject(all.hover);
  const putHover = (path, value) => {
    const next = setPath(hover, path, value);
    const merged = { ...all };
    if (Object.keys(next).length === 0) delete merged.hover; else merged.hover = next;
    onStates(merged);
  };
  return (
    <div className="sbx-normalhover" data-testid="normal-hover">
      <Pills
        role="tablist"
        label={label}
        value={tab}
        options={[{ value: 'normal', label: t('state_normal') }, { value: 'hover', label: t('state_hover'), hasValue: Object.keys(hover).length > 0 }]}
        onChange={setTab}
      />
      {tab === 'normal' ? children : (
        <>
          <ColorField id={`${id}-hc`} label={t('text_color')} value={getPath(hover, 'color')} onChange={(v) => putHover('color', v)} />
          {withSurface && (
            <>
              <ColorField id={`${id}-hbg`} label={t('background_color')} value={getPath(hover, 'background.color')} onChange={(v) => putHover('background.color', v)} />
              <ColorField id={`${id}-hbd`} label={t('border_color')} value={getPath(hover, 'border.color')} onChange={(v) => putHover('border.color', v)} />
            </>
          )}
          <SliderField
            id={`${id}-hd`}
            label={t('bg_transition')}
            value={getPath(style, 'effects.transition.duration_ms')}
            min={0}
            max={2000}
            step={50}
            unit="ms"
            neutral={0}
            onChange={(v) => onStyle(setPath(style, 'effects.transition.duration_ms', v))}
          />
        </>
      )}
    </div>
  );
}

/** Border radius as four linked corners. */
export function CornersBox({ style, onChange }) {
  const id = useId();
  const f = fields(style, onChange);
  return <SpacingBox id={`${id}-r`} label={t('border_corners')} get={f.get} putAll={f.putAll} keys={CORNERS} pathOf={(c) => `border.radius_corners.${c}`} labelOf={(c) => t(`corner_${c}`)} />;
}

const PADDING_PATHS = ['padding.top', 'padding.right', 'padding.bottom', 'padding.left'];

/** Padding as four linked sides, per device. */
export function PaddingBox({ deviceScope }) {
  const id = useId();
  return (
    <DeviceStyle scope={deviceScope} paths={PADDING_PATHS} label={t('padding_label')}>
      {(view) => {
        const f = fields(view.style, view.onChange);
        return <SpacingBox id={`${id}-p`} group="padding" label={t('padding_label')} get={f.get} putAll={f.putAll} />;
      }}
    </DeviceStyle>
  );
}
