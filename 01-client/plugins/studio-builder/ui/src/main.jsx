// Kohevo Studio builder — entry point.
//
// Reads the host page's non-executable JSON boot block (endpoint URLs, page
// id, CSRF token) and mounts the shell. Nothing is read from or written to
// localStorage / sessionStorage / IndexedDB: the editor state is rebuilt from
// the server on every load.

import { createRoot } from 'react-dom/client';
import { StudioShell } from './components/StudioShell.jsx';
import { setMessages } from './core/messages.mjs';

function readBoot() {
  const el = document.getElementById('sb-builder-boot');
  if (!el) return null;
  try {
    const boot = JSON.parse(el.textContent || '{}');
    return Number.isInteger(boot.pageId) && typeof boot.apiUrl === 'string' ? boot : null;
  } catch {
    return null;
  }
}

const boot = readBoot();
const root = document.getElementById('sb-builder-root');
if (boot && root) {
  if (boot.messages) setMessages(boot.messages);
  createRoot(root).render(<StudioShell boot={boot} />);
}
