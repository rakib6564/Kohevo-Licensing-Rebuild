// ThemeDialog — controlled editing of the supported design tokens
// (studio-builder.tokens). Each token shows its default, the value inherited
// from site branding (Settings › Branding) and the Studio override; only the
// override is editable, and only values the server's sanitizer accepts are
// stored (colours, lengths, shadows, font families — never raw CSS).

import { Dialog } from './Dialog.jsx';
import { SiteSettingsForm } from './SiteSettings.jsx';
import { t } from '../core/messages.mjs';
export function ThemeDialog({ onClose, onSaved }) {
  return (
    <Dialog
      title={t('site_settings_title')}
      onClose={onClose}
      footer={<button type="button" className="sbx-btn" onClick={onClose}>{t('close')}</button>}
    >
      <SiteSettingsForm onSaved={onSaved} />
    </Dialog>
  );
}
