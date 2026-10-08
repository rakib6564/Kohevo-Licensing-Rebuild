// MoreBottomSheet — Mobile bottom sheet for page tools and system settings (Image 5).
//
// Shows categorized 2-column action cards for Page settings, SEO, Layers,
// Media library, Version history, Custom code, and more.

import { memo } from 'react';
import { SheetPrimitive } from './sheets/SheetPrimitive.jsx';
import { t } from '../core/messages.mjs';
import {
  IconSettings,
  IconSearch,
  IconImage,
  IconGridPanes,
  IconCopy,
  IconTrash,
  IconLayers,
  IconBox,
  IconClock,
  IconCode,
  IconPlug,
  IconKeyboard,
  IconHelp,
  IconChevronRight,
} from './Icons.jsx';

export const MoreBottomSheet = memo(function MoreBottomSheet({
  onClose,
  onOpenLayers,
  onOpenSettings,
  onOpenHistory,
  onOpenPackages,
}) {
  const sections = [
    {
      category: t('page'),
      items: [
        {
          key: 'settings',
          title: t('more_page_settings'),
          desc: t('more_page_settings_desc'),
          Icon: IconSettings,
          action: () => { onClose(); if (onOpenSettings) onOpenSettings(); },
        },
        {
          key: 'seo',
          title: t('more_seo'),
          desc: t('more_seo_desc'),
          Icon: IconSearch,
          action: () => { onClose(); if (onOpenSettings) onOpenSettings(); },
        },
        {
          key: 'background',
          title: t('more_page_bg'),
          desc: t('more_page_bg_desc'),
          Icon: IconImage,
          action: () => { onClose(); },
        },
        {
          key: 'layout',
          title: t('more_page_layout'),
          desc: t('more_page_layout_desc'),
          Icon: IconGridPanes,
          action: () => { onClose(); },
        },
        {
          key: 'duplicate',
          title: t('more_duplicate_page'),
          desc: t('more_duplicate_page_desc'),
          Icon: IconCopy,
          action: () => { onClose(); alert('Page duplicated as draft'); },
        },
        {
          key: 'delete',
          title: t('more_delete_page'),
          desc: t('more_delete_page_desc'),
          Icon: IconTrash,
          danger: true,
          action: () => {
            if (window.confirm('Are you sure you want to delete this page?')) {
              onClose();
            }
          },
        },
      ],
    },
    {
      category: t('more_project'),
      items: [
        {
          key: 'layers',
          title: t('tab_layers'),
          desc: t('more_layers_desc'),
          Icon: IconLayers,
          action: () => { onClose(); if (onOpenLayers) onOpenLayers(); },
        },
        {
          key: 'media',
          title: t('more_media'),
          desc: t('more_media_desc'),
          Icon: IconImage,
          action: () => { onClose(); },
        },
        {
          key: 'reusable',
          title: t('more_reusable'),
          desc: t('more_reusable_desc'),
          Icon: IconBox,
          action: () => { onClose(); },
        },
        {
          key: 'components',
          title: t('more_global'),
          desc: t('more_global_desc'),
          Icon: IconGridPanes,
          action: () => { onClose(); },
        },
      ],
    },
    {
      category: t('more_history_group'),
      items: [
        {
          key: 'history',
          title: t('more_version_history'),
          desc: t('more_version_history_desc'),
          Icon: IconClock,
          action: () => { onClose(); if (onOpenHistory) onOpenHistory(); },
        },
        {
          key: 'packages',
          title: t('more_import_export'),
          desc: t('more_import_export_desc'),
          Icon: IconBox,
          action: () => { onClose(); if (onOpenPackages) onOpenPackages(); },
        },
      ],
    },
    {
      category: t('more_dev_group'),
      items: [
        {
          key: 'code',
          title: t('more_custom_code'),
          desc: t('more_custom_code_desc'),
          Icon: IconCode,
          action: () => { onClose(); },
        },
        {
          key: 'integrations',
          title: t('more_integrations'),
          desc: t('more_integrations_desc'),
          Icon: IconPlug,
          action: () => { onClose(); },
        },
        {
          key: 'shortcuts',
          title: t('shortcuts_title'),
          desc: t('more_shortcuts_desc'),
          Icon: IconKeyboard,
          action: () => { onClose(); },
        },
        {
          key: 'help',
          title: t('more_help'),
          desc: t('more_help_desc'),
          Icon: IconHelp,
          action: () => { onClose(); },
        },
      ],
    },
  ];

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
                  className={`sbx-more-card${item.danger ? ' is-danger' : ''}`}
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
