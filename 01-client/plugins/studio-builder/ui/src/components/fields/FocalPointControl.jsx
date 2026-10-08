// FocalPointControl — 2D Interactive focal point / object-position picker (Image 1 & Image 3).
//
// Shows an interactive preview thumbnail where the user can click or drag
// a target reticle (⊙) to set the visual focal point coordinates (x%, y%).

import { t } from '../../core/messages.mjs';
import { memo, useCallback, useRef, useState } from 'react';

export const FocalPointControl = memo(function FocalPointControl({
  imageUrl,
  value = { x: 50, y: 50 },
  onChange,
}) {
  const containerRef = useRef(null);
  const [dragging, setDragging] = useState(false);

  // Parse current coordinates
  const coords = typeof value === 'object' && value !== null
    ? { x: Math.max(0, Math.min(100, Number(value.x) || 50)), y: Math.max(0, Math.min(100, Number(value.y) || 50)) }
    : { x: 50, y: 50 };

  const updateFromPointer = useCallback((e) => {
    if (!containerRef.current) return;
    const rect = containerRef.current.getBoundingClientRect();
    const clientX = e.touches ? e.touches[0].clientX : e.clientX;
    const clientY = e.touches ? e.touches[0].clientY : e.clientY;

    const x = Math.max(0, Math.min(100, Math.round(((clientX - rect.left) / rect.width) * 100)));
    const y = Math.max(0, Math.min(100, Math.round(((clientY - rect.top) / rect.height) * 100)));

    if (onChange) {
      onChange({ x, y });
    }
  }, [onChange]);

  const handlePointerDown = (e) => {
    e.preventDefault();
    setDragging(true);
    updateFromPointer(e);

    const onPointerMove = (ev) => {
      updateFromPointer(ev);
    };

    const onPointerUp = () => {
      setDragging(false);
      window.removeEventListener('pointermove', onPointerMove);
      window.removeEventListener('pointerup', onPointerUp);
      window.removeEventListener('touchmove', onPointerMove);
      window.removeEventListener('touchend', onPointerUp);
    };

    window.addEventListener('pointermove', onPointerMove);
    window.addEventListener('pointerup', onPointerUp);
    window.addEventListener('touchmove', onPointerMove);
    window.addEventListener('touchend', onPointerUp);
  };

  return (
    <div className="sbx-focal-point">
      <div className="sbx-focal-point__header">
        <label className="sbx-field__label">{t('focal_point')}</label>
        <span className="sbx-focal-point__coords">{coords.x}% {coords.y}%</span>
      </div>

      <div
        ref={containerRef}
        className={`sbx-focal-point__viewport${dragging ? ' is-dragging' : ''}`}
        onPointerDown={handlePointerDown}
        onTouchStart={handlePointerDown}
        role="slider"
        aria-label={t('focal_image_label')}
        aria-valuetext={`X ${coords.x}%, Y ${coords.y}%`}
      >
        {imageUrl ? (
          <img
            src={imageUrl}
            alt={t('focal_preview')}
            className="sbx-focal-point__image"
          />
        ) : (
          <div className="sbx-focal-point__placeholder" />
        )}

        {/* Reticle / Target Picker */}
        <div
          className="sbx-focal-point__reticle"
          style={{ left: `${coords.x}%`, top: `${coords.y}%` }}
        >
          <span className="sbx-focal-point__reticle-ring" />
          <span className="sbx-focal-point__reticle-dot" />
        </div>
      </div>
    </div>
  );
});
