// ThemeBottomSheet — Mobile bottom sheet for the site's global design system (Image 4).
//
// Allows quick editing of Brand identity (logo, site name, tagline, favicon),
// Color palette mode (Light/Dark/Auto) + 6 core design tokens, and Typography.

import { memo, useEffect, useState } from 'react';
import { useEditor, useEngineState } from './EditorContext.jsx';
import { IconX, IconTrash, IconPalette } from './Icons.jsx';
import { t, errorMessage } from '../core/messages.mjs';
import { asList, asObject } from '../core/doc.mjs';

const DEFAULT_SWATCHES = [
  { ref: 'color.primary',    name: 'Primary',    hex: '#8B5CF6' },
  { ref: 'color.secondary',  name: 'Secondary',  hex: '#A78BFA' },
  { ref: 'color.background', name: 'Background', hex: '#0B0B0D' },
  { ref: 'color.surface',    name: 'Surface',    hex: '#141417' },
  { ref: 'color.border',     name: 'Border',     hex: '#2A2A2F' },
  { ref: 'color.text',       name: 'Text',       hex: '#F5F5F7' },
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
    { key: 'brand',      label: 'Brand' },
    { key: 'colors',     label: 'Colors' },
    { key: 'typography', label: 'Typography' },
    { key: 'buttons',    label: 'Buttons' },
    { key: 'spacing',    label: 'Spacing' },
    { key: 'components', label: 'Components' },
  ];

  return (
    <div className="sbx-bottom-sheet sbx-theme-sheet" role="dialog" aria-label="Theme Design System">
      <div className="sbx-bottom-sheet__drag-handle" aria-hidden="true" />

      {/* Header */}
      <div className="sbx-bottom-sheet__header">
        <div className="sbx-bottom-sheet__title-group">
          <h2 className="sbx-bottom-sheet__title">Theme</h2>
          <p className="sbx-bottom-sheet__subtitle">Manage your site&apos;s global design system</p>
        </div>
        <button
          type="button"
          className="sbx-bottom-sheet__close"
          onClick={onClose}
          aria-label="Close theme settings"
        >
          <IconX size={16} />
        </button>
      </div>

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
            <h3 className="sbx-theme-section__title">Brand identity</h3>
            <p className="sbx-theme-section__desc">Set your brand name, logo and general appearance.</p>

            <div className="sbx-theme-row">
              <label className="sbx-theme-label">Logo</label>
              <div className="sbx-theme-logo-preview">
                <span className="sbx-theme-logo-text">{siteName.toLowerCase()}</span>
              </div>
              <div className="sbx-theme-action-row">
                <button type="button" className="sbx-btn sbx-btn--action" onClick={() => alert('Media Library')}>Change logo</button>
                <button type="button" className="sbx-btn sbx-btn--icon" title="Remove logo"><IconTrash size={14} /></button>
              </div>
            </div>

            <div className="sbx-theme-field">
              <label className="sbx-theme-label" htmlFor="sbx-site-name">Site name</label>
              <input
                id="sbx-site-name"
                type="text"
                className="sbx-input"
                value={siteName}
                onChange={(e) => setSiteName(e.target.value)}
              />
            </div>

            <div className="sbx-theme-field">
              <label className="sbx-theme-label" htmlFor="sbx-tagline">Tagline</label>
              <input
                id="sbx-tagline"
                type="text"
                className="sbx-input"
                value={tagline}
                onChange={(e) => setTagline(e.target.value)}
              />
            </div>

            <div className="sbx-theme-row">
              <label className="sbx-theme-label">Favicon</label>
              <div className="sbx-theme-favicon-preview">
                <div className="sbx-theme-favicon-box" />
                <div className="sbx-theme-action-row">
                  <button type="button" className="sbx-btn sbx-btn--action">Change favicon</button>
                  <button type="button" className="sbx-btn sbx-btn--icon" title="Remove favicon"><IconTrash size={14} /></button>
                </div>
              </div>
            </div>
          </section>
        )}

        {/* ── Color Palette Section ── */}
        <section className="sbx-theme-section">
          <h3 className="sbx-theme-section__title">Color palette</h3>
          <p className="sbx-theme-section__desc">Define your global colors and brand palette.</p>

          <div className="sbx-theme-mode-row">
            <span className="sbx-theme-label">Color mode</span>
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
                  {mode.charAt(0).toUpperCase() + mode.slice(1)}
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
          <h3 className="sbx-theme-section__title">Typography</h3>
          <p className="sbx-theme-section__desc">Set your global fonts and text styles.</p>

          <div className="sbx-theme-grid-two">
            <div className="sbx-theme-field">
              <label className="sbx-theme-label" htmlFor="sbx-heading-font">Heading font</label>
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
              <label className="sbx-theme-label" htmlFor="sbx-body-font">Body font</label>
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
        <button type="button" className="sbx-btn" onClick={onClose}>Cancel</button>
        <button type="button" className="sbx-btn sbx-btn--primary" onClick={handleSave} disabled={saving}>
          {saving ? 'Saving...' : 'Apply changes'}
        </button>
      </div>
    </div>
  );
});
