// The reusable Kohevo Studio template library, derived from the Faisal Hossen site.
//
// The structure, class names and block settings are the real site's; only the COPY is neutralised
// (headlines, paragraphs, labels, images) so every entry reads as a starting point on any site.
// Entries are saved through the builder's own `save_template`, so they appear in the Library panel
// and can be applied (pages) or inserted (sections, blocks, header, footer) like any other template.
import { createHash } from 'node:crypto';
import * as P from './pages.mjs';
import { projects } from './content.mjs';

export const LIB = { placeholderMedia: 0 };          // set by the installer after the placeholder image is uploaded
export const CATEGORY = Object.freeze({ nav: 'navigation', hero: 'hero', content: 'content', cards: 'cards', forms: 'forms', cta: 'call-to-action', media: 'media', pages: 'pages', chrome: 'header-footer' });

// ── copy neutralisation ─────────────────────────────────────────────────────
const LONG = 'Supporting copy goes here. Replace it with your own, one or two sentences is plenty.';
const cls = (n) => (n.classNames || []).join(' ');
const keepHref = (h) => (/^\/work-/.test(h) ? '/work' : /^(\/|tel:|mailto:)/.test(h) ? h : '/');

function neutralText(n, c) {
  if (/^[\d\s/.\-–—]+$/.test(c)) return c;
  const num = c.match(/^(\d\d) \/ /);
  if (num) return `${num[1]} / Section label`;
  if (/eyebrow/.test(cls(n))) return 'Eyebrow label';
  if (/\b(chip|tag)\b/.test(cls(n))) return 'Tag';
  if (/caption|note-head|note-title|mark|label|type|number|inline-note|workbench-id/.test(cls(n))) return 'Short label';
  return c.length <= 28 ? 'Short label' : LONG;
}
function neutralize(node) {
  const p = node.props || {};
  switch (node.type) {
    case 'core.heading': {
      const had = !!p.highlight;
      p.text = p.level === 'h1' ? 'Page headline with a highlighted phrase' : p.level === 'h2' ? 'Section headline with a highlighted phrase' : 'Item title';
      if (had || p.level === 'h1') p.highlight = 'highlighted phrase'; else delete p.highlight;
      break;
    }
    case 'core.text': p.content = neutralText(node, p.content || ''); break;
    case 'core.button': p.link = { ...p.link, label: p.variant === 'primary' ? 'Primary action' : 'Secondary action', href: keepHref(p.link.href) }; break;
    case 'core.link': p.link = { ...p.link, label: p.style === 'arrow' ? 'Read more' : 'Link label', href: keepHref(p.link.href) }; break;
    case 'core.image': p.media = { media_id: LIB.placeholderMedia, alt: 'Placeholder image' }; break;
    case 'core.list': p.items = ['List item one', 'List item two', 'List item three'].map((text) => ({ text })); break;
    case 'core.tabs': p.items = ['One', 'Two', 'Three'].map((l) => ({ label: `Tab ${l.toLowerCase()}`, content: 'Tab content goes here.' })); break;
    default: break;
  }
  (node.children || []).forEach(neutralize);
  return node;
}
const neutralSection = (s) => { s.blocks.forEach(neutralize); return s; };

// ── id helpers ──────────────────────────────────────────────────────────────
let rid = 0;
const newId = (p, seed) => `${p}_${createHash('sha256').update(`${seed}:${++rid}`).digest('hex').slice(0, 24)}`;
function reid(node, seed) {
  const c = structuredClone(node);
  const walk = (n) => { n.id = newId(n.type ? 'blk' : 'sec', seed); (n.children || []).forEach(walk); (n.blocks || []).forEach(walk); };
  walk(c);
  return c;
}
function find(nodes, pred) {
  for (const n of nodes) {
    if (pred(n)) return n;
    const hit = find([...(n.children || []), ...(n.blocks || [])], pred);
    if (hit) return hit;
  }
  return null;
}
const hasClass = (name) => (n) => (n.classNames || []).includes(name);

// ── the source documents (neutralised) ──────────────────────────────────────
const NEUTRAL_HEADER = { brand: 'YOUR BRAND', links: [['Home', '/'], ['Work', '/work'], ['About', '/about'], ['Contact', '/contact']], cta: 'Get in touch', seed: 'lib-header' };
const NEUTRAL_FOOTER = { brand: 'YOUR BRAND', note: 'A short line about what you do.', phone: ['+1 000 000 0000', 'tel:+10000000000'], socials: [{ label: 'LinkedIn', href: 'https://example.com' }, { label: 'GitHub', href: 'https://example.com' }, { label: 'Email', href: 'mailto:hello@example.com' }], links: [['Work', '/work'], ['About', '/about'], ['Contact', '/contact']], legal: '© Your Brand. All rights reserved.', tagline: 'Web · UX · Systems', seed: 'lib-footer' };

export function sources() {
  const home = P.homeDoc().map(neutralSection);
  const work = P.workDoc().map(neutralSection);
  const about = P.aboutDoc().map(neutralSection);
  const contact = P.contactDoc().map(neutralSection);
  const caseStudy = P.caseStudyDoc(projects[0], projects[1]).map(neutralSection);
  return { home, work, about, contact, caseStudy, header: P.headerDoc(NEUTRAL_HEADER), footer: P.footerDoc(NEUTRAL_FOOTER) };
}

// ── the catalogue ───────────────────────────────────────────────────────────
// Every entry: key, type, category, name, description + where its node comes from.
export function catalogue() {
  const src = sources();
  const all = [...src.home, ...src.work, ...src.about, ...src.contact, ...src.caseStudy];
  const sectionOf = (doc, label) => doc.find((s) => s.label === label);
  const blockOf = (name, nth = 0) => {
    const hits = []; const walk = (nodes) => nodes.forEach((n) => { if (hasClass(name)(n)) hits.push(n); walk([...(n.children || []), ...(n.blocks || [])]); });
    walk([...all, ...src.header, ...src.footer]);
    if (!hits[nth]) throw new Error(`library: no block with class ${name}`);
    return hits[nth];
  };

  const pages = [
    ['kh-page-home', 'Home', 'Hero, workbench, proof band, capabilities, playground, principles, selected work, about preview and call to action.', src.home],
    ['kh-page-work', 'Work index', 'Page hero, project grid and a closing note.', src.work],
    ['kh-page-about', 'About', 'Page hero, story with portrait, principles and onward links.', src.about],
    ['kh-page-contact', 'Contact', 'Page hero, contact form, direct details and a note band.', src.contact],
    ['kh-page-case-study', 'Case study', 'Back link, headline, meta row, visual, challenge / solution / approach and a next-project link.', src.caseStudy],
  ].map(([key, name, description, sections]) => ({ key, type: 'page_template', category: CATEGORY.pages, name: `Portfolio · ${name}`, description, sections }));

  const sections = [
    ['kh-section-hero', 'Hero with actions and chips', CATEGORY.hero, sectionOf(src.home, 'Hero')],
    ['kh-section-page-hero', 'Page hero', CATEGORY.hero, sectionOf(src.work, 'Hero')],
    ['kh-section-workbench', 'Workbench (photo and notes)', CATEGORY.content, sectionOf(src.home, 'Workbench')],
    ['kh-section-proof-band', 'Dark statement band', CATEGORY.content, sectionOf(src.home, 'Proof, without theatre')],
    ['kh-section-capabilities', 'Capabilities list', CATEGORY.content, sectionOf(src.home, 'Capabilities')],
    ['kh-section-playground', 'Tabbed playground', CATEGORY.content, sectionOf(src.home, 'Playground')],
    ['kh-section-principles', 'Principles list', CATEGORY.content, sectionOf(src.home, 'How I think')],
    ['kh-section-selected-work', 'Selected work grid', CATEGORY.cards, sectionOf(src.home, 'Selected work')],
    ['kh-section-about-preview', 'About preview', CATEGORY.content, sectionOf(src.home, 'About preview')],
    ['kh-section-cta', 'Call to action band', CATEGORY.cta, sectionOf(src.home, 'Start a conversation')],
    ['kh-section-work-index', 'Work index with heading', CATEGORY.cards, sectionOf(src.work, 'The index')],
    ['kh-section-note-band', 'Note band', CATEGORY.content, sectionOf(src.work, 'Note')],
    ['kh-section-about-story', 'About story with portrait', CATEGORY.media, sectionOf(src.about, 'Story')],
    ['kh-section-links', 'Closing links', CATEGORY.cta, sectionOf(src.about, 'Links')],
    ['kh-section-contact-form', 'Contact form', CATEGORY.forms, sectionOf(src.contact, 'Form')],
    ['kh-section-contact-details', 'Contact details card', CATEGORY.forms, sectionOf(src.contact, 'Details')],
    ['kh-section-case-hero', 'Case study hero', CATEGORY.hero, sectionOf(src.caseStudy, 'Case study hero')],
    ['kh-section-case-body', 'Case study body', CATEGORY.content, sectionOf(src.caseStudy, 'Case study body')],
    ['kh-section-case-next', 'Next project', CATEGORY.nav, sectionOf(src.caseStudy, 'Next project')],
  ].map(([key, name, category, section]) => {
    if (!section) throw new Error(`library: missing source section for ${key}`);
    return { key, type: 'section_preset', category, name, description: '', section };
  });

  const blocks = [
    ['kh-nav-desktop', 'Main navigation', CATEGORY.nav, 'desktop-nav', 'Row of navigation links shown on desktop.'],
    ['kh-nav-mobile', 'Mobile menu (off-canvas)', CATEGORY.nav, 'header-menu', 'Menu button that opens an off-canvas drawer with the links and a call to action.'],
    ['kh-wordmark', 'Wordmark', CATEGORY.nav, 'wordmark', 'Text logo that links home.'],
    ['kh-footer-nav', 'Footer links', CATEGORY.nav, 'footer-nav', 'Footer link column with a back-to-top link.'],
    ['kh-footer-socials', 'Social links', CATEGORY.nav, 'footer-socials', 'Row of social links.'],
    ['kh-hero-actions', 'Button pair', CATEGORY.cta, 'hero-actions', 'A primary and an outline button side by side.'],
    ['kh-hero-chips', 'Chip row', CATEGORY.hero, 'hero-capabilities', 'A wrap of small outlined chips.'],
    ['kh-section-heading', 'Section heading', CATEGORY.content, 'section-heading', 'Eyebrow, headline with highlight and supporting copy.'],
    ['kh-section-rail', 'Section rail', CATEGORY.content, 'section-rail', 'Numbered eyebrow, rule and caption in the left margin.'],
    ['kh-capability-list', 'Capability rows', CATEGORY.content, 'capability-list', 'Numbered rows with title, detail and arrow.'],
    ['kh-principles-list', 'Principle rows', CATEGORY.content, 'principles-list', 'Indexed rows with title and body.'],
    ['kh-project-card', 'Project card', CATEGORY.cards, 'project-card', 'Image, meta line, title, summary, tags and link.'],
    ['kh-project-grid', 'Project grid', CATEGORY.cards, 'project-grid', 'Grid of project cards with a featured first card.'],
    ['kh-workbench', 'Workbench card', CATEGORY.media, 'workbench', 'Status line, photo and a notes panel.'],
    ['kh-portrait', 'Portrait with caption', CATEGORY.media, 'about-portrait-wrap', 'Image and caption.'],
    ['kh-playground', 'Tabbed playground', CATEGORY.content, 'problem-playground', 'Tabs block with an arrow link underneath.'],
    ['kh-case-meta', 'Meta row', CATEGORY.content, 'case-study-meta', 'Labelled facts in a row (type, role, technology).'],
    ['kh-contact-form', 'Contact form', CATEGORY.forms, 'contact-form', 'Form with name, email, company, subject and message.'],
    ['kh-cta-actions', 'CTA actions', CATEGORY.cta, 'cta-actions', 'Button with a short note beside it.'],
    ['kh-note-band', 'Note band', CATEGORY.content, 'work-index-footer-note', 'A single quiet line of text in a band.'],
  ].map(([key, name, category, klass, description]) => ({ key, type: 'block_preset', category, name, description, block: blockOf(klass) }));

  const chrome = [
    { key: 'kh-header', type: 'header_preset', category: CATEGORY.chrome, name: 'Header · wordmark, nav, call to action, mobile menu', description: 'Sticky header: wordmark, desktop navigation, call-to-action link and an off-canvas mobile menu.', sections: src.header },
    { key: 'kh-footer', type: 'footer_preset', category: CATEGORY.chrome, name: 'Footer · dark, three columns', description: 'Brand note, phone and social links, footer navigation and a legal line.', sections: src.footer },
  ];
  return { pages, sections, blocks, chrome, reid };
}
