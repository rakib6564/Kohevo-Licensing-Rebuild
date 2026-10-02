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
import { ResponsiveSelect, Tabs, VisibilityControls } from './controls.jsx';
import { asList, asObject, blockDefinition, blockIndentTarget, blockMoveTarget, blockOutdentTarget } from '../../core/doc.mjs';
import { STYLE_TOKEN_CATEGORIES, tokensFor } from '../../core/fields.mjs';
import * as ops from '../../core/operations.mjs';
import { t } from '../../core/messages.mjs';

export function BlockInspector({ info }) {
  const { manifest, boot, applyOp, removeNode, moveBlockTo, viewport } = useEditor();
  const working = useEngineState((s) => s.working);
  const [tab, setTab] = useState('content');
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
  const attributes = asObject(block.attributes);
  const slots = asList(def.binding_slots);
  const tabs = [
    { key: 'content', label: t('tab_content') },
    { key: 'style', label: t('tab_style') },
    { key: 'advanced', label: t('tab_advanced') },
    { key: 'responsive', label: t('tab_responsive') },
    { key: 'visibility', label: t('tab_visibility') },
    ...(slots.length ? [{ key: 'data', label: t('tab_data') }] : []),
  ];
  const up = blockMoveTarget(working, manifest, block.id, 'up');
  const down = blockMoveTarget(working, manifest, block.id, 'down');
  const indent = blockIndentTarget(working, manifest, block.id);
  const outdent = blockOutdentTarget(working, manifest, block.id);
  const capabilities = asList(def.style_capabilities);

  return (
    <div className="sbx-inspector">
      <h2 className="sbx-inspector__title">{def.label}</h2>
      <div className="sbx-inspector__actions" role="group" aria-label={def.label}>
        <button type="button" className="sbx-btn sbx-btn--xs" disabled={!up} onClick={() => moveBlockTo(block.id, up)}>↑ {t('move_up')}</button>
        <button type="button" className="sbx-btn sbx-btn--xs" disabled={!down} onClick={() => moveBlockTo(block.id, down)}>↓ {t('move_down')}</button>
        <button type="button" className="sbx-btn sbx-btn--xs" disabled={!indent} onClick={() => moveBlockTo(block.id, indent)}>→ {t('indent')}</button>
        <button type="button" className="sbx-btn sbx-btn--xs" disabled={!outdent} onClick={() => moveBlockTo(block.id, outdent)}>← {t('outdent')}</button>
        <button type="button" className="sbx-btn sbx-btn--xs sbx-btn--danger" onClick={() => removeNode(block.id)}>{t('remove')}</button>
      </div>
      <Tabs tabs={tabs} active={tab} onChange={setTab} idPrefix={idPrefix} />

      {tab === 'content' && (
        <div role="tabpanel" id={`${idPrefix}-panel-content`} aria-labelledby={`${idPrefix}-tab-content`}>
          <ObjectFields
            schema={def.field_schema}
            value={props}
            manifest={manifest}
            mediaPicker={boot.mediaPicker}
            onChange={(next) => applyOp(ops.updateBlockProps(block.id, next), { label: def.label })}
          />
        </div>
      )}

      {tab === 'style' && (
        <div role="tabpanel" id={`${idPrefix}-panel-style`} aria-labelledby={`${idPrefix}-tab-style`}>
          {capabilities.includes('align') && (
            <ResponsiveSelect
              label={t('align')}
              value={style.align ?? null}
              options={asList(manifest.vocabulary.alignments)}
              activeBreakpoint={viewport.breakpoint}
              onChange={(align) => applyOp(ops.updateBlockStyle(block.id, { ...style, align: Object.keys(align).length ? align : null }), { label: def.label })}
            />
          )}
          {capabilities.includes('typography') && (
            <fieldset className="sbx-fieldset">
              <legend>{t('typography')}</legend>
              <div className="sbx-field">
                <label className="sbx-field__label" htmlFor={`${idPrefix}-typo-weight`}>{t('font_weight')}</label>
                <select
                  id={`${idPrefix}-typo-weight`}
                  value={asObject(style.typography).weight || ''}
                  onChange={(e) => applyOp(ops.updateBlockStyle(block.id, {
                    ...style,
                    typography: { ...asObject(style.typography), weight: e.target.value || undefined },
                  }), { label: def.label })}
                >
                  <option value="">{t('inherit')}</option>
                  <option value="normal">Normal (400)</option>
                  <option value="medium">Medium (500)</option>
                  <option value="semibold">Semibold (600)</option>
                  <option value="bold">Bold (700)</option>
                  <option value="extrabold">Extra Bold (800)</option>
                </select>
              </div>
              <div className="sbx-field">
                <label className="sbx-field__label" htmlFor={`${idPrefix}-typo-transform`}>{t('text_transform')}</label>
                <select
                  id={`${idPrefix}-typo-transform`}
                  value={asObject(style.typography).transform || ''}
                  onChange={(e) => applyOp(ops.updateBlockStyle(block.id, {
                    ...style,
                    typography: { ...asObject(style.typography), transform: e.target.value || undefined },
                  }), { label: def.label })}
                >
                  <option value="">{t('none')}</option>
                  <option value="uppercase">UPPERCASE</option>
                  <option value="lowercase">lowercase</option>
                  <option value="capitalize">Capitalize</option>
                </select>
              </div>
            </fieldset>
          )}
          {capabilities.includes('border') && (
            <fieldset className="sbx-fieldset">
              <legend>{t('border')}</legend>
              <div className="sbx-field">
                <label className="sbx-field__label" htmlFor={`${idPrefix}-border-radius`}>{t('border_radius')}</label>
                <select
                  id={`${idPrefix}-border-radius`}
                  value={asObject(style.border).radius || ''}
                  onChange={(e) => applyOp(ops.updateBlockStyle(block.id, {
                    ...style,
                    border: { ...asObject(style.border), radius: e.target.value || undefined },
                  }), { label: def.label })}
                >
                  <option value="">{t('none')}</option>
                  <option value="sm">Small</option>
                  <option value="md">Medium</option>
                  <option value="lg">Large</option>
                  <option value="xl">XL</option>
                  <option value="2xl">2XL</option>
                  <option value="full">Full (Pill)</option>
                </select>
              </div>
            </fieldset>
          )}
          {capabilities.includes('shadow') && (
            <div className="sbx-field">
              <label className="sbx-field__label" htmlFor={`${idPrefix}-shadow`}>{t('box_shadow')}</label>
              <select
                id={`${idPrefix}-shadow`}
                value={typeof style.shadow === 'string' ? style.shadow : ''}
                onChange={(e) => applyOp(ops.updateBlockStyle(block.id, {
                  ...style,
                  shadow: e.target.value || undefined,
                }), { label: def.label })}
              >
                <option value="">{t('none')}</option>
                <option value="sm">Small</option>
                <option value="md">Medium</option>
                <option value="lg">Large</option>
                <option value="xl">XL</option>
                <option value="2xl">2XL</option>
              </select>
            </div>
          )}
          {Object.keys(STYLE_TOKEN_CATEGORIES).filter((k) => capabilities.includes(k)).map((key) => (
            <div className="sbx-field" key={key}>
              <label className="sbx-field__label" htmlFor={`${idPrefix}-${key}`}>{key.replace('_token', '').replace('_', ' ')}</label>
              <TokenSelect
                id={`${idPrefix}-${key}`}
                value={style[key] ?? null}
                tokens={tokensFor(manifest, STYLE_TOKEN_CATEGORIES[key])}
                onChange={(v) => applyOp(ops.updateBlockStyle(block.id, { ...style, [key]: v }), { label: def.label })}
              />
            </div>
          ))}
        </div>
      )}

      {tab === 'advanced' && (
        <div role="tabpanel" id={`${idPrefix}-panel-advanced`} aria-labelledby={`${idPrefix}-tab-advanced`}>
          <div className="sbx-field">
            <label className="sbx-field__label" htmlFor={`${idPrefix}-classes`}>{t('classes_label')}</label>
            <input
              id={`${idPrefix}-classes`}
              type="text"
              className="sbx-input"
              value={classNames.join(' ')}
              placeholder="e.g. my-custom-class hero-banner"
              onChange={(e) => {
                const names = e.target.value.trim().split(/\s+/).filter(Boolean);
                applyOp(ops.updateBlockClassNames(block.id, names), { label: def.label });
              }}
            />
            <p className="sbx-hint">{t('classes_hint')}</p>
          </div>
          <div className="sbx-field">
            <label className="sbx-field__label" htmlFor={`${idPrefix}-z-index`}>{t('z_index_label')}</label>
            <input
              id={`${idPrefix}-z-index`}
              type="number"
              className="sbx-input"
              value={style.z_index !== undefined && style.z_index !== null ? style.z_index : ''}
              placeholder="0"
              onChange={(e) => {
                const val = e.target.value === '' ? null : parseInt(e.target.value, 10);
                applyOp(ops.updateBlockStyle(block.id, { ...style, z_index: val }), { label: def.label });
              }}
            />
          </div>
          <div className="sbx-field">
            <label className="sbx-field__label" htmlFor={`${idPrefix}-attributes`}>{t('attributes_label')}</label>
            <input
              id={`${idPrefix}-attributes`}
              type="text"
              className="sbx-input"
              value={Object.entries(attributes).map(([k, v]) => `${k}=${v}`).join(' ')}
              placeholder="data-custom=value aria-role=article"
              onChange={(e) => {
                const pairs = e.target.value.trim().split(/\s+/).filter(Boolean);
                const nextAttrs = {};
                for (const pair of pairs) {
                  const [k, v] = pair.split('=');
                  if (k) nextAttrs[k] = v || '';
                }
                applyOp(ops.updateBlockAttributes(block.id, nextAttrs), { label: def.label });
              }}
            />
            <p className="sbx-hint">{t('attributes_hint')}</p>
          </div>
        </div>
      )}

      {tab === 'responsive' && (
        <div role="tabpanel" id={`${idPrefix}-panel-responsive`} aria-labelledby={`${idPrefix}-tab-responsive`}>
          <fieldset className="sbx-fieldset">
            <legend>{t('device_overrides')}</legend>
            {['desktop', 'tablet', 'mobile'].map((device) => {
              const devOverride = asObject(responsive[device]);
              return (
                <div key={device} className="sbx-field" style={{ marginBottom: '12px' }}>
                  <label className="sbx-field__label" style={{ fontWeight: 'bold' }}>
                    {device === 'desktop' ? t('device_desktop') : device === 'tablet' ? t('device_tablet') : t('device_mobile')}
                  </label>
                  <label className="sbx-field sbx-field--check">
                    <input
                      type="checkbox"
                      checked={Boolean(devOverride.hide)}
                      onChange={(e) => {
                        const nextDev = { ...devOverride, hide: e.target.checked };
                        if (!e.target.checked) delete nextDev.hide;
                        const nextResp = { ...responsive, [device]: nextDev };
                        if (Object.keys(nextDev).length === 0) delete nextResp[device];
                        applyOp(ops.updateBlockResponsive(block.id, nextResp), { label: def.label });
                      }}
                    />
                    <span>{t('hide_device', { device })}</span>
                  </label>
                </div>
              );
            })}
          </fieldset>
        </div>
      )}

      {tab === 'visibility' && (
        <div role="tabpanel" id={`${idPrefix}-panel-visibility`} aria-labelledby={`${idPrefix}-tab-visibility`}>
          <VisibilityControls
            value={block.visibility}
            manifest={manifest}
            onChange={(v) => applyOp(ops.updateBlockVisibility(block.id, v), { label: def.label })}
          />
        </div>
      )}

      {tab === 'data' && (
        <div role="tabpanel" id={`${idPrefix}-panel-data`} aria-labelledby={`${idPrefix}-tab-data`}>
          <p className="sbx-hint">{t('bindings_hint')}</p>
          <BindingsEditor block={block} def={def} manifest={manifest} applyOp={applyOp} />
        </div>
      )}
    </div>
  );
}

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
            onChange={(params) => commit({ ...bindings, [slot.slot]: { ...current, params } })}
          />
        )}
      </fieldset>
    );
  });
}

export { FieldControl };
