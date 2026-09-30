// PageInspector — document settings (update_settings) and the basic search
// appearance fields (update_seo). The complete SEO UI is out of Phase 5 scope.

import { useEffect, useId, useState } from 'react';
import { useEditor, useEngineState } from '../EditorContext.jsx';
import { asList, asObject } from '../../core/doc.mjs';
import * as ops from '../../core/operations.mjs';
import { t } from '../../core/messages.mjs';

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

export function PageInspector() {
  const { manifest, applyOp } = useEditor();
  const working = useEngineState((s) => s.working);
  const settings = asObject(working && working.settings);
  const seo = asObject(working && working.seo);
  const vocab = manifest.vocabulary || {};
  const select = (key, options, labelText) => (
    <div className="sbx-field" key={key}>
      <label className="sbx-field__label" htmlFor={`sbx-page-${key}`}>{labelText}</label>
      <select id={`sbx-page-${key}`} value={settings[key] ?? ''} onChange={(e) => applyOp(ops.updateSettings({ [key]: e.target.value }), { label: labelText })}>
        {options.map((o) => <option key={o} value={o}>{o}</option>)}
      </select>
    </div>
  );

  return (
    <div className="sbx-inspector">
      <h2 className="sbx-inspector__title">{t('page_settings')}</h2>
      {select('container_width', asList(vocab.container_widths), t('width'))}
      {select('header_mode', asList(vocab.chrome_modes), 'Header')}
      {select('footer_mode', asList(vocab.chrome_modes), 'Footer')}

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
