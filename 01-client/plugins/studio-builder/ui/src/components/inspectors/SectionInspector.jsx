// SectionInspector — section layout (update_section_layout), visibility
// (update_section_visibility) and structure actions (move/delete/add block).

import { useState } from 'react';
import { useEditor, useEngineState } from '../EditorContext.jsx';
import { ResponsiveSelect, Tabs, VisibilityControls } from './controls.jsx';
import { TokenSelect } from '../fields/FieldControl.jsx';
import { asList, asObject, sectionsOf } from '../../core/doc.mjs';
import { tokensFor } from '../../core/fields.mjs';
import * as ops from '../../core/operations.mjs';
import { t } from '../../core/messages.mjs';

const COLUMN_OPTIONS = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12];

export function SectionInspector({ info }) {
  const { manifest, applyOp, removeNode, moveSectionTo, insertSection, viewport } = useEditor();
  const working = useEngineState((s) => s.working);
  const [tab, setTab] = useState('layout');
  const section = info.node;
  const layout = asObject(section.layout);
  const total = sectionsOf(working).length;
  const vocab = manifest.vocabulary || {};
  const idPrefix = `sbx-sec-${section.id}`;
  const label = section.label || t('section');
  const setLayout = (patch) => applyOp(ops.updateSectionLayout(section.id, { ...layout, ...patch }), { label });

  return (
    <div className="sbx-inspector">
      <h2 className="sbx-inspector__title">{label}</h2>
      <div className="sbx-inspector__actions" role="group" aria-label={label}>
        <button type="button" className="sbx-btn sbx-btn--xs" disabled={info.index === 0} onClick={() => moveSectionTo(section.id, info.index - 1)}>↑ {t('move_up')}</button>
        <button type="button" className="sbx-btn sbx-btn--xs" disabled={info.index >= total - 1} onClick={() => moveSectionTo(section.id, info.index + 1)}>↓ {t('move_down')}</button>
        <button type="button" className="sbx-btn sbx-btn--xs" onClick={() => insertSection(info.index + 1)}>+ {t('add_section')}</button>
        <button type="button" className="sbx-btn sbx-btn--xs sbx-btn--danger" onClick={() => removeNode(section.id)}>{t('remove')}</button>
      </div>
      <Tabs tabs={[{ key: 'layout', label: t('tab_layout') }, { key: 'visibility', label: t('tab_visibility') }]} active={tab} onChange={setTab} idPrefix={idPrefix} />

      {tab === 'layout' && (
        <div role="tabpanel" id={`${idPrefix}-panel-layout`} aria-labelledby={`${idPrefix}-tab-layout`}>
          <div className="sbx-field">
            <label className="sbx-field__label" htmlFor={`${idPrefix}-width`}>{t('width')}</label>
            <select id={`${idPrefix}-width`} value={layout.width || 'wide'} onChange={(e) => setLayout({ width: e.target.value })}>
              {asList(vocab.container_widths).map((w) => <option key={w} value={w}>{w}</option>)}
            </select>
          </div>
          <div className="sbx-field">
            <label className="sbx-field__label" htmlFor={`${idPrefix}-gap`}>{t('gap')}</label>
            <select id={`${idPrefix}-gap`} value={layout.gap || 'md'} onChange={(e) => setLayout({ gap: e.target.value })}>
              {asList(vocab.spacing_scale).map((s) => <option key={s} value={s}>{s}</option>)}
            </select>
          </div>
          <ResponsiveSelect
            label={t('columns')}
            value={layout.columns ?? { base: 1 }}
            options={COLUMN_OPTIONS}
            numeric
            requireBase
            activeBreakpoint={viewport.breakpoint}
            onChange={(columns) => setLayout({ columns })}
          />
          <ResponsiveSelect
            label={t('padding_y')}
            value={layout.padding_y ?? { base: 'md' }}
            options={asList(vocab.spacing_scale)}
            requireBase
            activeBreakpoint={viewport.breakpoint}
            onChange={(padding) => setLayout({ padding_y: padding })}
          />
          <div className="sbx-field">
            <label className="sbx-field__label" htmlFor={`${idPrefix}-bg`}>{t('background')}</label>
            <TokenSelect id={`${idPrefix}-bg`} value={layout.background_token ?? null} tokens={tokensFor(manifest, ['surface', 'color'])} onChange={(v) => setLayout({ background_token: v })} />
          </div>
        </div>
      )}

      {tab === 'visibility' && (
        <div role="tabpanel" id={`${idPrefix}-panel-visibility`} aria-labelledby={`${idPrefix}-tab-visibility`}>
          <VisibilityControls value={section.visibility} manifest={manifest} onChange={(v) => applyOp(ops.updateSectionVisibility(section.id, v), { label })} />
        </div>
      )}
    </div>
  );
}
