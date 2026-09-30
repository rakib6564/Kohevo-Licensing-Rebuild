// RightPanel — property panels for the current selection (block, section or page).

import { memo } from 'react';
import { useEditor, useEngineState } from './EditorContext.jsx';
import { BlockInspector } from './inspectors/BlockInspector.jsx';
import { SectionInspector } from './inspectors/SectionInspector.jsx';
import { PageInspector } from './inspectors/PageInspector.jsx';
import { findNode } from '../core/doc.mjs';
import { t } from '../core/messages.mjs';

export const RightPanel = memo(function RightPanel() {
  const { selection } = useEditor();
  const working = useEngineState((s) => s.working);
  const info = selection ? findNode(working, selection) : null;

  return (
    <aside className="sbx-right" aria-label={t('inspector')}>
      {info && info.kind === 'block' && <BlockInspector key={info.node.id} info={info} />}
      {info && info.kind === 'section' && <SectionInspector key={info.node.id} info={info} />}
      {!info && (
        <>
          <p className="sbx-hint">{t('nothing_selected')}</p>
          <PageInspector />
        </>
      )}
    </aside>
  );
});
