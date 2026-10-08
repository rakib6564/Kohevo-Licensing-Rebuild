// RightPanel — the docked Inspector (desktop). It follows the selection: a block or section Inspector, the
// lock banner, the multi-selection note, or the empty state. Hiding it (top bar toggle) gives the canvas the width.
// On a phone the same content lives in the sheet instead (LeftPanel), so this renders nothing there.

import { memo } from 'react';
import { InspectorHost } from './InspectorHost.jsx';
import { t } from '../core/messages.mjs';

export const RightPanel = memo(function RightPanel({ onNavigate }) {
  return (
    <aside className="sbx-right" aria-label={t('inspector')} data-testid="inspector-panel">
      <InspectorHost onNavigate={onNavigate} />
    </aside>
  );
});
