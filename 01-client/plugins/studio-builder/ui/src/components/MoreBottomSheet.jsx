// MoreBottomSheet — Mobile bottom sheet for page tools and system settings (Image 5).
//
// Shows categorized 2-column action cards for Page settings, SEO, Duplicate page, Layers,
// the Library (templates and global components), Version history, Import & Export and Custom code.
// Each card opens the same panel or dialog the desktop editor uses.

import { memo } from 'react';
import { SheetPrimitive } from './sheets/SheetPrimitive.jsx';
import { t } from '../core/messages.mjs';
import {
  IconSettings,
  IconSearch,
  IconGridPanes,
  IconCopy,
  IconLayers,
  IconBox,
  IconClock,
  IconCode,
  IconChevronRight,
} from './Icons.jsx';

export const MoreBottomSheet = memo(function MoreBottomSheet({
  onClose,
  onOpenLayers,
  onOpenSettings,
  onOpenLibrary = null,
  onDuplicatePage = null,
  onOpenHistory,
  onOpenPackages,
  onOpenCustomCode = null,
}) {
  // Every card opens something that exists elsewhere in the editor; a card without one is not shown.
  const card = (key, title, desc, Icon, open) => (open ? { key, title, desc, Icon, action: () => { onClose(); open(); } } : null);
  const sections = [
    {
      category: t('page'),
      items: [
        card('settings', t('more_page_settings'), t('more_page_settings_desc'), IconSettings, onOpenSettings),
        card('seo', t('more_seo'), t('more_seo_desc'), IconSearch, onOpenSettings),
        card('duplicate', t('more_duplicate_page'), t('more_duplicate_page_desc'), IconCopy, onDuplicatePage),
      ],
    },
    {
      category: t('more_project'),
      items: [
        card('layers', t('tab_layers'), t('more_layers_desc'), IconLayers, onOpenLayers),
        card('reusable', t('more_reusable'), t('more_reusable_desc'), IconBox, onOpenLibrary),
        card('components', t('more_global'), t('more_global_desc'), IconGridPanes, onOpenLibrary),
      ],
    },
    {
      category: t('more_history_group'),
      items: [
        card('history', t('more_version_history'), t('more_version_history_desc'), IconClock, onOpenHistory),
        card('packages', t('more_import_export'), t('more_import_export_desc'), IconBox, onOpenPackages),
      ],
    },
    {
      category: t('more_dev_group'),
      items: [
        card('code', t('more_custom_code'), t('more_custom_code_desc'), IconCode, onOpenCustomCode),
      ],
    },
  ].map((sec) => ({ ...sec, items: sec.items.filter(Boolean) })).filter((sec) => sec.items.length);

  return (
    <SheetPrimitive title={t('dock_more')} subtitle={t('more_subtitle')} onClose={onClose} className="sbx-more-sheet" label={t('more_settings_label')} closeLabel={t('close_settings')} testId="sheet-more">
      {/* Categorized Action Grid */}
      <div className="sbx-bottom-sheet__body">
        {sections.map((sec) => (
          <div key={sec.category} className="sbx-more-category">
            <h3 className="sbx-more-category__title">{sec.category}</h3>
            <div className="sbx-more-grid">
              {sec.items.map((item) => (
                <button
                  key={item.key}
                  type="button"
                  className="sbx-more-card"
                  data-more={item.key}
                  onClick={item.action}
                >
                  <span className="sbx-more-card__icon">
                    <item.Icon size={17} />
                  </span>
                  <div className="sbx-more-card__info">
                    <span className="sbx-more-card__title">{item.title}</span>
                    <span className="sbx-more-card__desc">{item.desc}</span>
                  </div>
                  <span className="sbx-more-card__chevron" aria-hidden="true">
                    <IconChevronRight size={14} />
                  </span>
                </button>
              ))}
            </div>
          </div>
        ))}
      </div>
    </SheetPrimitive>
  );
});
