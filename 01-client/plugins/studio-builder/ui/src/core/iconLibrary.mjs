// The built-in icon library (assets/icons/icons.json, the same icons the server draws): loaded once, when a picker first
// asks for it, so the editor does not carry 1,600 icons it may never show.

let loading = null;

/** { categories: [{ key, label, icons }], icons: { name: svg markup } }; an empty library if the file cannot be read. */
export function loadIcons(url) {
  if (!loading) {
    loading = fetch(url, { credentials: 'same-origin' })
      .then((r) => (r.ok ? r.json() : null))
      .then((data) => (data && data.icons ? data : { categories: [], icons: {} }))
      .catch(() => { loading = null; return { categories: [], icons: {} }; });
  }
  return loading;
}

/** Names matching `query` in `category` ('' for all): whole words first, so "fish" finds the fish before "selfish". */
export function searchIcons(library, query, category = '') {
  const q = String(query || '').trim().toLowerCase().replace(/\s+/g, '-');
  const group = category ? (library.categories.find((c) => c.key === category) || { icons: [] }).icons : Object.keys(library.icons);
  if (!q) return group;
  const hits = group.filter((n) => n.includes(q));
  const whole = (n) => n === q || n.split('-').includes(q);
  return [...hits.filter(whole), ...hits.filter((n) => !whole(n))];
}
