// Test entry: renders the REAL builder regions (ShellLayout) to static HTML
// against a given engine state, so the shell can be checked without a browser.
import { renderToString } from 'react-dom/server';
import { EditorContext } from '../../src/components/EditorContext.jsx';
import { ShellLayout } from '../../src/components/StudioShell.jsx';
import { SyncEngine } from '../../src/core/sync.mjs';
import { viewportByKey } from '../../src/core/viewport.mjs';
import { AiReviewDialog } from '../../src/components/AiReviewDialog.jsx';

export { SyncEngine };

export function render({ manifest, document, revisionId = 42, revisionKind = 'manual', selection = null, viewportKey = 'desktop', mutate = null, lockState = null, assistantUrl = null, review = null }) {
  const engine = new SyncEngine({ transport: {}, pageId: 1, schedule: () => 0, cancel: () => {} });
  engine.load({ document, page: { id: 1, title: 'About us', public_path: '/about', is_published: false, has_unpublished_changes: true }, revision: { id: revisionId, revision_kind: revisionKind, revision_number: 7 } }, manifest);
  if (mutate) mutate(engine);
  const noop = () => {};
  const ctx = {
    boot: { pageId: 1, canvasUrl: '/plugins/studio-builder/admin/canvas.php', previewUrl: '/plugins/studio-builder/admin/preview.php', pagesUrl: '/plugins/studio-builder/admin/index.php', canvasSandbox: 'allow-same-origin', mediaPicker: false, assistantUrl },
    engine, manifest, transport: {}, selection, select: noop, announce: noop, applyOp: noop,
    insertBlock: noop, insertSection: noop, removeNode: noop, moveBlockTo: noop, moveSectionTo: noop, labelOf: () => '',
    viewport: viewportByKey(viewportKey),
  };
  // React separates adjacent text nodes with <!-- --> in static markup; drop them for readable assertions.
  return renderToString(
    <EditorContext.Provider value={ctx}>
      <ShellLayout viewportKey={viewportKey} onViewport={noop} onSave={noop} onUndo={noop} onRedo={noop} onPublish={noop} onReload={noop} announcement="" lockState={lockState || { held: true, otherEditor: false }} onAiReview={noop} dialogs={review ? <AiReviewDialog onClose={noop} onPublish={noop} review={review} /> : null} />
    </EditorContext.Provider>,
  ).replace(/<!-- -->/g, '');
}
