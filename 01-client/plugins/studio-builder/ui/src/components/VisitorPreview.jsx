// VisitorPreview — the page as a visitor sees it, inside the builder.
//
// Frames the authorized preview endpoint (the same pipeline as the public
// page: private, no-store, noindex) at the chosen device width. Editing chrome
// is reduced to one slim bar. The preview renders the last SAVED draft, so
// pending edits are called out with a one-click save.

import { memo, useEffect, useRef, useState } from 'react';
import { useEditor, useEngineState } from './EditorContext.jsx';
import { STATUS } from '../core/sync.mjs';
import { VIEWPORTS } from '../core/viewport.mjs';
import { visitorPreviewUrl } from '../core/shellState.mjs';
import { isChromePartial, previewHostPages } from '../core/pages.mjs';
import { t } from '../core/messages.mjs';
import { IconArrowLeft, IconExternalLink } from './Icons.jsx';

export const VisitorPreview = memo(function VisitorPreview({ viewportKey, onViewport, onExit, onSave }) {
  const { boot, viewport, transport } = useEditor();
  const page = useEngineState((s) => s.page);
  const revisionId = useEngineState((s) => (s.revision ? s.revision.id : 0));
  const pendingCount = useEngineState((s) => s.pending.length);
  const status = useEngineState((s) => s.status);
  const [reloads, setReloads] = useState(0);
  const [loading, setLoading] = useState(true);
  const [stage, setStage] = useState({ width: 0, height: 0 });
  // A header or footer on its own is only a strip: it is shown on a real page, chosen here (kept in memory only).
  const partial = isChromePartial(page);
  const [hosts, setHosts] = useState(null);
  const [hostId, setHostId] = useState(0);
  const stageRef = useRef(null);

  useEffect(() => {
    const el = stageRef.current;
    if (!el || typeof ResizeObserver === 'undefined') return undefined;
    const ro = new ResizeObserver(([entry]) => {
      const r = entry.contentRect;
      setStage({ width: r.width, height: r.height });
    });
    ro.observe(el);
    return () => ro.disconnect();
  }, []);

  useEffect(() => {
    if (!partial || !transport || typeof transport.pages !== 'function') return undefined;
    let alive = true;
    transport.pages().then((res) => {
      if (!alive) return;
      const list = res && res.ok ? previewHostPages(res.data.pages) : [];
      setHosts(list);
      setHostId((current) => (list.some((p) => Number(p.id) === current) ? current : (list[0] ? Number(list[0].id) : 0)));
    });
    return () => { alive = false; };
  }, [partial, transport]);

  // A save moves the revision on: show it without making the user hit reload.
  useEffect(() => { setLoading(true); }, [revisionId, reloads, hostId]);

  const stale = pendingCount > 0 || status === STATUS.DIRTY || status === STATUS.SAVING;
  const scale = stage.width > 0 ? Math.min(1, Math.max(0.35, (stage.width - 32) / viewport.width)) : 1;
  const frameHeight = stage.height > 0 ? Math.max(480, (stage.height - 16) / scale) : 800;
  const url = visitorPreviewUrl(boot, partial ? hostId : 0);

  return (
    <section className="sbx-visitor" aria-label={t('visitor_preview')}>
      <div className="sbx-visitor__bar">
        <button type="button" className="sbx-btn sbx-btn--action" onClick={onExit} data-testid="visitor-exit">
          <IconArrowLeft size={13} />
          <span className="sbx-btn__text">{t('back_to_editing')}</span>
        </button>

        <div className="sbx-viewport-group" role="toolbar" aria-label={t('viewport')}>
          {VIEWPORTS.map((v) => (
            <button
              key={v.key}
              type="button"
              className={`sbx-btn sbx-btn--seg${viewportKey === v.key ? ' is-active' : ''}`}
              aria-pressed={viewportKey === v.key}
              onClick={() => onViewport(v.key)}
              title={`${t(v.key)} (${v.width}px)`}
            >
              {t(v.key)}
            </button>
          ))}
        </div>

        {partial && (
          <label className="sbx-visitor__host">
            <span>{t('preview_on_page')}</span>
            <select
              data-testid="preview-host"
              value={hostId}
              disabled={!hosts || hosts.length === 0}
              onChange={(e) => setHostId(Number(e.target.value))}
              title={t('preview_on_page_hint')}
            >
              {hosts && hosts.length === 0 && <option value={0}>{t('preview_on_page_none')}</option>}
              {(hosts || []).map((p) => <option key={p.id} value={p.id}>{p.title}</option>)}
            </select>
          </label>
        )}

        <div className="sbx-visitor__bar-right">
          <button type="button" className="sbx-btn sbx-btn--action" onClick={() => setReloads((n) => n + 1)}>
            {t('reload')}
          </button>
          <a className="sbx-btn sbx-btn--action" href={url} target="_blank" rel="noopener" title={t('open_new_tab')}>
            <IconExternalLink size={13} />
            <span className="sbx-btn__text">{t('open_new_tab')}</span>
          </a>
        </div>
      </div>

      {stale && (
        <div className="sbx-visitor__note" role="status">
          <span>{t('visitor_preview_stale')}</span>
          <button type="button" className="sbx-btn sbx-btn--save-draft" onClick={onSave} disabled={status === STATUS.SAVING || status === STATUS.CONFLICT}>
            {t('save_draft')}
          </button>
        </div>
      )}

      <div className="sbx-visitor__stage" ref={stageRef}>
        <div className="sbx-canvas__fit" style={{ width: `${Math.floor(viewport.width * scale)}px`, height: `${Math.floor(frameHeight * scale)}px`, margin: '0 auto' }}>
          <div
            className="sbx-canvas__device"
            data-scale={scale.toFixed(3)}
            style={{ width: `${viewport.width}px`, height: `${frameHeight}px`, transform: scale < 1 ? `scale(${scale})` : undefined, transformOrigin: '0 0', top: 0, left: 0 }}
          >
            <iframe
              key={`${revisionId}-${reloads}-${hostId}`}
              title={t('visitor_preview')}
              src={url}
              sandbox="allow-same-origin allow-scripts"
              referrerPolicy="same-origin"
              className="sbx-canvas__frame"
              onLoad={() => setLoading(false)}
            />
            {loading && <div className="sbx-canvas__loading" aria-hidden="true" />}
          </div>
        </div>
      </div>
    </section>
  );
});
