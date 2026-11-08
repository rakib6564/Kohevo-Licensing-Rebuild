# Turns the original site's copy into data/pages.json for the Studio build, with the original generator's own parser
# (site/gen/build.py), so the wording and the grouping of sections match the static site exactly.
#
#   python3 build/export.py <path to a rakibhasaan-site checkout>
import html, json, os, re, sys

SRC = os.path.abspath(sys.argv[1])
GEN = os.path.join(SRC, 'site', 'gen')
OUT = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'data', 'pages.json')

# Run the generator's set-up and parsers (everything before its HTML renderers), in its own folder.
src = open(os.path.join(GEN, 'build.py'), encoding='utf-8').read()
head = src[:src.index('# ---------------------------------------------------------------- block renderers')]
g = {'__file__': os.path.join(GEN, 'build.py'), '__name__': 'export'}
exec(compile(head, 'build.py', 'exec'), g)
for name in ('is_faq', 'is_simple', 'icon_for'):
    m = re.search(r'^def %s\(.*?(?=^def |^[A-Z_]+ = |^# ---)' % name, src, re.S | re.M)
    exec(compile(m.group(0), 'build.py', 'exec'), g)
# The Construction page's copy is a constant inside the generator.
m = re.search(r"^CONSTRUCTION = '''(.*?)'''", src, re.S | re.M)
g['CONSTRUCTION'] = m.group(1).encode().decode('unicode_escape').encode('latin-1').decode('utf-8')
clean, plain, parse_blocks, parse_page, chunk, link_for, is_faq, is_simple = (g[k] for k in ('clean', 'plain', 'parse_blocks', 'parse_page', 'chunk', 'link_for', 'is_faq', 'is_simple'))

SLUG = {'Services.dc.html': '/services/', 'SvcWordPress.dc.html': '/wordpress-websites/', 'SvcCustom.dc.html': '/custom-development/',
        'SvcSEO.dc.html': '/seo/', 'SvcApps.dc.html': '/apps/', 'SvcPlatforms.dc.html': '/platforms/', 'SvcBrand.dc.html': '/branding/',
        'Audit.dc.html': '/free-audit/', 'About.dc.html': '/about/', 'Process.dc.html': '/process/', 'Reviews.dc.html': '/reviews/',
        'FAQ.dc.html': '/faq/', 'Contact.dc.html': '/contact/', 'Work.dc.html': '/work/', 'Case.dc.html': '/work/', 'Creative.dc.html': '/'}
href = lambda label: SLUG.get(link_for(label), '/contact/')

def inl(t):
    """Inline markdown to the HTML a Studio rich text block keeps: strong, links, placeholders as <em>."""
    t = html.escape(clean(t), quote=False)
    t = re.sub(r'\[([^\]]+)\]\((https?://[^)]+)\)', r'<a href="\2">\1</a>', t)
    t = re.sub(r'\[([^\]]+)\]', r'<em>[\1]</em>', t)
    t = re.sub(r'\*\*(.+?)\*\*', r'<strong>\1</strong>', t)
    t = re.sub(r'`([^`]+)`', r'<code>\1</code>', t)
    return t.replace('Read the case study.', '<a href="/work/">Read the case study</a>.')

def btns(spec):
    out = []
    for n, p in enumerate(x.strip() for x in spec.split(' · ') if x.strip()):
        label = plain(p).strip()
        out.append({'label': label, 'href': href(label), 'primary': p.startswith('**') or n == 0})
    return out

def blocks(bl):
    """The parsed blocks as data: FAQs grouped, tables classified the way the generator draws them."""
    out, i = [], 0
    while i < len(bl):
        kind, v = bl[i]
        if is_faq(bl[i]):
            items = []
            while i < len(bl) and is_faq(bl[i]):
                m = re.match(r'^\*\*([^*]+\?)\*\*\s*(.*)$', bl[i][1])
                items.append({'q': plain(m.group(1)), 'a': inl(m.group(2))}); i += 1
            out.append({'k': 'faq', 'items': items}); continue
        if kind == 'p':
            out.append({'k': 'p', 'html': inl(v[8:].strip() if v.startswith('Summary:') else v)})
        elif kind == 'ul':
            leads = [re.match(r'^\*\*(.+?)\*\*:?\s*(.+)$', it) for it in v]
            if all(leads):
                out.append({'k': 'tiles', 'items': [{'h': plain(m.group(1).rstrip(':')), 'html': inl(m.group(2).lstrip(': '))} for m in leads]})
            else:
                out.append({'k': 'checks', 'items': [inl(it) for it in v]})
        elif kind == 'ol':
            items = []
            for it in v:
                m = re.match(r'^\*\*(.+?)\*\*\s*(.*)$', it)
                items.append({'h': plain(m.group(1).rstrip('.')) if m else '', 'html': inl(m.group(2) if m else it)})
            out.append({'k': 'steps', 'items': items})
        elif kind == 'table':
            head, *body = v
            if plain(head[0]) in ('New filter', 'Field', 'Rule'):
                i += 1; continue
            if 'price' in plain(head[-1]).lower() and len(head) >= 3:
                cards = []
                for n, r in enumerate(body):
                    name = plain(r[0])
                    feat = 'most chosen' in name.lower() or (len(body) >= 2 and n == 1)
                    cards.append({'name': re.sub(r'\s*\(most chosen\)', '', name, flags=re.I), 'html': inl(r[1]), 'price': plain(r[-1]), 'feat': feat,
                                  'cta': 'Start with an audit' if 'quoted' in plain(r[-1]).lower() else 'Get a quote'})
                out.append({'k': 'prices', 'items': cards})
            elif len(head) == 2 and 'price' in plain(head[1]).lower():
                out.append({'k': 'pricelist', 'head': [plain(head[0]), plain(head[1])], 'rows': [[plain(r[0]), plain(r[1])] for r in body]})
            elif len(head) == 2:
                out.append({'k': 'picker', 'head': [plain(head[0]), plain(head[1])], 'rows': [{'q': plain(r[0]), 'a': plain(r[1]), 'href': href(plain(r[1]))} for r in body]})
            else:
                out.append({'k': 'specs', 'head': [plain(h) for h in head], 'rows': [[plain(c) for c in r] for r in body]})
        elif kind == 'quote':
            q = clean(v)
            m = re.match(r'^(.*?)\s+—\s+(.*)$', q)
            text, who = (m.group(1), m.group(2)) if m else (q, '')
            out.append({'k': 'quote', 'text': plain(text.strip().strip('"“”')), 'who': plain(who)})
        elif kind == 'btns':
            out.append({'k': 'btns', 'items': btns(v)})
        elif kind == 'link':
            label = plain(v).strip().rstrip('→').strip()
            out.append({'k': 'link', 'label': label, 'href': href(label)})
        elif kind == 'badge':
            out.append({'k': 'badge', 'items': [plain(p) for p in v.split(' · ')]})
        elif kind == 'note':
            out.append({'k': 'note', 'html': inl(v)})
        i += 1
    return out

THEMES = ['bone', 'ink', 'bone', 'navy', 'bone', 'ink']

def section(s, n, theme, last):
    bl = parse_blocks(s['lines'])
    if last:
        return {'kind': 'cta', 'n': n, 'title': clean(s['title']), 'blocks': blocks(bl) or [{'k': 'btns', 'items': btns('**Get a free audit**')}]}
    subs = []
    for sub in s['subs']:
        sb = parse_blocks(sub['lines'])
        link = next((b for b in sb if b[0] == 'link'), None)
        lab = plain(link[1]).rstrip('→').strip() if link else ''
        subs.append({'title': re.sub(r'^\d+\.\s*', '', clean(sub['title'])), 'blocks': blocks([b for b in sb if b[0] != 'link']),
                     'link': {'label': lab, 'href': href(lab)} if link else None})
    data = blocks(bl)
    return {'kind': 'section', 'n': n, 'theme': theme, 'title': clean(s['title']), 'blocks': data, 'subs': subs,
            'quotes': sum(1 for b in data if b['k'] == 'quote') >= 3, 'lead': all(b[0] == 'p' for b in bl)}

def statement(s, n, theme):
    bl = parse_blocks(s['lines'])
    first = next(i for i, b in enumerate(bl) if b[0] == 'p')
    m = re.match(r'^(.+?[.!?])\s+(.*)$', bl[first][1])
    lead, rest = (m.group(1), m.group(2)) if m else (bl[first][1], '')
    return {'kind': 'statement', 'n': n, 'theme': theme, 'title': clean(s['title']), 'lead': plain(lead),
            'rest': blocks(bl[:first] + ([('p', rest)] if rest else []) + bl[first + 1:])}

def page_items(fname, sections):
    items, n, done = [], 0, set()
    if fname in g['BANDS']:
        img, alt, cap = g['BANDS'][fname]
        items.append({'kind': 'band', 'img': img.split('/')[-1], 'alt': alt, 'cap': cap})
    if fname == 'About.dc.html':
        items.append({'kind': 'world', 'label': 'Where my clients are'})
    for k, s in enumerate(sections):
        last = k == len(sections) - 1
        if s['title'] == 'Send a message':
            n += 1; items.append({'kind': 'contact', 'n': n}); continue
        if k in done: continue
        if s['title'].startswith('Case in point: the Kohevo'):
            n += 1; items.append({'kind': 'products', 'n': n}); continue
        if fname == 'Reviews.dc.html' and s['title'] == 'Client reviews':
            items += [{'kind': 'reviews'}, {'kind': 'world', 'label': 'Clients in 14 countries'}]; n += 1; continue
        if not last and is_simple(s):
            run, j = [s], k + 1
            while j < len(sections) - 1 and is_simple(sections[j]) and sections[j]['title'] != 'Send a message' and len(run) < 4:
                run.append(sections[j]); j += 1
            done.update(range(k + 1, j))
            theme = THEMES[n % len(THEMES)]
            if len(run) >= 2:
                items.append({'kind': 'bento', 'n': n + 1, 'theme': theme, 'cards': [{'title': clean(r['title']), 'icon': g['icon_for'](r['title']), 'blocks': blocks(parse_blocks(r['lines']))} for r in run]})
            else:
                items.append(statement(s, n + 1, theme))
            n += len(run); continue
        n += 1
        items.append(section(s, n, THEMES[(n - 1) % len(THEMES)], last))
    if fname in g['IMGQ_PAGES'] and items:
        items.insert(len(items) - 1, {'kind': 'recent'})
    return items

pages = {}
for fname, (start, crumb) in g['PAGES'].items():
    spec, intro, sections = parse_page(chunk(start))
    secs = [s for s in sections if s['title'] not in ('Add more reviews', 'Case study template (every project page)')]
    pages[SLUG[fname].strip('/')] = {
        'name': clean(start[3:].replace('Service: ', '')), 'parent': 'services' if crumb else '', 'h1': clean(spec.get('H1', '')),
        'seo_title': plain(spec.get('SEO title', spec.get('H1', ''))), 'meta': plain(spec.get('Meta description', '')),
        'intro': blocks(intro), 'items': page_items(fname, secs)}

spec, intro, sections = parse_page(g['CONSTRUCTION'])
items = []
for k, s in enumerate(sections):
    n = k + 1
    if s['title'] == "Construction sites I've built":
        items.append({'kind': 'projects', 'n': n, 'title': s['title'], 'industry': 'Construction & trades'})
    elif k == len(sections) - 1:
        items.append({'kind': 'cta_form', 'n': n, 'title': clean(s['title']), 'blocks': blocks([b for b in parse_blocks(s['lines']) if b[0] == 'p'])})
    else:
        items.append(section(s, n, THEMES[(n - 1) % len(THEMES)], False))
pages['construction'] = {'name': 'Construction & trades', 'parent': 'work', 'h1': clean(spec['H1']), 'seo_title': plain(spec['SEO title']),
                         'meta': plain(spec['Meta description']), 'intro': blocks(intro), 'items': items,
                         'chips': ['General contractors', 'Design-build firms', 'Remodelers', 'Flooring & tile', 'Glass & glazing', 'Electricians']}

spec, intro, sections = parse_page(chunk('## Work archive + case study template'))
cta = [s for s in sections if s['title'].startswith('Not sure')][0]
pages['work'] = {'name': 'Work', 'parent': '', 'h1': clean(spec['H1']), 'seo_title': plain(spec['SEO title']), 'meta': plain(spec['Meta description']),
                 'intro': blocks(intro), 'items': [{'kind': 'grid'}, section(cta, 2, 'orange', True)]}

cases = json.load(open(os.path.join(GEN, 'cases.json'), encoding='utf-8'))
for c in cases:
    c['img'] = c['img'].split('/')[-1]
    c['img2'] = c['img2'].split('/')[-1]
    for key in ('p_client', 'p_challenge', 'p_built', 'p_result', 'p_testi'):
        c[key] = [''.join(('<em>%s</em>' % html.escape(s['t'])) if s['f'] else html.escape(s['t']) for s in para) for para in c[key]]

reviews = json.load(open(os.path.join(GEN, 'reviews.json'), encoding='utf-8'))
os.makedirs(os.path.dirname(OUT), exist_ok=True)
json.dump({'pages': pages, 'cases': cases, 'reviews': reviews, 'featured': re.search(r"^FEATURED = (\[.*?\])", src, re.M) and json.loads(re.search(r"^FEATURED = (\[.*?\])", src, re.M).group(1).replace("'", '"'))}, open(OUT, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
print('wrote', OUT, len(pages), 'pages,', len(cases), 'cases,', len(reviews), 'reviews')
