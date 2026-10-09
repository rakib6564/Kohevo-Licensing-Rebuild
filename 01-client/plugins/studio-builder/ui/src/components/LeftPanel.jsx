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
import { PagesPanel } from './PagesPanel.jsx';
import { LibraryPanel } from './LibraryPanel.jsx';
import { InspectorHost } from './InspectorHost.jsx';
import { useIsMobileShell } from '../hooks/useIsMobileShell.mjs';
import { PageInspector } from './inspectors/PageInspector.jsx';
import { SiteSettingsForm } from './SiteSettings.jsx';
import { ElementManager } from './ElementManager.jsx';
import { blockDefinition, findNode } from '../core/doc.mjs';
import { t } from '../core/messages.mjs';
import { Pills } from './ui/index.js';
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

/** The tallest a sheet may be: the visible viewport (the keyboard shrinks it) less a small gap at the top. */
function fullSheetHeight() {
  if (typeof window === 'undefined') return 800;
  return Math.round((window.visualViewport ? window.visualViewport.height : window.innerHeight) - 24);
}

export const LeftPanel = memo(function LeftPanel({
  tab: controlledTab,
  onTabChange,
  collapsed: controlledCollapsed,
  onToggleCollapse,
  mobileOpen = false,
  onCloseMobile = () => {},
}) {
  const { manifest, tokensSaved } = useEditor();
  const isMobile = useIsMobileShell();
  const { selection } = useSelection();
  const working = useEngineState((s) => s.working);
  const info = selection ? findNode(working, selection) : null;
  const asideRef = useRef(null);
  const sheet = useSheetDrag(asideRef, onCloseMobile);
  const resetSheet = sheet.reset;
  // Each time the sheet closes it forgets how far it was dragged.
  useEffect(() => { if (!mobileOpen) resetSheet(); }, [mobileOpen, resetSheet]);

  const [localTab, setLocalTab] = useState(() => (selection ? 'inspector' : 'blocks'));
  const [localCollapsed, setLocalCollapsed] = useState(false);
  // The Layers tab holds two views: this page's structure, and the site's pages.
  const [navView, setNavView] = useState('layers');
  const [settingsView, setSettingsView] = useState('page');
  const elementsAllowed = !!(manifest && manifest.permissions && manifest.permissions.admin);
  const siteAllowed = !!(manifest && manifest.permissions && (manifest.permissions.tokens || manifest.permissions.view));

  const setTab = onTabChange || setLocalTab;
  // On a desktop the Inspector has its own docked panel (right); only the phone sheet keeps it as a tab here.
  const requestedTab = controlledTab !== undefined ? controlledTab : localTab;
  const tab = !isMobile && requestedTab === 'inspector' ? 'structure' : requestedTab;

  const collapsed = controlledCollapsed !== undefined ? controlledCollapsed : localCollapsed;
  const toggleCollapse = onToggleCollapse || (() => setLocalCollapsed((c) => !c));

  // On a phone, selecting a node points the sheet at its Inspector (the sheet itself stays closed).
  useEffect(() => {
    if (selection && isMobile) {
      setTab('inspector');
    }
  }, [selection, isMobile, setTab]);

  const tabs = [
    { key: 'blocks',    label: t('tab_add'),       Icon: IconPlus,     title: t('tab_add_title') },
    { key: 'structure', label: t('tab_layers'),    Icon: IconLayers,   title: t('panel_structure') },
    { key: 'inspector', label: t('tab_style'),     Icon: IconSliders,  title: t('inspector') },
    { key: 'library',   label: t('tab_library'),   Icon: IconPalette,  title: t('panel_library') },
    { key: 'settings',  label: t('tab_settings'),  Icon: IconSettings, title: t('tab_settings_title') },
  ].filter((x) => isMobile || x.key !== 'inspector');

  const sheetFull = sheet.height !== null && sheet.height >= fullSheetHeight() - 8;
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
        className={`sbx-left${collapsed ? ' is-collapsed' : ''}${mobileOpen ? ' is-mobile-open' : ''}${mobileOpen && sheetFull ? ' is-sheet-full' : ''}${sheet.dragging ? ' is-dragging' : ''}`}
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
          {tab === 'inspector' && (
            <button
              type="button"
              className="sbx-sheet-expand"
              data-testid="sheet-expand"
              aria-pressed={sheetFull}
              onClick={() => (sheetFull ? sheet.reset() : sheet.setHeight(fullSheetHeight()))}
            >
              {sheetFull ? t('sheet_half_panel') : t('sheet_full_panel')}
            </button>
          )}
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
          <Pills
            size="sm"
            className="sbx-nav-switch"
            label={t('nav_view_label')}
            value={navView}
            onChange={setNavView}
            options={[{ value: 'layers', label: t('nav_layers'), 'data-nav': 'layers' }, { value: 'pages', label: t('nav_pages'), 'data-nav': 'pages' }]}
          />
          <div hidden={navView !== 'layers'}>
            <Outline />
            <BlockPalette compact />
          </div>
          {navView === 'pages' && <PagesPanel onOpenSettings={() => setTab('settings')} />}
        </div>

        {/* 3. Style / Inspector Tab (phone sheet only; a desktop has the docked right panel) */}
        {isMobile && (
        <div
          id="sbx-leftpanel-inspector"
          role="tabpanel"
          aria-labelledby="sbx-lefttab-inspector"
          hidden={tab !== 'inspector'}
          className="sbx-left__body sbx-left__body--inspector"
        >
          <InspectorHost onNavigate={setTab} />
        </div>
        )}

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
          {(siteAllowed || elementsAllowed) && (
            <Pills
              size="sm"
              className="sbx-ss__views"
              label={t('site_settings_title')}
              value={settingsView}
              options={['page', siteAllowed && 'site', elementsAllowed && 'elements'].filter(Boolean).map((v) => ({ value: v, label: t(`ss_view_${v}`), 'data-settings-view': v }))}
              onChange={setSettingsView}
            />
          )}
          {siteAllowed && settingsView === 'site' ? <SiteSettingsForm onSaved={tokensSaved} /> : elementsAllowed && settingsView === 'elements' ? <ElementManager /> : <PageInspector />}
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
