// ContainerLayers — a Container's Background Overlay and Shape Divider sections, laid out like the reference editor.
//
// The overlay is a layer between the Container's background and its children (`style.overlay`: a colour or a gradient,
// its opacity and blend mode), with Normal / Hover tabs (the hover state's overlay colour and opacity, and the transition
// that eases into it). The shape dividers are the Container's own props, a shape at the top and at the bottom edge.

import { useId, useState } from 'react';
import { Check, Pills } from '../ui/index.js';
import { TokenSelect } from '../fields/FieldControl.jsx';
import { ColorField, GradientField } from './StyleControls.jsx';
import { SelectField, SliderField } from './SurfaceControls.jsx';
import { asObject } from '../../core/doc.mjs';
import { OPTIONS, getPath, isColourToken, setPath } from '../../core/styleSurface.mjs';
import { t } from '../../core/messages.mjs';
import { SHAPE_NAMES, SHAPE_PATHS } from '../../core/shapes.mjs';

/** An overlay with neither a colour nor a gradient is not an overlay: drop it rather than store an invalid one. */
function withOverlay(style, next) {
  const out = { ...style };
  if (!next || (next.color === undefined && next.gradient === undefined)) delete out.overlay;
  else out.overlay = next;
  return out;
}

export function OverlayStates({ style, states, onStyle, onStates, tokens }) {
  const id = useId();
  const [tab, setTab] = useState('normal');
  const ov = asObject(style.overlay);
  const [kind, setKind] = useState(ov.gradient ? 'gradient' : 'color');
  const all = asObject(states);
  const hover = asObject(all.hover);
  const put = (field, value) => {
    const next = { ...ov, [field]: value };
    if (value === undefined) delete next[field];
    onStyle(withOverlay(style, next));
  };
  const putHover = (path, value) => {
    const next = setPath(hover, path, value);
    const merged = { ...all };
    if (Object.keys(next).length === 0) delete merged.hover; else merged.hover = next;
    onStates(merged);
  };
  const token = isColourToken(ov.color) ? ov.color : null;
  return (
    <div className="sbx-normalhover" data-testid="overlay-states">
      <Pills
        role="tablist"
        label={t('section_overlay')}
        value={tab}
        options={[{ value: 'normal', label: t('state_normal') }, { value: 'hover', label: t('state_hover'), hasValue: !!hover.overlay }]}
        onChange={setTab}
      />
      {tab === 'normal' ? (
        <>
          <Pills
            label={t('overlay_type')}
            size="sm"
            value={kind}
            options={[{ value: 'color', label: t('bg_mode_color') }, { value: 'gradient', label: t('gradient') }]}
            onChange={(k) => {
              setKind(k);
              // One paint at a time: switching drops the other kind's value.
              const next = { ...ov };
              delete next[k === 'color' ? 'gradient' : 'color'];
              onStyle(withOverlay(style, next));
            }}
          />
          {kind === 'color' ? (
            <ColorField
              id={`${id}-color`}
              label={t('background_color')}
              value={token ? undefined : ov.color}
              onChange={(v) => put('color', v)}
              tokens={tokens}
              token={token}
              onToken={(ref) => put('color', ref ?? undefined)}
            />
          ) : (
            <GradientField value={ov.gradient} onChange={(v) => put('gradient', v || undefined)} />
          )}
          {(ov.color || ov.gradient) && (
            <>
              <SliderField id={`${id}-op`} label={t('opacity_label')} value={ov.opacity} min={0} max={1} step={0.05} neutral={1} onChange={(v) => put('opacity', v)} />
              <SelectField id={`${id}-blend`} label={t('fx_blend')} value={ov.blend} options={OPTIONS.blend} emptyLabel={t('tag_default')} onChange={(v) => put('blend', v === 'normal' ? undefined : v)} />
            </>
          )}
        </>
      ) : (
        <div data-testid="overlay-hover">
          <ColorField id={`${id}-hcolor`} label={t('background_color')} value={getPath(hover, 'overlay.color')} tokens={tokens} onChange={(v) => putHover('overlay.color', v)} effective={ov.color ? { css: ov.color, label: ov.color, source: 'normal' } : null} />
          <SliderField id={`${id}-hop`} label={t('opacity_label')} value={getPath(hover, 'overlay.opacity')} min={0} max={1} step={0.05} neutral={1} onChange={(v) => putHover('overlay.opacity', v)} />
          <SliderField
            id={`${id}-duration`}
            label={t('bg_transition')}
            value={getPath(style, 'effects.transition.duration_ms')}
            min={0}
            max={2000}
            step={50}
            unit="ms"
            neutral={0}
            onChange={(v) => onStyle(setPath(style, 'effects.transition.duration_ms', v))}
          />
        </div>
      )}
    </div>
  );
}

export { SHAPE_NAMES };
const DEFAULT_SHAPE_HEIGHT = 60;

export function ShapeDividers({ props, onProps, tokens }) {
  const id = useId();
  const [side, setSide] = useState('top');
  const key = (field) => `shape_${side}${field ? `_${field}` : ''}`;
  const put = (field, value) => onProps({ ...props, [key(field)]: value });
  const shape = SHAPE_NAMES.includes(props[key()]) ? props[key()] : null;
  return (
    <div className="sbx-normalhover" data-testid="shape-dividers">
      <Pills
        role="tablist"
        label={t('section_shape_divider')}
        value={side}
        options={[
          { value: 'top', label: t('shape_top'), hasValue: SHAPE_NAMES.includes(props.shape_top) },
          { value: 'bottom', label: t('shape_bottom'), hasValue: SHAPE_NAMES.includes(props.shape_bottom) },
        ]}
        onChange={setSide}
      />
      <div className="sbx-field" role="radiogroup" aria-label={t('shape_type')}>
        <span className="sbx-field__label">{t('shape_type')}</span>
        <div className="sbx-shapes" data-testid="shape-picker">
          <button type="button" role="radio" aria-checked={!shape} aria-label={t('none')} title={t('none')} className={`sbx-shape${!shape ? ' is-active' : ''}`} onClick={() => put('', 'none')}><span>{t('none')}</span></button>
          {SHAPE_NAMES.map((name) => (
            <button key={name} type="button" role="radio" aria-checked={shape === name} aria-label={t(`shape_${name}`)} title={t(`shape_${name}`)} className={`sbx-shape${shape === name ? ' is-active' : ''}`} onClick={() => put('', name)}>
              <svg viewBox="0 0 1000 100" preserveAspectRatio="none" aria-hidden="true">{SHAPE_PATHS[name].split('|').map((d, i, all) => <path key={d} d={d} fillOpacity={all.length > 1 ? 0.35 : undefined} />)}</svg>
            </button>
          ))}
        </div>
      </div>
      {shape && (
        <>
          <div className="sbx-field">
            <span className="sbx-field__label">{t('shape_color')}</span>
            <TokenSelect id={`${id}-${side}-color`} label={t('shape_color')} value={props[key('color')] ?? null} tokens={tokens} onChange={(v) => put('color', v ?? null)} />
          </div>
          <SliderField id={`${id}-${side}-w`} label={t('shape_width')} value={props[key('width')] || 100} min={100} max={300} unit="%" neutral={100} onChange={(v) => put('width', v ?? 100)} />
          <SliderField id={`${id}-${side}-h`} label={t('shape_height')} value={props[key('height')] || DEFAULT_SHAPE_HEIGHT} min={0} max={500} unit="px" neutral={0} onChange={(v) => put('height', v ?? 0)} />
          <Check label={t('shape_flip')} checked={!!props[key('flip')]} onChange={(e) => put('flip', e.target.checked)} />
          <Check label={t('shape_front')} checked={!!props[key('front')]} onChange={(e) => put('front', e.target.checked)} />
        </>
      )}
    </div>
  );
}
