// ContainerPane — a Container's own section, laid out like the reference editor: Flexbox or Grid, Boxed or Full width with
// its width, vertical spacing, a minimum height, how the items sit (direction, justify, align, gaps, wrap) and, folded away, overflow and
// the HTML element (including a whole-container link).
//
// It edits the same document values as the panes it replaces (`style.layout`, `style.dimensions`, the block's `tag`),
// plus the boxed width (`layout.content_width` / `content_size` / `content_max`), `layout.padding_y` and `link`. A Container's older props (gap, direction,
// wrap) still render; they show here as what the fields start from until the layout overrides them.

import { useEffect, useId, useState } from 'react';
import { Field, Check } from '../ui/index.js';
import { useEditor, useEngineState } from '../EditorContext.jsx';
import { DraftText } from './StyleControls.jsx';
import { IconChoice, SelectField } from './SurfaceControls.jsx';
import { DeviceStyle } from './DeviceStyle.jsx';
import { Icon } from './InspectorIcons.jsx';
import { OPTIONS, getPath, paddingBeatsSpacing, setPath, setPaths, surfaceAccepts } from '../../core/styleSurface.mjs';
import { OVERRIDE_DEVICES, RESPONSIVE_SCOPES, clearOverride, hasOverride } from '../../core/responsiveStyle.mjs';
import { blockLinkIssue } from '../../core/operations.mjs';
import { asList, asObject } from '../../core/doc.mjs';
import { optionLabel } from '../../core/optionLabels.mjs';
import { sizedLabel } from '../../core/optionValues.mjs';
import { boxWidthLabel, boxWidthOf, boxWidths, siteContentPx } from '../../core/siteWidths.mjs';
import { templateCapsWidth } from '../../core/layoutTemplate.mjs';
import { t } from '../../core/messages.mjs';

/** What the Container's own `gap` prop (a spacing step) measures, so the gap fields can show it as their starting point. */
const GAP_STEPS = Object.freeze({ none: '0', xs: '4', sm: '8', md: '16', lg: '24', xl: '32', '2xl': '48' });

/** Slider ranges per unit: [min, max, step]. A unit not listed only takes typed values. */
const RANGES = Object.freeze({
  px: [0, 1600, 10], '%': [0, 100, 1], vw: [0, 100, 1], vh: [0, 100, 1], rem: [0, 100, 0.5], em: [0, 100, 0.5],
});

/** A Container's own boxed width, the one layout value a device can set beside the flex and grid fields. */
const BOX_MAX = Object.freeze(['layout.content_max']);

const NUMBER_UNIT = /^(\d*\.?\d+)([a-z%]*)$/i;

/**
 * A length as a slider, a number and a unit, like the reference editor's size fields. The slider commits when it is let go
 * (one undo step per drag); a value it cannot show (calc(), say) stays editable as text.
 */
export function UnitSlider({ id, label, value, units, path, placeholder, onCommit, hint }) {
  const parsed = typeof value === 'string' ? value.trim().match(NUMBER_UNIT) : null;
  const [unit, setUnit] = useState((parsed && parsed[2]) || units[0]);
  const [drag, setDrag] = useState(null);
  const range = RANGES[unit];
  const number = parsed ? Number(parsed[1]) : null;
  const shown = drag ?? (number ?? '');
  const commit = (n, u = unit) => {
    if (n === '' || n === null || Number.isNaN(Number(n))) { onCommit(undefined); return; }
    const next = `${Number(n)}${u}`;
    if (!path || surfaceAccepts(path, next) || /^dimensions\./.test(path)) onCommit(next === value ? undefined : next);
  };
  if (value && !parsed) {
    return <DraftText id={id} label={label} value={value} path={path} placeholder={placeholder} onCommit={onCommit} hint={hint} />;
  }
  const release = () => { if (drag !== null) { commit(drag); setDrag(null); } };
  return (
    <Field label={label} htmlFor={id} hint={hint} className="sbx-unitslider">
      <div className="sbx-slider">
        {range && (
          <input
            type="range"
            aria-label={label}
            min={range[0]}
            max={range[1]}
            step={range[2]}
            value={shown === '' ? range[0] : Math.min(range[1], Number(shown))}
            onChange={(e) => setDrag(e.target.value)}
            onPointerUp={release}
            onKeyUp={release}
            onBlur={release}
          />
        )}
        <span className="sbx-slider__num">
          <input
            id={id}
            type="number"
            className="sbx-input"
            min="0"
            step={range ? range[2] : 'any'}
            placeholder={placeholder}
            value={shown}
            onChange={(e) => setDrag(e.target.value)}
            onBlur={(e) => { commit(e.target.value); setDrag(null); }}
            onKeyDown={(e) => { if (e.key === 'Enter') e.currentTarget.blur(); }}
          />
          <select
            className="sbx-unitfield__unit"
            aria-label={`${label} (${t('unit')})`}
            value={unit}
            onChange={(e) => { setUnit(e.target.value); if (number !== null) commit(number, e.target.value); }}
          >
            {units.map((u) => <option key={u} value={u}>{u}</option>)}
          </select>
        </span>
      </div>
    </Field>
  );
}

/** The Single template that lays the open page out on the live site, re-read with each revision (its category can change). */
function useLayoutTemplate(wanted) {
  const { transport, boot } = useEditor();
  const page = useEngineState((s) => s.page);
  const revisionId = useEngineState((s) => (s.revision ? s.revision.id : 0));
  const chromed = wanted && !!page && ['page', 'landing'].includes(page.page_type);
  const [template, setTemplate] = useState(null);
  useEffect(() => {
    if (!chromed || !transport || typeof transport.layoutTemplate !== 'function') { setTemplate(null); return undefined; }
    let alive = true;
    transport.layoutTemplate(boot.pageId).then((res) => {
      if (alive) setTemplate(res && res.ok && res.data ? res.data.template || null : null);
    }).catch(() => { if (alive) setTemplate(null); });
    return () => { alive = false; };
  }, [transport, boot.pageId, revisionId, chromed]);
  return template;
}

/** Column and row gap side by side, linked by default (one value for both), in px unless a unit is typed. */
function GapFields({ id, style, onChange, placeholder }) {
  const column = getPath(style, 'layout.column_gap');
  const row = getPath(style, 'layout.row_gap');
  const [linked, setLinked] = useState(() => (column ?? null) === (row ?? null));
  const put = (path, v) => {
    const pairs = linked ? [['layout.column_gap', v], ['layout.row_gap', v]] : [[path, v]];
    onChange(setPaths(style, pairs));
  };
  return (
    <fieldset className="sbx-fieldset sbx-gaps">
      <legend>{t('container_gaps')}</legend>
      <div className="sbx-gaps__row">
        <DraftText id={`${id}-colgap`} path="layout.column_gap" label={t('gap_column')} value={column} placeholder={placeholder} bareUnit="px" onCommit={(v) => put('layout.column_gap', v)} />
        <DraftText id={`${id}-rowgap`} path="layout.row_gap" label={t('gap_row')} value={row} placeholder={placeholder} bareUnit="px" onCommit={(v) => put('layout.row_gap', v)} />
        <button type="button" className="sbx-iconbtn sbx-spacing__link" aria-pressed={linked} data-testid="gaps-link" onClick={() => setLinked((v) => !v)}>
          <Icon name="link" size={14} />
          <span className="sbx-sr-only">{linked ? t('gaps_linked') : t('gaps_unlinked')}</span>
        </button>
      </div>
    </fieldset>
  );
}

/**
 * The items: what can differ per device. `style` is the view for the device being edited; `base` is the desktop style,
 * which a tablet or phone inherits from, and the Container's older props are what desktop starts from.
 */
function ItemsFields({ id, style, base, onChange, grid, props }) {
  const get = (path) => getPath(style, path);
  const put = (path, v) => onChange(setPath(style, path, v));
  const fromProp = props.direction === 'horizontal' ? 'row' : 'column';
  const wrapFromProp = props.direction === 'horizontal' ? (props.wrap === 'nowrap' ? 'nowrap' : 'wrap') : undefined;
  // On desktop the view and `base` agree, so this is the prop's value; a tablet or phone inherits desktop's first.
  const inherited = (path, fallback) => getPath(base, path) ?? fallback;
  // A choice shown because it is inherited is pressed but not stored: choosing it stores it instead of clearing it.
  const choose = (path, shown) => (v) => put(path, v === undefined && get(path) === undefined ? shown : v);
  const direction = get('layout.direction') ?? inherited('layout.direction', fromProp);
  const wrap = get('layout.wrap') ?? inherited('layout.wrap', wrapFromProp);
  const gapHint = GAP_STEPS[props.gap] ?? GAP_STEPS.md;
  return (
    <>
      {!grid && <IconChoice label={t('layout_direction')} kind="dir" value={direction} options={OPTIONS.direction} onChange={choose('layout.direction', direction)} />}
      {grid && (
        <div className="sbx-gridcount">
          <UnitlessInt id={`${id}-cols`} label={t('layout_columns')} value={get('layout.columns')} onChange={(v) => put('layout.columns', v)} />
          <UnitlessInt id={`${id}-rows`} label={t('layout_rows')} value={get('layout.rows')} onChange={(v) => put('layout.rows', v)} />
        </div>
      )}
      <IconChoice label={t('layout_justify')} kind="justify" value={get('layout.justify')} options={OPTIONS.justify} onChange={(v) => put('layout.justify', v)} />
      <IconChoice label={t('layout_align')} kind="items" value={get('layout.align')} options={OPTIONS.align.filter((a) => a !== 'baseline')} onChange={(v) => put('layout.align', v)} />
      <GapFields id={id} style={style} onChange={onChange} placeholder={gapHint} />
      {!grid && (
        <>
          <IconChoice label={t('layout_wrap')} kind="wrap" value={wrap} options={['nowrap', 'wrap']} onChange={choose('layout.wrap', wrap)} />
          <p className="sbx-hint">{t('container_wrap_hint')}</p>
          {/* How wrapped lines share the height, once there can be more than one. */}
          {wrap === 'wrap' && (
            <SelectField id={`${id}-align-content`} label={t('layout_align_content')} value={get('layout.align_content')} options={OPTIONS.alignContent} onChange={(v) => put('layout.align_content', v)} />
          )}
        </>
      )}
    </>
  );
}

function UnitlessInt({ id, label, value, onChange }) {
  return (
    <Field label={label} htmlFor={id}>
      <input
        id={id}
        type="number"
        className="sbx-input"
        min="1"
        max="12"
        step="1"
        value={value ?? ''}
        onChange={(e) => {
          if (e.target.value === '') { onChange(undefined); return; }
          const n = parseInt(e.target.value, 10);
          if (!Number.isNaN(n)) onChange(Math.max(1, Math.min(12, n)));
        }}
      />
    </Field>
  );
}

/** The HTML element, and where a linked (`a`) container points. */
function TagFields({ id, block, onTag }) {
  const tag = block.tag || 'div';
  const link = asObject(block.link);
  const [draft, setDraft] = useState(link.url || '');
  const [error, setError] = useState('');
  const saveLink = (url, newTab = link.new_tab === true) => {
    const next = url.trim() ? { url: url.trim(), ...(newTab ? { new_tab: true } : {}) } : null;
    const issue = next ? blockLinkIssue(next) : '';
    setError(issue ? t('container_link_invalid') : '');
    if (!issue) onTag('a', next);
  };
  return (
    <>
      <Field label={t('html_tag')} htmlFor={`${id}-tag`}>
        <select id={`${id}-tag`} value={tag} onChange={(e) => onTag(e.target.value === 'div' ? null : e.target.value, e.target.value === 'a' && link.url ? link : null)}>
          {OPTIONS.tags.map((v) => <option key={v} value={v}>{v === 'div' ? t('tag_default') : v === 'a' ? t('tag_link') : `${optionLabel(v)} · <${v}>`}</option>)}
        </select>
      </Field>
      {tag === 'main' && <p className="sbx-hint">{t('container_main_hint')}</p>}
      {tag === 'a' && (
        <>
          <Field label={t('container_link_url')} htmlFor={`${id}-href`} error={error || null} errorId={`${id}-href-err`}>
            <input
              id={`${id}-href`}
              type="text"
              className="sbx-input"
              placeholder={t('url_placeholder_path')}
              value={draft}
              aria-invalid={error ? true : undefined}
              onChange={(e) => setDraft(e.target.value)}
              onBlur={() => saveLink(draft)}
              onKeyDown={(e) => { if (e.key === 'Enter') e.currentTarget.blur(); }}
            />
          </Field>
          <Check label={t('container_link_new_tab')} checked={link.new_tab === true} disabled={!link.url} onChange={(e) => saveLink(draft, e.target.checked)} />
          <p className="sbx-hint">{t('container_link_hint')}</p>
        </>
      )}
    </>
  );
}

/**
 * @param {object} props
 * @param {object} props.block        the Container block
 * @param {object} props.style        its style (desktop)
 * @param {(next: object) => void} props.onChange       saves the style
 * @param {object} props.deviceScope  for the fields that can differ per device
 * @param {(tag: string|null, link: object|null) => void} props.onTag  saves the HTML element (and its link)
 * @param {boolean} [props.top]       a top-level Container, the one a page template's width can hold in
 */
export function ContainerPane({ block, style, onChange, deviceScope, onTag, top = false }) {
  const id = useId();
  const { layoutChooser, setLayoutChooser, manifest, boot } = useEditor();
  const layoutTemplate = useLayoutTemplate(top);
  const siteContentWidth = siteContentPx(manifest) || '1280';
  const layout = asObject(style.layout);
  const props = asObject(block.props);
  const grid = layout.display === 'grid';
  const boxed = layout.content_width === 'boxed';
  const put = (path, v) => onChange(setPath(style, path, v));
  const setContentWidth = (v) => {
    // Boxed holds the children to a width while the container itself stays full width, so its own width goes.
    const pairs = [['layout.content_width', v === 'full' ? undefined : v]];
    // With no width of its own, Boxed keeps to the site's content width (Site settings › Layout), as in Elementor.
    if (v === 'boxed') {
      pairs.push(['dimensions.width', undefined]);
    } else {
      pairs.push(['layout.content_max', undefined], ['layout.content_size', undefined]);
    }
    onChange(setPaths(style, pairs));
  };
  const boxWidth = boxWidthOf(layout);
  const setBoxWidth = (v) => {
    const custom = v === 'custom';
    onChange(setPaths(style, [
      ['layout.content_size', custom || v === 'default' ? undefined : v],
      ['layout.content_max', custom ? (layout.content_max ?? `${siteContentWidth}px`) : undefined],
    ]));
    // A device's own width belongs to Custom: it would still hold the box in otherwise.
    const devices = OVERRIDE_DEVICES.filter((d) => hasOverride(deviceScope.responsive, d, BOX_MAX));
    if (!custom && devices.length) deviceScope.saveResponsive(devices.reduce((r, d) => clearOverride(r, d, BOX_MAX), deviceScope.responsive));
  };
  const spacing = asList(manifest && manifest.vocabulary && manifest.vocabulary.spacing_scale);
  return (
    <div className="sbx-containerpane" data-testid="container-pane">
      <SelectField
        id={`${id}-display`}
        label={t('container_layout')}
        value={grid ? 'grid' : 'flex'}
        options={['flex', 'grid']}
        emptyLabel={null}
        onChange={(v) => {
          // Choosing here answers the canvas's "which layout?" question for this container too.
          if (layoutChooser === block.id && setLayoutChooser) setLayoutChooser(null);
          put('layout.display', v);
        }}
        optionLabels={{ flex: t('container_layout_flex'), grid: t('container_layout_grid') }}
      />
      <SelectField
        id={`${id}-content-width`}
        label={t('container_content_width')}
        value={boxed ? 'boxed' : 'full'}
        options={OPTIONS.contentWidth}
        emptyLabel={null}
        onChange={setContentWidth}
      />
      {boxed ? (
        <>
          <Field label={t('container_width')} htmlFor={`${id}-box-width`} hint={boxWidth === 'default' ? t('container_width_site', { width: `${siteContentWidth}px` }) : null}>
            <select id={`${id}-box-width`} value={boxWidth} onChange={(e) => setBoxWidth(e.target.value)}>
              {boxWidths(manifest).map((w) => <option key={w} value={w}>{boxWidthLabel(manifest, w)}</option>)}
            </select>
          </Field>
          {boxWidth === 'custom' && (
            <DeviceStyle scope={deviceScope} paths={BOX_MAX} label={t('custom_width')}>
              {(view) => (
                <UnitSlider
                  id={`${id}-content-max`}
                  label={t('custom_width')}
                  value={getPath(view.style, 'layout.content_max')}
                  units={['px', '%', 'vw', 'rem']}
                  path="layout.content_max"
                  placeholder={siteContentWidth}
                  onCommit={(v) => view.onChange(setPath(view.style, 'layout.content_max', v ?? undefined))}
                />
              )}
            </DeviceStyle>
          )}
        </>
      ) : (
        <DeviceStyle scope={deviceScope} paths={['dimensions.width']} label={t('container_width')}>
          {(view) => (
            <UnitSlider id={`${id}-width`} label={t('container_width')} value={getPath(view.style, 'dimensions.width')} units={['%', 'px', 'vw', 'rem']} path="dimensions.width" placeholder="100" onCommit={(v) => view.onChange(setPath(view.style, 'dimensions.width', v))} />
          )}
        </DeviceStyle>
      )}
      {top && templateCapsWidth(layoutTemplate, boxed ? boxWidth : 'full') && (
        <p className="sbx-hint" role="note" data-testid="width-template-cap">
          {t('width_template_cap', { title: layoutTemplate.title, width: boxWidthLabel(manifest, layoutTemplate.content_width) })}
          {' '}
          <a href={`${boot.builderUrl || ''}?page=${layoutTemplate.id}`} target="_blank" rel="noopener">{t('width_template_edit')}</a>
        </p>
      )}
      <DeviceStyle scope={deviceScope} paths={['layout.padding_y']} label={t('padding_y')}>
        {(view) => (
          <SelectField
            id={`${id}-padding-y`}
            label={t('padding_y')}
            value={getPath(view.style, 'layout.padding_y')}
            options={spacing}
            optionLabels={Object.fromEntries(spacing.map((s) => [s, sizedLabel('container.padding_y', s)]))}
            onChange={(v) => view.onChange(setPath(view.style, 'layout.padding_y', v))}
          />
        )}
      </DeviceStyle>
      {paddingBeatsSpacing(style) && <p className="sbx-hint">{t('container_padding_overridden')}</p>}
      <DeviceStyle scope={deviceScope} paths={['dimensions.min_height']} label={t('container_min_height')}>
        {(view) => (
          <UnitSlider
            id={`${id}-min-height`}
            label={t('container_min_height')}
            value={getPath(view.style, 'dimensions.min_height')}
            units={['px', 'vh', 'vw', 'rem', 'em']}
            path="dimensions.min_height"
            placeholder="0"
            hint={t('container_min_height_hint')}
            onCommit={(v) => view.onChange(setPath(view.style, 'dimensions.min_height', v))}
          />
        )}
      </DeviceStyle>

      <h4 className="sbx-containerpane__head">{t('container_items')}</h4>
      <DeviceStyle scope={deviceScope} paths={RESPONSIVE_SCOPES.layout} label={t('container_items')}>
        {(view) => <ItemsFields id={id} style={view.style} base={style} onChange={view.onChange} grid={grid} props={props} />}
      </DeviceStyle>

      <details className="sbx-more sbx-containerpane__more" data-testid="container-more">
        <summary>{t('container_more')}</summary>
        <SelectField id={`${id}-overflow`} label={t('dim_overflow')} value={getPath(style, 'dimensions.overflow')} options={OPTIONS.overflow} emptyLabel={t('tag_default')} onChange={(v) => put('dimensions.overflow', v)} />
        <TagFields id={id} block={block} onTag={onTag} />
      </details>
    </div>
  );
}
