"""Strict source check for every row of the settings/widget inventory.

Usage: python3 -I csv-verify.py <01-client dir> <output csv>

Per domain:
  Widget: Studio  - Setting_Or_Widget must be a block type id actually
                    registered in source: a renderer's type() return value,
                    or a `'type' => '<id>'` definition in a plugin.
  Field: Forms    - must be listed in FormsAPI::SUPPORTED_TYPES.
  everything else - the key must appear as an exact literal: '<plugin>.<key>'
                    or '<key>' in PHP, or name="<key>" in a form. Rows whose
                    Storage_Key says const/config/table are matched as a whole
                    identifier instead.
Rows with Disposition "Not implemented" are reported as NOT_IMPLEMENTED.
Result per row: VERIFIED, NOT_IMPLEMENTED or NOT_FOUND.
"""
import csv, os, re, sys, collections

root, out = sys.argv[1], sys.argv[2]
texts = []
for top in ['admin', 'includes', 'src', 'plugins', 'api', 'public', 'config.php', '.env.example']:
    path = os.path.join(root, top)
    if os.path.isfile(path):
        texts.append(open(path, encoding='utf-8', errors='ignore').read())
        continue
    for dp, dn, fn in os.walk(path):
        if any(x in dp for x in ('/tests', 'node_modules', '/lang', '/vendor', '/assets')):
            continue
        for f in fn:
            if f.endswith(('.php', '.sql')):
                texts.append(open(os.path.join(dp, f), encoding='utf-8', errors='ignore').read())
blob = '\n'.join(texts)

block_types = set(re.findall(r"function type\(\)\s*:\s*string\s*\{\s*return '([^']+)'", blob))
block_types |= set(re.findall(r"'type'\s*=>\s*'([a-z][a-z0-9_]*\.[a-z0-9_]+)'", blob))
m = re.search(r"const SUPPORTED_TYPES = \[(.*?)\];", open(os.path.join(root, 'plugins/forms/FormsAPI.php'), encoding='utf-8').read(), re.S)
field_types = set(re.findall(r"'([a-z_]+)'", m.group(1))) if m else set()

PLUGINS = {'backups', 'booking', 'coaching', 'forms', 'licensing', 'mcp-gateway', 'media-library',
           'membership', 'multilang-translate', 'stripe-payment', 'studio-builder'}

rows = list(csv.DictReader(open(os.path.join(root, 'audit/kohevo-settings-widget-inventory.csv'), encoding='utf-8', newline='')))
res = []
for i, r in enumerate(rows, start=2):
    k, c, d = r['Setting_Or_Widget'].strip(), r['Component'], r['Domain']
    storage = r['Storage_Key'].lower()
    if r['Disposition'].strip().lower() == 'not implemented':
        st = 'NOT_IMPLEMENTED'
    elif d == 'Widget: Studio':
        st = 'VERIFIED' if k in block_types else 'NOT_FOUND'
    elif d == 'Field: Forms':
        st = 'VERIFIED' if k in field_types else 'NOT_FOUND'
    elif storage.startswith(('const', 'config')) or 'table' in storage or storage == 'auditlog':
        st = 'VERIFIED' if re.search(r'(?<![A-Za-z0-9_])' + re.escape(k) + r'(?![A-Za-z0-9_])', blob) else 'NOT_FOUND'
    else:
        cands = [f"'{k}'", f'"{k}"', f'name="{k}"']
        if c in PLUGINS:
            cands = [f"'{c}.{k}'", f'"{c}.{k}"', f'name="{k}"', f"'{k}'"]
        st = 'VERIFIED' if any(x in blob for x in cands) else 'NOT_FOUND'
    res.append([i, d, c, k, st])

with open(out, 'w', newline='', encoding='utf-8') as f:
    w = csv.writer(f, lineterminator='\n')
    w.writerow(['csv_line', 'Domain', 'Component', 'Setting_Or_Widget', 'source_match'])
    w.writerows(res)
print('rows', len(res), dict(collections.Counter(x[4] for x in res)))
for x in res:
    if x[4] == 'NOT_FOUND':
        print('  NOT_FOUND', x)
