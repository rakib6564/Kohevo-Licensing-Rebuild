// useIsMobileShell — true while the viewport is at or below the mobile-shell width
// (core/shellState.mjs MOBILE_SHELL_MAX_WIDTH). False outside a browser, so server
// rendering and tests get the desktop shell.

import { useSyncExternalStore } from 'react';
import { MOBILE_SHELL_MAX_WIDTH, isMobileShellWidth } from '../core/shellState.mjs';

const QUERY = `(max-width: ${MOBILE_SHELL_MAX_WIDTH}px)`;

function subscribe(onChange) {
  if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return () => {};
  const mq = window.matchMedia(QUERY);
  mq.addEventListener('change', onChange);
  return () => mq.removeEventListener('change', onChange);
}

const read = () => (typeof window !== 'undefined' && isMobileShellWidth(window.innerWidth));

export function useIsMobileShell() {
  return useSyncExternalStore(subscribe, read, () => false);
}

/** The width right now (for one-off decisions such as the initial device preset). */
export function isMobileShellNow() {
  return read();
}
