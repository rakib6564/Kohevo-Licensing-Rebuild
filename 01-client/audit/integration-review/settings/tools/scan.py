#!/usr/bin/env python3
"""Read-only settings-key scanner for the Kohevo client/licensing trees.
Usage: python3 -I scan.py <tree_root> <tree_label> <out_json>
"""
import json, os, re, sys

root, label, out = sys.argv[1], sys.argv[2], sys.argv[3]
EXCL = {'vendor', 'tests', 'node_modules', 'audit', 'archive', 'scratch', 'Claude', 'docs', 'db_backups'}

files = []
for dp, dns, fns in os.walk(root):
    rel = os.path.relpath(dp, root)
    parts = [] if rel == '.' else rel.split(os.sep)
    if parts and parts[0] in EXCL:
        dns[:] = []
        continue
    dns[:] = [d for d in dns if d not in ('vendor', 'node_modules', 'tests')]
    for f in fns:
        if f.endswith('.php'):
            files.append(os.path.join(dp, f))
files.sort()

src = {}
for f in files:
    with open(f, encoding='utf-8', errors='replace') as fh:
        src[os.path.relpath(f, root)] = fh.read().split('\n')

def plugin_slug(rel):
    p = rel.split(os.sep)
    if p[0] == 'plugins' and len(p) > 1:
        return p[1]
    if 'StudioSchemaManager' in rel:
        return 'studio-builder'
    return None

# --- constants map: NAME -> value (only string consts) ---
consts = {}      # NAME -> set(values) (fallback)
fconsts = {}     # rel -> {NAME: value}
classfile = {}   # ClassName -> rel
CONST_RE = re.compile(r"const\s+([A-Z_][A-Z0-9_]*)\s*=\s*'([^']*)'")
CLASS_RE = re.compile(r"^\s*(?:final\s+|abstract\s+)?class\s+(\w+)")
for rel, lines in src.items():
    for ln in lines:
        cm = CLASS_RE.match(ln)
        if cm: classfile.setdefault(cm.group(1), rel)
        for m in CONST_RE.finditer(ln):
            consts.setdefault(m.group(1), set()).add(m.group(2))
            fconsts.setdefault(rel, {})[m.group(1)] = m.group(2)

recs = []   # dict(key, kind, file, line, text, via)
dyn = []    # unresolved dynamic call sites
Q = r"(['\"])([^'\"$]+)['\"]"
CALL = r"(?:\\?Database|self|static)::(setting|setSetting)\(\s*"
DIRECT = re.compile(CALL + Q + r"\s*([,)])")
CONCAT = re.compile(CALL + r"(['\"])([^'\"$]*)\2\s*\.")
CONSTC = re.compile(CALL + r"(\\?[A-Za-z_\\]+|self|static)::([A-Z_][A-Z0-9_]*)\s*([,)\.])")
ANYCALL = re.compile(r"(?:\\?Database|self|static|->)(?:::)?(setting|setSetting)\(\s*(.{0,60})")
INST = re.compile(r"\$(this|plugin)->(setting|setSetting)\(\s*" + Q)

# wrappers: closure var or named function whose body reads 'prefix' . $param
WRAP_HEAD = re.compile(r"(?:\$(\w+)\s*=\s*(?:static\s+)?(?:fn|function)|function\s+(\w+))\s*\(\s*(?:string\s+)?\$(\w+)")
named_wrappers = {}  # fname -> (prefix, kind, file, line)

def add(key, kind, rel, i, text, via):
    recs.append(dict(key=key, kind='write' if kind == 'setSetting' else 'read',
                     file=rel, line=i + 1, text=text.strip()[:300], via=via))

local_wrappers = {}  # rel -> {var: (prefix, kind)}
for rel, lines in src.items():
    lw = {}
    for i, ln in enumerate(lines):
        m = WRAP_HEAD.search(ln)
        if not m:
            continue
        var, fname, param = m.group(1), m.group(2), m.group(3)
        body = ' '.join(lines[i:i + 5])
        bm = re.search(r"(?:Database::)(setting|setSetting)\(\s*'([^']*)'\s*\.\s*\$" + param + r"\s*[,)]", body)
        if not bm:
            continue
        if var:
            lw[var] = (bm.group(2), bm.group(1), i + 1)
        elif fname and fname not in ('setting', 'setSetting'):
            named_wrappers[fname] = (bm.group(2), bm.group(1), rel, i + 1)
    local_wrappers[rel] = lw

for rel, lines in src.items():
    slug = plugin_slug(rel)
    for i, ln in enumerate(lines):
        s = ln.strip()
        if s.startswith('*') or s.startswith('//') or s.startswith('/*'):
            continue
        handled = set()
        for m in DIRECT.finditer(ln):
            add(m.group(3), m.group(1), rel, i, ln, 'direct'); handled.add(m.start())
        for m in CONCAT.finditer(ln):
            dyn.append(dict(file=rel, line=i + 1, kind=m.group(1), pattern=m.group(3) + '*', text=ln.strip()[:300])); handled.add(m.start())
        for m in CONSTC.finditer(ln):
            cls, name, tail = m.group(2), m.group(3), m.group(4)
            if cls in ('self', 'static'):
                v = fconsts.get(rel, {}).get(name)
            else:
                v = fconsts.get(classfile.get(cls.split('\\')[-1], ''), {}).get(name)
            vals = {v} if v else consts.get(name)
            if vals and tail != '.':
                for v in vals:
                    add(v, m.group(1), rel, i, ln, 'const:' + name)
            else:
                dyn.append(dict(file=rel, line=i + 1, kind=m.group(1), pattern=(list(vals)[0] if vals else name) + '*', text=ln.strip()[:300]))
            handled.add(m.start())
        for m in INST.finditer(ln):
            if slug:
                add(slug + '.' + m.group(4), m.group(2), rel, i, ln, 'plugin-instance')
            else:
                dyn.append(dict(file=rel, line=i + 1, kind=m.group(2), pattern='<slug>.' + m.group(4), text=ln.strip()[:300]))
            handled.add(m.start())
        # local closure wrappers
        for var, (prefix, kind, defl) in local_wrappers.get(rel, {}).items():
            for m in re.finditer(r"\$" + var + r"\(\s*" + Q, ln):
                add(prefix + m.group(2), kind, rel, i, ln, 'closure:$' + var)
        for fname, (prefix, kind, frel, fl) in named_wrappers.items():
            for m in re.finditer(r"(?<![\w>:])" + fname + r"\(\s*" + Q, ln):
                add(prefix + m.group(2), kind, rel, i, ln, 'func:' + fname)
        # anything else: dynamic
        for m in ANYCALL.finditer(ln):
            arg = m.group(2)
            if re.match(r"['\"][^'\"$]+['\"]\s*[,)]", arg):
                continue
            if re.match(r"['\"][^'\"$]*['\"]\s*\.", arg):
                continue
            if re.match(r"(?:\\?[A-Za-z_\\]+|self|static)::[A-Z_]", arg):
                continue
            if re.match(r"['\"]", arg) and '$' not in arg.split(',')[0]:
                continue
            if 'function ' in ln:
                continue
            dyn.append(dict(file=rel, line=i + 1, kind=m.group(1), pattern='?', text=ln.strip()[:300]))

json.dump(dict(tree=label, files=len(files), recs=recs, dyn=dyn,
               named_wrappers={k: list(v) for k, v in named_wrappers.items()},
               local_wrappers={r: {k: list(v) for k, v in w.items()} for r, w in local_wrappers.items() if w}),
          open(out, 'w'), indent=1)
keys = sorted({r['key'] for r in recs})
print(label, 'files', len(files), 'records', len(recs), 'distinct keys', len(keys), 'dynamic sites', len(dyn))
