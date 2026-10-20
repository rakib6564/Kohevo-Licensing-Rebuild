// BackgroundStates — the Background section's Normal and Hover tabs, as in the reference editor.
//
// Normal is the block's own background (the children: the usual background controls). Hover edits the hover state's
// background (`style_states.hover.background`), the same value the States section edits, next to the transition
// duration that eases between the two (`style.effects.transition.duration_ms`).

import { useId, useState } from 'react';
import { Pills } from '../ui/index.js';
import { ColorField } from './StyleControls.jsx';
import { SliderField } from './SurfaceControls.jsx';
import { asObject } from '../../core/doc.mjs';
import { getPath, setPath } from '../../core/styleSurface.mjs';
import { t } from '../../core/messages.mjs';

export function BackgroundStates({ style, states, onStyle, onStates, children }) {
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
  const hoverSet = !!getPath(hover, 'background.color') || !!getPath(hover, 'background.gradient');
  return (
    <>
      <Pills
        role="tablist"
        label={t('background_label')}
        value={tab}
        options={[
          { value: 'normal', label: t('state_normal') },
          { value: 'hover', label: t('state_hover'), hasValue: hoverSet },
        ]}
        onChange={setTab}
      />
      {tab === 'normal' ? children : (
        <div className="sbx-bg-hover" data-testid="bg-hover">
          <p className="sbx-hint">{t('bg_hover_hint')}</p>
          <ColorField id={`${id}-hover-bg`} label={t('background_color')} value={getPath(hover, 'background.color')} onChange={(v) => putHover('background.color', v)} />
          <SliderField
            id={`${id}-hover-duration`}
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
    </>
  );
}
