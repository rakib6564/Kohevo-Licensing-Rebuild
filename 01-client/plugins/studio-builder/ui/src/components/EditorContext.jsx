// Shared editor services for the component tree (actions + static manifest).
// Document state itself is read from the SyncEngine store by the components
// that need it, so an edit re-renders only what depends on the changed data.

import { createContext, useContext, useSyncExternalStore } from 'react';

export const EditorContext = createContext(null);

export function useEditor() {
  const ctx = useContext(EditorContext);
  if (!ctx) throw new Error('useEditor() outside <EditorContext>');
  return ctx;
}

/** Subscribe to the engine store (optionally a slice of it). */
export function useEngineState(selector = (s) => s) {
  const { engine } = useEditor();
  const read = () => selector(engine.getSnapshot());
  return useSyncExternalStore(engine.subscribe, read, read);
}
