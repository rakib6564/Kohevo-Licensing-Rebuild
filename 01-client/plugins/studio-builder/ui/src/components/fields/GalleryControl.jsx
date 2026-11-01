// GalleryControl — the Gallery widget's images: how many are chosen, a grid of thumbnails, and the library picker
// choosing several at once. Drag a thumbnail to reorder it; click one to edit its alt text, caption and category.

import { useState } from 'react';
import { t } from '../../core/messages.mjs';
import { openMediaPicker } from '../../core/mediaPicker.mjs';
import { addImages, editableSchema, moveImage } from '../../core/galleryImages.mjs';
import { asObject } from '../../core/doc.mjs';

export function GalleryControl({ field, value, onChange, renderFields }) {
  const [open, setOpen] = useState(null);
  const [dragging, setDragging] = useState(null);
  const max = field.max_items || 300;
  const add = () => openMediaPicker({
    types: 'image',
    multiple: true,
    onPick: (picked) => onChange(addImages(value, [].concat(picked), max)),
  });
  const clear = () => {
    if (window.confirm(t('gallery_clear_confirm'))) { setOpen(null); onChange([]); }
  };
  const move = (from, to) => {
    const next = moveImage(value, from, to);
    if (next !== value) { onChange(next); if (open === from) setOpen(to); }
  };
  const remove = (i) => { setOpen(null); onChange(value.filter((_, j) => j !== i)); };
  const item = open !== null ? asObject(value[open]) : null;

  return (
    <fieldset className="sbx-fieldset sbx-gallery-field" data-testid="gallery-images">
      <legend className="sbx-visually-hidden">{field.label}</legend>
      <div className="sbx-gallery-field__bar">
        <span data-testid="gallery-count">{t('gallery_selected').replace('{n}', String(value.length))}</span>
        <button type="button" className="sbx-btn sbx-btn--xs" onClick={clear} disabled={value.length === 0} data-testid="gallery-clear">{t('gallery_clear')}</button>
      </div>
      {value.length > 0 && (
        <ul className="sbx-gallery-field__grid">
          {value.map((img, i) => (
            <li
              key={`${asObject(img).url}-${i}`}
              draggable
              onDragStart={() => setDragging(i)}
              onDragOver={(e) => e.preventDefault()}
              onDrop={(e) => { e.preventDefault(); if (dragging !== null) move(dragging, i); setDragging(null); }}
              onDragEnd={() => setDragging(null)}
            >
              <button
                type="button"
                className={`sbx-gallery-field__thumb${open === i ? ' is-open' : ''}`}
                aria-pressed={open === i}
                aria-label={`${t('gallery_edit_image')} ${i + 1}`}
                onClick={() => setOpen(open === i ? null : i)}
                data-testid="gallery-thumb"
              >
                <img src={asObject(img).url} alt="" loading="lazy" draggable={false} />
              </button>
            </li>
          ))}
        </ul>
      )}
      {item && (
        <div className="sbx-gallery-field__edit" role="group" aria-label={`${t('gallery_edit_image')} ${open + 1}`}>
          <div className="sbx-repeater__bar">
            <span>
              <button type="button" className="sbx-btn sbx-btn--xs" onClick={() => move(open, open - 1)} disabled={open === 0} aria-label={t('move_up')}>←</button>
              <button type="button" className="sbx-btn sbx-btn--xs" onClick={() => move(open, open + 1)} disabled={open === value.length - 1} aria-label={t('move_down')}>→</button>
            </span>
            <button type="button" className="sbx-btn sbx-btn--xs" onClick={() => remove(open)} data-testid="gallery-remove">{t('remove_item')}</button>
          </div>
          {renderFields(editableSchema(field.item_schema), item, (v) => onChange(value.map((x, j) => (j === open ? { ...asObject(x), ...v } : x))))}
        </div>
      )}
      <button type="button" className="sbx-btn" onClick={add} disabled={value.length >= max} data-testid="gallery-add">+ {t('gallery_add')}</button>
    </fieldset>
  );
}
