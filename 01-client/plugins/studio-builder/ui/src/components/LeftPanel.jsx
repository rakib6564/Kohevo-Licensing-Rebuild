// LeftPanel — Unified single-sided builder navigation & property panel.
//
// Desktop: Docked left panel with fast tab switching between Add (palette),
// Layers (structure tree), Style (inspector), Library, and Settings.
// Mobile: Responsive slide-up bottom sheet with native-app touch workflow.

import { memo, useEffect, useRef, useState } from 'react';
import { useEditor, useEngineState, useSelection } from './EditorContext.jsx';
import { useSheetDrag } from './sheets/SheetPrimitive.jsx';
import { BlockPalette } from './BlockPalette.jsx';
import { Outline } from './Outline.jsx';
import { LibraryPanel } from './LibraryPanel.jsx';
import { BlockInspector } from './inspectors/BlockInspector.jsx';
import { SectionInspector } from './inspectors/SectionInspector.jsx';
import { PageInspector } from './inspectors/PageInspector.jsx';
import { blockDefinition, findNode } from '../core/doc.mjs';
import { effectivelyLocked, isLocked, lockIndex } from '../core/layerLock.mjs';
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
  const { manifest, setLocked } = useEditor();
  const { selection, selectedIds } = useSelection();
  const working = useEngineState((s) => s.working);
  const info = selection ? findNode(working, selection) : null;
  // Several nodes selected: the Inspector shows the count, not one node's properties.
  const multi = selectedIds.length > 1;
  const inspected = multi ? null : info;
  const locked = !!info && effectivelyLocked(lockIndex(working), info.node.id);
  const ownLock = !!info && isLocked(info.node);

  const asideRef = useRef(null);
  const sheet = useSheetDrag(asideRef, onCloseMobile);
  const resetSheet = sheet.reset;
  // Each time the sheet closes it forgets how far it was dragged.
  useEffect(() => { if (!mobileOpen) resetSheet(); }, [mobileOpen, resetSheet]);

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
    { key: 'blocks',    label: t('tab_add'),       Icon: IconPlus,     title: t('tab_add_title') },
    { key: 'structure', label: t('tab_layers'),    Icon: IconLayers,   title: t('panel_structure') },
    { key: 'inspector', label: t('tab_style'),     Icon: IconSliders,  title: t('inspector') },
    { key: 'library',   label: t('tab_library'),   Icon: IconPalette,  title: t('panel_library') },
    { key: 'settings',  label: t('tab_settings'),  Icon: IconSettings, title: t('tab_settings_title') },
  ];

  let sheetTitle = t('panel_word');
  if (tab === 'blocks') sheetTitle = t('sheet_add');
  else if (tab === 'structure') sheetTitle = t('sheet_layers');
  else if (tab === 'inspector') {
    if (info && info.kind === 'block') {
      const def = blockDefinition(manifest, info.node.type);
      sheetTitle = def ? t('sheet_edit_named', { label: def.label }) : t('sheet_edit_block');
    } else if (info && info.kind === 'section') {
      sheetTitle = t('sheet_edit_section');
    } else {
      sheetTitle = t('sheet_page_settings');
    }
  } else if (tab === 'library') sheetTitle = t('sheet_library');
  else if (tab === 'settings') sheetTitle = t('sheet_page_settings');

  return (
    <>
      {/* Mobile backdrop */}
      <div
        className={`sbx-sheet-backdrop${mobileOpen ? ' is-visible' : ''}`}
        onClick={onCloseMobile}
        aria-hidden="true"
      />

      <aside
        ref={asideRef}
        className={`sbx-left${collapsed ? ' is-collapsed' : ''}${mobileOpen ? ' is-mobile-open' : ''}${sheet.dragging ? ' is-dragging' : ''}`}
        style={mobileOpen && sheet.height !== null ? { '--sbx-sheet-h': `${sheet.height}px` } : undefined}
        aria-label={t('panel_structure')}
      >
        {/* Mobile drag handle: drag to resize, pull down to close */}
        <div className="sbx-sheet-handle" data-testid="left-sheet-handle" {...sheet.handlers} aria-hidden="true" />

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
            aria-label={t('close_panel')}
            title={t('close')}
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
            title={collapsed ? t('expand_panel') : t('collapse_panel')}
            aria-label={collapsed ? t('expand_panel') : t('collapse_panel')}
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
                    onClick={() => setTab('structure')}
                  >
                    <IconLayers size={14} />
                    <span>{t('view_layers')}</span>
                  </button>
                  <button
                    type="button"
                    className="sbx-btn sbx-btn--secondary"
                    onClick={() => setTab('blocks')}
                  >
                    <IconPlus size={14} />
                    <span>{t('add_elements')}</span>
                  </button>
                  <button
                    type="button"
                    className="sbx-btn sbx-btn--ghost"
                    onClick={() => setTab('settings')}
                  >
                    <IconSettings size={14} />
                    <span>{t('sheet_page_settings')}</span>
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
          title={t('open_editor_panel')}
        >
          <IconSliders size={15} />
          <span>{t('panel_word')}</span>
        </button>
      )}
    </>
  );
});
