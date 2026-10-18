# Mechanical check of settings-full-inventory.csv against a source tree (read-only).
# usage: python3 -I verify-full-inventory.py <csv> <repo-root> [window]
import csv, sys, os, re, collections
csvp, root = sys.argv[1], sys.argv[2]; win = int(sys.argv[3]) if len(sys.argv) > 3 else 6
rows = list(csv.DictReader(open(csvp, newline='', encoding='utf-8')))
treedir = {'client': '01-client', 'licensing': '02-licensing'}
res = collections.Counter(); problems = []
def lines_of(path):
    try: return open(path, encoding='utf-8', errors='replace').read().split('\n')
    except OSError: return None
def needle(key):
    # dynamic/prefixed keys: match the literal part before any placeholder
    k = re.split(r'[<{*$]', key)[0].rstrip('.')
    return k if len(k) >= 3 else None
def alts(key):
    # keys composed in code: the part after the plugin prefix, and the last segment
    parts = key.split('.')
    out = []
    if len(parts) > 1: out.append('.'.join(parts[1:]))
    if len(parts) > 2: out.append(parts[-1])
    return [a for a in out if len(a) >= 4]
for r in rows:
    td = treedir.get(r['tree']); k = r['key']; n = needle(k)
    of = r['owner_file'].strip()
    if not of or of.startswith('(') or of.lower() in ('none', 'n/a', '-'):
        res['owner: none recorded'] += 1
    else:
        L = lines_of(os.path.join(root, td, of))
        if L is None:
            res['owner: file missing'] += 1; problems.append((k, r['tree'], 'owner file missing', of)); 
        elif n is None:
            res['owner: dynamic key (not checked)'] += 1
        else:
            try: ln = int(r['owner_line'])
            except ValueError: ln = 0
            seg = L[max(0, ln-1-win):ln+win] if ln else []
            near = any(n in l for l in seg)
            anywhere = any(n in l for l in L)
            alt_near = any(a in l for a in alts(k) for l in seg)
            alt_any = any(a in l for a in alts(k) for l in L)
            if near: res['owner: key at cited line'] += 1
            elif anywhere: res['owner: key in file, line drifted'] += 1
            elif alt_near: res['owner: composed key, name at cited line'] += 1
            elif alt_any: res['owner: composed key, name in file'] += 1
            else: res['owner: key NOT in file'] += 1; problems.append((k, r['tree'], 'key not in owner file', of))
    for ref in [x.strip() for x in r['readers'].split(';') if x.strip()]:
        m = re.match(r'([^:]+\.php):(\d+)', ref)
        if not m: continue
        L = lines_of(os.path.join(root, td, m.group(1)))
        if L is None: res['reader: file missing'] += 1; problems.append((k, r['tree'], 'reader file missing', ref)); continue
        if n is None: res['reader: dynamic'] += 1; continue
        ln = int(m.group(2))
        seg = L[max(0, ln-1-win):ln+win]
        if any(n in l for l in seg): res['reader: key at cited line'] += 1
        elif any(n in l for l in L): res['reader: key in file, line drifted'] += 1
        elif any(a in l for a in alts(k) for l in seg): res['reader: composed key, name at cited line'] += 1
        elif any(a in l for a in alts(k) for l in L): res['reader: composed key, name in file'] += 1
        else: res['reader: key NOT in file'] += 1; problems.append((k, r['tree'], 'key not in reader file', ref))
print('rows', len(rows))
for kk, v in sorted(res.items()): print(f'  {kk}: {v}')
print('classification:', dict(collections.Counter(r['classification'] for r in rows)))
print('used:', dict(collections.Counter(r['used'] for r in rows)))
for p in problems[:40]: print('  PROBLEM', *p)
print('problems total', len(problems))
