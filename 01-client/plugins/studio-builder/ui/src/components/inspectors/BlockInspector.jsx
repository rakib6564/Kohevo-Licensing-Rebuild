// BlockInspector — metadata-driven editing of one block.
//
//   Content    props       from the block's FieldSchema manifest   → update_block_props
//   Style      align + the block's declared style capabilities     → update_block_style
//   Visibility devices / audience                                   → update_block_visibility
//   Data       binding slots → allowlisted providers + param schema → update_block_bindings
//
// Plus move/indent/outdent/delete actions (keyboard-accessible alternatives
// to drag & drop). Every change is a canonical operation.

import { useEffect, useState } from 'react';
import { useEditor, useEngineState } from '../EditorContext.jsx';
import { FieldControl, ObjectFields, TokenSelect } from '../fields/FieldControl.jsx';
import { presentersFor, layoutOwnedProps } from '../../core/propPresenters.mjs';
import { ResponsiveSelect, VisibilityControls } from './controls.jsx';
import { StyleControls } from './StyleControls.jsx';
import { BorderExtras, DimensionsExtras, EffectsPane, LayoutPane, PositionPane, ShadowExtras, SpacingPane, StatesPane, TransformStates, TypographyExtras } from './SurfaceControls.jsx';
import { COLOUR_TOKEN_CATEGORIES, OPTIONS } from '../../core/styleSurface.mjs';
import { MotionInspector } from './MotionInspector.jsx';
import { PartStyles } from './PartStyles.jsx';
import { ClassNamesField, DataAttributes, IdentityFields } from './AdvancedControls.jsx';
import { ContainerPane } from './ContainerPane.jsx';
import { BackgroundStates } from './BackgroundStates.jsx';
import { BlendField, NormalHover, PaddingBox, TextColorField, TextShadowField, TextStrokeField } from './TextBlockStyle.jsx';
import { IconProps, ImageStates } from './MediaBlockStyle.jsx';
import { OverlayStates, ShapeDividers } from './ContainerLayers.jsx';
import { InspectorShell } from './InspectorShell.jsx';
import { InspectorHeader } from './InspectorHeader.jsx';
import { InspectorBar } from './InspectorBar.jsx';
import { asList, asObject, blockDefinition, blockIndentTarget, blockMoveTarget, blockOutdentTarget } from '../../core/doc.mjs';
import { STYLE_TOKEN_CATEGORIES, tokensFor } from '../../core/fields.mjs';
import { CONTAINER_TYPE, MEDIA_TYPES, alignTitleKey } from '../../core/inspectorSections.mjs';
import * as ops from '../../core/operations.mjs';
import { t } from '../../core/messages.mjs';
import { IconButton } from './InspectorIcons.jsx';
import { optionLabel } from '../../core/optionLabels.mjs';
import { Field, Popover } from '../ui/index.js';
import { DeviceStyle } from './DeviceStyle.jsx';
import { colourFieldProps, partField } from '../../core/effectiveColour.mjs';
import { useCanvasComputed } from '../../hooks/useCanvasComputed.js';
import { ALIGN_PROP_TYPES, alignPropBlocks, effectiveAlign } from '../../core/alignProp.mjs';
import { RESPONSIVE_SCOPES, legacyHideDevices } from '../../core/responsiveStyle.mjs';

/** What a layout block is before an author picks a display: a Container and Flex are flex boxes, a Grid is a grid. */
const LAYOUT_DISPLAY = Object.freeze({ 'core.container': 'flex', 'layout.flex': 'flex', 'layout.grid': 'grid' });

/** The token styles a colour control already offers as its theme colours. */
const COLOUR_CONTROL_TOKENS = ['surface_token', 'text_token', 'border_token'];

/** Mirrors CanonicalDocumentSchema::Z_INDEX_MIN / Z_INDEX_MAX. */
const Z_INDEX_MIN = -999;
const Z_INDEX_MAX = 999;

export function BlockInspector({ info }) {
  const { manifest, boot, applyOp, removeNode, moveBlockTo, viewport } = useEditor();
  const working = useEngineState((s) => s.working);
  const block = info.node;
  const def = blockDefinition(manifest, block.type);
  const computed = useCanvasComputed(block.id, block);
  const idPrefix = `sbx-blk-${block.id}`;

  if (!def) {
    return (
      <div className="sbx-inspector">
        <h2 className="sbx-inspector__title">{t('unavailable_block')}</h2>
        <p className="sbx-muted"><code>{block.type}</code></p>
        <button type="button" className="sbx-btn sbx-btn--danger" onClick={() => removeNode(block.id)}>{t('remove')}</button>
      </div>
    );
  }

  const props = asObject(block.props);
  const style = asObject(block.style);
  const responsive = asObject(block.responsive);
  const classNames = asList(block.classNames);
  const up = blockMoveTarget(working, manifest, block.id, 'up');
  const down = blockMoveTarget(working, manifest, block.id, 'down');
  const indent = blockIndentTarget(working, manifest, block.id);
  const outdent = blockOutdentTarget(working, manifest, block.id);
  const capabilities = asList(def.style_capabilities);
  const save = (op) => applyOp(op, { label: def.label });
  const saveStyle = (next) => save(ops.updateBlockStyle(block.id, next));
  // The controls that can differ per device edit through this (desktop = the style, tablet/mobile = block.responsive).
  const deviceScope = { style, responsive, saveStyle, saveResponsive: (next) => save(ops.updateBlockResponsive(block.id, next)) };
  const patchTypography = (patch) => saveStyle({ ...style, typography: { ...asObject(style.typography), ...patch } });

  // The theme's colour tokens: what the colour fields of a group's card offer next to a literal colour.
  const colourTokens = tokensFor(manifest, COLOUR_TOKEN_CATEGORIES);

  // What each field really shows on the page, and where an edit of it has to go (core/effectiveColour.mjs).
  const saveParts = (next) => save(ops.updateBlockStyleParts(block.id, next));
  const mainPart = asList(def.style_parts).find((p) => p.key === 'button');
  const effectiveCtx = { tokens: colourTokens, computed, partLabel: mainPart ? mainPart.label : null };
  const colours = (field, state = 'normal') => colourFieldProps(block, field, state, effectiveCtx, saveParts);
  const normalColours = (field) => colours(field);
  const held = (path) => partField(block, path, effectiveCtx, saveParts);
  const heldWeight = held('typography.weight');
  const surface = { colours: normalColours, held, computed };

  // Style's Alignment is the one control, for a block whose older prop still holds the value too.
  const hasAlignProp = ALIGN_PROP_TYPES.has(block.type);
  const saveAlign = (align) => {
    if (hasAlignProp && alignPropBlocks(props)) save(ops.updateBlockProps(block.id, { ...props, align: 'left' }));
    saveStyle({ ...style, align: Object.keys(align).length ? align : null });
  };

  const sections = {
    content: () => (
      <ObjectFields
        schema={def.field_schema}
        value={props}
        manifest={manifest}
        mediaPicker={boot.mediaPicker}
        presenters={presentersFor(block.type)}
        blockType={block.type}
        owned={layoutOwnedProps(block.type, style)}
        device={{ responsive, saveResponsive: deviceScope.saveResponsive }}
        onChange={(next) => save(ops.updateBlockProps(block.id, next))}
      />
    ),
    data: () => (
      <>
        <p className="sbx-hint">{t('bindings_hint')}</p>
        <BindingsEditor block={block} def={def} manifest={manifest} applyOp={applyOp} />
      </>
    ),
    // Inside a Button's or Heading's own section the row is named (Position, Alignment); as its own section the header names it.
    align: ({ label = null } = {}) => (
      <ResponsiveSelect
        label={label || t(alignTitleKey({ def, node: block }))}
        hideLegend={!label}
        icons="text"
        value={hasAlignProp ? effectiveAlign(props, style) : (style.align ?? null)}
        options={asList(manifest.vocabulary.alignments)}
        activeBreakpoint={viewport.breakpoint}
        onChange={saveAlign}
      />
    ),
    typography: ({ noTextColor = false } = {}) => {
      const weight = capabilities.includes('typography') && (
        <Field label={t('font_weight')} htmlFor={`${idPrefix}-typo-weight`}>
          <select
            id={`${idPrefix}-typo-weight`}
            value={heldWeight ? heldWeight.value : asObject(style.typography).weight || ''}
            onChange={(e) => (heldWeight ? heldWeight.put(e.target.value || undefined) : patchTypography({ weight: e.target.value || undefined }))}
          >
            <option value="">{computed ? `${t('inherit')} · ${computed.fontWeight}` : t('inherit')}</option>
            <option value="normal">{t('fw_normal')}</option>
            <option value="medium">{t('fw_medium')}</option>
            <option value="semibold">{t('fw_semibold')}</option>
            <option value="bold">{t('fw_bold')}</option>
            <option value="extrabold">{t('fw_extrabold')}</option>
          </select>
        </Field>
      );
      const transform = capabilities.includes('typography') && (
        <Field label={t('text_transform')} htmlFor={`${idPrefix}-typo-transform`}>
          <select
            id={`${idPrefix}-typo-transform`}
            value={asObject(style.typography).transform || ''}
            onChange={(e) => patchTypography({ transform: e.target.value || undefined })}
          >
            <option value="">{t('none')}</option>
            <option value="uppercase">{t('tt_uppercase')}</option>
            <option value="lowercase">{t('tt_lowercase')}</option>
            <option value="capitalize">{t('tt_capitalize')}</option>
          </select>
        </Field>
      );
      const tokens = manifest && manifest.tokens;
      // The size, weight and colour stay in the row; line height, spacing, family, case and decoration are in the pencil's card.
      return (
        <Popover
          label={t('edit_typography')}
          row={(
            <>
              {weight}
              <StyleControls style={style} capabilities={capabilities} only="typography" typoPart="main" mediaPicker={boot.mediaPicker} onChange={saveStyle} deviceScope={deviceScope} tokens={tokens} noTextColor={noTextColor} {...surface} />
            </>
          )}
        >
          {transform}
          <StyleControls style={style} capabilities={capabilities} only="typography" typoPart="more" mediaPicker={boot.mediaPicker} onChange={saveStyle} deviceScope={deviceScope} tokens={tokens} />
          <TypographyExtras style={style} onChange={saveStyle} tokens={colourTokens} />
        </Popover>
      );
    },
    container: () => (
      <ContainerPane
        key={block.id}
        block={block}
        style={style}
        onChange={saveStyle}
        deviceScope={deviceScope}
        onTag={(tag, link) => save(ops.updateBlockTag(block.id, tag, link))}
      />
    ),
    // Normal is the block's own background; Hover is its hover state's, beside the transition that eases into it.
    background: () => (
      <BackgroundStates
        style={style}
        states={block.style_states}
        onStyle={saveStyle}
        onStates={(next) => save(ops.updateBlockStyleStates(block.id, next))}
        tokens={colourTokens}
        colours={colours}
      >
        <StyleControls
          style={style}
          capabilities={capabilities}
          only="background"
          mediaPicker={boot.mediaPicker}
          onChange={saveStyle}
          tokens={manifest && manifest.tokens}
          {...surface}
          bgPanes={block.type === CONTAINER_TYPE ? {
            // A Container's video and slideshow: its own props, kept with the rest of its background.
            video: (
              <>
                <ObjectFields schema={asList(def.field_schema).filter((f) => f.key === 'bg_video')} value={props} manifest={manifest} blockType={block.type} onChange={(next) => save(ops.updateBlockProps(block.id, next))} />
                <p className="sbx-hint">{t('bg_video_hint')}</p>
              </>
            ),
            slideshow: (
              <>
                <ObjectFields schema={asList(def.field_schema).filter((f) => f.key === 'bg_slides' || f.key === 'bg_slide_seconds')} value={props} manifest={manifest} blockType={block.type} onChange={(next) => save(ops.updateBlockProps(block.id, next))} />
                <p className="sbx-hint">{t('bg_slides_hint')}</p>
              </>
            ),
          } : undefined}
        />
      </BackgroundStates>
    ),
    overlay: () => <OverlayStates style={style} states={block.style_states} onStyle={saveStyle} onStates={(next) => save(ops.updateBlockStyleStates(block.id, next))} tokens={manifest && manifest.tokens} />,
    shape_divider: () => <ShapeDividers props={props} onProps={(next) => save(ops.updateBlockProps(block.id, next))} tokens={colourTokens} />,
    // Every side's width, style and colour and every corner's radius are in view, not behind a pencil.
    border: () => (
      <>
        <StyleControls style={style} capabilities={capabilities} only="border" onChange={saveStyle} tokens={manifest && manifest.tokens} {...surface} />
        <BorderExtras style={style} onChange={saveStyle} tokens={colourTokens} />
        {block.type === CONTAINER_TYPE && sections.shadow()}
      </>
    ),
    shadow: () => {
      const custom = !!style.shadow && typeof style.shadow === 'object';
      return (
        <Popover
          label={t('edit_shadow')}
          row={custom ? <span className="sbx-pop__summary">{t('shadow_custom')}</span> : <StyleControls style={style} capabilities={capabilities} only="shadow" onChange={saveStyle} />}
        >
          <ShadowExtras style={style} onChange={saveStyle} tokens={colourTokens} />
        </Popover>
      );
    },
    dimensions: () => (
      <>
        <DeviceStyle scope={deviceScope} paths={RESPONSIVE_SCOPES.dimensions} label={t('dimensions_label')}>
          {(view) => <StyleControls style={view.style} capabilities={capabilities} only="dimensions" onChange={view.onChange} />}
        </DeviceStyle>
        <DimensionsExtras style={style} media={MEDIA_TYPES.has(block.type)} onChange={saveStyle} />
      </>
    ),
    layout: () => <LayoutPane style={style} onChange={saveStyle} deviceScope={deviceScope} defaultDisplay={LAYOUT_DISPLAY[block.type]} itemOnly={block.type === CONTAINER_TYPE} />,
    transform: () => <TransformStates label={def.label} style={style} states={block.style_states} onStyle={saveStyle} onStates={(next) => save(ops.updateBlockStyleStates(block.id, next))} />,
    effects: () => <EffectsPane style={style} onChange={saveStyle} />,
    states: () => <StatesPane type={block.type} states={block.style_states} tokens={colourTokens} colours={colours} onChange={(next) => save(ops.updateBlockStyleStates(block.id, next))} />,
    parts: () => (
      <>
        <p className="sbx-hint">{t('section_parts_hint')}</p>
        <PartStyles blockId={block.id} parts={def.style_parts} value={block.style_parts} responsive={responsive} tokens={colourTokens} onChange={(next) => save(ops.updateBlockStyleParts(block.id, next))} onResponsiveChange={deviceScope.saveResponsive} />
      </>
    ),
    // A Button's one Style section: position, typography, text shadow, the colours and surface per state, shadow, border (its corners too), padding.
    button_style: () => (
      <>
        {sections.align({ label: t('button_position') })}
        {sections.typography({ noTextColor: true })}
        <TextShadowField style={style} onChange={saveStyle} tokens={colourTokens} />
        <NormalHover label={def.label} style={style} states={block.style_states} onStyle={saveStyle} onStates={(next) => save(ops.updateBlockStyleStates(block.id, next))} tokens={colourTokens} colours={colours} withSurface>
          <TextColorField style={style} onChange={saveStyle} tokens={capabilities.includes('text_token') ? tokensFor(manifest, STYLE_TOKEN_CATEGORIES.text_token) : undefined} colourProps={colours('text')} />
          <StyleControls style={style} capabilities={capabilities} only="background" mediaPicker={boot.mediaPicker} onChange={saveStyle} tokens={manifest && manifest.tokens} {...surface} />
        </NormalHover>
        {sections.shadow()}
        {sections.border()}
        <PaddingBox deviceScope={deviceScope} />
      </>
    ),
    // A Heading's one Style section: alignment, typography, text stroke and shadow, blend mode, the text colour per state.
    heading_style: () => (
      <>
        {sections.align({ label: t('align') })}
        {sections.typography({ noTextColor: true })}
        <TextStrokeField style={style} onChange={saveStyle} tokens={colourTokens} />
        <TextShadowField style={style} onChange={saveStyle} tokens={colourTokens} />
        <BlendField style={style} onChange={saveStyle} />
        <NormalHover label={def.label} style={style} states={block.style_states} onStyle={saveStyle} onStates={(next) => save(ops.updateBlockStyleStates(block.id, next))} tokens={colourTokens} colours={colours}>
          <TextColorField style={style} onChange={saveStyle} tokens={capabilities.includes('text_token') ? tokensFor(manifest, STYLE_TOKEN_CATEGORIES.text_token) : undefined} colourProps={colours('text')} />
        </NormalHover>
      </>
    ),
    // A Text Editor's (or Text's) one Style section: alignment, text colour, typography, text shadow.
    text_style: () => (
      <>
        {sections.align({ label: t('align') })}
        <TextColorField style={style} onChange={saveStyle} tokens={capabilities.includes('text_token') ? tokensFor(manifest, STYLE_TOKEN_CATEGORIES.text_token) : undefined} colourProps={colours('text')} />
        {sections.typography({ noTextColor: true })}
        <TextShadowField style={style} onChange={saveStyle} tokens={colourTokens} />
      </>
    ),
    // An Image's one Style section: alignment, its size and fit, opacity and filters per state, border, corners, shadow.
    image_style: () => (
      <>
        {sections.align({ label: t('align') })}
        {sections.dimensions()}
        <ImageStates style={style} states={block.style_states} onStyle={saveStyle} onStates={(next) => save(ops.updateBlockStyleStates(block.id, next))} />
        {sections.border()}
        {sections.shadow()}
      </>
    ),
    // An Icon's one Style section: its primary colour per state, then the frame's secondary colour, size, padding, rotation and border.
    icon_style: () => (
      <>
        <NormalHover label={def.label} colorLabel={t('icon_primary_color')} style={style} states={block.style_states} onStyle={saveStyle} onStates={(next) => save(ops.updateBlockStyleStates(block.id, next))} tokens={colourTokens} colours={colours}>
          <TextColorField label={t('icon_primary_color')} style={style} onChange={saveStyle} tokens={capabilities.includes('text_token') ? tokensFor(manifest, STYLE_TOKEN_CATEGORIES.text_token) : undefined} colourProps={colours('text')} />
        </NormalHover>
        <IconProps props={props} onProps={(next) => save(ops.updateBlockProps(block.id, next))} tokens={colourTokens} />
      </>
    ),
    tag: () => (
      <Field label={t('wrapper_tag')} htmlFor={`${idPrefix}-tag`}>
        <select id={`${idPrefix}-tag`} value={block.tag || 'div'} onChange={(e) => save(ops.updateBlockTag(block.id, e.target.value === 'div' ? null : e.target.value))}>
          {OPTIONS.tags.map((tag) => <option key={tag} value={tag}>{tag === 'div' ? optionLabel(tag) : `${optionLabel(tag)} · <${tag}>`}</option>)}
        </select>
        <p className="sbx-hint">{t('wrapper_tag_hint')}</p>
      </Field>
    ),
    opacity: () => <StyleControls style={style} capabilities={capabilities} only="opacity" onChange={saveStyle} />,
    // The colour tokens are picked in the colour controls; they stay here only while one holds a value.
    tokens: () => Object.keys(STYLE_TOKEN_CATEGORIES).filter((k) => capabilities.includes(k) && (!COLOUR_CONTROL_TOKENS.includes(k) || style[k])).map((key) => (
      <Field label={t(`tok_field_${key}`)} htmlFor={`${idPrefix}-${key}`} key={key}>
        <TokenSelect
          id={`${idPrefix}-${key}`}
          label={t(`tok_field_${key}`)}
          value={style[key] ?? null}
          tokens={tokensFor(manifest, STYLE_TOKEN_CATEGORIES[key])}
          onChange={(v) => saveStyle({ ...style, [key]: v })}
        />
      </Field>
    )),
    motion: () => <MotionInspector block={block} applyOp={applyOp} label={def.label} tokens={tokensFor(manifest, ['surface', 'color'])} />,
    responsive: () => (
      <>
      <VisibilityControls value={block.visibility} manifest={manifest} onChange={(v) => save(ops.updateBlockVisibility(block.id, v))} />
      {legacyHideDevices(responsive).length > 0 && (
      <fieldset className="sbx-fieldset" data-legacy-hide>
        <legend>{t('device_overrides')}</legend>
        <p className="sbx-hint">{t('device_overrides_legacy')}</p>
        {legacyHideDevices(responsive).map((device) => {
          const devOverride = asObject(responsive[device]);
          return (
            <div key={device} className="sbx-field" style={{ marginBottom: '12px' }}>
              <label className="sbx-field__label" style={{ fontWeight: 'bold' }}>{t(device)}</label>
              <label className="sbx-field sbx-field--check">
                <input
                  type="checkbox"
                  checked={Boolean(devOverride.hide)}
                  onChange={(e) => {
                    const nextDev = { ...devOverride, hide: e.target.checked };
                    if (!e.target.checked) delete nextDev.hide;
                    const nextResp = { ...responsive, [device]: nextDev };
                    if (Object.keys(nextDev).length === 0) delete nextResp[device];
                    save(ops.updateBlockResponsive(block.id, nextResp));
                  }}
                />
                <span>{t(`hide_on_${device}`)}</span>
              </label>
            </div>
          );
        })}
      </fieldset>
      )}
      </>
    ),
    advanced: () => (
      <>
        <DeviceStyle scope={deviceScope} paths={RESPONSIVE_SCOPES.spacing} label={t('section_spacing')}>
          {(view) => <SpacingPane style={view.style} onChange={view.onChange} tokens={capabilities.includes('spacing_token') ? tokensFor(manifest, STYLE_TOKEN_CATEGORIES.spacing_token) : null} />}
        </DeviceStyle>
        {block.type === CONTAINER_TYPE && <LayoutPane style={style} onChange={saveStyle} deviceScope={deviceScope} itemOnly />}
        {capabilities.includes('position') && <PositionPane style={style} onChange={saveStyle} />}
        <Field label={t('z_index_label')} htmlFor={`${idPrefix}-z-index`}>
          <input
            id={`${idPrefix}-z-index`}
            type="number"
            className="sbx-input"
            min={Z_INDEX_MIN}
            max={Z_INDEX_MAX}
            value={style.z_index !== undefined && style.z_index !== null ? style.z_index : ''}
            placeholder="0"
            onChange={(e) => {
              // Same range the server enforces (CanonicalDocumentSchema::Z_INDEX_*): authors cannot stack above the platform signature.
              const raw = e.target.value === '' ? null : parseInt(e.target.value, 10);
              const val = raw === null || Number.isNaN(raw) ? null : Math.max(Z_INDEX_MIN, Math.min(Z_INDEX_MAX, raw));
              saveStyle({ ...style, z_index: val });
            }}
          />
        </Field>
        <IdentityFields block={block} doc={working} part="id" onChange={(attrs) => save(ops.updateBlockAttributes(block.id, attrs))} />
        <ClassNamesField id={`${idPrefix}-classes`} value={classNames} onChange={(list) => save(ops.updateBlockClassNames(block.id, list))} />
      </>
    ),
    identity: () => <IdentityFields block={block} doc={working} part="a11y" onChange={(attrs) => save(ops.updateBlockAttributes(block.id, attrs))} />,
    custom_css: () => <ElementCssField block={block} admin={!!(manifest && manifest.permissions && manifest.permissions.admin)} onChange={(css) => save(ops.updateBlockCustomCss(block.id, css))} />,
    attributes: () => <DataAttributes block={block} onChange={(attrs) => save(ops.updateBlockAttributes(block.id, attrs))} />,
  };

  return (
    <InspectorShell
      idPrefix={idPrefix}
      node={block}
      def={def}
      header={<InspectorHeader node={block} def={def} />}
      actions={(
        <div className="sbx-inspector__tools" role="group" aria-label={def.label}>
          <IconButton icon="up" label={t('move_up')} disabled={!up} onClick={() => moveBlockTo(block.id, up)} />
          <IconButton icon="down" label={t('move_down')} disabled={!down} onClick={() => moveBlockTo(block.id, down)} />
          <IconButton icon="indent" label={t('indent')} disabled={!indent} onClick={() => moveBlockTo(block.id, indent)} />
          <IconButton icon="outdent" label={t('outdent')} disabled={!outdent} onClick={() => moveBlockTo(block.id, outdent)} />
        </div>
      )}
      footer={<InspectorBar block={block} label={def.label} />}
      renderSection={(id) => (sections[id] ? sections[id]() : null)}
    />
  );
}

/** The element's own CSS, saved when the field is left. `selector` stands for this element; administrators only. */
function ElementCssField({ block, admin, onChange }) {
  const [draft, setDraft] = useState(block.custom_css || '');
  useEffect(() => { setDraft(block.custom_css || ''); }, [block.id, block.custom_css]);
  if (!admin) return <p className="sbx-hint">{t('element_css_admin')}</p>;
  return (
    <Field label={t('section_custom_css')} htmlFor={`sbx-element-css-${block.id}`}>
      <textarea
        id={`sbx-element-css-${block.id}`}
        className="sbx-input sbx-code"
        rows={8}
        spellCheck={false}
        maxLength={8192}
        placeholder={'selector { color: red; }'}
        value={draft}
        onChange={(e) => setDraft(e.target.value)}
        onBlur={() => { if (draft !== (block.custom_css || '')) onChange(draft); }}
      />
      <p className="sbx-hint">{t('element_css_hint')}</p>
    </Field>
  );
}

// A cleared optional parameter is left out: binding params are scalars, never null.
const withoutEmpty = (params) => Object.fromEntries(Object.entries(params).filter(([, v]) => v !== null && v !== undefined && v !== ''));

function BindingsEditor({ block, def, manifest, applyOp }) {
  const bindings = asObject(block.bindings);
  const commit = (next) => applyOp(ops.updateBlockBindings(block.id, next), { label: def.label });
  return asList(def.binding_slots).map((slot) => {
    const current = asObject(bindings[slot.slot]);
    const provider = asList(manifest.providers).find((p) => p.key === slot.provider);
    const bound = current.provider === slot.provider;
    return (
      <fieldset key={slot.slot} className="sbx-fieldset">
        <legend>{t('provider')}: <code>{slot.provider}</code></legend>
        <label className="sbx-field sbx-field--check">
          <input
            type="checkbox"
            checked={bound}
            disabled={!provider}
            onChange={(e) => {
              const next = { ...bindings };
              if (e.target.checked) next[slot.slot] = { provider: slot.provider };
              else delete next[slot.slot];
              commit(next);
            }}
          />
          <span>{slot.slot}</span>
        </label>
        {bound && provider && asList(provider.params).length > 0 && (
          <ObjectFields
            schema={provider.params}
            value={asObject(current.params)}
            manifest={manifest}
            onChange={(params) => commit({ ...bindings, [slot.slot]: { ...current, params: withoutEmpty(params) } })}
          />
        )}
      </fieldset>
    );
  });
}

export { FieldControl };
