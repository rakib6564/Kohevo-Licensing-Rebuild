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

/**
 * Selection lives in its own context so a click re-renders only what shows the
 * selection, not every consumer of the (stable) editor services above.
 *
 *   sel          the full model {primary, ids, hover, focus}
 *   selection    primary id (null when nothing is selected)
 *   selectedIds  every selected id, in pick order
 *   select(id)   replace the selection; pick(id, {shift, toggle}, rows?) is a modified click
 */
export const SelectionContext = createContext(null);

export function useSelection() {
  const ctx = useContext(SelectionContext);
  if (!ctx) throw new Error('useSelection() outside <SelectionContext>');
  return ctx;
}

/** Subscribe to the engine store (optionally a slice of it). */
export function useEngineState(selector = (s) => s) {
  const { engine } = useEditor();
  const read = () => selector(engine.getSnapshot());
  return useSyncExternalStore(engine.subscribe, read, read);
}
