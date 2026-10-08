// MobileDock — Native mobile app style bottom navigation dock.
//
// Gives admins instant thumb access to Add Elements, Layers Tree, Style/Inspector,
// Page Settings, and Canvas Full-View directly on mobile phones or small devices.

import { memo } from 'react';
import { t } from '../core/messages.mjs';
import {
  IconPlus,
  IconSliders,
  IconPalette,
  IconEye,
  IconMoreHorizontal,
} from './Icons.jsx';

export const MobileDock = memo(function MobileDock({
  activeTab,
  mobileSheetOpen,
  onSelectTab,
  hasSelection = false,
}) {
  const items = [
    { key: 'blocks',    label: t('dock_blocks'),   Icon: IconPlus },
    { key: 'inspector', label: t('dock_edit'),     Icon: IconSliders, hasDot: hasSelection },
    { key: 'theme',     label: t('dock_theme'),    Icon: IconPalette },
    { key: 'preview',   label: t('dock_preview'),  Icon: IconEye },
    { key: 'more',      label: t('dock_more'),     Icon: IconMoreHorizontal },
  ];

  return (
    <nav className="sbx-mobile-dock" aria-label={t('mobile_nav')}>
      {items.map((item) => {
        const isActive = mobileSheetOpen && activeTab === item.key;
        return (
          <button
            key={item.key}
            type="button"
            data-dock={item.key}
            data-testid={`mobile-dock-${item.key}`}
            className={`sbx-dock-btn${isActive ? ' is-active' : ''}`}
            onClick={() => onSelectTab(item.key)}
            title={item.label}
          >
            <span className="sbx-dock-btn__icon">
              <item.Icon size={18} />
              {item.hasDot && <span className="sbx-dock-btn__dot" aria-hidden="true" />}
            </span>
            <span className="sbx-dock-btn__label">{item.label}</span>
          </button>
        );
      })}
    </nav>
  );
});
