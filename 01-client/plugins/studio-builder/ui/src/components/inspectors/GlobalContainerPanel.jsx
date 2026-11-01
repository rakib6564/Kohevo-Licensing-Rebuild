// GlobalContainerPanel — a top-level Container that references a global component (`global_ref`) owns no layout or
// children of its own: this panel shows the component, its publish state, a link to edit it, "Detach" (a server command
// that leaves a local copy), and only the visibility controls (where THIS page shows it).

import { useEditor } from '../EditorContext.jsx';
import { VisibilityControls } from './controls.jsx';
import { componentByRef } from '../../core/library.mjs';
import * as ops from '../../core/operations.mjs';
import { t } from '../../core/messages.mjs';

export function GlobalContainerPanel({ container, label }) {
  const { manifest, applyOp, library = null, detachComponent, publishComponent, boot } = useEditor();
  const component = componentByRef(library && library.components, container.global_ref);
  const perms = (manifest && manifest.permissions) || {};
  return (
    <div className="sbx-global" data-testid="global-container-panel">
      <h3 className="sbx-inspector__subtitle">{t('global_container_title')}</h3>
      <p className="sbx-hint">{t('global_container_body')}</p>
      {library && !component && <p role="alert" className="sbx-field__error">{t('global_container_missing')}</p>}
      {component && (
        <p>
          <strong>{component.title}</strong>
          <br />
          <span className="sbx-muted">{component.is_published ? (component.has_unpublished_changes ? t('library_component_changes') : t('library_component_published')) : t('library_component_unpublished')}</span>
        </p>
      )}
      <div className="sbx-inspector__actions">
        {component && <a className="sbx-btn sbx-btn--xs" href={`${boot.builderUrl || ''}?page=${component.id}`} target="_blank" rel="noopener">{t('library_edit_component')}</a>}
        {component && perms.publish && (!component.is_published || component.has_unpublished_changes) && (
          <button type="button" className="sbx-btn sbx-btn--xs sbx-btn--primary" onClick={() => publishComponent(component)}>{t('library_publish_component')}</button>
        )}
        <button type="button" className="sbx-btn sbx-btn--xs" onClick={() => detachComponent(container.id)}>{t('global_container_detach')}</button>
      </div>
      <h3 className="sbx-inspector__subtitle">{t('tab_visibility')}</h3>
      <VisibilityControls value={container.visibility} manifest={manifest} onChange={(v) => applyOp(ops.updateBlockVisibility(container.id, v), { label })} />
    </div>
  );
}
