// ThemeBottomSheet — the mobile Theme tab: the same Site settings form as the
// desktop Theme dialog (ThemeDialog), inside a draggable bottom sheet. It edits
// only the design tokens the server accepts, so every save can succeed.

import { memo } from 'react';
import { SheetPrimitive } from './sheets/SheetPrimitive.jsx';
import { SiteSettingsForm } from './SiteSettings.jsx';
import { t } from '../core/messages.mjs';

export const ThemeBottomSheet = memo(function ThemeBottomSheet({ onClose, onSaved }) {
  return (
    <SheetPrimitive title={t('dock_theme')} subtitle={t('theme_subtitle')} onClose={onClose} className="sbx-theme-sheet" label={t('theme_label')} closeLabel={t('close_theme')} testId="sheet-theme">
      <div className="sbx-bottom-sheet__body">
        <SiteSettingsForm onSaved={onSaved} />
      </div>
    </SheetPrimitive>
  );
});
