// PackageDialog — Phase 8A: export this page as a Kohevo Studio JSON package,
// or import one. Import is always two explicit steps: "Analyse (dry run)"
// (the server validates and plans, writes nothing) and "Import into draft",
// which is enabled only when that analysis said `can_commit` for exactly the
// current inputs. Replacing this page's draft goes through the sync engine's
// command path (drains pending edits, carries expected_revision_id, a 409
// enters the conflict state without losing local edits); nothing is ever
// published from here.

import { useState } from 'react';
import { Dialog } from './Dialog.jsx';
import { useEditor, useEngineState } from './EditorContext.jsx';
import { t, errorMessage } from '../core/messages.mjs';
import {
  MODES, parsePackageText, importRequest, analysisKey, canImport, splitIssues, reportOf, exportQuery, downloadPackage,
} from '../core/packages.mjs';

function IssueList({ title, issues }) {
  if (!issues.length) return null;
  return (
    <div className="sbx-field">
      <p className="sbx-field__label">{title}</p>
      <ul className="sbx-package__issues" data-testid={`issues-${issues[0].severity}`}>
        {issues.slice(0, 50).map((i, n) => (
          <li key={n} data-code={i.code}>
            <code>{i.code}</code> {i.message}{i.path ? <span className="sbx-muted"> ({i.path})</span> : null}
          </li>
        ))}
      </ul>
    </div>
  );
}

function planLabel(action) {
  const name = action.token_group || action.title || action.template_key || action.slug || '';
  return t(`plan_${action.action}`, { name });
}

export function ReportView({ report }) {
  if (!report) return null;
  const s = report.summary || {};
  const { errors, warnings } = splitIssues(report);
  return (
    <div className="sbx-package__report" data-testid="package-report" data-can-commit={report.can_commit ? 'true' : 'false'}>
      <p role="status">
        {report.dry_run ? (report.can_commit ? t('import_ready') : t('import_blocked')) : t('import_done')}
      </p>
      <p className="sbx-hint">
        {t('import_summary', {
          pages: s.pages_count || 0, components: s.global_components_count || 0, templates: s.templates_count || 0, tokens: s.tokens_count || 0,
          sections: s.sections_count || 0, blocks: s.blocks_count || 0, errors: s.errors_count || 0, warnings: s.warnings_count || 0,
        })}
      </p>
      <IssueList title={t('import_errors')} issues={errors} />
      <IssueList title={t('import_warnings')} issues={warnings} />
      {Array.isArray(report.planned_actions) && report.planned_actions.length > 0 && (
        <div className="sbx-field">
          <p className="sbx-field__label">{t('import_plan')}</p>
          <ul data-testid="package-plan">{report.planned_actions.map((a, n) => <li key={n}>{planLabel(a)}</li>)}</ul>
        </div>
      )}
      {(s.global_components_count || 0) > 0 && <p className="sbx-hint">{t('import_components_draft')}</p>}
    </div>
  );
}

export function PackageDialog({ onClose, onReplaced, initialTab = 'export' }) {
  const { transport, boot, manifest, engine } = useEditor();
  const page = useEngineState((s) => s.page);
  const revision = useEngineState((s) => s.revision);
  const perms = (manifest && manifest.permissions) || {};
  const [tab, setTab] = useState(perms.edit ? initialTab : 'export');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  // Export
  const [withComponents, setWithComponents] = useState(true);
  const [withTemplate, setWithTemplate] = useState(true);
  const [withTokens, setWithTokens] = useState(false);
  const [exported, setExported] = useState(false);

  // Import
  const [file, setFile] = useState(null); // { name, package, items }
  const [mode, setMode] = useState(MODES.CREATE);
  const [includeTokens, setIncludeTokens] = useState(false);
  const [report, setReport] = useState(null);
  const [analyzedKey, setAnalyzedKey] = useState(null);
  const [committed, setCommitted] = useState(null);

  const revisionId = revision ? revision.id : null;
  const body = (dryRun) => importRequest({ pkg: file && file.package, mode, includeTokens, page, revisionId, dryRun });
  const currentKey = file ? analysisKey(body(true)) : null;
  const ready = canImport(report, analyzedKey, currentKey);
  const stale = !!report && report.dry_run && analyzedKey !== currentKey;

  const doExport = async () => {
    setBusy(true);
    setError(null);
    const res = await transport.exportPackage(exportQuery(boot.pageId, { components: withComponents, template: withTemplate, tokens: withTokens }));
    setBusy(false);
    if (!res.ok) { setError(errorMessage(res.error)); return; }
    downloadPackage(res.data.package, res.data.filename);
    setExported(true);
  };

  const onFile = async (e) => {
    const f = e.target.files && e.target.files[0];
    setReport(null);
    setAnalyzedKey(null);
    setCommitted(null);
    setError(null);
    if (!f) { setFile(null); return; }
    const parsed = parsePackageText(await f.text());
    if (!parsed.ok) { setFile(null); setError(t(parsed.error)); return; }
    setFile({ name: f.name, package: parsed.package, items: parsed.items });
  };

  const analyze = async () => {
    if (!file || busy) return;
    setBusy(true);
    setError(null);
    setCommitted(null);
    const req = body(true);
    const res = await transport.importPackage(req);
    setBusy(false);
    const rep = reportOf(res);
    if (!rep) { setReport(null); setError(errorMessage(res.error)); return; }
    setReport(rep);
    setAnalyzedKey(analysisKey(req));
  };

  const commit = async () => {
    if (!ready || busy) return;
    setBusy(true);
    setError(null);
    if (mode === MODES.REPLACE) {
      let last = null;
      const ok = await engine.command(async (base) => {
        last = await transport.importPackage({ ...body(false), expected_revision_id: base.expected_revision_id });
        return last;
      }, { label: t('import_done') });
      setBusy(false);
      const rep = reportOf(last);
      if (rep) setReport(rep);
      if (!ok) { setError(errorMessage(last ? last.error : engine.getSnapshot().error)); return; }
      setCommitted(rep ? rep.committed : null);
      if (onReplaced) onReplaced();
      return;
    }
    const res = await transport.importPackage(body(false));
    setBusy(false);
    const rep = reportOf(res);
    if (rep) setReport(rep);
    if (!res.ok) { setError(errorMessage(res.error)); return; }
    setCommitted(rep.committed);
  };

  const footer = tab === 'export' ? (
    <>
      <button type="button" className="sbx-btn" onClick={onClose}>{t('cancel')}</button>
      <button type="button" className="sbx-btn sbx-btn--primary" disabled={busy} onClick={doExport} data-testid="export-download">{t('export_download')}</button>
    </>
  ) : (
    <>
      <button type="button" className="sbx-btn" onClick={onClose}>{t('cancel')}</button>
      <button type="button" className="sbx-btn" disabled={!file || busy} onClick={analyze} data-testid="import-analyze">{t('import_analyze')}</button>
      <button type="button" className="sbx-btn sbx-btn--primary" disabled={!ready || busy || !!committed} onClick={commit} data-testid="import-commit">{t('import_commit')}</button>
    </>
  );

  return (
    <Dialog title={t('packages_title')} onClose={onClose} footer={footer}>
      <div className="sbx-topbar__center" role="tablist" aria-label={t('packages')}>
        <button type="button" role="tab" aria-selected={tab === 'export'} className={`sbx-btn sbx-btn--seg${tab === 'export' ? ' is-active' : ''}`} onClick={() => setTab('export')}>{t('packages_tab_export')}</button>
        {perms.edit && <button type="button" role="tab" aria-selected={tab === 'import'} className={`sbx-btn sbx-btn--seg${tab === 'import' ? ' is-active' : ''}`} onClick={() => setTab('import')}>{t('packages_tab_import')}</button>}
      </div>

      {tab === 'export' && (
        <div data-testid="package-export">
          <p className="sbx-hint">{t('export_hint')}</p>
          <label className="sbx-field"><input type="checkbox" checked={withComponents} onChange={(e) => setWithComponents(e.target.checked)} /> {t('export_components')}</label>
          <label className="sbx-field"><input type="checkbox" checked={withTemplate} onChange={(e) => setWithTemplate(e.target.checked)} /> {t('export_template')}</label>
          <label className="sbx-field"><input type="checkbox" checked={withTokens} onChange={(e) => setWithTokens(e.target.checked)} /> {t('export_tokens')}</label>
          {exported && <p role="status">{t('export_done')}</p>}
        </div>
      )}

      {tab === 'import' && (
        <div data-testid="package-import">
          <p className="sbx-hint">{t('import_hint')}</p>
          <div className="sbx-field">
            <label className="sbx-field__label" htmlFor="sbx-pkg-file">{t('import_file')}</label>
            <input id="sbx-pkg-file" type="file" accept=".json,application/json" onChange={onFile} />
          </div>
          <fieldset className="sbx-field">
            <legend>{t('import_mode')}</legend>
            <label><input type="radio" name="sbx-pkg-mode" checked={mode === MODES.CREATE} onChange={() => setMode(MODES.CREATE)} /> {t('import_mode_create')}</label>
            <label><input type="radio" name="sbx-pkg-mode" checked={mode === MODES.REPLACE} onChange={() => setMode(MODES.REPLACE)} disabled={!page || !Number.isInteger(page.id)} /> {t('import_mode_replace')}</label>
          </fieldset>
          {perms.tokens && (
            <label className="sbx-field"><input type="checkbox" checked={includeTokens} onChange={(e) => setIncludeTokens(e.target.checked)} /> {t('import_tokens')}</label>
          )}
          {stale && <p className="sbx-hint" role="note" data-testid="package-stale">{t('import_reanalyze')}</p>}
          <ReportView report={report} />
          {committed && Array.isArray(committed.pages) && boot.builderUrl && (
            <ul data-testid="package-committed">
              {committed.pages.filter((p) => p.mode === 'created').map((p) => (
                <li key={p.page_id}><a href={`${boot.builderUrl}?page=${p.page_id}`} target="_blank" rel="noopener">{t('import_open')} /{p.slug}</a></li>
              ))}
            </ul>
          )}
        </div>
      )}
      {error && <p role="alert" className="sbx-field__problem">{error}</p>}
    </Dialog>
  );
}
