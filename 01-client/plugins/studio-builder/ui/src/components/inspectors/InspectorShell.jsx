// InspectorShell — the frame of the contextual Inspector: a header (icon, name, type, ⋯ menu), a tab strip
// (Content · Style · Advanced), a search box, and collapsible sections.
//
// WHICH sections appear is decided by core/inspectorSections.mjs; WHAT is inside each one is supplied by the
// caller (`renderSection`). Open / closed state lives in that module too, keyed by block type for the session, so
// a section the author opened on one heading stays open on the next. A closed section renders no controls at all.

import { useId, useMemo, useState, useSyncExternalStore } from 'react';
import { t } from '../../core/messages.mjs';
import {
  applicableSections, defaultOpenIds, inspectorContext, isSectionOpen, searchSections, setSectionOpen, subscribeSections,
} from '../../core/inspectorSections.mjs';
import { Tabs } from './controls.jsx';

const TAB_LABEL = { content: 'tab_content', style: 'tab_style', advanced: 'tab_advanced' };

function Section({ typeKey, section, ctx, open, idPrefix, render, forceOpen }) {
  const fallback = defaultOpenIds(section.tab, ctx).includes(section.id);
  const read = () => isSectionOpen(typeKey, section.id, fallback);
  const stored = useSyncExternalStore(subscribeSections, read, read);
  const isOpen = forceOpen || stored;
  const summary = section.summary(ctx);
  const headId = `${idPrefix}-sec-${section.id}-head`;
  const bodyId = `${idPrefix}-sec-${section.id}-body`;
  return (
    <section className={`sbx-isec${isOpen ? ' is-open' : ''}`} data-section={section.id}>
      <h3 className="sbx-isec__heading">
        <button
          type="button"
          id={headId}
          className="sbx-isec__toggle"
          aria-expanded={isOpen}
          aria-controls={bodyId}
          onClick={() => setSectionOpen(typeKey, section.id, !isOpen)}
        >
          <span className="sbx-isec__chevron" aria-hidden="true">{isOpen ? '▾' : '▸'}</span>
          <span className="sbx-isec__title">{t(section.titleKey)}</span>
          {summary ? <span className="sbx-isec__summary">{summary}</span> : null}
        </button>
      </h3>
      <div id={bodyId} role="region" aria-labelledby={headId} className="sbx-isec__body" hidden={!isOpen}>
        {isOpen ? render(section.id) : null}
      </div>
    </section>
  );
}

/**
 * @param {object} props
 * @param {string} props.idPrefix        stable id prefix (also the tab/panel ids)
 * @param {object} props.node            the selected block
 * @param {object} props.def             its manifest definition
 * @param {React.ReactNode} props.header the header (icon, name, ⋯); the shell adds nothing of its own to it
 * @param {React.ReactNode} [props.actions] extra controls under the header
 * @param {(id: string) => React.ReactNode} props.renderSection renders the controls of one section
 */
export function InspectorShell({ idPrefix, node, def, header, actions, renderSection }) {
  const ctx = useMemo(() => inspectorContext(node, def), [node, def]);
  const groups = useMemo(() => applicableSections(ctx), [ctx]);
  const [tab, setTab] = useState('content');
  const [query, setQuery] = useState('');
  const searchId = useId();
  const typeKey = node.type;

  const activeTab = groups.some((g) => g.tab === tab) ? tab : (groups[0] ? groups[0].tab : 'content');
  const tabs = groups.map((g) => ({ key: g.tab, label: t(TAB_LABEL[g.tab]) }));
  const matches = useMemo(() => searchSections(ctx, query, (s) => t(s.titleKey)), [ctx, query]);
  const searching = query.trim() !== '';

  return (
    <div className="sbx-inspector">
      {header}
      {actions}
      <div className="sbx-isearch">
        <label className="sbx-sr-only" htmlFor={searchId}>{t('inspector_search')}</label>
        <input
          id={searchId}
          type="search"
          className="sbx-input"
          placeholder={t('inspector_search')}
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          onKeyDown={(e) => { if (e.key === 'Escape' && query) { e.stopPropagation(); setQuery(''); } }}
        />
        <p className="sbx-sr-only" role="status" aria-live="polite">{searching ? t('inspector_matches', { n: matches.length }) : ''}</p>
      </div>

      {searching ? (
        <div className="sbx-isec-list" data-searching="true">
          {matches.length === 0 && <p className="sbx-hint">{t('inspector_no_match')}</p>}
          {matches.map((s) => (
            <Section key={s.id} typeKey={typeKey} section={s} ctx={ctx} idPrefix={idPrefix} render={renderSection} forceOpen />
          ))}
        </div>
      ) : (
        <>
          {tabs.length > 1 && <Tabs tabs={tabs} active={activeTab} onChange={setTab} idPrefix={idPrefix} />}
          {groups.filter((g) => g.tab === activeTab).map((g) => (
            <div key={g.tab} role="tabpanel" id={`${idPrefix}-panel-${g.tab}`} aria-labelledby={`${idPrefix}-tab-${g.tab}`} className="sbx-isec-list">
              {g.sections.map((s) => (
                <Section key={s.id} typeKey={typeKey} section={s} ctx={ctx} idPrefix={idPrefix} render={renderSection} />
              ))}
            </div>
          ))}
        </>
      )}
    </div>
  );
}
