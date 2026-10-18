"""Independent checks of the widget matrix and of the inventory CSV's claims.

Usage: python3 -I inventory-claims-verify.py <repo root> <out dir>

Widget matrix (01-client/audit/kohevo-widget-test-matrix.md):
  - every backticked type id in a table row is registered in source
    (a renderer type() return value or a plugin `'type' => '<id>'`);
  - every renderer class named in a row exists as `class <Name>`;
  - the "Test files" count equals the number of test files that contain the
    type id literally (tests/ and plugins/studio-builder/ui/tests);
  - every registered type id appears in the matrix (completeness).
Inventory CSV (01-client/audit/kohevo-settings-widget-inventory.csv):
  - rows whose Secret_Handling claims encryption: the storage key literal
    appears in a non-test PHP file that also calls an encryption helper;
  - rows claiming Parity_Status "Identical": the key literal also appears in
    02-licensing source (Studio widgets and Forms fields are checked by id).
"""
import csv, os, re, sys, json

root, outdir = sys.argv[1], sys.argv[2]
client = os.path.join(root, '01-client')
lic = os.path.join(root, '02-licensing')
SKIP = ('/tests', 'node_modules', '/vendor', '/lang', '/audit', '/assets', '/archive')
ENC = re.compile(r'slate_encrypt_secret|slate_seal_posted_secrets|sealSecrets|resolveStoredSecret|resolveStoredApiKey|\$encSecret|\$keepStored')


def src_files(base, exts=('.php',)):
    for dp, dn, fn in os.walk(base):
        if any(s in dp for s in SKIP):
            continue
        for f in fn:
            if f.endswith(exts):
                yield os.path.join(dp, f)


def read(p):
    return open(p, encoding='utf-8', errors='ignore').read()


client_src = {p: read(p) for p in src_files(client)}
lic_src = {p: read(p) for p in src_files(lic)}
client_blob = '\n'.join(client_src.values())
lic_blob = '\n'.join(lic_src.values())

types = set(re.findall(r"function type\(\)\s*:\s*string\s*\{\s*return '([^']+)'", client_blob))
types |= set(re.findall(r"'type'\s*=>\s*'([a-z][a-z0-9_]*\.[a-z0-9_]+)'", client_blob))
classes = set(re.findall(r'\bclass\s+([A-Za-z_][A-Za-z0-9_]*)', client_blob))

test_texts = {}
for base in (os.path.join(client, 'tests'), os.path.join(client, 'plugins/studio-builder/ui/tests')):
    for dp, dn, fn in os.walk(base):
        if 'node_modules' in dp:
            continue
        for f in fn:
            if f.endswith(('.php', '.mjs', '.js')):
                test_texts[os.path.join(dp, f)] = read(os.path.join(dp, f))

fm = re.search(r"const SUPPORTED_TYPES = \[(.*?)\];", read(os.path.join(client, 'plugins/forms/FormsAPI.php')), re.S)
field_types = set(re.findall(r"'([a-z_]+)'", fm.group(1))) if fm else set()

# ---- widget matrix ----
matrix = read(os.path.join(client, 'audit/kohevo-widget-test-matrix.md'))
mrows, seen = [], set()
for line in matrix.splitlines():
    if not line.startswith('| `'):
        continue
    cells = [c.strip() for c in line.strip('|').split('|')]
    tid = cells[0].strip('`')
    cls = cells[1].strip('`') if len(cells) > 1 else ''
    seen.add(tid)
    is_field = tid in field_types and '.' not in tid
    registered = (tid in field_types) if is_field else (tid in types)
    cls_ok = True if (not cls or not re.fullmatch(r'[A-Z][A-Za-z0-9_]+', cls)) else (cls in classes)
    count_claim = None
    for c in cells[1:4]:
        if re.fullmatch(r'\d+', c):
            count_claim = int(c)
            break
    actual = sum(1 for t in test_texts.values() if (f"'{tid}'" in t or f'"{tid}"' in t or f'`{tid}`' in t))
    mrows.append({'type': tid, 'class': cls, 'registered': registered, 'class_exists': cls_ok,
                  'tests_claimed': count_claim, 'tests_found': actual,
                  'count_matches': (count_claim is None or count_claim == actual)})
all_ids = set(re.findall(r'`([a-z][a-z0-9_-]*\.[a-z0-9_]+)`', matrix))
missing_from_matrix = sorted(t for t in types if t not in all_ids)
# SDK rows (plugin slug first, block ids in column 2) and 'not implemented' rows name things that are not type ids by design
sdk_or_absent = lambda m: '.' not in m['type'] or ' ' in m['type'] or '`' in m['type']

# ---- inventory claims ----
inv = list(csv.DictReader(open(os.path.join(client, 'audit/kohevo-settings-widget-inventory.csv'), encoding='utf-8', newline='')))
crow = []
for r in inv:
    k, d = r['Setting_Or_Widget'].strip(), r['Domain']
    comp = r['Component'].strip()
    lits = [f"'{k}'", f'"{k}"', f"'{comp}.{k}'", f'name="{k}"']
    sh = r['Secret_Handling'].lower()
    enc_claim = 'encrypt' in sh and 'not encrypt' not in sh
    enc_ok = None
    if enc_claim:
        enc_ok = any(any(l in t for l in lits) and ENC.search(t) for t in client_src.values())
    par = r['Parity_Status'].strip().lower()
    par_ok = None
    if par == 'identical':
        if d == 'Widget: Studio' or d == 'Field: Forms':
            par_ok = (f"'{k}'" in lic_blob)
        elif r['Storage_Key'].lower().startswith(('const', 'config')):
            par_ok = re.search(r'(?<![A-Za-z0-9_])' + re.escape(k) + r'(?![A-Za-z0-9_])', lic_blob) is not None
        else:
            par_ok = any(l in lic_blob for l in lits)
    crow.append({'key': k, 'domain': d, 'encryption_claim': enc_claim, 'encryption_verified': enc_ok,
                 'parity_claim': par, 'parity_verified': par_ok})

os.makedirs(outdir, exist_ok=True)
with open(os.path.join(outdir, 'widget-matrix-verification.csv'), 'w', newline='') as f:
    w = csv.DictWriter(f, fieldnames=list(mrows[0].keys()), lineterminator='\n'); w.writeheader(); w.writerows(mrows)
with open(os.path.join(outdir, 'inventory-claims-verification.csv'), 'w', newline='') as f:
    w = csv.DictWriter(f, fieldnames=list(crow[0].keys()), lineterminator='\n'); w.writeheader(); w.writerows(crow)

summary = {
    'matrix_rows': len(mrows),
    'matrix_unregistered_type_ids': [m['type'] for m in mrows if not m['registered'] and not sdk_or_absent(m)],
    'matrix_rows_not_type_ids (SDK plugin rows / not-implemented names)': [m['type'] for m in mrows if not m['registered'] and sdk_or_absent(m)],
    'matrix_missing_classes': [m['class'] for m in mrows if not m['class_exists']],
    'matrix_test_count_mismatches': [(m['type'], m['tests_claimed'], m['tests_found']) for m in mrows if not m['count_matches']],
    'registered_types_total': len(types),
    'registered_types_missing_from_matrix': missing_from_matrix,
    'inventory_rows': len(inv),
    'encryption_claims': sum(1 for c in crow if c['encryption_claim']),
    'encryption_claims_unverified': [c['key'] for c in crow if c['encryption_claim'] and not c['encryption_verified']],
    'parity_identical_claims': sum(1 for c in crow if c['parity_claim'] == 'identical'),
    'parity_claims_unverified': [c['key'] for c in crow if c['parity_claim'] == 'identical' and not c['parity_verified']],
}
print(json.dumps(summary, indent=1))
