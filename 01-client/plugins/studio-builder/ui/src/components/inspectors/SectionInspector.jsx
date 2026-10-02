// SectionInspector — section layout (update_section_layout), visibility
// (update_section_visibility) and structure actions (move/delete/add block).
//
// A section that references a global component (Phase 6) owns no layout or
// blocks of its own: its panel shows the component, its publish state, a link
// to edit the component, "Detach" (server command → local copy), and only the
// visibility controls (where THIS page shows it).

import { useState } from 'react';
import { useEditor, useEngineState } from '../EditorContext.jsx';
import { ResponsiveSelect, Tabs, VisibilityControls } from './controls.jsx';
import { TokenSelect } from '../fields/FieldControl.jsx';
import { asList, asObject, isGlobalSection, sectionsOf } from '../../core/doc.mjs';
import { tokensFor } from '../../core/fields.mjs';
import { componentByRef } from '../../core/library.mjs';
import * as ops from '../../core/operations.mjs';
import { t } from '../../core/messages.mjs';

const COLUMN_OPTIONS = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12];

function GlobalSectionPanel({ section, label }) {
  const { manifest, applyOp, library = null, detachComponent, publishComponent, boot } = useEditor();
  const component = componentByRef(library && library.components, section.global_ref);
  const perms = (manifest && manifest.permissions) || {};
  return (
    <div className="sbx-global" data-testid="global-section-panel">
      <h3 className="sbx-inspector__subtitle">{t('global_section_title')}</h3>
      <p className="sbx-hint">{t('global_section_body')}</p>
      {library && !component && <p role="alert" className="sbx-field__problem">{t('global_section_missing')}</p>}
      {component && (
        <p>
          <strong>{component.title}</strong>
          <br />
          <span className="sbx-muted">{component.is_published ? (component.has_unpublished_changes ? t('library_component_changes') : t('library_component_published')) : t('library_component_unpublished')}</span>
        </p>
      )}
      <div className="sbx-inspector__actions">
        {component && <a className="sbx-btn sbx-btn--xs" href={`${boot.builderUrl || ''}?page=${component.id}`} target="_blank" rel="noopener">{t('library_edit_component')}</a>}
        {component && perms.publish && (!component.is_published || component.has_unpublished_changes) && (
          <button type="button" className="sbx-btn sbx-btn--xs sbx-btn--primary" onClick={() => publishComponent(component)}>{t('library_publish_component')}</button>
        )}
        <button type="button" className="sbx-btn sbx-btn--xs" onClick={() => detachComponent(section.id)}>{t('global_section_detach')}</button>
      </div>
      <h3 className="sbx-inspector__subtitle">{t('tab_visibility')}</h3>
      <VisibilityControls value={section.visibility} manifest={manifest} onChange={(v) => applyOp(ops.updateSectionVisibility(section.id, v), { label })} />
    </div>
  );
}

export function SectionInspector({ info }) {
  const { manifest, applyOp, removeNode, moveSectionTo, insertSection, viewport, openSaveTemplate, openComponentDialog } = useEditor();
  const working = useEngineState((s) => s.working);
  const page = useEngineState((s) => s.page);
  const perms = (manifest && manifest.permissions) || {};
  const pageType = page ? page.page_type : 'page';
  const canReference = asList(manifest && manifest.components && manifest.components.referencing_types).includes(pageType);
  const [tab, setTab] = useState('layout');
  const section = info.node;
  const layout = asObject(section.layout);
  const total = sectionsOf(working).length;
  const vocab = manifest.vocabulary || {};
  const idPrefix = `sbx-sec-${section.id}`;
  const label = section.label || t('section');
  const setLayout = (patch) => applyOp(ops.updateSectionLayout(section.id, { ...layout, ...patch }), { label });
  const global = isGlobalSection(section);

  return (
    <div className="sbx-inspector">
      <h2 className="sbx-inspector__title">{label}</h2>
      <div className="sbx-inspector__actions" role="group" aria-label={label}>
        <button type="button" className="sbx-btn sbx-btn--xs" disabled={info.index === 0} onClick={() => moveSectionTo(section.id, info.index - 1)}>↑ {t('move_up')}</button>
        <button type="button" className="sbx-btn sbx-btn--xs" disabled={info.index >= total - 1} onClick={() => moveSectionTo(section.id, info.index + 1)}>↓ {t('move_down')}</button>
        <button type="button" className="sbx-btn sbx-btn--xs" onClick={() => insertSection(info.index + 1)}>+ {t('add_section')}</button>
        {!global && perms.admin && openSaveTemplate && (
          <button type="button" className="sbx-btn sbx-btn--xs" title={t('library_save_template')} onClick={openSaveTemplate}>💾 {t('library_save_template')}</button>
        )}
        {!global && perms.edit && canReference && openComponentDialog && (
          <button type="button" className="sbx-btn sbx-btn--xs" title={t('library_new_component')} onClick={openComponentDialog}>❖ {t('library_new_component')}</button>
        )}
        <button type="button" className="sbx-btn sbx-btn--xs sbx-btn--danger" onClick={() => removeNode(section.id)}>{t('remove')}</button>
      </div>
      {global && <GlobalSectionPanel section={section} label={label} />}
      {!global && <Tabs tabs={[{ key: 'layout', label: t('tab_layout') }, { key: 'visibility', label: t('tab_visibility') }]} active={tab} onChange={setTab} idPrefix={idPrefix} />}

      {!global && tab === 'layout' && (
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

      {!global && tab === 'visibility' && (
        <div role="tabpanel" id={`${idPrefix}-panel-visibility`} aria-labelledby={`${idPrefix}-tab-visibility`}>
          <VisibilityControls value={section.visibility} manifest={manifest} onChange={(v) => applyOp(ops.updateSectionVisibility(section.id, v), { label })} />
        </div>
      )}
    </div>
  );
}
