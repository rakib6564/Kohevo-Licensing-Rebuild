// LeftPanel — Unified single-sided builder navigation & property panel.
//
// Desktop: Docked left panel with fast tab switching between Add (palette),
// Layers (structure tree), Style (inspector), Library, and Settings.
// Mobile: Responsive slide-up bottom sheet with native-app touch workflow.

import { memo, useEffect, useState } from 'react';
import { useEditor, useEngineState } from './EditorContext.jsx';
import { BlockPalette } from './BlockPalette.jsx';
import { Outline } from './Outline.jsx';
import { LibraryPanel } from './LibraryPanel.jsx';
import { BlockInspector } from './inspectors/BlockInspector.jsx';
import { SectionInspector } from './inspectors/SectionInspector.jsx';
import { PageInspector } from './inspectors/PageInspector.jsx';
import { blockDefinition, findNode } from '../core/doc.mjs';
import { t } from '../core/messages.mjs';
import {
  IconPlus,
  IconLayers,
  IconSliders,
  IconPalette,
  IconSettings,
  IconChevronLeft,
  IconChevronRight,
  IconX,
} from './Icons.jsx';

export const LeftPanel = memo(function LeftPanel({
  tab: controlledTab,
  onTabChange,
  collapsed: controlledCollapsed,
  onToggleCollapse,
  mobileOpen = false,
  onCloseMobile = () => {},
}) {
  const { selection, manifest } = useEditor();
  const working = useEngineState((s) => s.working);
  const info = selection ? findNode(working, selection) : null;

  const [localTab, setLocalTab] = useState(() => (selection ? 'inspector' : 'blocks'));
  const [localCollapsed, setLocalCollapsed] = useState(false);

  const tab = controlledTab !== undefined ? controlledTab : localTab;
  const setTab = onTabChange || setLocalTab;

  const collapsed = controlledCollapsed !== undefined ? controlledCollapsed : localCollapsed;
  const toggleCollapse = onToggleCollapse || (() => setLocalCollapsed((c) => !c));

  // Contextual activation: when a block/section is selected, seamlessly switch to inspector
  useEffect(() => {
    if (selection) {
      setTab('inspector');
    }
  }, [selection, setTab]);

  const tabs = [
    { key: 'blocks',    label: 'Add',       Icon: IconPlus,     title: 'Add Blocks & Elements' },
    { key: 'structure', label: 'Layers',    Icon: IconLayers,   title: t('panel_structure') },
    { key: 'inspector', label: 'Style',     Icon: IconSliders,  title: t('inspector') },
    { key: 'library',   label: 'Library',   Icon: IconPalette,  title: t('panel_library') },
    { key: 'settings',  label: 'Settings',  Icon: IconSettings, title: 'Page Settings & SEO' },
  ];

  let sheetTitle = 'Panel';
  if (tab === 'blocks') sheetTitle = 'Add Element';
  else if (tab === 'structure') sheetTitle = 'Page Layers';
  else if (tab === 'inspector') {
    if (info && info.kind === 'block') {
      const def = blockDefinition(manifest, info.node.type);
      sheetTitle = def ? `Edit ${def.label}` : 'Edit Block';
    } else if (info && info.kind === 'section') {
      sheetTitle = 'Edit Section';
    } else {
      sheetTitle = 'Page Settings';
    }
  } else if (tab === 'library') sheetTitle = 'Templates & Presets';
  else if (tab === 'settings') sheetTitle = 'Page Settings';

  return (
    <>
      {/* Mobile backdrop */}
      <div
        className={`sbx-sheet-backdrop${mobileOpen ? ' is-visible' : ''}`}
        onClick={onCloseMobile}
        aria-hidden="true"
      />

      <aside
        className={`sbx-left${collapsed ? ' is-collapsed' : ''}${mobileOpen ? ' is-mobile-open' : ''}`}
        aria-label={t('panel_structure')}
      >
        {/* Mobile drag handle */}
        <div className="sbx-sheet-handle" onClick={onCloseMobile} aria-hidden="true" />

        {/* Mobile Sheet Top Bar */}
        <div className="sbx-sheet-header">
          <div className="sbx-sheet-header__info">
            <span className="sbx-sheet-badge">{tab.toUpperCase()}</span>
            <strong className="sbx-sheet-title">{sheetTitle}</strong>
          </div>
          <button
            type="button"
            className="sbx-sheet-close"
            onClick={onCloseMobile}
            aria-label="Close panel"
            title="Close"
          >
            <IconX size={18} />
          </button>
        </div>

        {/* Modern Tab Bar */}
        <div className="sbx-tabs-container">
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
                title={x.title}
              >
                <span className="sbx-tab__icon" aria-hidden="true">
                  <x.Icon size={14} />
                </span>
                <span className="sbx-tab__label">{x.label}</span>
                {x.key === 'inspector' && info && (
                  <span className="sbx-tab__dot" aria-hidden="true" />
                )}
              </button>
            ))}
          </div>

          <button
            type="button"
            className="sbx-collapse-btn"
            onClick={toggleCollapse}
            title={collapsed ? 'Expand panel' : 'Collapse panel'}
            aria-label={collapsed ? 'Expand panel' : 'Collapse panel'}
          >
            {collapsed ? <IconChevronRight size={14} /> : <IconChevronLeft size={14} />}
          </button>
        </div>

        {/* 1. Blocks Tab — Element palette */}
        <div
          id="sbx-leftpanel-blocks"
          role="tabpanel"
          aria-labelledby="sbx-lefttab-blocks"
          hidden={tab !== 'blocks'}
          className="sbx-left__body sbx-left__body--blocks"
        >
          <BlockPalette />
        </div>

        {/* 2. Structure / Layers Tab — Accessible outline tree */}
        <div
          id="sbx-leftpanel-structure"
          role="tabpanel"
          aria-labelledby="sbx-lefttab-structure"
          hidden={tab !== 'structure'}
          className="sbx-left__body sbx-left__body--structure"
        >
          <Outline />
          <BlockPalette compact />
        </div>

        {/* 3. Style / Inspector Tab — Metadata-driven property inspector */}
        <div
          id="sbx-leftpanel-inspector"
          role="tabpanel"
          aria-labelledby="sbx-lefttab-inspector"
          hidden={tab !== 'inspector'}
          className="sbx-left__body sbx-left__body--inspector"
        >
          <aside className="sbx-inspector-host" aria-label={t('inspector')}>
            {info && info.kind === 'block' && <BlockInspector key={info.node.id} info={info} />}
            {info && info.kind === 'section' && <SectionInspector key={info.node.id} info={info} />}
            {!info && (
              <div className="sbx-inspector-empty">
                <div className="sbx-inspector-empty__icon">
                  <IconSliders size={28} />
                </div>
                <h3 className="sbx-inspector-empty__title">{t('nothing_selected')}</h3>
                <p className="sbx-inspector-empty__desc">
                  Select any section or block on the canvas or from the Layers tab to customize typography, layout, spacing, colors, and styling.
                </p>
                <div className="sbx-inspector-empty__actions">
                  <button
                    type="button"
                    className="sbx-btn sbx-btn--secondary"
                    onClick={() => setTab('structure')}
                  >
                    <IconLayers size={14} />
                    <span>View Layers</span>
                  </button>
                  <button
                    type="button"
                    className="sbx-btn sbx-btn--secondary"
                    onClick={() => setTab('blocks')}
                  >
                    <IconPlus size={14} />
                    <span>Add Elements</span>
                  </button>
                  <button
                    type="button"
                    className="sbx-btn sbx-btn--ghost"
                    onClick={() => setTab('settings')}
                  >
                    <IconSettings size={14} />
                    <span>Page Settings</span>
                  </button>
                </div>
              </div>
            )}
          </aside>
        </div>

        {/* 4. Library Tab — Templates & Saved Components */}
        <div
          id="sbx-leftpanel-library"
          role="tabpanel"
          aria-labelledby="sbx-lefttab-library"
          hidden={tab !== 'library'}
          className="sbx-left__body sbx-left__body--library"
        >
          <LibraryPanel />
        </div>

        {/* 5. Page Settings Tab — SEO, Dimensions, & Conditions */}
        <div
          id="sbx-leftpanel-settings"
          role="tabpanel"
          aria-labelledby="sbx-lefttab-settings"
          hidden={tab !== 'settings'}
          className="sbx-left__body sbx-left__body--settings"
        >
          <PageInspector />
        </div>
      </aside>

      {/* Floating expand pill if desktop sidebar is collapsed */}
      {collapsed && (
        <button
          type="button"
          className="sbx-floating-expand-btn"
          onClick={() => toggleCollapse()}
          title="Open Editor Panel"
        >
          <IconSliders size={15} />
          <span>Panel</span>
        </button>
      )}
    </>
  );
});
