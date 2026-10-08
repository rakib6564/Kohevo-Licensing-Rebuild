// InspectorHeader — who is selected: the block's icon, its name (the author's label, else its type), the type when
// it was renamed, and the same ⋯ menu a Layers row has (rename, duplicate, lock, hide, save to library, delete).
// Move up / down stay as their own buttons under the header, so they are left out of the menu here.

import { useMemo, useState } from 'react';
import { useEditor, useEngineState } from '../EditorContext.jsx';
import { RowMenu } from '../RowMenu.jsx';
import { renderBlockIcon } from '../blockIcons.jsx';
import { lockIndex } from '../../core/layerLock.mjs';
import { rowMenuItems } from '../../core/rowMenu.mjs';
import { t } from '../../core/messages.mjs';
import * as ops from '../../core/operations.mjs';

export function InspectorHeader({ node, def }) {
  const { manifest, duplicateNode, renameNode, setLocked, removeNode, applyOp, openSaveTemplate } = useEditor();
  const working = useEngineState((s) => s.working);
  const pageType = useEngineState((s) => (s.page ? s.page.page_type : 'page'));
  const locks = useMemo(() => lockIndex(working), [working]);
  const [editing, setEditing] = useState(null);

  // The author's own name for the layer, else the block's type; the Layers row adds a content hint, the header does not.
  const custom = node.metadata && typeof node.metadata.label === 'string' ? node.metadata.label.trim() : '';
  const renamed = custom !== '';
  const name = renamed ? custom : def.label;
  const row = { kind: 'block', id: node.id, node, index: 0, setSize: 1, parentId: null };
  const items = rowMenuItems({ row, doc: working, manifest, locks, pageType, canSaveToLibrary: !!(manifest.permissions && manifest.permissions.admin) })
    .filter((item) => item.key !== 'move_up' && item.key !== 'move_down');

  const finish = () => {
    if (editing !== null && renameNode) renameNode(node.id, editing);
    setEditing(null);
  };

  const run = (key) => {
    switch (key) {
      case 'rename': setEditing((node.metadata && node.metadata.label) || ''); break;
      case 'duplicate': duplicateNode(node.id); break;
      case 'lock': setLocked(node.id, true); break;
      case 'unlock': setLocked(node.id, false); break;
      case 'hide': case 'show': {
        const hidden = key === 'show';
        applyOp(ops.updateBlockVisibility(node.id, { ...(node.visibility || {}), devices: hidden ? ['base', 'sm', 'md', 'lg'] : [] }), { label: def.label });
        break;
      }
      case 'save_library': if (openSaveTemplate) openSaveTemplate(); break;
      case 'delete': removeNode(node.id); break;
      default: break;
    }
  };

  return (
    <header className="sbx-ihead">
      <span className="sbx-ihead__icon" aria-hidden="true">{renderBlockIcon(node.type, def.icon, def.label)}</span>
      <div className="sbx-ihead__names">
        {editing !== null ? (
          <input
            className="sbx-input sbx-ihead__rename"
            aria-label={t('row_rename')}
            value={editing}
            maxLength={80}
            autoFocus
            onChange={(e) => setEditing(e.target.value)}
            onBlur={finish}
            onKeyDown={(e) => {
              if (e.key === 'Enter') { e.preventDefault(); finish(); }
              if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); setEditing(null); }
            }}
          />
        ) : (
          <h2 className="sbx-inspector__title">{name}</h2>
        )}
        {renamed && editing === null ? <span className="sbx-ihead__type">{def.label}</span> : null}
      </div>
      <RowMenu label={name} testId="inspector-menu" items={items} onChoose={run} />
    </header>
  );
}
