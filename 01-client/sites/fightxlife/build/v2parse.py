"""FightxLife V2 content pack (.docx in a zip) -> structured JSON for build/v2.php.

page: {doc, url, seo_title, meta, h1, intro: [block], sections: [{id, h2, blocks: [block]}], design}
block: {t:'p', html} | {t:'ul', items:[html]} | {t:'h3', text, blocks:[...]} | {t:'cta', kind, label, href} | {t:'note', text}
"""
import html, io, json, re, sys, zipfile

W = '{http://schemas.openxmlformats.org/wordprocessingml/2006/main}'
from xml.etree import ElementTree as ET
R = '{http://schemas.openxmlformats.org/officeDocument/2006/relationships}'

CTA = re.compile(r'^\[(?P<kind>[^:\]]+):\s*[“"](?P<label>.+?)[”"]\s*→\s*(?P<href>\S+?)\s*\]$')
META = re.compile(r'^\[(Page URL|SEO title|Meta description|HTML id|Design and integration):\s*(.*)\]$', re.S)
HEAD = re.compile(r'^\[(H[123])\]\s*')


def runs_html(p, rels):
    out, plain, all_italic, any_text = [], [], True, False
    for node in p:
        tag = node.tag.replace(W, '')
        if tag == 'r':
            parts = [node]
            href = None
        elif tag == 'hyperlink':
            parts = node.findall(W + 'r')
            href = rels.get(node.get(R + 'id'), node.get(W + 'anchor'))
        else:
            continue
        chunk = ''
        for r in parts:
            t = ''.join((x.text or '') for x in r.iter() if x.tag in (W + 't',)) + ('\n' if r.find(W + 'br') is not None else '')
            if not t:
                continue
            any_text = True
            rp = r.find(W + 'rPr')
            b = rp is not None and rp.find(W + 'b') is not None and rp.find(W + 'b').get(W + 'val') not in ('0', 'false')
            i = rp is not None and rp.find(W + 'i') is not None and rp.find(W + 'i').get(W + 'val') not in ('0', 'false')
            if t.strip() and not i:
                all_italic = False
            e = html.escape(t, quote=False).replace('\n', '<br>')
            chunk += f'<strong>{e}</strong>' if b and t.strip() else e
            plain.append(t)
        if href and chunk:
            chunk = f'<a href="{html.escape(href)}">{chunk}</a>'
        out.append(chunk)
    h = re.sub(r'</strong>(\s*)<strong>', r'\1', ''.join(out)).strip()
    return h, ''.join(plain).strip(), (all_italic and any_text)


def parse(name, data):
    z = zipfile.ZipFile(io.BytesIO(data))
    doc = ET.fromstring(z.read('word/document.xml'))
    rels = {}
    if 'word/_rels/document.xml.rels' in z.namelist():
        for rel in ET.fromstring(z.read('word/_rels/document.xml.rels')):
            rels[rel.get('Id')] = rel.get('Target')
    page = {'doc': name, 'url': '', 'seo_title': '', 'meta': '', 'h1': '', 'intro': [], 'sections': [], 'design': ''}
    target = page['intro']      # where blocks go now
    sec = None
    h3 = None
    pending_id = ''
    body = doc.find(W + 'body')
    for p in body.iter(W + 'p'):
        h, text, italic = runs_html(p, rels)
        if not text:
            continue
        ppr = p.find(W + 'pPr')
        is_li = ppr is not None and ppr.find(W + 'numPr') is not None
        m = META.match(text)
        if m:
            k, v = m.group(1), m.group(2).strip()
            if k == 'Page URL': page['url'] = v
            elif k == 'SEO title': page['seo_title'] = v
            elif k == 'Meta description': page['meta'] = v
            elif k == 'HTML id': pending_id = v
            else: page['design'] = v
            continue
        hm = HEAD.match(text)
        if hm:
            level, t = hm.group(1), HEAD.sub('', text).strip()
            if level == 'H1':
                page['h1'] = t; target = page['intro']; h3 = None
            elif level == 'H2':
                sec = {'id': pending_id, 'h2': t, 'blocks': []}; pending_id = ''
                page['sections'].append(sec); target = sec['blocks']; h3 = None
            else:
                h3 = {'t': 'h3', 'text': t, 'blocks': []}
                (sec['blocks'] if sec else page['intro']).append(h3); target = h3['blocks']
            continue
        cm = CTA.match(text)
        if cm:
            target.append({'t': 'cta', 'kind': cm.group('kind').strip(), 'label': cm.group('label').strip(), 'href': html.unescape(cm.group('href'))})
            continue
        if text.startswith('[') and text.endswith(']'):
            target.append({'t': 'note', 'text': text[1:-1]})
            continue
        if text.startswith('[') and not text.endswith(']'):
            target.append({'t': 'note', 'text': text.strip('[]')})
            continue
        if italic:
            target.append({'t': 'note', 'text': text})
            continue
        if is_li:
            if target and target[-1]['t'] == 'ul':
                target[-1]['items'].append(h)
            else:
                target.append({'t': 'ul', 'items': [h]})
            continue
        target.append({'t': 'p', 'html': h})
    return page


def main(zip_path, out):
    z = zipfile.ZipFile(zip_path)
    pages = []
    for n in sorted(z.namelist()):
        base = n.split('/')[-1]
        if not base.endswith('.docx') or base.startswith(('0.', '301')):
            continue
        pages.append(parse(base, z.read(n)))
    json.dump(pages, open(out, 'w'), ensure_ascii=False, indent=1)
    for p in pages:
        print(f"{p['url']:<26} sec={len(p['sections'])} intro={len(p['intro'])} {p['h1'][:50]}")


if __name__ == '__main__':
    main(sys.argv[1], sys.argv[2])
