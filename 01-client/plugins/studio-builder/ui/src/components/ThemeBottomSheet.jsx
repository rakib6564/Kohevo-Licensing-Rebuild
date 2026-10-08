// ThemeBottomSheet — Mobile bottom sheet for the site's global design system (Image 4).
//
// Allows quick editing of Brand identity (logo, site name, tagline, favicon),
// Color palette mode (Light/Dark/Auto) + 6 core design tokens, and Typography.

import { memo, useEffect, useState } from 'react';
import { useEditor, useEngineState } from './EditorContext.jsx';
import { IconTrash, IconPalette } from './Icons.jsx';
import { SheetPrimitive } from './sheets/SheetPrimitive.jsx';
import { t, errorMessage } from '../core/messages.mjs';
import { asList, asObject } from '../core/doc.mjs';

const DEFAULT_SWATCHES = [
  { ref: 'color.primary',    get name() { return t('swatch_primary'); },    hex: '#8B5CF6' },
  { ref: 'color.secondary',  get name() { return t('swatch_secondary'); },  hex: '#A78BFA' },
  { ref: 'color.background', get name() { return t('swatch_background'); }, hex: '#0B0B0D' },
  { ref: 'color.surface',    get name() { return t('swatch_surface'); },    hex: '#141417' },
  { ref: 'color.border',     get name() { return t('swatch_border'); },     hex: '#2A2A2F' },
  { ref: 'color.text',       get name() { return t('swatch_text'); },       hex: '#F5F5F7' },
];

export const ThemeBottomSheet = memo(function ThemeBottomSheet({ onClose, onSaved }) {
  const { transport, boot, manifest } = useEditor();
  const working = useEngineState((s) => s.working);
  const group = (asObject(working && working.settings).token_group) || 'default';

  const [activeTab, setActiveTab] = useState('brand');
  const [colorMode, setColorMode] = useState('dark');
  const [siteName, setSiteName] = useState('Northstar');
  const [tagline, setTagline] = useState('Independent Creative Studio');
  const [headingFont, setHeadingFont] = useState('Inter');
  const [bodyFont, setBodyFont] = useState('Inter');

  const [tokenData, setTokenData] = useState(null);
  const [swatches, setSwatches] = useState(DEFAULT_SWATCHES);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState(null);

  useEffect(() => {
    let alive = true;
    if (transport && typeof transport.tokens === 'function') {
      transport.tokens(group).then((res) => {
        if (!alive) return;
        if (res.ok) {
          setTokenData(res.data.tokens);
          const tks = asList(res.data.tokens && res.data.tokens.tokens);
          if (tks.length > 0) {
            setSwatches((prev) => prev.map((sw) => {
              const found = tks.find((t) => t.ref === sw.ref);
              return found && found.effective ? { ...sw, hex: found.effective } : sw;
            }));
          }
        }
      });
    }
    return () => { alive = false; };
  }, [transport, group]);

  const handleColorChange = (ref, newHex) => {
    setSwatches((prev) => prev.map((s) => (s.ref === ref ? { ...s, hex: newHex } : s)));
  };

  const handleSave = async () => {
    if (!transport || typeof transport.saveTokens !== 'function') return;
    setSaving(true);
    setError(null);
    const tokens = {};
    swatches.forEach((s) => {
      tokens[s.ref] = s.hex;
    });
    const res = await transport.saveTokens({ group, tokens });
    setSaving(false);
    if (!res.ok) {
      setError(errorMessage(res.error));
      return;
    }
    if (onSaved) onSaved(res.data.tokens);
  };

  const tabs = [
    { key: 'brand',      label: t('theme_tab_brand') },
    { key: 'colors',     label: t('theme_tab_colors') },
    { key: 'typography', label: t('theme_typography') },
    { key: 'buttons',    label: t('theme_tab_buttons') },
    { key: 'spacing',    label: t('theme_tab_spacing') },
    { key: 'components', label: t('theme_tab_components') },
  ];

  return (
    <SheetPrimitive title={t('dock_theme')} subtitle={t('theme_subtitle')} onClose={onClose} className="sbx-theme-sheet" label={t('theme_label')} closeLabel={t('close_theme')} testId="sheet-theme">
      {/* Horizontal Subtabs */}
      <div className="sbx-theme-sheet__tabs" role="tablist">
        {tabs.map((t) => (
          <button
            key={t.key}
            type="button"
            role="tab"
            aria-selected={activeTab === t.key}
            className={`sbx-theme-sheet__tab${activeTab === t.key ? ' is-active' : ''}`}
            onClick={() => setActiveTab(t.key)}
          >
            {t.label}
          </button>
        ))}
      </div>

      {/* Sheet Scroll Body */}
      <div className="sbx-bottom-sheet__body">
        {error && <p className="sbx-field__problem" role="alert">{error}</p>}

        {/* ── Brand Tab ── */}
        {(activeTab === 'brand' || activeTab === 'colors') && (
          <section className="sbx-theme-section">
            <h3 className="sbx-theme-section__title">{t('theme_brand_identity')}</h3>
            <p className="sbx-theme-section__desc">{t('theme_brand_desc')}</p>

            <div className="sbx-theme-row">
              <label className="sbx-theme-label">{t('theme_logo')}</label>
              <div className="sbx-theme-logo-preview">
                <span className="sbx-theme-logo-text">{siteName.toLowerCase()}</span>
              </div>
              <div className="sbx-theme-action-row">
                <button type="button" className="sbx-btn sbx-btn--action" onClick={() => alert('Media Library')}>{t('theme_change_logo')}</button>
                <button type="button" className="sbx-btn sbx-btn--icon" title={t('theme_remove_logo')}><IconTrash size={14} /></button>
              </div>
            </div>

            <div className="sbx-theme-field">
              <label className="sbx-theme-label" htmlFor="sbx-site-name">{t('theme_site_name')}</label>
              <input
                id="sbx-site-name"
                type="text"
                className="sbx-input"
                value={siteName}
                onChange={(e) => setSiteName(e.target.value)}
              />
            </div>

            <div className="sbx-theme-field">
              <label className="sbx-theme-label" htmlFor="sbx-tagline">{t('theme_tagline')}</label>
              <input
                id="sbx-tagline"
                type="text"
                className="sbx-input"
                value={tagline}
                onChange={(e) => setTagline(e.target.value)}
              />
            </div>

            <div className="sbx-theme-row">
              <label className="sbx-theme-label">{t('theme_favicon')}</label>
              <div className="sbx-theme-favicon-preview">
                <div className="sbx-theme-favicon-box" />
                <div className="sbx-theme-action-row">
                  <button type="button" className="sbx-btn sbx-btn--action">{t('theme_change_favicon')}</button>
                  <button type="button" className="sbx-btn sbx-btn--icon" title={t('theme_remove_favicon')}><IconTrash size={14} /></button>
                </div>
              </div>
            </div>
          </section>
        )}

        {/* ── Color Palette Section ── */}
        <section className="sbx-theme-section">
          <h3 className="sbx-theme-section__title">{t('theme_palette')}</h3>
          <p className="sbx-theme-section__desc">{t('theme_palette_desc')}</p>

          <div className="sbx-theme-mode-row">
            <span className="sbx-theme-label">{t('theme_color_mode')}</span>
            <div className="sbx-theme-mode-pills" role="radiogroup">
              {['light', 'dark', 'auto'].map((mode) => (
                <button
                  key={mode}
                  type="button"
                  role="radio"
                  aria-checked={colorMode === mode}
                  className={`sbx-theme-mode-pill${colorMode === mode ? ' is-active' : ''}`}
                  onClick={() => setColorMode(mode)}
                >
                  {t(`theme_mode_${mode}`)}
                </button>
              ))}
            </div>
          </div>

          <div className="sbx-theme-swatches-grid">
            {swatches.map((sw) => (
              <div key={sw.ref} className="sbx-theme-swatch-card">
                <div
                  className="sbx-theme-swatch-preview"
                  style={{ backgroundColor: sw.hex }}
                />
                <span className="sbx-theme-swatch-name">{sw.name}</span>
                <span className="sbx-theme-swatch-hex">{sw.hex.toUpperCase()}</span>
              </div>
            ))}
          </div>
        </section>

        {/* ── Typography Section ── */}
        <section className="sbx-theme-section">
          <h3 className="sbx-theme-section__title">{t('theme_typography')}</h3>
          <p className="sbx-theme-section__desc">{t('theme_type_desc')}</p>

          <div className="sbx-theme-grid-two">
            <div className="sbx-theme-field">
              <label className="sbx-theme-label" htmlFor="sbx-heading-font">{t('theme_heading_font')}</label>
              <select
                id="sbx-heading-font"
                className="sbx-select"
                value={headingFont}
                onChange={(e) => setHeadingFont(e.target.value)}
              >
                <option value="Inter">Inter</option>
                <option value="system-ui">System Sans</option>
                <option value="Playfair Display">Playfair Display</option>
                <option value="Geist">Geist</option>
              </select>
            </div>

            <div className="sbx-theme-field">
              <label className="sbx-theme-label" htmlFor="sbx-body-font">{t('theme_body_font')}</label>
              <select
                id="sbx-body-font"
                className="sbx-select"
                value={bodyFont}
                onChange={(e) => setBodyFont(e.target.value)}
              >
                <option value="Inter">Inter</option>
                <option value="system-ui">System Sans</option>
                <option value="Open Sans">Open Sans</option>
                <option value="Geist">Geist</option>
              </select>
            </div>
          </div>
        </section>
      </div>

      <div className="sbx-bottom-sheet__footer">
        <button type="button" className="sbx-btn" onClick={onClose}>{t('cancel')}</button>
        <button type="button" className="sbx-btn sbx-btn--primary" onClick={handleSave} disabled={saving}>
          {saving ? t('theme_saving') : t('theme_apply')}
        </button>
      </div>
    </SheetPrimitive>
  );
});
