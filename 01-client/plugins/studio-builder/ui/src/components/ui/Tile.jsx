// Tile — an icon (or thumbnail) over a short name, in a grid. The Add panel's blocks use it; so can any picker
// that wants "a few options at a glance". `reason` is shown under a disabled tile instead of hiding it in a tooltip.

import { useId } from 'react';

export function TileGrid({ className = '', children, ...rest }) {
  return <div className={`sbx-tilegrid${className ? ` ${className}` : ''}`} {...rest}>{children}</div>;
}

export function Tile({ icon, label, srText, reason, disabled = false, active = false, className = '', children, ...rest }) {
  const reasonId = useId();
  return (
    <button
      type="button"
      className={`sbx-tile${active ? ' is-active' : ''}${disabled ? ' is-disabled' : ''}${className ? ` ${className}` : ''}`}
      aria-disabled={disabled || undefined}
      aria-describedby={disabled && reason ? reasonId : undefined}
      {...rest}
    >
      {icon ? <span className="sbx-tile__icon" aria-hidden="true">{icon}</span> : null}
      <span className="sbx-tile__label">{label}</span>
      {srText ? <span className="sbx-sr-only sbx-tile__desc">{srText}</span> : null}
      {disabled && reason ? <span id={reasonId} className="sbx-tile__reason" data-testid="insert-reason">{reason}</span> : null}
      {children}
    </button>
  );
}
