// InspectorHost — what the Inspector shows for the current selection: a block or section Inspector, the lock
// banner, the multi-selection note or the empty state. Docked on the right on a desktop, and inside the sheet on a phone.

import { memo } from 'react';
import { useEditor, useEngineState, useSelection } from './EditorContext.jsx';
import { BlockInspector } from './inspectors/BlockInspector.jsx';
import { SectionInspector } from './inspectors/SectionInspector.jsx';
import { findNode } from '../core/doc.mjs';
import { effectivelyLocked, isLocked, lockIndex } from '../core/layerLock.mjs';
import { t } from '../core/messages.mjs';
import { IconLayers, IconPlus, IconSettings, IconSliders } from './Icons.jsx';

/** @param {{onNavigate: (tab: string) => void}} props the empty state's shortcuts name a left-panel tab */
export const InspectorHost = memo(function InspectorHost({ onNavigate }) {
  const { setLocked } = useEditor();
  const { selection, selectedIds } = useSelection();
  const working = useEngineState((s) => s.working);
  const info = selection ? findNode(working, selection) : null;
  // Several nodes selected: the Inspector shows the count, not one node's properties.
  const multi = selectedIds.length > 1;
  const inspected = multi ? null : info;
  const locked = !!info && effectivelyLocked(lockIndex(working), info.node.id);
  const ownLock = !!info && isLocked(info.node);

  return (
    <aside className="sbx-inspector-host" aria-label={t('inspector')}>
    {inspected && locked && (
      <div className="sbx-lock-banner" role="status" data-testid="lock-banner">
        <span>{ownLock ? t('layer_locked_note') : t('layer_locked_by_parent')}</span>
        {ownLock && (
          <button type="button" className="sbx-btn sbx-btn--seg" onClick={() => setLocked(info.node.id, false)}>{t('unlock_layer')}</button>
        )}
      </div>
    )}
    <fieldset className="sbx-lock-fieldset" disabled={locked}>
      {inspected && inspected.kind === 'block' && <BlockInspector key={inspected.node.id} info={inspected} />}
      {inspected && inspected.kind === 'section' && <SectionInspector key={inspected.node.id} info={inspected} />}
    </fieldset>
    {multi && (
      <div className="sbx-inspector-empty" data-testid="inspector-multi" role="status">
        <h3 className="sbx-inspector-empty__title">{t('bulk_selected', { count: selectedIds.length })}</h3>
        <p className="sbx-inspector-empty__desc">{t('multi_select_hint')}</p>
      </div>
    )}
    {!info && !multi && (
      <div className="sbx-inspector-empty">
        <div className="sbx-inspector-empty__icon">
          <IconSliders size={28} />
        </div>
        <h3 className="sbx-inspector-empty__title">{t('nothing_selected')}</h3>
        <p className="sbx-inspector-empty__desc">
          {t('inspector_empty_desc')}
        </p>
        <div className="sbx-inspector-empty__actions">
          <button
            type="button"
            className="sbx-btn sbx-btn--secondary"
            onClick={() => onNavigate('structure')}
          >
            <IconLayers size={14} />
            <span>{t('view_layers')}</span>
          </button>
          <button
            type="button"
            className="sbx-btn sbx-btn--secondary"
            onClick={() => onNavigate('blocks')}
          >
            <IconPlus size={14} />
            <span>{t('add_elements')}</span>
          </button>
          <button
            type="button"
            className="sbx-btn sbx-btn--ghost"
            onClick={() => onNavigate('settings')}
          >
            <IconSettings size={14} />
            <span>{t('sheet_page_settings')}</span>
          </button>
        </div>
      </div>
    )}
    </aside>
  );
});
