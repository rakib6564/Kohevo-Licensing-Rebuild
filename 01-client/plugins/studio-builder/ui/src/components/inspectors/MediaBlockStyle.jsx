// MediaBlockStyle — the pieces of an Image's and an Icon's one Style section, laid out like the reference editor.
//
// An Image's Normal / Hover tabs edit its opacity and CSS filters (the block's own style, or its hover state), with a hover
// zoom and the transition that eases into it. An Icon's size, frame and rotation are its own props (set here, not under
// Content), next to its primary colour per state and the frame's secondary colour.

import { useId, useState } from 'react';
import { Pills } from '../ui/index.js';
import { TokenSelect } from '../fields/FieldControl.jsx';
import { SliderField } from './SurfaceControls.jsx';
import { asObject } from '../../core/doc.mjs';
import { FILTER, getPath, setPath } from '../../core/styleSurface.mjs';
import { t } from '../../core/messages.mjs';

const FILTER_NEUTRAL = { blur: 0, brightness: 100, contrast: 100, saturate: 100, grayscale: 0 };

/** Opacity and the CSS filters of one style object (the block's own, or its hover state). */
function LookSliders({ id, look, put }) {
  return (
    <>
      <SliderField id={`${id}-opacity`} label={t('opacity_label')} value={getPath(look, 'opacity')} min={0} max={1} step={0.05} neutral={1} onChange={(v) => put('opacity', v)} />
      <fieldset className="sbx-fieldset" data-testid="css-filters">
        <legend>{t('css_filters')}</legend>
        {Object.entries(FILTER).map(([name, [min, max, unit]]) => (
          <SliderField key={name} id={`${id}-${name}`} label={t(`fx_${name}`)} value={getPath(look, `effects.filter.${name}`)} min={min} max={max} unit={unit} neutral={FILTER_NEUTRAL[name]} onChange={(v) => put(`effects.filter.${name}`, v)} />
        ))}
      </fieldset>
    </>
  );
}

/** An Image's Normal / Hover tabs. */
export function ImageStates({ style, states, onStyle, onStates }) {
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
    <div className="sbx-normalhover" data-testid="image-states">
      <Pills
        role="tablist"
        label={t('section_image')}
        value={tab}
        options={[{ value: 'normal', label: t('state_normal') }, { value: 'hover', label: t('state_hover'), hasValue: Object.keys(hover).length > 0 }]}
        onChange={setTab}
      />
      {tab === 'normal' ? (
        <LookSliders id={`${id}-n`} look={style} put={(path, v) => onStyle(setPath(style, path, v))} />
      ) : (
        <>
          <LookSliders id={`${id}-h`} look={hover} put={putHover} />
          <SliderField id={`${id}-zoom`} label={t('hover_zoom')} value={getPath(hover, 'effects.transform.scale')} min={1} max={2} step={0.05} neutral={1} onChange={(v) => putHover('effects.transform.scale', v)} />
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
        </>
      )}
    </div>
  );
}

/** An Icon's own props in its Style section: the frame's secondary colour, size, padding, rotation and border width. */
export function IconProps({ props, onProps, tokens }) {
  const id = useId();
  const framed = props.view === 'stacked' || props.view === 'framed';
  const put = (key, value) => onProps({ ...props, [key]: value ?? (key === 'secondary_token' ? null : 0) });
  return (
    <div data-testid="icon-props">
      {framed && (
        <div className="sbx-field">
          <span className="sbx-field__label">{t('icon_secondary_color')}</span>
          <TokenSelect id={`${id}-secondary`} label={t('icon_secondary_color')} value={props.secondary_token ?? null} tokens={tokens} onChange={(v) => put('secondary_token', v)} />
        </div>
      )}
      <SliderField id={`${id}-size`} label={t('icon_size')} value={props.size_px || undefined} min={0} max={300} unit="px" neutral={0} onChange={(v) => put('size_px', v)} />
      {framed && <SliderField id={`${id}-pad`} label={t('padding_label')} value={props.frame_padding || undefined} min={0} max={100} unit="px" neutral={0} onChange={(v) => put('frame_padding', v)} />}
      <SliderField id={`${id}-rotate`} label={t('icon_rotate')} value={props.rotate || undefined} min={-360} max={360} unit="°" neutral={0} onChange={(v) => put('rotate', v)} />
      {props.view === 'framed' && <SliderField id={`${id}-border`} label={t('icon_border_width')} value={props.frame_border || undefined} min={0} max={20} unit="px" neutral={0} onChange={(v) => put('frame_border', v)} />}
    </div>
  );
}
