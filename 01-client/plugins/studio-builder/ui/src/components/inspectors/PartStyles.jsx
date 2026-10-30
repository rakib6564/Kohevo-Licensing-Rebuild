// PartStyles — style one part of a widget on its own (BlockParts.php): the titles of a Tabs block, the number of a Stat,
// the inputs of a Form, the tiles of a Gallery.
//
// The author picks a part and Normal, Hover or All but the first (a line between items); the controls below edit that part's partial style in
// `block.style_parts` (`<part>`, `<part>:hover`, `<part>:focus` or `<part>:not_first`), a closed set the server checks again (StyleSurface::partIssues):
// text, background (a colour or a gradient), border and corners, padding and margin, size, the part's own flex or grid
// layout, opacity and effects. A cleared field is removed, and a part left empty is removed with it.
//
// On tablet or mobile (the editor's device switch) the same controls edit `block.responsive.<device>.style_parts`
// instead, limited to what the server takes per device (StyleSurface::responsivePartFields): font size, corners,
// padding, margin, size and layout. Empty there means "as on the larger device".

import { useState } from 'react';
import { asList, asObject } from '../../core/doc.mjs';
import { writePart } from '../../core/partStyles.mjs';
import { FILTER, OPTIONS, TRANSFORM, getPath, setPath, setPaths } from '../../core/styleSurface.mjs';
import { DEVICE_KEYS, OVERRIDE_DEVICES } from '../../core/responsiveStyle.mjs';
import { t } from '../../core/messages.mjs';
import { useEditor } from '../EditorContext.jsx';
import { Check, Field, Pills } from '../ui/index.js';
import { DraftText, GradientField } from './StyleControls.jsx';
import { IntField, SelectField, SliderField, SpacingBox, ThemeColor, TracksField } from './SurfaceControls.jsx';

const WEIGHTS = ['normal', 'medium', 'semibold', 'bold', 'extrabold'];
const TRANSFORMS = ['none', 'uppercase', 'lowercase', 'capitalize'];
const BORDER_STYLES = ['solid', 'dashed', 'dotted', 'double', 'none'];
const FAMILIES = ['heading', 'body'];
const DISPLAYS = ['block', 'flex', 'grid', 'inline-flex', 'inline-block', 'none'];
const FITS = ['cover', 'contain', 'fill', 'none', 'scale-down'];
const OVERFLOWS = ['visible', 'hidden', 'auto'];
const SIZES = ['width', 'height', 'min_width', 'min_height', 'max_width', 'max_height'];
const BORDER_SIDES = ['all', 'top', 'right', 'bottom', 'left', 'top_bottom', 'left_right'];
const POSITIONS = ['relative', 'absolute'];
const OFFSETS = ['top', 'right', 'bottom', 'left'];

/** A group that opens on its own when it holds a value, and then stays as the author leaves it (a device switch included). */
function Group({ title, initiallyOpen, children }) {
  const [open, setOpen] = useState(initiallyOpen);
  return (
    <details className="sbx-more" open={open} onToggle={(e) => setOpen(e.currentTarget.open)}>
      <summary>{title}</summary>
      {children}
    </details>
  );
}

/**
 * `parts`: the definition's `style_parts` ([{ key, label, states? }]); `value`: the block's `style_parts`;
 * `responsive` and `onResponsiveChange`: the block's `responsive`, for the tablet and mobile values.
 */
/** Part states besides the plain one (BlockParts::STATES): '' is the part itself. */
const PART_STATES = ['', 'hover', 'focus', 'not_first'];

export function PartStyles({ blockId, parts, value, responsive, tokens, onChange, onResponsiveChange }) {
  const list = asList(parts);
  const { viewport } = useEditor();
  const [part, setPart] = useState(list.length ? list[0].key : '');
  const [state, setState] = useState('normal');
  if (!list.length) return null;
  const device = DEVICE_KEYS.includes(viewport && viewport.key) ? viewport.key : 'desktop';
  const perDevice = OVERRIDE_DEVICES.includes(device) && typeof onResponsiveChange === 'function';
  const takesStates = (list.find((p) => p.key === part) || {}).states !== false;
  const shownState = perDevice || !takesStates ? 'normal' : state;
  const key = shownState === 'normal' ? part : `${part}:${shownState}`;

  const resp = asObject(responsive);
  const deviceParts = perDevice ? asObject(asObject(resp[device]).style_parts) : {};
  const source = perDevice ? deviceParts : asObject(value);
  const style = asObject(source[key]);
  const write = (partial) => {
    if (!perDevice) { onChange(writePart(value, key, partial)); return; }
    const nextParts = writePart(deviceParts, key, partial);
    const conf = { ...asObject(resp[device]) };
    if (Object.keys(nextParts).length) conf.style_parts = nextParts;
    else delete conf.style_parts;
    const next = { ...resp };
    if (Object.keys(conf).length) next[device] = conf;
    else delete next[device];
    onResponsiveChange(next);
  };
  const put = (path, v) => write(setPath(style, path, v));
  const putAll = (pairs) => write(setPaths(style, pairs));
  const get = (path) => getPath(style, path);
  const id = `sbx-part-${blockId}-${device}-${key.replace(':', '-')}`;
  const isSet = (k) => Object.keys(asObject(asObject(value)[k])).length > 0
    || OVERRIDE_DEVICES.some((d) => Object.keys(asObject(asObject(asObject(resp[d]).style_parts)[k])).length > 0);
  const length = (path, label, placeholder = '', kind = 'length') => (
    <DraftText key={path} id={`${id}-${path.replace(/\./g, '-')}`} label={label} kind={kind} value={get(path)} placeholder={placeholder} onCommit={(v) => put(path, v)} />
  );
  const select = (path, label, options, labels = null) => (
    <SelectField key={path} id={`${id}-${path.replace(/\./g, '-')}`} label={label} value={get(path)} options={options} optionLabels={labels} onChange={(v) => put(path, v)} />
  );
  const display = get('layout.display');
  const hasGradient = typeof get('background.gradient') === 'string';

  return (
    <div className="sbx-parts" data-part-styles>
      <Field label={t('part_label')} htmlFor={`sbx-part-${blockId}`}>
        <select id={`sbx-part-${blockId}`} value={part} onChange={(e) => setPart(e.target.value)}>
          {list.map((p) => (
            <option key={p.key} value={p.key}>{p.label}{PART_STATES.some((st) => isSet(st ? `${p.key}:${st}` : p.key)) ? ' •' : ''}</option>
          ))}
        </select>
      </Field>
      {perDevice ? (
        <p className="sbx-hint">{t('part_device_hint', { device: t(device) })}</p>
      ) : takesStates && (
        <Pills
          label={t('part_state')}
          size="sm"
          value={shownState}
          options={[
            { value: 'normal', label: t('state_normal'), hasValue: isSet(part) },
            { value: 'hover', label: t('state_hover'), hasValue: isSet(`${part}:hover`) },
            { value: 'focus', label: t('state_focus'), hasValue: isSet(`${part}:focus`) },
            { value: 'not_first', label: t('state_not_first'), hasValue: isSet(`${part}:not_first`) },
          ]}
          onChange={setState}
        />
      )}

      <fieldset className="sbx-fieldset">
        <legend>{t('part_text')}</legend>
        {!perDevice && <ThemeColor id={`${id}-color`} label={t('text_color')} value={get('color')} tokens={tokens} onChange={(v) => put('color', v)} />}
        {!perDevice && select('typography.family', t('font_family'), FAMILIES, { heading: t('font_heading'), body: t('font_body') })}
        {length('typography.size', t('font_size'), '16px')}
        {!perDevice && (
          <>
            {select('typography.weight', t('font_weight'), WEIGHTS, Object.fromEntries(WEIGHTS.map((w) => [w, t(`fw_${w}`)])))}
            {select('typography.transform', t('text_transform'), TRANSFORMS, { none: t('none'), uppercase: t('tt_uppercase'), lowercase: t('tt_lowercase'), capitalize: t('tt_capitalize') })}
            {length('typography.letter_spacing', t('letter_spacing'), '0px')}
            {length('typography.line_height', t('line_height'), '1.5em')}
          </>
        )}
      </fieldset>

      {!perDevice && (
        <fieldset className="sbx-fieldset">
          <legend>{t('part_fill')}</legend>
          <ThemeColor id={`${id}-bg`} label={t('background_color')} value={get('background.color')} tokens={tokens} onChange={(v) => put('background.color', v)} />
          <Check label={t('part_gradient')} checked={hasGradient} onChange={(e) => put('background.gradient', e.target.checked ? 'linear-gradient(135deg, #ff4dae, #e9008a)' : undefined)} />
          {hasGradient && <GradientField value={get('background.gradient')} onChange={(v) => put('background.gradient', v || undefined)} />}
          <SliderField id={`${id}-opacity`} label={t('part_opacity')} value={get('opacity')} min={0} max={100} unit="%" neutral={100} onChange={(v) => put('opacity', v)} />
        </fieldset>
      )}

      <fieldset className="sbx-fieldset">
        <legend>{t('part_border')}</legend>
        {!perDevice && (
          <>
            {length('border.width', t('border_width'), '0px')}
            {select('border.style', t('border_style'), BORDER_STYLES)}
            {select('border.sides', t('part_border_sides'), BORDER_SIDES, Object.fromEntries(BORDER_SIDES.map((k) => [k, t(`part_sides_${k}`)])))}
            <ThemeColor id={`${id}-bcolor`} label={t('border_color')} value={get('border.color')} tokens={tokens} onChange={(v) => put('border.color', v)} />
          </>
        )}
        {length('border.radius', t('border_radius'), '0px')}
      </fieldset>

      <SpacingBox id={id} group="padding" label={t('padding_label')} get={get} putAll={putAll} />
      {shownState === 'normal' && <SpacingBox id={`${id}-m`} group="margin" label={t('margin_label')} get={get} putAll={putAll} />}

      <Group title={t('part_size')} initiallyOpen={SIZES.some((s) => get(`size.${s}`) != null)}>
        {SIZES.map((s) => (s.startsWith('max_') ? length(`size.${s}`, t(`part_size_${s}`), 'none', 'maxLength') : length(`size.${s}`, t(`part_size_${s}`), 'auto')))}
        {select('size.object_fit', t('part_object_fit'), FITS)}
        {select('size.overflow', t('part_overflow'), OVERFLOWS)}
      </Group>

      <Group title={t('part_layout')} initiallyOpen={display != null}>
        {select('layout.display', t('layout_display'), DISPLAYS, { none: t('part_display_none') })}
        {(display === 'flex' || display === 'inline-flex') && (
          <>
            {select('layout.direction', t('layout_direction'), OPTIONS.direction)}
            {select('layout.wrap', t('layout_wrap'), OPTIONS.wrap)}
            {select('layout.justify', t('layout_justify'), OPTIONS.justify)}
          </>
        )}
        {display === 'grid' && (
          <>
            <IntField id={`${id}-columns`} label={t('layout_columns')} value={get('layout.columns')} min={1} max={12} onChange={(v) => put('layout.columns', v)} />
            <TracksField id={`${id}-tracks`} value={get('layout.tracks')} onChange={(v) => put('layout.tracks', v)} />
          </>
        )}
        {(display === 'flex' || display === 'inline-flex' || display === 'grid') && select('layout.align', t('layout_align'), OPTIONS.align)}
        {length('layout.gap', t('layout_gap'), '1rem')}
        {length('layout.row_gap', t('layout_row_gap'), '1rem')}
        {length('layout.column_gap', t('layout_column_gap'), '1rem')}
      </Group>

      {!perDevice && (
        <Group title={t('part_position')} initiallyOpen={get('position.mode') != null}>
          {select('position.mode', t('position_mode'), POSITIONS)}
          {get('position.mode') && OFFSETS.map((s) => length(`position.${s}`, t(`side_${s}`), 'auto'))}
        </Group>
      )}

      {!perDevice && (
        <Group title={t('effects_transform')} initiallyOpen={false}>
          <SliderField id={`${id}-scale`} label={t('fx_scale')} value={get('effects.transform.scale')} min={TRANSFORM.scale[0]} max={TRANSFORM.scale[1]} step={0.01} neutral={1} onChange={(v) => put('effects.transform.scale', v)} />
          <SliderField id={`${id}-rotate`} label={t('fx_rotate')} value={get('effects.transform.rotate')} min={TRANSFORM.rotate[0]} max={TRANSFORM.rotate[1]} unit="deg" neutral={0} onChange={(v) => put('effects.transform.rotate', v)} />
          {length('effects.transform.translate_x', t('fx_translate_x'), '0px')}
          {length('effects.transform.translate_y', t('fx_translate_y'), '0px')}
          <SliderField id={`${id}-grayscale`} label={t('fx_grayscale')} value={get('effects.filter.grayscale')} min={FILTER.grayscale[0]} max={FILTER.grayscale[1]} unit="%" neutral={0} onChange={(v) => put('effects.filter.grayscale', v)} />
          <SliderField id={`${id}-brightness`} label={t('fx_brightness')} value={get('effects.filter.brightness')} min={FILTER.brightness[0]} max={FILTER.brightness[1]} unit="%" neutral={100} onChange={(v) => put('effects.filter.brightness', v)} />
          {shownState === 'normal' && (
            <>
              <SliderField id={`${id}-duration`} label={t('fx_duration')} value={get('effects.transition.duration_ms')} min={0} max={2000} step={50} unit="ms" neutral={0} onChange={(v) => put('effects.transition.duration_ms', v)} />
              {select('effects.transition.easing', t('fx_easing'), OPTIONS.easing)}
              {select('effects.cursor', t('fx_cursor'), OPTIONS.cursor)}
            </>
          )}
        </Group>
      )}
    </div>
  );
}
