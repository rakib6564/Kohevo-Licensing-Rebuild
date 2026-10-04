// LeftPanel — 3 primary tabs matching the reference design:
//   1. + Blocks  (card palette for inserting blocks)
//   2. Inspector (structure tree / outline)
//   3. Theme     (placeholder linking to theme dialog)
//
// The RightPanel still owns property panels (BlockInspector, SectionInspector,
// PageInspector) to keep the existing test contracts intact.

import { memo, useState } from 'react';
import { BlockPalette } from './BlockPalette.jsx';
import { Outline } from './Outline.jsx';
import { LibraryPanel } from './LibraryPanel.jsx';
import { t } from '../core/messages.mjs';
import { IconPlus, IconSliders, IconPalette } from './Icons.jsx';

export const LeftPanel = memo(function LeftPanel() {
  const [tab, setTab] = useState('blocks');

  const tabs = [
    { key: 'blocks',    label: 'Blocks',    Icon: IconPlus },
    { key: 'structure', label: t('panel_structure'), Icon: IconSliders },
    { key: 'library',   label: t('panel_library'),   Icon: IconPalette },
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
            <span className="sbx-tab__icon" aria-hidden="true"><x.Icon size={13} /></span>
            <span>{x.label}</span>
          </button>
        ))}
      </div>

      {/* Blocks tab — insert palette */}
      <div
        id="sbx-leftpanel-blocks"
        role="tabpanel"
        aria-labelledby="sbx-lefttab-blocks"
        hidden={tab !== 'blocks'}
        className="sbx-left__body"
      >
        <BlockPalette />
      </div>

      {/* Structure / Inspector tab — outline tree + compact palette */}
      <div
        id="sbx-leftpanel-structure"
        role="tabpanel"
        aria-labelledby="sbx-lefttab-structure"
        hidden={tab !== 'structure'}
        className="sbx-left__body"
      >
        <Outline />
        <BlockPalette compact />
      </div>

      {/* Library tab */}
      <div
        id="sbx-leftpanel-library"
        role="tabpanel"
        aria-labelledby="sbx-lefttab-library"
        hidden={tab !== 'library'}
        className="sbx-left__body"
      >
        {tab === 'library' && <LibraryPanel />}
      </div>
    </aside>
  );
});
