// LiveRegion — screen-reader announcements (selection, save, conflict, errors).

import { memo } from 'react';

export const LiveRegion = memo(function LiveRegion({ text }) {
  return (
    <div className="sbx-sr-only" role="status" aria-live="polite" aria-atomic="true">
      {text}
    </div>
  );
});
