// Test entry: renders the REAL builder regions (ShellLayout) to static HTML
// against a given engine state, so the shell can be checked without a browser.
import { renderToString } from 'react-dom/server';
import { EditorContext, SelectionContext } from '../../src/components/EditorContext.jsx';
import { EMPTY_SELECTION, selectOnly } from '../../src/core/selection.mjs';
import { ShellLayout } from '../../src/components/StudioShell.jsx';
import { SyncEngine } from '../../src/core/sync.mjs';
import { viewportByKey } from '../../src/core/viewport.mjs';
import { AiReviewDialog } from '../../src/components/AiReviewDialog.jsx';
import { PackageDialog, ReportView } from '../../src/components/PackageDialog.jsx';

export { SyncEngine };

export function render({ manifest, document, revisionId = 42, revisionKind = 'manual', selection = null, selectedIds = null, viewportKey = 'desktop', mutate = null, lockState = null, assistantUrl = null, review = null }) {
  const engine = new SyncEngine({ transport: {}, pageId: 1, schedule: () => 0, cancel: () => {} });
  engine.load({ document, page: { id: 1, title: 'About us', public_path: '/about', is_published: false, has_unpublished_changes: true }, revision: { id: revisionId, revision_kind: revisionKind, revision_number: 7 } }, manifest);
  if (mutate) mutate(engine);
  const noop = () => {};
  const ctx = {
    boot: { pageId: 1, canvasUrl: '/plugins/studio-builder/admin/canvas.php', previewUrl: '/plugins/studio-builder/admin/preview.php', pagesUrl: '/plugins/studio-builder/admin/index.php', canvasSandbox: 'allow-same-origin', mediaPicker: false, assistantUrl },
    engine, manifest, transport: {}, announce: noop, applyOp: noop,
    insertBlock: noop, insertSection: noop, removeNode: noop, moveBlockTo: noop, moveSectionTo: noop, labelOf: () => '',
    viewport: viewportByKey(viewportKey),
  };
  // React separates adjacent text nodes with <!-- --> in static markup; drop them for readable assertions.
  const sel = selectedIds && selectedIds.length ? { ...selectOnly(EMPTY_SELECTION, selectedIds[selectedIds.length - 1]), ids: selectedIds } : selectOnly(EMPTY_SELECTION, selection);
  const selectionCtx = { sel, selection: sel.primary, selectedIds: sel.ids, select: noop, pick: noop, setHover: noop, setFocus: noop };
  return renderToString(
    <EditorContext.Provider value={ctx}><SelectionContext.Provider value={selectionCtx}>
      <ShellLayout viewportKey={viewportKey} onViewport={noop} onSave={noop} onUndo={noop} onRedo={noop} onPublish={noop} onReload={noop} announcement="" lockState={lockState || { held: true, otherEditor: false }} onAiReview={noop} dialogs={review ? <AiReviewDialog onClose={noop} onPublish={noop} review={review} /> : null} />
    </SelectionContext.Provider></EditorContext.Provider>,
  ).replace(/<!-- -->/g, '');
}

/** Phase 8A: the package dialog (import tab) and a report, rendered to static HTML. */
export function renderPackages({ manifest, permissions, report = null, tab = 'import', withShell = false, source = 'package' }) {
  const engine = new SyncEngine({ transport: {}, pageId: 1, schedule: () => 0, cancel: () => {} });
  const m = { ...manifest, permissions: { ...manifest.permissions, ...permissions } };
  engine.load({ document: { document_type: 'page', schema_version: '1.0', sections: [], seo: {}, settings: {}, template_key: 'default' }, page: { id: 1, uuid: '11111111-1111-4111-8111-111111111111', title: 'About us', public_path: '/about', is_published: false, has_unpublished_changes: true }, revision: { id: 42, revision_kind: 'manual', revision_number: 7 } }, m);
  const noop = () => {};
  const ctx = {
    boot: { pageId: 1, canvasUrl: '/c', previewUrl: '/p', pagesUrl: '/i', builderUrl: '/b', canvasSandbox: 'allow-same-origin', mediaPicker: false },
    engine, manifest: m, transport: {}, announce: noop, applyOp: noop,
    insertBlock: noop, insertSection: noop, removeNode: noop, moveBlockTo: noop, moveSectionTo: noop, labelOf: () => '',
    viewport: viewportByKey('desktop'),
  };
  const body = withShell
    ? <ShellLayout viewportKey="desktop" onViewport={noop} onSave={noop} onUndo={noop} onRedo={noop} onPublish={noop} onReload={noop} announcement="" lockState={{ held: true, otherEditor: false }} onPackages={noop} />
    : <>{<PackageDialog onClose={noop} initialTab={tab} initialSource={source} />}{report ? <ReportView report={report} /> : null}</>;
  const selectionCtx = { sel: EMPTY_SELECTION, selection: null, selectedIds: [], select: noop, pick: noop, setHover: noop, setFocus: noop };
  return renderToString(<EditorContext.Provider value={ctx}><SelectionContext.Provider value={selectionCtx}>{body}</SelectionContext.Provider></EditorContext.Provider>).replace(/<!-- -->/g, '');
}
