import { blk, sec, text, h, btn, link, box, flex, grid, list, rich, setSeed } from './dsl.mjs';
import { site, contact, problemStates, principles, capabilities, projects, MEDIA, hrefOf } from './content.mjs';

const media = (id, alt) => ({ media_id: id, alt });
const img = (id, alt, o = {}) => blk('core.image', { media: media(id, alt), caption: '', aspect_ratio: 'auto', rounded: false }, o);
const fade = { type: 'fade_up' };
const S0 = { padding_y: 'none', width: 'full', columns: { base: 1, md: 1 } };            // a section whose inner shell carries the gutters
const T = (c, cls, o = {}) => text(c, { classNames: cls, ...o });
const eyebrow = (c, extra = '') => T(c, `eyebrow ${extra}`.trim());

function sectionHeading(eyebrowText, title, body, highlight = '') {
  return box([eyebrow(eyebrowText), h('h2', title, { highlight }), ...(body ? [T(body, 'section-heading-body')] : [])], { classNames: 'section-heading', animation: fade });
}
function rail(num, caption) {
  return box([eyebrow(num), box([], { classNames: 'rail-rule' }), T(caption, 'rail-caption')], { classNames: 'section-rail' });
}
const railLayout = (num, caption, children, cls = '') => box([rail(num, caption), ...children], { classNames: `rail-layout ${cls}`.trim() });
const arrowBox = () => box([], { classNames: 'capability-arrow' });

// ── Header / footer partials ────────────────────────────────────────────────
export function headerDoc() {
  setSeed('header');
  const links = [['Home', '/'], ['Work', '/work'], ['About', '/about'], ['Contact', '/contact']];
  return [sec('Header', [
    box([
      box([link(site.name.toUpperCase(), '/', { linkStyle: 'plain' })], { classNames: 'wordmark' }),
      box(links.map(([l, href]) => link(l, href, { linkStyle: 'plain' })), { classNames: 'desktop-nav' }),
      box([link('Start a project', '/contact', { linkStyle: 'plain' })], { classNames: 'header-project-link' }),
      box([blk('layout.offcanvas', { drawer_id: 'site-menu', title: 'Menu', position: 'right', trigger_text: 'Menu', trigger_variant: 'outline' }, {
        children: [...links.map(([l, href]) => link(l, href, { linkStyle: 'plain' })), btn('Start a project', '/contact', { classNames: 'mobile-nav-cta' })],
      })], { classNames: 'header-menu' }),
    ], { classNames: 'header-inner' }),
  ], S0)];
}
export function footerDoc() {
  setSeed('footer');
  return [sec('Footer', [
    box([
      box([
        box([box([link(site.name.toUpperCase(), '/', { linkStyle: 'plain' })], { classNames: 'wordmark footer-wordmark' }), T('A calm digital partner for problems worth solving.', 'footer-note')]),
        box([
          box([link(contact.phone, contact.phoneHref, { linkStyle: 'plain' })], { classNames: 'footer-phone' }),
          box(contact.socials.map((s) => link(s.label, s.href, { linkStyle: 'plain' })), { classNames: 'footer-socials' }),
        ], { classNames: 'footer-contact-block' }),
        box([...[['Work', '/work'], ['About', '/about'], ['Contact', '/contact']].map(([l, href]) => link(l, href, { linkStyle: 'plain' })), box([link('Back to top', '/', { linkStyle: 'plain' })], { classNames: 'footer-link' })], { classNames: 'footer-nav' }),
      ], { classNames: 'footer-top' }),
      box([T(`© ${new Date().getFullYear()} ${site.name}. Built with intent.`, ''), T('Web · UX · Systems', '')], { classNames: 'footer-bottom' }),
    ], { classNames: 'footer-shell' }),
  ], { ...S0, background_token: 'surface.inverse' })];
}

// ── Home ────────────────────────────────────────────────────────────────────
export function homeDoc() {
  setSeed('home');
  const hero = sec('Hero', [
    box([
      box([
        eyebrow(`${site.label} · ${site.context}`, 'dot hero-eyebrow'),
        h('h1', site.positioning),
        T(site.supporting, 'hero-supporting'),
        box([btn('Start a project', '/contact'), btn('Explore my work', '/work', { variant: 'outline' })], { classNames: 'hero-actions' }),
        box(site.capabilities.map((c) => T(c, 'chip')), { classNames: 'hero-capabilities' }),
      ], { classNames: 'hero-copy', animation: fade }),
      box([box([], { classNames: 'hero-side-line' }), T('Technology is a capability. Problem-solving is the identity.', '')], { classNames: 'hero-side-note' }),
    ], { classNames: 'hero' }),
  ], S0);

  const workbench = sec('Workbench', [
    railLayout('01 / visual proof', 'A little context before the work', [
        sectionHeading('Workbench', 'Show the thinking, not just the finished screen.', 'A personal website should make the way of working visible. This is a small window into how I frame, simplify, and build.'),
        box([
          box([box([], { classNames: 'status-dot' }), T('Workbench / thinking in public', ''), T('HQ—001', 'workbench-id')], { classNames: 'workbench-topline' }),
          box([
            box([img(MEDIA.workbench, 'Faisal Hossen standing outdoors in a navy shirt'), T('Real person. Real curiosity.', 'photo-caption')], { classNames: 'workbench-photo-wrap' }),
            box([
              T('How a problem becomes a product', 'workbench-note-head'),
              T('Less noise. Better decisions.', 'workbench-note-title'),
              list(['Frame the problem', 'Find the useful next step', 'Build for real people'], { listStyle: 'none', classNames: 'signal-list' }),
              box([T('Design + build', '')], { classNames: 'workbench-footer' }),
            ], { classNames: 'workbench-notes' }),
          ], { classNames: 'workbench-grid' }),
        ], { classNames: 'workbench', animation: fade }),
    ]),
  ], S0);

  const contrast = sec('Proof, without theatre', [
    box([
      box([T('Proof, without theatre', 'contrast-mark'), h('h2', 'No invented numbers. Just useful work.', { highlight: 'Just useful work.' })]),
      box([
        T('This site is being built as a product demonstration: a place to see the judgement behind the interface, the care behind the details, and the person behind the work.', ''),
        T('Clear about what is verified — and what is still being built.', 'contrast-footnote'),
      ], { classNames: 'contrast-copy' }),
    ], { classNames: 'contrast-inner' }),
  ], { ...S0, background_token: 'surface.inverse' });

  const caps = sec('Capabilities', [
    railLayout('02 / what I build', 'The right shape for the problem', [
        sectionHeading('Capabilities', 'A useful blend of design judgement and technical range.', 'The tools change. The standard stays the same: make the right thing clearer, calmer, and easier to use.'),
        box(capabilities.map((c) => box([T(c.mark, 'capability-mark'), box([h('h3', c.name), T(c.detail, '')]), arrowBox()], { classNames: 'capability-row' })), { classNames: 'capability-list', animation: fade }),
    ], 'capabilities-main'),
  ], S0);

  const playground = sec('Playground', [
    box([
      box([eyebrow('03 / a small playground'), h('h2', 'Start with the problem. Not the platform.', { highlight: 'Not the platform.' }), T('Choose the situation that sounds familiar. You will get a useful first question, not a quiz score.', 'playground-lede')], { classNames: 'playground-intro' }),
      box([box([blk('core.tabs', {
        items: problemStates.map((p) => ({ label: p.shortLabel, content: `${p.label}\n${p.diagnosis}\n\nRecommendation — ${p.recommendation}\nNext step — ${p.nextStep}` })),
        options: { position: 'top' },
      }), link('Talk through the problem', '/contact', { linkStyle: 'arrow' })], { classNames: 'problem-playground' })], { classNames: 'playground-wrap', animation: fade }),
    ], { classNames: 'playground-section' }),
  ], S0);

  const thinking = sec('How I think', [
    railLayout('04 / how I think', 'Simple principles, applied carefully', [
        sectionHeading('Working principles', 'Good digital work makes progress feel possible.', 'Not every problem needs more software. It needs a better question, a clearer path, and enough care to make the next step hold up.'),
        box(principles.map((p) => box([T(p.index, 'principle-index'), box([h('h3', p.title), T(p.body, '')])], { classNames: 'principle-row' })), { classNames: 'principles-list', animation: fade }),
    ]),
  ], S0);

  const work = sec('Selected work', [
    box([
        rail('05 / selected work', 'Real project notes, kept honest.'),
        sectionHeading('Project notes', 'Useful work, in three different shapes.', 'A flooring website, a therapist experience, and an AI product flow. Start with the case study that feels closest to the problem in front of you.'),
        projectGrid(),
        box([btn('See all project notes', '/work', { variant: 'outline' })], { classNames: 'section-action' }),
    ], { classNames: 'selected-work-section' }),
  ], S0);

  const about = sec('About preview', [
    box([
      box([eyebrow('About / the practice'), h('h2', 'A digital home should still feel human.')]),
      box([
        T('There is a person behind the systems: curious, practical, and interested in the details that make a digital experience feel easy.', ''),
        T('Independent · worldwide', 'inline-note'),
        btn('Read about the practice', '/about', { variant: 'ghost' }),
      ], { classNames: 'about-preview-copy' }),
    ], { classNames: 'about-preview' }),
  ], S0);

  const cta = sec('Start a conversation', [
    box([
      eyebrow('06 / start a conversation'),
      h('h2', 'Have a problem worth solving?', { highlight: 'worth solving?' }),
      T('No pressure. No complicated pitch. Just a conversation about the problem.', 'cta-supporting'),
      box([btn('Contact page', '/contact'), T(`Use the form or call ${contact.phone}.`, 'cta-note')], { classNames: 'cta-actions' }),
    ], { classNames: 'cta-inner' }),
  ], { ...S0, background_token: 'surface.accent' });

  return [hero, workbench, contrast, caps, playground, thinking, work, about, cta];
}

// One project card (also used by the Work page).
export function projectCard(p, featured) {
  return box([
    box([img(MEDIA[p.media], p.imageAlt), box([], { classNames: 'project-card-arrow' })], { classNames: 'project-card-image' }),
    box([
      box([T(`${p.number} /`, 'project-card-number'), eyebrow(p.category), T(p.type, 'project-card-type')], { classNames: 'project-card-meta' }),
      box([h('h3', p.title)], { classNames: 'project-card-title' }),
      T(p.summary, 'project-card-summary'),
      box(p.focus.map((f) => T(f, 'tag')), { classNames: 'project-tags' }),
      box([link('Read the project note', hrefOf(p), { linkStyle: 'arrow' })], { classNames: 'project-card-link' }),
    ], { classNames: 'project-card-body' }),
  ], { classNames: `project-card${featured ? ' project-card-featured' : ''}` });
}
export function projectGrid() {
  return box(projects.map((p, i) => projectCard(p, i === 0)), { classNames: 'project-grid home-project-grid', animation: fade });
}

// ── Shared page pieces ───────────────────────────────────────────────────────
const pageHero = (eyebrowText, title, supporting, extra = '') =>
  sec('Hero', [box([eyebrow(eyebrowText, 'dot'), h('h1', title), T(supporting, 'page-hero-supporting')], { classNames: `page-hero ${extra}`.trim() })], S0);
const noteBand = (cls, c) => box([T(c, 'band-text')], { classNames: cls });
const fillHero = (...a) => pageHero(...a);

// ── Work ─────────────────────────────────────────────────────────────────────
export function workDoc() {
  setSeed('work');
  return [
    pageHero('Selected work / project notes', "A few problems I've helped make clearer.", 'Three project stories from the available source material. No invented numbers, testimonials, or outcomes — just the problem, the implementation, and the experience it was designed to create.'),
    sec('The index', [railLayout('01 / the index', 'The useful details are in the notes.', [
      box([
        box([eyebrow('Three directions'), h('h2', 'Different surfaces. Same standard.', { highlight: 'Same standard.' })]),
        T('From a clearer flooring website to an editable therapist experience and an AI product flow, each project starts with understanding what people need to do next.', ''),
      ], { classNames: 'work-index-heading' }),
      projectGrid(),
    ], 'work-index-section')], S0),
    sec('Note', [noteBand('work-index-footer-note', 'Each project page keeps the source boundaries visible. If a result is not verified, it is not presented as a metric.')], S0),
  ];
}

// ── About ────────────────────────────────────────────────────────────────────
export function aboutDoc() {
  setSeed('about');
  return [
    pageHero('About / how the work is shaped', 'Digital work should feel considered before it feels complicated.', "I'm an independent digital problem solver who combines web development, product thinking, UX, AI, automation, and SEO to make the next useful step clearer."),
    sec('Story', [box([
      box([img(MEDIA.profile, 'Faisal Hossen in a black jacket portrait'), T('The person behind the systems', 'photo-caption')], { classNames: 'about-portrait-wrap' }),
      box([
        sectionHeading('The point of view', 'Start with the problem, not the platform.', site.supporting),
        box([
          T('Good digital work does more than put information on a screen. It helps people understand what is happening, decide what matters, and move forward without unnecessary friction.', ''),
          T('The work can take the form of a website, a product flow, an AI-powered interaction, or a clearer information system. The shape changes with the problem; the standard stays practical, calm, and specific.', ''),
          T(site.capabilities.join(' · '), 'about-capability-note'),
        ], { classNames: 'about-copy-stack' }),
      ], { classNames: 'about-story-copy' }),
    ], { classNames: 'about-story-section' })], S0),
    sec('Principles', [railLayout('01 / working style', 'Simple principles, applied carefully.', [
      sectionHeading('How I work', 'Clarity is a design decision.', 'The goal is not to make every project look the same. It is to give every project a clearer reason for being.'),
      box(principles.map((p) => box([T(p.index, ''), box([h('h2', p.title), T(p.body, '')])], { classNames: 'about-principle-row' })), { classNames: 'about-principle-list' }),
    ], 'about-principles-section')], S0),
    sec('Links', [box([
      box([eyebrow('Keep exploring'), h('h2', 'See the work, or bring a problem.')]),
      box([btn('Explore selected work', '/work'), btn('Go to contact', '/contact', { variant: 'outline' })], { classNames: 'about-links-actions' }),
    ], { classNames: 'about-links-section' })], S0),
  ];
}

// ── Contact ──────────────────────────────────────────────────────────────────
const field = (name, label, o = {}) => blk('core.form_field', { name, label, field_type: o.type || 'text', placeholder: '', required: !!o.required, help_text: '', options: '' }, { classNames: o.cls || 'form-field' });
export function contactDoc() {
  setSeed('contact');
  return [
    pageHero('Contact / start with the problem', 'Have a problem worth solving?', 'Tell me what feels stuck, who it affects, and what a better next step could look like.'),
    sec('Form', [railLayout("01 / let's talk", 'A clear first note is enough.', [
      box([
        eyebrow('Start a conversation'),
        h('h2', "Bring the situation. We'll find the shape.", { highlight: "We'll find the shape." }),
        T("Share a little context and I'll have a better starting point for the conversation. No complicated pitch required.", 'contact-form-lede'),
      ], { classNames: 'contact-form-intro' }),
      blk('core.form', { action: '/contact', method: 'post', form_name: 'contact', submit_text: 'Send message', submit_variant: 'primary' }, {
        classNames: 'contact-form',
        children: [
          field('name', 'Name', { required: true }), field('email', 'Email', { type: 'email', required: true }),
          field('company', 'Company / Website (optional)'), field('subject', 'Subject', { required: true }),
          field('message', 'Message', { type: 'textarea', required: true, cls: 'form-field form-field-full' }),
        ],
      }),
    ], 'contact-work-section')], S0),
    sec('Details', [railLayout('02 / another way in', 'Prefer a direct line?', [
      box([
        box([img(MEDIA.smile, 'Faisal Hossen standing by the sea')], { classNames: 'contact-portrait-wrap' }),
        box([
          eyebrow('Prefer another way?'),
          h('h2', 'A useful conversation can start simply.'),
          box([T('Phone', 'contact-phone-label'), link(contact.phone, contact.phoneHref, { linkStyle: 'plain' })], { classNames: 'contact-phone' }),
          box(contact.socials.map((s) => link(s.label, s.href, { linkStyle: 'plain' })), { classNames: 'contact-socials' }),
        ], { classNames: 'contact-details-copy' }),
      ], { classNames: 'contact-details-card' }),
    ], 'contact-details-section')], S0),
    sec('Note', [noteBand('contact-note-band', 'The form validates in the browser and on the server. Email delivery uses environment-based provider settings; no secret or invented email address is stored in the frontend.')], S0),
  ];
}

// ── Case studies ─────────────────────────────────────────────────────────────
export function caseStudyDoc(p, next) {
  setSeed('case-' + p.slug);
  const external = p.externalUrl
    ? box([link(p.externalLabel, p.externalUrl, { linkStyle: 'plain' })], { classNames: 'external-project-link' })
    : T('No public live URL was supplied for this project.', 'verified-note');
  return [
    sec('Case study hero', [box([
      box([link('Back to selected work', '/work', { linkStyle: 'plain' })], { classNames: 'back-link' }),
      box([box([eyebrow(`Project ${p.number} / ${p.category}`), h('h1', p.title)]), T(p.summary, 'case-study-intro')], { classNames: 'case-study-heading' }),
      box([
        box([T('Type', 'meta-label'), T(p.type, '')]), box([T('Role', 'meta-label'), T(p.role, '')]), box([T('Technology', 'meta-label'), T(p.technology.join(' · '), '')]),
      ], { classNames: 'case-study-meta' }),
      box([img(MEDIA[p.media], p.imageAlt)], { classNames: 'case-study-visual' }),
      external,
    ], { classNames: 'case-study-hero' })], S0),
    sec('Case study body', [box([
      box([eyebrow('The working note'), box([], { classNames: 'rail-rule' }), T('A truthful account of the problem, choices, and delivered experience.', 'rail-caption')], { classNames: 'case-study-rail' }),
      box([
        box([eyebrow(`01 / ${p.challengeLabel}`), h('h2', 'The question was how to make the next step clearer.'), T(p.challenge, 'case-study-text')], { classNames: 'case-study-section' }),
        box([box([eyebrow(`02 / ${p.solutionLabel}`), h('h2', 'The work made the experience easier to understand and use.')]), list(p.solutions, { listStyle: 'check', classNames: 'solution-list' })], { classNames: 'case-study-section case-study-solution' }),
        box([eyebrow(`03 / ${p.approachLabel}`), h('h2', 'A system that holds the important details.'), T(p.approach, 'case-study-text')], { classNames: 'case-study-section case-study-approach' }),
        box([eyebrow('04 / What changed in the experience'), h('h2', 'Less ambiguity. A more deliberate path through the work.'), T(p.experienceChange, 'case-study-text')], { classNames: 'case-study-change' }),
      ], { classNames: 'case-study-content' }),
    ], { classNames: 'case-study-body' })], S0),
    sec('Next project', [box([
      eyebrow('Continue exploring'),
      box([T(next ? `Next project / ${next.number}` : 'Back to the index', 'next-small'), link(next ? next.title : 'Selected work', next ? hrefOf(next) : '/work', { linkStyle: 'plain' })], { classNames: 'next-project-link' }),
    ], { classNames: 'case-study-next' })], S0),
  ];
}

export const EXTRA_PAGES = [
  { slug: 'work', title: 'Selected Work', page_type: 'page', route_mode: 'standalone', doc: workDoc, seo: { description: 'A selection of verified project notes covering web experiences, reusable systems, and interactive product work.' } },
  { slug: 'about', title: 'About Faisal Hossen', page_type: 'page', route_mode: 'standalone', doc: aboutDoc, seo: { description: 'About Faisal Hossen’s practical, independent approach to web development, product thinking, UX, AI, automation, and SEO.' } },
  { slug: 'contact', title: 'Contact Faisal Hossen', page_type: 'page', route_mode: 'standalone', doc: contactDoc, seo: { description: 'Start a conversation with Faisal Hossen about a website, product experience, AI, automation, UX, or SEO problem.' } },
  ...projects.map((p, i) => ({ slug: `work-${p.slug}`, title: p.title, page_type: 'page', route_mode: 'standalone', doc: () => caseStudyDoc(p, projects[i + 1]), seo: { description: p.summary } })),
];
