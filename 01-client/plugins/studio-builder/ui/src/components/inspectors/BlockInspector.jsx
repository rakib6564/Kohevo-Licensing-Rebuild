// BlockInspector — metadata-driven editing of one block.
//
//   Content    props       from the block's FieldSchema manifest   → update_block_props
//   Style      align + the block's declared style capabilities     → update_block_style
//   Visibility devices / audience                                   → update_block_visibility
//   Data       binding slots → allowlisted providers + param schema → update_block_bindings
//
// Plus move/indent/outdent/delete actions (keyboard-accessible alternatives
// to drag & drop). Every change is a canonical operation.

import { useState } from 'react';
import { useEditor, useEngineState } from '../EditorContext.jsx';
import { FieldControl, ObjectFields, TokenSelect } from '../fields/FieldControl.jsx';
import { presentersFor } from '../../core/propPresenters.mjs';
import { ResponsiveSelect, VisibilityControls } from './controls.jsx';
import { StyleControls } from './StyleControls.jsx';
import { BorderExtras, DimensionsExtras, EffectsPane, LayoutPane, PositionPane, ShadowExtras, SpacingPane, StatesPane, TypographyExtras } from './SurfaceControls.jsx';
import { COLOUR_TOKEN_CATEGORIES, OPTIONS } from '../../core/styleSurface.mjs';
import { EntranceSelect, MotionInspector } from './MotionInspector.jsx';
import { ClassNamesField, DataAttributes, IdentityFields } from './AdvancedControls.jsx';
import { InspectorShell } from './InspectorShell.jsx';
import { InspectorHeader } from './InspectorHeader.jsx';
import { InspectorBar } from './InspectorBar.jsx';
import { asList, asObject, blockDefinition, blockIndentTarget, blockMoveTarget, blockOutdentTarget } from '../../core/doc.mjs';
import { STYLE_TOKEN_CATEGORIES, tokensFor } from '../../core/fields.mjs';
import { MEDIA_TYPES } from '../../core/inspectorSections.mjs';
import * as ops from '../../core/operations.mjs';
import { t } from '../../core/messages.mjs';
import { IconButton } from './InspectorIcons.jsx';
import { optionLabel } from '../../core/optionLabels.mjs';
import { Field, Popover } from '../ui/index.js';
import { DeviceStyle } from './DeviceStyle.jsx';
import { RESPONSIVE_SCOPES } from '../../core/responsiveStyle.mjs';

/** Mirrors CanonicalDocumentSchema::Z_INDEX_MIN / Z_INDEX_MAX. */
const Z_INDEX_MIN = -999;
const Z_INDEX_MAX = 999;

export function BlockInspector({ info }) {
  const { manifest, boot, applyOp, removeNode, moveBlockTo, viewport } = useEditor();
  const working = useEngineState((s) => s.working);
  const block = info.node;
  const def = blockDefinition(manifest, block.type);
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

  const sections = {
    content: () => (
      <ObjectFields
        schema={def.field_schema}
        value={props}
        manifest={manifest}
        mediaPicker={boot.mediaPicker}
        presenters={presentersFor(block.type)}
        blockType={block.type}
        onChange={(next) => save(ops.updateBlockProps(block.id, next))}
      />
    ),
    data: () => (
      <>
        <p className="sbx-hint">{t('bindings_hint')}</p>
        <BindingsEditor block={block} def={def} manifest={manifest} applyOp={applyOp} />
      </>
    ),
    align: () => (
      <ResponsiveSelect
        label={t('align')}
        icons="text"
        value={style.align ?? null}
        options={asList(manifest.vocabulary.alignments)}
        activeBreakpoint={viewport.breakpoint}
        onChange={(align) => saveStyle({ ...style, align: Object.keys(align).length ? align : null })}
      />
    ),
    typography: () => {
      const weight = capabilities.includes('typography') && (
        <Field label={t('font_weight')} htmlFor={`${idPrefix}-typo-weight`}>
          <select
            id={`${idPrefix}-typo-weight`}
            value={asObject(style.typography).weight || ''}
            onChange={(e) => patchTypography({ weight: e.target.value || undefined })}
          >
            <option value="">{t('inherit')}</option>
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
              <StyleControls style={style} capabilities={capabilities} only="typography" typoPart="main" mediaPicker={boot.mediaPicker} onChange={saveStyle} deviceScope={deviceScope} tokens={tokens} />
            </>
          )}
        >
          {transform}
          <StyleControls style={style} capabilities={capabilities} only="typography" typoPart="more" mediaPicker={boot.mediaPicker} onChange={saveStyle} deviceScope={deviceScope} tokens={tokens} />
          <TypographyExtras style={style} onChange={saveStyle} tokens={colourTokens} />
        </Popover>
      );
    },
    background: () => <StyleControls style={style} capabilities={capabilities} only="background" mediaPicker={boot.mediaPicker} onChange={saveStyle} tokens={manifest && manifest.tokens} />,
    border: () => (
      <Popover
        label={t('edit_border')}
        row={<StyleControls style={style} capabilities={capabilities} only="border" onChange={saveStyle} tokens={manifest && manifest.tokens} />}
      >
        <BorderExtras style={style} onChange={saveStyle} tokens={colourTokens} />
      </Popover>
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
        <StyleControls style={style} capabilities={capabilities} only="dimensions" onChange={saveStyle} />
        <DimensionsExtras style={style} media={MEDIA_TYPES.has(block.type)} onChange={saveStyle} />
      </>
    ),
    layout: () => <LayoutPane style={style} onChange={saveStyle} deviceScope={deviceScope} />,
    position: () => <PositionPane style={style} onChange={saveStyle} />,
    effects: () => <EffectsPane style={style} onChange={saveStyle} />,
    states: () => <StatesPane states={block.style_states} onChange={(next) => save(ops.updateBlockStyleStates(block.id, next))} />,
    tag: () => (
      <Field label={t('wrapper_tag')} htmlFor={`${idPrefix}-tag`}>
        <select id={`${idPrefix}-tag`} value={block.tag || 'div'} onChange={(e) => save(ops.updateBlockTag(block.id, e.target.value === 'div' ? null : e.target.value))}>
          {OPTIONS.tags.map((tag) => <option key={tag} value={tag}>{tag === 'div' ? optionLabel(tag) : `${optionLabel(tag)} · <${tag}>`}</option>)}
        </select>
        <p className="sbx-hint">{t('wrapper_tag_hint')}</p>
      </Field>
    ),
    opacity: () => <StyleControls style={style} capabilities={capabilities} only="opacity" onChange={saveStyle} />,
    tokens: () => Object.keys(STYLE_TOKEN_CATEGORIES).filter((k) => capabilities.includes(k)).map((key) => (
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
    motion: () => <MotionInspector block={block} applyOp={applyOp} label={def.label} />,
    responsive: () => (
      <>
      <VisibilityControls value={block.visibility} manifest={manifest} onChange={(v) => save(ops.updateBlockVisibility(block.id, v))} />
      <fieldset className="sbx-fieldset">
        <legend>{t('device_overrides')}</legend>
        {['desktop', 'tablet', 'mobile'].map((device) => {
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
      </>
    ),
    advanced: () => (
      <>
        <DeviceStyle scope={deviceScope} paths={RESPONSIVE_SCOPES.spacing} label={t('section_spacing')}>
          {(view) => <SpacingPane style={view.style} onChange={view.onChange} tokens={capabilities.includes('spacing_token') ? tokensFor(manifest, STYLE_TOKEN_CATEGORIES.spacing_token) : null} />}
        </DeviceStyle>
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
        <EntranceSelect block={block} applyOp={applyOp} label={def.label} />
        <IdentityFields block={block} doc={working} part="id" onChange={(attrs) => save(ops.updateBlockAttributes(block.id, attrs))} />
        <ClassNamesField id={`${idPrefix}-classes`} value={classNames} onChange={(list) => save(ops.updateBlockClassNames(block.id, list))} />
      </>
    ),
    identity: () => <IdentityFields block={block} doc={working} part="a11y" onChange={(attrs) => save(ops.updateBlockAttributes(block.id, attrs))} />,
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
