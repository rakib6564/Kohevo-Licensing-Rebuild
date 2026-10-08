// MoreBottomSheet — Mobile bottom sheet for page tools and system settings (Image 5).
//
// Shows categorized 2-column action cards for Page settings, SEO, Layers,
// Media library, Version history, Custom code, and more.

import { memo } from 'react';
import { SheetPrimitive } from './sheets/SheetPrimitive.jsx';
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
      category: 'Page',
      items: [
        {
          key: 'settings',
          title: 'Page settings',
          desc: 'Title, slug, meta and basic settings',
          Icon: IconSettings,
          action: () => { onClose(); if (onOpenSettings) onOpenSettings(); },
        },
        {
          key: 'seo',
          title: 'SEO',
          desc: 'Search engine optimization',
          Icon: IconSearch,
          action: () => { onClose(); if (onOpenSettings) onOpenSettings(); },
        },
        {
          key: 'background',
          title: 'Page background',
          desc: 'Set background for this page',
          Icon: IconImage,
          action: () => { onClose(); },
        },
        {
          key: 'layout',
          title: 'Page layout',
          desc: 'Container, width and spacing',
          Icon: IconGridPanes,
          action: () => { onClose(); },
        },
        {
          key: 'duplicate',
          title: 'Duplicate page',
          desc: 'Create a copy of this page',
          Icon: IconCopy,
          action: () => { onClose(); alert('Page duplicated as draft'); },
        },
        {
          key: 'delete',
          title: 'Delete page',
          desc: 'Remove this page permanently',
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
      category: 'Project',
      items: [
        {
          key: 'layers',
          title: 'Layers',
          desc: 'View and manage page structure',
          Icon: IconLayers,
          action: () => { onClose(); if (onOpenLayers) onOpenLayers(); },
        },
        {
          key: 'media',
          title: 'Media library',
          desc: 'Manage your images and files',
          Icon: IconImage,
          action: () => { onClose(); },
        },
        {
          key: 'reusable',
          title: 'Reusable blocks',
          desc: 'Manage saved blocks',
          Icon: IconBox,
          action: () => { onClose(); },
        },
        {
          key: 'components',
          title: 'Global components',
          desc: 'Manage site-wide components',
          Icon: IconGridPanes,
          action: () => { onClose(); },
        },
      ],
    },
    {
      category: 'History & Collaboration',
      items: [
        {
          key: 'history',
          title: 'Version history',
          desc: 'View and restore past versions',
          Icon: IconClock,
          action: () => { onClose(); if (onOpenHistory) onOpenHistory(); },
        },
        {
          key: 'packages',
          title: 'Import & Export',
          desc: 'Export project package or HTML',
          Icon: IconBox,
          action: () => { onClose(); if (onOpenPackages) onOpenPackages(); },
        },
      ],
    },
    {
      category: 'Developer & Advanced',
      items: [
        {
          key: 'code',
          title: 'Custom code',
          desc: 'Add custom CSS, JS or head code',
          Icon: IconCode,
          action: () => { onClose(); },
        },
        {
          key: 'integrations',
          title: 'Integrations',
          desc: 'Connect third-party services',
          Icon: IconPlug,
          action: () => { onClose(); },
        },
        {
          key: 'shortcuts',
          title: 'Keyboard shortcuts',
          desc: 'View all shortcuts',
          Icon: IconKeyboard,
          action: () => { onClose(); },
        },
        {
          key: 'help',
          title: 'Help & support',
          desc: 'Documentation and guides',
          Icon: IconHelp,
          action: () => { onClose(); },
        },
      ],
    },
  ];

  return (
    <SheetPrimitive title="More" subtitle="Additional tools and settings for your page" onClose={onClose} className="sbx-more-sheet" label="More Settings" closeLabel="Close settings" testId="sheet-more">
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
