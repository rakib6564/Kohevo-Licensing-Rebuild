// PageInspector — document settings (update_settings) and the basic search
// appearance fields (update_seo). The complete SEO UI is out of Phase 5 scope.
//
// Phase 6 adds the header/footer bindings: the mode selects stay canonical
// settings (`header_mode` / `footer_mode` ∈ inherit|custom|hidden), and the
// panel shows what the server's ChromeResolver currently resolves them to
// (site partial `default`, this page's own partial, built-in) with links to
// edit or create those partial pages. Rendering rules live on the server only.

import { useEffect, useId, useState } from 'react';
import { useEditor, useEngineState } from '../EditorContext.jsx';
import { asList, asObject } from '../../core/doc.mjs';
import * as ops from '../../core/operations.mjs';
import { t, errorMessage } from '../../core/messages.mjs';

function TextSetting({ label, value, maxLength, multiline = false, onCommit }) {
  const id = useId();
  const [draft, setDraft] = useState(value ?? '');
  useEffect(() => { setDraft(value ?? ''); }, [value]);
  const Tag = multiline ? 'textarea' : 'input';
  return (
    <div className="sbx-field">
      <label className="sbx-field__label" htmlFor={id}>{label}</label>
      <Tag
        id={id}
        value={draft}
        maxLength={maxLength}
        rows={multiline ? 3 : undefined}
        onChange={(e) => {
          const v = multiline ? e.target.value : e.target.value.replace(/[\r\n]+/g, ' ');
          setDraft(v);
          onCommit(v);
        }}
      />
    </div>
  );
}

const MODE_LABEL = { inherit: 'chrome_mode_inherit', custom: 'chrome_mode_custom', hidden: 'chrome_mode_hidden' };

function ChromeRegion({ region, binding, settings, onMode, onCreatePartial, builderUrl }) {
  const regionLabel = t(region === 'header' ? 'chrome_header' : 'chrome_footer').toLowerCase();
  const mode = settings[`${region}_mode`] || 'inherit';
  const site = binding && binding.site;
  const custom = binding && binding.custom;
  let resolved = null;
  if (binding) {
    if (binding.resolved === 'hidden') resolved = t('chrome_resolved_hidden');
    else if (binding.resolved === 'custom' && custom) resolved = t('chrome_resolved_custom', { region: regionLabel, title: custom.title });
    else if (binding.resolved === 'site' && site) resolved = t('chrome_resolved_site', { region: regionLabel, title: site.title });
    else resolved = t('chrome_resolved_builtin', { region: regionLabel });
  }
  const draftNote = (p) => (p && p.has_unpublished_changes ? ` ${t('chrome_draft_note')}` : '');
  return (
    <fieldset className="sbx-fieldset sbx-chrome" data-region={region}>
      <legend>{t(region === 'header' ? 'chrome_header' : 'chrome_footer')}</legend>
      <div className="sbx-field">
        <label className="sbx-field__label" htmlFor={`sbx-page-${region}_mode`}>{t(region === 'header' ? 'chrome_header' : 'chrome_footer')}</label>
        <select id={`sbx-page-${region}_mode`} value={mode} onChange={(e) => onMode(e.target.value)}>
          {['inherit', 'custom', 'hidden'].map((m) => <option key={m} value={m}>{t(MODE_LABEL[m])}</option>)}
        </select>
      </div>
      {resolved && <p className="sbx-hint" data-testid={`chrome-${region}-resolved`}>{resolved}</p>}
      {binding && mode === 'custom' && binding.resolved !== 'custom' && <p className="sbx-hint">{t('chrome_custom_missing', { region: regionLabel })}</p>}
      {binding && mode !== 'hidden' && (
        <div className="sbx-inspector__actions">
          {site
            ? <a className="sbx-btn sbx-btn--xs" href={`${builderUrl}?page=${site.id}`} target="_blank" rel="noopener">{t('chrome_edit_site', { region: regionLabel })}{draftNote(site)}</a>
            : <button type="button" className="sbx-btn sbx-btn--xs" onClick={() => onCreatePartial(region, binding.site_slug)}>{t('chrome_create_site', { region: regionLabel })}</button>}
          {mode === 'custom' && (custom
            ? <a className="sbx-btn sbx-btn--xs" href={`${builderUrl}?page=${custom.id}`} target="_blank" rel="noopener">{t('chrome_edit_custom', { region: regionLabel })}{draftNote(custom)}</a>
            : <button type="button" className="sbx-btn sbx-btn--xs" onClick={() => onCreatePartial(region, binding.page_slug)}>{t('chrome_create_custom', { region: regionLabel })}</button>)}
        </div>
      )}
    </fieldset>
  );
}

export function PageInspector() {
  const { manifest, applyOp, transport, boot, createPartial = null } = useEditor();
  const working = useEngineState((s) => s.working);
  const page = useEngineState((s) => s.page);
  const revisionId = useEngineState((s) => (s.revision ? s.revision.id : 0));
  const settings = asObject(working && working.settings);
  const seo = asObject(working && working.seo);
  const vocab = manifest.vocabulary || {};
  const chromed = !!page && ['page', 'landing'].includes(page.page_type);
  const [chrome, setChrome] = useState(null);
  const [chromeError, setChromeError] = useState(null);

  // Re-read what the server resolves whenever the revision (and so the settings) changed.
  useEffect(() => {
    if (!chromed || !transport || typeof transport.chrome !== 'function') return undefined;
    let alive = true;
    transport.chrome(boot.pageId).then((res) => {
      if (!alive) return;
      if (res && res.ok) { setChrome(res.data.chrome); setChromeError(null); } else if (res) setChromeError(errorMessage(res.error));
    });
    return () => { alive = false; };
  }, [transport, boot.pageId, revisionId, chromed]);

  const select = (key, options, labelText) => (
    <div className="sbx-field" key={key}>
      <label className="sbx-field__label" htmlFor={`sbx-page-${key}`}>{labelText}</label>
      <select id={`sbx-page-${key}`} value={settings[key] ?? ''} onChange={(e) => applyOp(ops.updateSettings({ [key]: e.target.value }), { label: labelText })}>
        {options.map((o) => <option key={o} value={o}>{o}</option>)}
      </select>
    </div>
  );

  const regionBinding = (region) => (chrome ? { ...chrome[region], site_slug: chrome.site_slug, page_slug: chrome.page_slug } : null);

  return (
    <div className="sbx-inspector">
      <h2 className="sbx-inspector__title">{t('page_settings')}</h2>
      {select('container_width', asList(vocab.container_widths), t('width'))}

      <h3 className="sbx-inspector__subtitle">{t('chrome_title')}</h3>
      {!chromed && <p className="sbx-hint">{t('chrome_not_chromed')}</p>}
      {chromed && chromeError && <p className="sbx-hint" role="note">{chromeError}</p>}
      {chromed && ['header', 'footer'].map((region) => (
        <ChromeRegion
          key={region}
          region={region}
          binding={regionBinding(region)}
          settings={settings}
          builderUrl={boot.builderUrl || ''}
          onMode={(mode) => applyOp(ops.updateSettings({ [`${region}_mode`]: mode }), { label: t(region === 'header' ? 'chrome_header' : 'chrome_footer') })}
          onCreatePartial={(r, slug) => createPartial && createPartial(r, slug)}
        />
      ))}

      <h3 className="sbx-inspector__subtitle">{t('seo')}</h3>
      <TextSetting label="Title" value={seo.title} maxLength={255} onCommit={(v) => applyOp(ops.updateSeo({ title: v }), { label: t('seo') })} />
      <TextSetting label="Description" value={seo.description} maxLength={500} multiline onCommit={(v) => applyOp(ops.updateSeo({ description: v }), { label: t('seo') })} />
      <div className="sbx-field">
        <label className="sbx-field__label" htmlFor="sbx-page-robots">Robots</label>
        <select id="sbx-page-robots" value={seo.robots || 'index,follow'} onChange={(e) => applyOp(ops.updateSeo({ robots: e.target.value }), { label: t('seo') })}>
          {asList(vocab.robots).map((r) => <option key={r} value={r}>{r}</option>)}
        </select>
      </div>
    </div>
  );
}
