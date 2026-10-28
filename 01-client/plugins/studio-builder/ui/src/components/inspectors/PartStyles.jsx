// PartStyles — style one part of a widget on its own (BlockParts.php): the titles of a Tabs block, the number of a Stat,
// the inputs of a Form.
//
// The author picks a part and Normal or Hover; the controls below edit that part's partial style in
// `block.style_parts` (`<part>` or `<part>:hover`), a closed set the server checks again (StyleSurface::partIssues):
// text colour, size, weight, case, letter spacing, line height, background colour, border, corners and padding.
// A cleared field is removed, and a part left empty is removed with it.

import { useState } from 'react';
import { asList, asObject } from '../../core/doc.mjs';
import { writePart } from '../../core/partStyles.mjs';
import { getPath, setPath, setPaths } from '../../core/styleSurface.mjs';
import { t } from '../../core/messages.mjs';
import { Field, Pills } from '../ui/index.js';
import { DraftText } from './StyleControls.jsx';
import { SelectField, SpacingBox, ThemeColor } from './SurfaceControls.jsx';

const WEIGHTS = ['normal', 'medium', 'semibold', 'bold', 'extrabold'];
const TRANSFORMS = ['none', 'uppercase', 'lowercase', 'capitalize'];
const BORDER_STYLES = ['solid', 'dashed', 'dotted', 'double', 'none'];

/** `parts`: the definition's `style_parts` ([{ key, label }]); `value`: the block's `style_parts`. */
export function PartStyles({ blockId, parts, value, tokens, onChange }) {
  const list = asList(parts);
  const [part, setPart] = useState(list.length ? list[0].key : '');
  const [state, setState] = useState('normal');
  if (!list.length) return null;
  const key = state === 'hover' ? `${part}:hover` : part;
  const style = asObject(asObject(value)[key]);
  const put = (path, v) => onChange(writePart(value, key, setPath(style, path, v)));
  const putAll = (pairs) => onChange(writePart(value, key, setPaths(style, pairs)));
  const get = (path) => getPath(style, path);
  const id = `sbx-part-${blockId}-${key.replace(':', '-')}`;
  const isSet = (k) => Object.keys(asObject(asObject(value)[k])).length > 0;
  const length = (path, label, placeholder = '') => (
    <DraftText id={`${id}-${path.replace(/\./g, '-')}`} label={label} kind="length" value={get(path)} placeholder={placeholder} onCommit={(v) => put(path, v)} />
  );

  return (
    <div className="sbx-parts" data-part-styles>
      <Field label={t('part_label')} htmlFor={`sbx-part-${blockId}`}>
        <select id={`sbx-part-${blockId}`} value={part} onChange={(e) => setPart(e.target.value)}>
          {list.map((p) => (
            <option key={p.key} value={p.key}>{p.label}{isSet(p.key) || isSet(`${p.key}:hover`) ? ' •' : ''}</option>
          ))}
        </select>
      </Field>
      <Pills
        label={t('part_state')}
        size="sm"
        value={state}
        options={[
          { value: 'normal', label: t('state_normal'), hasValue: isSet(part) },
          { value: 'hover', label: t('state_hover'), hasValue: isSet(`${part}:hover`) },
        ]}
        onChange={setState}
      />

      <ThemeColor id={`${id}-color`} label={t('text_color')} value={get('color')} tokens={tokens} onChange={(v) => put('color', v)} />
      {length('typography.size', t('font_size'), '16px')}
      <SelectField id={`${id}-weight`} label={t('font_weight')} value={get('typography.weight')} options={WEIGHTS} optionLabels={Object.fromEntries(WEIGHTS.map((w) => [w, t(`fw_${w}`)]))} onChange={(v) => put('typography.weight', v)} />
      <SelectField id={`${id}-transform`} label={t('text_transform')} value={get('typography.transform')} options={TRANSFORMS} optionLabels={{ none: t('none'), uppercase: t('tt_uppercase'), lowercase: t('tt_lowercase'), capitalize: t('tt_capitalize') }} onChange={(v) => put('typography.transform', v)} />
      {length('typography.letter_spacing', t('letter_spacing'), '0px')}
      {length('typography.line_height', t('line_height'), '1.5em')}
      <ThemeColor id={`${id}-bg`} label={t('background_color')} value={get('background.color')} tokens={tokens} onChange={(v) => put('background.color', v)} />
      {length('border.width', t('border_width'), '0px')}
      <SelectField id={`${id}-bstyle`} label={t('border_style')} value={get('border.style')} options={BORDER_STYLES} onChange={(v) => put('border.style', v)} />
      <ThemeColor id={`${id}-bcolor`} label={t('border_color')} value={get('border.color')} tokens={tokens} onChange={(v) => put('border.color', v)} />
      {length('border.radius', t('border_radius'), '0px')}
      <SpacingBox id={id} group="padding" label={t('padding_label')} get={get} putAll={putAll} />
      {state === 'normal' && <SpacingBox id={`${id}-m`} group="margin" label={t('margin_label')} get={get} putAll={putAll} />}
    </div>
  );
}
