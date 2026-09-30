// LeftPanel — block palette and the page structure outline.

import { memo, useState } from 'react';
import { BlockPalette } from './BlockPalette.jsx';
import { Outline } from './Outline.jsx';
import { LibraryPanel } from './LibraryPanel.jsx';
import { t } from '../core/messages.mjs';

export const LeftPanel = memo(function LeftPanel() {
  const [tab, setTab] = useState('structure');
  const tabs = [
    { key: 'structure', label: t('panel_structure') },
    { key: 'blocks', label: t('panel_blocks') },
    { key: 'library', label: t('panel_library') },
  ];
  return (
    <aside className="sbx-left" aria-label={t('panel_structure')}>
      <div className="sbx-tabs" role="tablist" aria-label={t('panel_structure')}>
        {tabs.map((x) => (
          <button
            key={x.key}
            id={`sbx-lefttab-${x.key}`}
            type="button"
            role="tab"
            aria-selected={tab === x.key}
            aria-controls={`sbx-leftpanel-${x.key}`}
            className={`sbx-tab${tab === x.key ? ' is-active' : ''}`}
            onClick={() => setTab(x.key)}
          >
            {x.label}
          </button>
        ))}
      </div>
      <div id="sbx-leftpanel-structure" role="tabpanel" aria-labelledby="sbx-lefttab-structure" hidden={tab !== 'structure'} className="sbx-left__body">
        <Outline />
        <BlockPalette compact />
      </div>
      <div id="sbx-leftpanel-blocks" role="tabpanel" aria-labelledby="sbx-lefttab-blocks" hidden={tab !== 'blocks'} className="sbx-left__body">
        <BlockPalette />
      </div>
      <div id="sbx-leftpanel-library" role="tabpanel" aria-labelledby="sbx-lefttab-library" hidden={tab !== 'library'} className="sbx-left__body">
        {tab === 'library' && <LibraryPanel />}
      </div>
    </aside>
  );
});
