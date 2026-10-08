// SectionPresetsPanel — the Add panel's Sections tab: the built-in, composed section presets
// (system templates from the server), grouped by category, with a wireframe thumbnail each.
// One click inserts a real, editable copy through the template pipeline (insert_template).

import { memo, useMemo } from 'react';
import { useEditor } from './EditorContext.jsx';
import { Wireframe } from './Wireframe.jsx';
import { t } from '../core/messages.mjs';
import { asList } from '../core/doc.mjs';
import { groupPresets as groupPresetsBy } from '../core/library.mjs';

export const categoryLabel = (slug) => t(`preset_cat_${String(slug).replace(/-/g, '_')}`);

export const SectionPresetsPanel = memo(function SectionPresetsPanel({ query, favorites, onToggleFavorite }) {
  const { library = null, insertTemplate } = useEditor();
  const groups = useMemo(() => groupPresetsBy(library && library.presets, query, categoryLabel), [library, query]);

  if (!library) return <p className="sbx-muted" role="status">{t('loading')}</p>;
  if (!asList(library.presets).length) return <p className="sbx-muted">{t('pal_presets_unavailable')}</p>;
  if (!groups.length) return <p className="sbx-palette__empty sbx-muted">{t('pal_no_sections')}</p>;

  return (
    <div className="sbx-presets" data-testid="section-presets">
      {groups.map((g) => (
        <section key={g.category} className="sbx-presets__group" aria-labelledby={`sbx-preset-cat-${g.category}`}>
          <h3 id={`sbx-preset-cat-${g.category}`} className="sbx-palette__category">{categoryLabel(g.category)}</h3>
          <ul className="sbx-presets__list">
            {g.items.map((p) => {
              const fav = favorites.has(p.template_key);
              return (
                <li key={p.template_key} className="sbx-preset-card" data-preset={p.template_key}>
                  <button
                    type="button"
                    className="sbx-preset-card__insert"
                    onClick={() => insertTemplate(p)}
                    aria-label={t('pal_insert_section', { name: p.name })}
                  >
                    <Wireframe outline={p.outline} />
                    <span className="sbx-preset-card__name">{p.name}</span>
                    <span className="sbx-preset-card__desc">{p.description}</span>
                  </button>
                  <button
                    type="button"
                    className={`sbx-star-btn sbx-preset-card__star${fav ? ' is-favorited' : ''}`}
                    aria-pressed={fav}
                    onClick={(e) => onToggleFavorite(e, p.template_key)}
                    title={fav ? t('pal_unfavorite') : t('pal_favorite_add')}
                    aria-label={fav ? t('pal_unfavorite') : t('pal_favorite_add')}
                  >
                    {fav ? '★' : '☆'}
                  </button>
                </li>
              );
            })}
          </ul>
        </section>
      ))}
    </div>
  );
});
