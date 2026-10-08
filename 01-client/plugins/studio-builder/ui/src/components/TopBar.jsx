// TopBar — Page identity, device viewports, undo/redo, preview and publish.
// Sleek, minimal header with pixel-perfect responsive controls.

import { memo, useState } from 'react';
import { useEditor, useEngineState } from './EditorContext.jsx';
import { STATUS } from '../core/sync.mjs';
import { VIEWPORTS } from '../core/viewport.mjs';
import { t, errorMessage } from '../core/messages.mjs';
import { isAiRevision } from '../core/review.mjs';
import {
  IconArrowLeft,
  IconEdit,
  IconEye,
  IconUndo,
  IconRedo,
  IconClock,
  IconExternalLink,
  IconSaveDisk,
  IconSendPlane,
  IconMonitor,
  IconTablet,
  IconSmartphone,
  IconPalette,
  IconExport,
  IconSparkles,
  IconMenu,
  IconSliders,
} from './Icons.jsx';

const STATUS_KEY = {
  [STATUS.LOADING]: 'status_loading',
  [STATUS.IDLE]: 'status_idle',
  [STATUS.SAVED]: 'status_saved',
  [STATUS.DIRTY]: 'status_dirty',
  [STATUS.SAVING]: 'status_saving',
  [STATUS.ERROR]: 'status_error',
  [STATUS.CONFLICT]: 'status_conflict',
};

const VIEWPORT_ICONS = {
  desktop: IconMonitor,
  tablet: IconTablet,
  mobile: IconSmartphone,
};

export const SaveStatus = memo(function SaveStatus() {
  const status = useEngineState((s) => s.status);
  const error = useEngineState((s) => s.error);
  return (
    <span
      className={`sbx-status sbx-status--${status}`}
      data-status={status}
      title={status === STATUS.ERROR && error ? errorMessage(error) : undefined}
    >
      <span className="sbx-status__dot" aria-hidden="true" />
      <span className="sbx-status__text">{t(STATUS_KEY[status] || 'status_idle')}</span>
    </span>
  );
});

export const TopBar = memo(function TopBar({
  viewMode = 'edit',
  onViewMode = () => {},
  inspectorOpen = false,
  onToggleInspector = null,
  viewportKey,
  onViewport,
  onSave,
  onUndo,
  onRedo,
  onPublish,
  onHistory,
  onTheme = null,
  onAiReview = null,
  onPackages = null,
  onOpenMobileDock = null,
}) {
  const { boot, engine, manifest } = useEditor();
  const page = useEngineState((s) => s.page);
  const revision = useEngineState((s) => s.revision);
  const aiDraft = isAiRevision(revision);
  const status = useEngineState((s) => s.status);
  const undoCount = useEngineState((s) => s.undo.length);
  const redoCount = useEngineState((s) => s.redo.length);
  const pendingCount = useEngineState((s) => s.pending.length);
  const conflict = status === STATUS.CONFLICT;
  const busy = status === STATUS.SAVING;
  const canPublish = !!(manifest.permissions && manifest.permissions.publish);
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);

  const slugText = page
    ? ((page.slug || page.title || '').replace(/^\/+/, '')).toUpperCase()
    : 'PAGE';

  const revNum = revision ? (revision.revision_number || revision.id || 1) : 1;
  const liveNum = page && page.published_revision_number
    ? page.published_revision_number
    : (page && page.is_published ? revNum : '—');

  return (
    <header className="sbx-topbar" role="banner">
      {/* ── Left Area: Brand & Document Identity ── */}
      <div className="sbx-topbar__left">
        <div className="sbx-brand-badge" title="KOHEVO STUDIO BUILDER 2.0">
          <span className="sbx-brand-badge__logo">K</span>
          <div className="sbx-brand-badge__info">
            <span className="sbx-brand-badge__title">KOHEVO STUDIO</span>
            <span className="sbx-brand-badge__sub">BUILDER 2.0</span>
          </div>
        </div>

        <a
          className="sbx-topbar__back-btn"
          href={boot.pagesUrl}
          title={t('back_to_pages')}
          aria-label={t('back_to_pages')}
        >
          <IconArrowLeft size={15} />
        </a>

        <div className="sbx-topbar__title-group">
          {Array.isArray(boot.allPages) && boot.allPages.length > 1 ? (
            <div className="sbx-page-switcher">
              <select
                className="sbx-page-switcher__select"
                value={boot.pageId}
                onChange={(e) => {
                  const targetId = e.target.value;
                  if (targetId && Number(targetId) !== Number(boot.pageId)) {
                    if (pendingCount > 0) {
                      if (!window.confirm('You have unsaved changes. Switch page anyway?')) {
                        e.target.value = boot.pageId;
                        return;
                      }
                    }
                    window.location.href = `${boot.builderUrl}?page=${targetId}`;
                  }
                }}
                title="Switch page in builder"
                aria-label="Switch page"
              >
                {boot.allPages.map((p) => {
                  const label = {
                    home: 'Home',
                    work: 'Projects',
                    services: 'Services',
                    about: 'About',
                    contact: 'Contact',
                  }[p.slug] || p.title;
                  return (
                    <option key={p.id} value={p.id}>
                      {label} (/{p.slug})
                    </option>
                  );
                })}
              </select>
            </div>
          ) : (
            <strong
              className="sbx-topbar__page-title"
              title={`${page ? page.title : 'Page'} (/${slugText})`}
            >
              {page ? page.title : 'Page'}
            </strong>
          )}

          <span
            className={`sbx-status-pill ${
              page && page.is_published ? 'sbx-status-pill--published' : 'sbx-status-pill--draft'
            }`}
          >
            {page && page.is_published ? 'PUBLISHED' : 'DRAFT'}
          </span>

          <span
            className="sbx-topbar__rev-badge"
            title={`Revision ${revNum} · Live ${liveNum} (/${slugText})`}
          >
            r{revNum}
          </span>

          <SaveStatus />

          {aiDraft && (
            <span
              className="sbx-badge sbx-badge--ai"
              data-testid="ai-badge"
              title={t('ai_review_hint')}
            >
              {t('ai_badge')}
            </span>
          )}
        </div>
      </div>

      {/* ── Center Area: View Mode, Viewport Switchers, Undo/Redo ── */}
      <div className="sbx-topbar__center">
        <div className="sbx-view-mode-toggle" role="group" aria-label={t('view_mode')}>
          <button
            type="button"
            className={`sbx-view-mode-btn${viewMode === 'edit' ? ' is-active' : ''}`}
            aria-pressed={viewMode === 'edit'}
            onClick={() => onViewMode('edit')}
            title={t('mode_edit_hint')}
          >
            <IconEdit size={12} />
            <span>{t('mode_edit')}</span>
          </button>
          <button
            type="button"
            className={`sbx-view-mode-btn${viewMode === 'preview' ? ' is-active' : ''}`}
            aria-pressed={viewMode === 'preview'}
            data-testid="mode-preview"
            onClick={() => onViewMode('preview')}
            title={t('mode_preview_hint')}
          >
            <IconEye size={12} />
            <span>{t('mode_preview')}</span>
          </button>
          <button
            type="button"
            className={`sbx-view-mode-btn${viewMode === 'visitor' ? ' is-active' : ''}`}
            aria-pressed={viewMode === 'visitor'}
            data-testid="mode-visitor"
            onClick={() => onViewMode('visitor')}
            title={t('mode_visitor_hint')}
          >
            <IconExternalLink size={12} />
            <span>{t('mode_visitor')}</span>
          </button>
        </div>

        <div className="sbx-viewport-group" role="toolbar" aria-label={t('viewport')}>
          {VIEWPORTS.map((v) => (
            <button
              key={v.key}
              type="button"
              className={`sbx-btn sbx-btn--seg${viewportKey === v.key ? ' is-active' : ''}`}
              aria-pressed={viewportKey === v.key}
              onClick={() => onViewport(v.key)}
              title={`${t(v.key)} (${v.breakpoint}, ${v.width}px)`}
            >
              {t(v.key)}
            </button>
          ))}
        </div>

        {onToggleInspector && (
          <button
            type="button"
            className={`sbx-btn sbx-btn--icon sbx-inspector-toggle${inspectorOpen ? ' is-active' : ''}`}
            onClick={onToggleInspector}
            aria-pressed={inspectorOpen}
            data-testid="inspector-toggle"
            title={inspectorOpen ? t('inspector_hide') : t('inspector_show')}
            aria-label={inspectorOpen ? t('inspector_hide') : t('inspector_show')}
          >
            <IconSliders size={13} />
          </button>
        )}

        <div className="sbx-topbar__history-group">
          <button
            type="button"
            className="sbx-btn sbx-btn--icon"
            onClick={onUndo}
            disabled={conflict || busy || undoCount === 0}
            aria-keyshortcuts="Control+Z Meta+Z"
            title="Undo (Ctrl+Z)"
            aria-label={t('undo')}
          >
            <IconUndo size={13} />
          </button>
          <button
            type="button"
            className="sbx-btn sbx-btn--icon"
            onClick={onRedo}
            disabled={conflict || busy || redoCount === 0}
            aria-keyshortcuts="Control+Shift+Z Meta+Shift+Z"
            title="Redo (Ctrl+Y)"
            aria-label={t('redo')}
          >
            <IconRedo size={13} />
          </button>
        </div>
      </div>

      {/* ── Right Area: Tools & Actions ── */}
      <div className="sbx-topbar__right">
        {/* Secondary Tool Actions */}
        <div className="sbx-topbar__tools">
          <button
            type="button"
            className="sbx-btn sbx-btn--action"
            onClick={onHistory}
            disabled={conflict}
            title="Revision history"
          >
            <IconClock size={13} />
            <span className="sbx-btn__text">{t('history')}</span>
          </button>

          {onPackages && (
            <button
              type="button"
              className="sbx-btn sbx-btn--action sbx-btn--export"
              data-testid="open-packages"
              onClick={onPackages}
              disabled={conflict}
              title="Import or export packages"
            >
              {t('packages')}
            </button>
          )}

          {onTheme && (
            <button
              type="button"
              className="sbx-btn sbx-btn--action"
              onClick={onTheme}
              title="Theme tokens"
            >
              <IconPalette size={13} />
              <span className="sbx-btn__text">{t('theme')}</span>
            </button>
          )}

          {aiDraft && onAiReview && (
            <button
              type="button"
              className="sbx-btn sbx-btn--seg sbx-btn--ai-cta"
              data-testid="ai-review"
              onClick={onAiReview}
              disabled={conflict}
            >
              {t('ai_review')}
            </button>
          )}

          {!aiDraft && boot.assistantUrl && (
            <a
              className="sbx-btn sbx-btn--action"
              href={boot.assistantUrl}
              target="_blank"
              rel="noopener"
            >
              {t('ai_assistant')}
            </a>
          )}

          <a
            className="sbx-btn sbx-btn--action sbx-btn--preview-link"
            href={`${boot.previewUrl}?page=${boot.pageId}`}
            target="_blank"
            rel="noopener"
            title={t('open_new_tab')}
            aria-label={t('open_new_tab')}
          >
            <IconExternalLink size={13} />
          </a>
        </div>

        {/* Primary CTA Buttons */}
        <div className="sbx-topbar__cta-group">
          <button
            type="button"
            className="sbx-btn sbx-btn--save-draft"
            onClick={onSave}
            disabled={conflict || pendingCount === 0}
            aria-keyshortcuts="Control+S Meta+S"
            title="Save draft (Ctrl+S)"
          >
            <IconSaveDisk size={13} />
            <span>Save draft</span>
          </button>

          {canPublish && (
            <button
              type="button"
              className="sbx-btn sbx-btn--publish"
              onClick={onPublish}
              disabled={conflict || busy || !engine}
              title={page && page.is_published ? 'Update live page' : 'Publish page'}
            >
              {page && page.is_published ? (
                <>
                  <IconSendPlane size={13} />
                  <span>Update live page</span>
                </>
              ) : (
                t('publish')
              )}
            </button>
          )}

          {/* Mobile More Menu Toggle */}
          <button
            type="button"
            className="sbx-btn sbx-btn--icon sbx-mobile-more-btn"
            onClick={() => setMobileMenuOpen((o) => !o)}
            aria-label="More actions"
            title="More actions"
          >
            <IconMenu size={15} />
          </button>
        </div>
      </div>

      {/* Mobile Overflow Menu Dropdown */}
      {mobileMenuOpen && (
        <div className="sbx-topbar__mobile-menu" role="menu">
          <button
            type="button"
            className="sbx-mobile-menu-item"
            onClick={() => { setMobileMenuOpen(false); onHistory(); }}
          >
            <IconClock size={15} />
            <span>{t('history')}</span>
          </button>
          {onTheme && (
            <button
              type="button"
              className="sbx-mobile-menu-item"
              onClick={() => { setMobileMenuOpen(false); onTheme(); }}
            >
              <IconPalette size={15} />
              <span>{t('theme')}</span>
            </button>
          )}
          {onPackages && (
            <button
              type="button"
              className="sbx-mobile-menu-item"
              onClick={() => { setMobileMenuOpen(false); onPackages(); }}
            >
              <IconExport size={15} />
              <span>Import / Export</span>
            </button>
          )}
          <a
            className="sbx-mobile-menu-item"
            href={`${boot.previewUrl}?page=${boot.pageId}`}
            target="_blank"
            rel="noopener"
            onClick={() => setMobileMenuOpen(false)}
          >
            <IconExternalLink size={15} />
            <span>Preview link</span>
          </a>
        </div>
      )}
    </header>
  );
});
