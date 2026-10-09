// PageInspector — document settings (update_settings) and the search
// appearance fields (update_seo): title, description, canonical URL, social
// share image and robots — exactly the canonical document's `seo` keys (no
// second store). They are draft data like everything else in the document and
// reach the public site only through Publish. The server validates every
// value; the limits shown come from its manifest.
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
import { seoLimits, canonicalProblem, canonicalValue } from '../../core/seo.mjs';
import { SeoImageControl } from './SeoImageControl.jsx';
import { optionLabel } from '../../core/optionLabels.mjs';

function TextSetting({ label, value, maxLength, multiline = false, onCommit, counter = false }) {
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
        aria-describedby={counter ? `${id}-count` : undefined}
        onChange={(e) => {
          const v = multiline ? e.target.value : e.target.value.replace(/[\r\n]+/g, ' ');
          setDraft(v);
          onCommit(v);
        }}
      />
      {counter && <p className="sbx-hint" id={`${id}-count`} data-testid={`${id}-count`}>{t('seo_count', { count: Array.from(draft).length, max: maxLength })}</p>}
    </div>
  );
}

// The canonical URL: invalid text stays in the box with a message and is NOT
// sent; an empty box clears the value (null = "use this page's own address").
function CanonicalSetting({ value, onCommit }) {
  const id = useId();
  const [draft, setDraft] = useState(value ?? '');
  useEffect(() => { setDraft(value ?? ''); }, [value]);
  const problem = canonicalProblem(draft);
  return (
    <div className="sbx-field">
      <label className="sbx-field__label" htmlFor={id}>{t('seo_canonical_label')}</label>
      <input
        id={id}
        type="text"
        inputMode="url"
        autoComplete="off"
        maxLength={2048}
        value={draft}
        aria-invalid={problem ? 'true' : undefined}
        aria-describedby={`${id}-hint`}
        onChange={(e) => {
          const v = e.target.value.replace(/[\r\n\s]+/g, '');
          setDraft(v);
          if (canonicalProblem(v) === null) onCommit(canonicalValue(v));
        }}
      />
      <p className="sbx-hint" id={`${id}-hint`}>{t('seo_canonical_hint')}</p>
      {problem && <p className="sbx-field__problem" role="alert" data-testid="seo-canonical-problem">{t(problem)}</p>}
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
  const limits = seoLimits(manifest);
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
        {options.map((o) => <option key={o} value={o}>{optionLabel(o)}</option>)}
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
      <p className="sbx-hint">{t('seo_draft_note')}</p>
      <TextSetting label={t('seo_title_label')} value={seo.title} maxLength={limits.title} counter onCommit={(v) => applyOp(ops.updateSeo({ title: v }), { label: t('seo') })} />
      <TextSetting label={t('seo_description_label')} value={seo.description} maxLength={limits.description} multiline counter onCommit={(v) => applyOp(ops.updateSeo({ description: v }), { label: t('seo') })} />
      <CanonicalSetting value={seo.canonical_url} onCommit={(v) => applyOp(ops.updateSeo({ canonical_url: v }), { label: t('seo') })} />
      <SeoImageControl value={seo.og_image_media_id} mediaPicker={boot.mediaPicker} onChange={(v) => applyOp(ops.updateSeo({ og_image_media_id: v }), { label: t('seo') })} />
      <div className="sbx-field">
        <label className="sbx-field__label" htmlFor="sbx-page-robots">{t('seo_robots_label')}</label>
        <select id="sbx-page-robots" value={seo.robots || 'index,follow'} onChange={(e) => applyOp(ops.updateSeo({ robots: e.target.value }), { label: t('seo') })}>
          {asList(vocab.robots).map((r) => <option key={r} value={r}>{optionLabel(r)}</option>)}
        </select>
      </div>
    </div>
  );
}
