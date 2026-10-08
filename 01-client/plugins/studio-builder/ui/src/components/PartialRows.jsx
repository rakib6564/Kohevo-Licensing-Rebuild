// PartialRows — the page's header and footer shown in Layers as references, not as layers: they live in
// shared partials (edited in their own page), so they cannot be moved, locked or deleted from here.

import { useEffect, useState } from 'react';
import { useEditor, useEngineState } from './EditorContext.jsx';
import { t } from '../core/messages.mjs';
import { partialRef } from '../core/pages.mjs';

/** The chrome bindings of the current page; refetched when its header/footer mode changes. */
export function useChromeBindings() {
  const { boot, transport } = useEditor();
  const headerMode = useEngineState((s) => (s.working && s.working.settings ? s.working.settings.header_mode : null));
  const footerMode = useEngineState((s) => (s.working && s.working.settings ? s.working.settings.footer_mode : null));
  const [chrome, setChrome] = useState(null);

  useEffect(() => {
    if (!transport || typeof transport.chrome !== 'function') return undefined;
    let live = true;
    transport.chrome(boot.pageId).then((res) => { if (live && res && res.ok) setChrome(res.data.chrome); });
    return () => { live = false; };
  }, [transport, boot.pageId, headerMode, footerMode]);

  return chrome;
}

export function PartialRows({ region, chrome }) {
  const { boot } = useEditor();
  if (!chrome || !chrome.chromed) return null; // header/footer partials, components and presets have no chrome of their own
  const ref = partialRef(chrome[region]);
  if (!ref) return null;
  const regionLabel = t(region === 'header' ? 'chrome_header' : 'chrome_footer');
  const text = ref.kind === 'hidden' ? t('nav_partial_hidden')
    : ref.kind === 'custom' ? t('nav_partial_custom', { title: ref.page.title })
      : ref.kind === 'site' ? t('nav_partial_site', { title: ref.page.title })
        : t('nav_partial_builtin');
  return (
    <div className={`sbx-partial sbx-partial--${ref.kind}`} data-testid={`partial-${region}`} data-partial-kind={ref.kind} role="group" aria-label={`${regionLabel}: ${text}`}>
      <span className="sbx-partial__kind" aria-hidden="true">▤</span>
      <span className="sbx-partial__label">{regionLabel}</span>
      <span className="sbx-partial__text">{text}</span>
      {ref.page && (
        <a className="sbx-partial__edit" href={`${boot.builderUrl}?page=${ref.page.id}`} target="_blank" rel="noopener" aria-label={t('nav_partial_edit', { region: regionLabel.toLowerCase() })}>{t('mode_edit')}</a>
      )}
    </div>
  );
}
