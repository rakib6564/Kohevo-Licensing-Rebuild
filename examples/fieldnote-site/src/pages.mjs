import { blk, sec, text, h, btn, link, box, flex, grid, list, rich, setSeed } from './dsl.mjs';
import { site, nav, projects, services, process, testimonials, faq, team, values } from './content.mjs';

export const fade = { type: 'fade_up' };
export const S0 = { padding_y: 'none', width: 'full', columns: { base: 1, md: 1 } };   // the inner .shell carries the gutters
const DARK = { ...S0, background_token: 'surface.inverse' };
const SAND = { ...S0, background_token: 'surface.secondary' };
export const T = (c, cls, o = {}) => text(c, { classNames: cls, ...o });
export const eyebrow = (c, extra = '') => T(c, `eyebrow ${extra}`.trim());
const shell = (children, cls = '', o = {}) => box(children, { classNames: `shell ${cls}`.trim(), ...o });
const head = (eye, title, body, highlight = '', cls = '') => box([eyebrow(eye), h('h2', title, { highlight }), ...(body ? [T(body, 'lede')] : [])], { classNames: `section-head ${cls}`.trim(), animation: fade });
const arrowLink = (label, href, cls = '') => link(label, href, { linkStyle: 'arrow', classNames: cls });
const field = (name, label, o = {}) => blk('core.form_field', { name, label, field_type: o.type || 'text', placeholder: o.ph || '', required: !!o.required, help_text: '', options: '' }, { classNames: o.cls || 'form-field' });
const startForm = (name) => blk('core.form', { action: '/contact', method: 'post', form_name: name, submit_text: 'Send enquiry', submit_variant: 'primary' }, {
  classNames: 'enquiry-form',
  children: [
    field('name', 'Your name', { required: true, ph: 'Ada Lovelace' }),
    field('email', 'Email', { type: 'email', required: true, ph: 'you@company.com' }),
    field('company', 'Company or project', { ph: 'What are we working on?' }),
    field('message', 'How can we help?', { type: 'textarea', required: true, ph: 'A few lines about the project, timing and budget is perfect.', cls: 'form-field form-field--wide' }),
  ],
});
const modalCta = (id, label = 'Start a project', variant = 'primary') => blk('core.modal', { modal_id: id, title: 'Tell us about your project', size: 'md', trigger_text: label, trigger_variant: variant }, {
  classNames: 'cta-modal',
  children: [T('We reply within one working day, usually sooner.', 'modal-note'), startForm(`enquiry_${id}`)],
});

// ── project cards ───────────────────────────────────────────────────────────
const projectCard = (p, big = false) => box([
  box([T(p.tags.join(' · '), 'cover-tags'), T(p.name.slice(0, 1), 'cover-mark')], { classNames: `cover ${p.cover}` }),
  box([
    box([h('h3', p.name), T(p.year, 'project-year')], { classNames: 'project-title' }),
    T(p.kind, 'project-kind'),
    arrowLink('View case study', `/${p.slug}`, 'project-link'),
  ], { classNames: 'project-body' }),
], { classNames: `project-card${big ? ' project-card--big' : ''}`, animation: fade });

// ── header / footer partials ────────────────────────────────────────────────
export function headerDoc() {
  setSeed('header');
  return [sec('Header', [
    shell([
      box([link(site.name, '/', { linkStyle: 'plain' })], { classNames: 'wordmark' }),
      blk('core.nav_menu', { aria_label: 'Main', layout: 'horizontal', align: 'left', style: 'plain', items: nav.map(([label, url]) => ({ label, url })) }, { classNames: 'desktop-nav' }),
      box([btn('Start a project', '/contact', { size: 'sm' })], { classNames: 'header-cta' }),
      box([blk('layout.offcanvas', { drawer_id: 'site-menu', title: site.name, position: 'right', trigger_text: 'Menu', trigger_variant: 'outline' }, {
        children: [
          blk('core.nav_menu', { aria_label: 'Mobile', layout: 'vertical', align: 'left', style: 'plain', items: [{ label: 'Home', url: '/' }, ...nav.map(([label, url]) => ({ label, url }))] }, { classNames: 'drawer-nav' }),
          btn('Start a project', '/contact', { classNames: 'drawer-cta' }),
          T(site.email, 'drawer-mail'),
        ],
      })], { classNames: 'header-menu' }),
    ], 'header-inner'),
  ], S0)];
}

export function footerDoc() {
  setSeed('footer');
  return [sec('Footer', [
    shell([
      box([
        box([box([link(site.name, '/', { linkStyle: 'plain' })], { classNames: 'wordmark footer-wordmark' }), T('A small, senior studio for brands and websites people remember.', 'footer-note')], { classNames: 'footer-brand' }),
        box([T('Studio', 'footer-head'), ...nav.map(([label, url]) => link(label, url, { linkStyle: 'plain' }))], { classNames: 'footer-col' }),
        box([T('Say hello', 'footer-head'), link(site.email, `mailto:${site.email}`, { linkStyle: 'plain' }), T(site.city, 'footer-city'), link('Book an intro call', '/contact', { linkStyle: 'arrow' })], { classNames: 'footer-col' }),
        box([T('Booking now', 'footer-head'), T('Taking projects from January. Reserve your slot early.', 'footer-slot'), btn('Check availability', '/contact', { variant: 'outline', size: 'sm' })], { classNames: 'footer-col footer-col--cta' }),
      ], { classNames: 'footer-top' }),
      box([T('© 2026 Fieldnote Studio. Sample site built with Kohevo Studio.', ''), T('Made slowly, in Antwerp.', '')], { classNames: 'footer-bottom' }),
    ], 'footer-shell'),
  ], DARK)];
}

// ── Home ────────────────────────────────────────────────────────────────────
export function homeDoc() {
  setSeed('home');
  const hero = sec('Hero', [
    shell([
      box([
        eyebrow(`${site.tagline} · ${site.city}`, 'dot'),
        h('h1', 'Websites and brands people remember.', { highlight: 'people remember.' }),
        T('Fieldnote is a small, senior studio. We shape the idea, design the look and build the site, so your team can run it long after we leave.', 'hero-supporting'),
        box([modalCta('start-home'), btn('See our work', '/work', { variant: 'outline' })], { classNames: 'hero-actions' }),
      ], { classNames: 'hero-copy', animation: fade }),
      box([
        T('Studio availability', 'slot-label'),
        T('Next project slot opens in', 'slot-line'),
        blk('core.countdown', { target: site.openTarget, label: '', done_text: 'We are taking new projects now.' }, { classNames: 'slot-countdown' }),
        box([T('Brand', 'chip'), T('Websites', 'chip'), T('Product', 'chip'), T('Content', 'chip')], { classNames: 'chips' }),
      ], { classNames: 'hero-slot', animation: { type: 'scale_up' } }),
    ], 'hero'),
  ], S0);

  const proof = sec('Proof', [
    shell([
      blk('core.stats', { items: [
        { value: 9, label: 'Years in practice', prefix: '', suffix: '', decimals: 0 },
        { value: 64, label: 'Sites and brands launched', prefix: '', suffix: '+', decimals: 0 },
        { value: 4, label: 'Weeks to a typical launch', prefix: '', suffix: '', decimals: 0 },
        { value: 98, label: 'Clients who return', prefix: '', suffix: '%', decimals: 0 },
      ] }, { classNames: 'proof-stats', animation: fade }),
    ], 'proof'),
  ], S0);

  const work = sec('Selected work', [
    shell([
      head('Selected work', 'Recent projects, told plainly.', 'Four small stories about what we were asked, what we changed and what happened next.', 'told plainly.'),
      box(projects.map((p, i) => projectCard(p, i === 0)), { classNames: 'project-grid' }),
      box([arrowLink('All projects', '/work')], { classNames: 'section-more' }),
    ], 'block'),
  ], S0);

  const svc = sec('Services', [
    shell([
      head('What we do', 'Four things, done carefully.', 'Pick one, or let us take the whole thing from first sketch to launch day.', 'done carefully.', 'on-dark'),
      box(services.map((s) => box([
        T(s.n, 'svc-n'),
        box([h('h3', s.name), T(s.line, '')], { classNames: 'svc-text' }),
        box([], { classNames: 'svc-arrow' }),
      ], { classNames: 'svc-row' })), { classNames: 'svc-list', animation: fade }),
      box([arrowLink('Explore services', '/services', 'on-dark-link')], { classNames: 'section-more' }),
    ], 'block on-dark'),
  ], DARK);

  const proc = sec('Process', [
    shell([
      head('How we work', 'A calm process with no surprises.', 'Four stages, each with something you can see and react to. Tap through them.', 'no surprises.'),
      box([blk('core.tabs', { items: process.map(([label, content]) => ({ label, content })), options: { position: 'top' } })], { classNames: 'process-tabs', animation: fade }),
    ], 'block'),
  ], SAND);

  const voices = sec('Kind words', [
    shell([
      head('Kind words', 'What it is like to work with us.', '', 'work with us.'),
      box([blk('core.carousel', { slides: testimonials.map(([quote, author]) => ({ image: null, quote, author, caption: '' })), options: { autoplay_ms: 7000, loop: true, per_view: 1 } })], { classNames: 'voices', animation: fade }),
    ], 'block'),
  ], S0);

  const cta = sec('Call to action', [
    shell([
      box([
        eyebrow('Start here', 'on-dark'),
        h('h2', 'Have something worth building? Let us talk.', { highlight: 'Let us talk.' }),
        T('Tell us a little about it. You will hear back within a working day with a plan and a fixed price.', 'lede on-dark'),
        box([modalCta('start-cta'), link(site.email, `mailto:${site.email}`, { linkStyle: 'arrow', classNames: 'on-dark-link' })], { classNames: 'cta-actions' }),
      ], { classNames: 'cta-band', animation: fade }),
    ], 'cta'),
  ], DARK);

  return [hero, proof, work, svc, proc, voices, cta];
}

// ── Work index ──────────────────────────────────────────────────────────────
export function workDoc() {
  setSeed('work');
  return [
    sec('Page header', [shell([eyebrow('Work', 'dot'), h('h1', 'Selected projects.', { highlight: 'projects.' }), T('A few recent projects from brand, web and product. Each one began with a good question.', 'lede')], 'page-head', { animation: fade })], S0),
    sec('Projects', [shell([box(projects.map((p, i) => projectCard(p, i === 0)), { classNames: 'project-grid' })], 'block block-after')], S0),
    sec('Call to action', [shell([box([h('h2', 'Your project could be next.', { highlight: 'could be next.' }), T('We take on a handful of projects each quarter.', 'lede on-dark'), box([modalCta('start-work'), btn('Meet the studio', '/about', { variant: 'outline' })], { classNames: 'cta-actions' })], { classNames: 'cta-band', animation: fade })], 'cta')], DARK),
  ];
}

// ── Case studies ────────────────────────────────────────────────────────────
export function caseDoc(p, i) {
  setSeed(`case-${p.slug}`);
  const next = projects[(i + 1) % projects.length];
  return [
    sec('Case study header', [shell([
      eyebrow(`Case study · ${p.tags.join(' · ')}`, 'dot'),
      h('h1', p.name),
      T(p.summary, 'lede'),
    ], 'page-head', { animation: fade })], S0),
    sec('Cover', [shell([box([T(p.tags.join(' · '), 'cover-tags'), T(p.kind, 'cover-kind'), T(p.name.slice(0, 1), 'cover-mark')], { classNames: `cover cover--hero ${p.cover}` })], 'block-tight')], S0),
    sec('Facts', [shell([box([
      box([T('Client', 'fact-k'), T(p.name, 'fact-v')], { classNames: 'fact' }),
      box([T('Year', 'fact-k'), T(p.year, 'fact-v')], { classNames: 'fact' }),
      box([T('Role', 'fact-k'), T(p.role, 'fact-v')], { classNames: 'fact' }),
      box([T('Built with', 'fact-k'), T(p.stack, 'fact-v')], { classNames: 'fact' }),
    ], { classNames: 'facts' })], 'block-tight')], S0),
    sec('Challenge', [shell([box([eyebrow('The challenge'), h('h2', 'What was in the way.'), T(p.challenge, 'lede')], { classNames: 'narrow', animation: fade })], 'block')], S0),
    sec('Approach', [shell([
      head('Our approach', 'Three steps, in order.', '', 'in order.'),
      box(p.steps.map(([title, body], n) => box([T(String(n + 1).padStart(2, '0'), 'step-n'), h('h3', title), T(body, '')], { classNames: 'step-card', animation: fade })), { classNames: 'steps' }),
    ], 'block')], SAND),
    sec('Outcome', [shell([
      head('The outcome', 'What changed.', 'Sample figures for illustration. Replace them with your own results.', 'changed.', 'on-dark'),
      box(p.results.map(([value, label]) => box([T(value, 'result-v'), T(label, 'result-l')], { classNames: 'result', animation: fade })), { classNames: 'results' }),
      box([blk('core.quote', { text: p.quote[0], author: p.quote[1], role: '', align: 'left' }, { classNames: 'case-quote' })], { classNames: 'quote-wrap' }),
    ], 'block on-dark')], DARK),
    sec('Next project', [shell([box([T('Next project', 'eyebrow'), h('h2', next.name), T(next.kind, 'lede'), box([btn('View case study', `/${next.slug}`), btn('All work', '/work', { variant: 'outline' })], { classNames: 'cta-actions' })], { classNames: 'next-band', animation: fade })], 'block')], S0),
  ];
}

// ── Services ────────────────────────────────────────────────────────────────
export function servicesDoc() {
  setSeed('services');
  return [
    sec('Page header', [shell([eyebrow('Services', 'dot'), h('h1', 'Everything a launch needs.', { highlight: 'a launch needs.' }), T('Brand, website, product and care. Work with us on one, or on all four.', 'lede')], 'page-head', { animation: fade })], S0),
    sec('Services', [shell([box(services.map((s) => box([
      box([T(s.n, 'svc-n'), h('h2', s.name), T(s.line, 'lede')], { classNames: 'svc-detail-head' }),
      list(s.points, { listStyle: 'check', classNames: 'svc-points' }),
    ], { classNames: 'svc-detail', animation: fade })), { classNames: 'svc-details' })], 'block block-after')], S0),
    sec('Packages', [shell([
      head('Packages', 'Fixed prices, clear scope.', 'Most projects fit one of these. If yours does not, we will say so and suggest something better.', 'clear scope.'),
      box([blk('core.table', { caption: 'Studio packages', header: 'Package | From | Timeline | Includes', striped: true, rows: [
        { cells: 'Focused site | €4,500 | 4 weeks | Up to 6 pages, copy support, launch' },
        { cells: 'Brand + site | €9,000 | 8 weeks | Identity, up to 10 pages, guidelines' },
        { cells: 'Product design | €12,000 | 6–10 weeks | Research, prototypes, design system' },
        { cells: 'Care plan | €450 / month | Ongoing | Updates, speed and accessibility checks' },
      ] })], { classNames: 'packages', animation: fade }),
    ], 'block')], SAND),
    sec('Process', [shell([
      head('Process', 'How a project runs.', '', 'a project runs.'),
      box([blk('core.tabs', { items: process.map(([label, content]) => ({ label, content })), options: { position: 'top' } })], { classNames: 'process-tabs', animation: fade }),
    ], 'block')], S0),
    sec('FAQ', [shell([
      head('Questions', 'Things people ask first.', '', 'ask first.'),
      box([blk('core.accordion', { items: faq.map(([heading, body]) => ({ heading, body })), allow_multiple: false })], { classNames: 'faq', animation: fade }),
    ], 'block')], S0),
    sec('Call to action', [shell([box([h('h2', 'Not sure what you need? Start with a chat.', { highlight: 'Start with a chat.' }), T('A 30-minute call is free and you leave with a clear next step.', 'lede on-dark'), box([modalCta('start-services'), btn('Contact', '/contact', { variant: 'outline' })], { classNames: 'cta-actions' })], { classNames: 'cta-band', animation: fade })], 'cta')], DARK),
  ];
}

// ── About ───────────────────────────────────────────────────────────────────
export function aboutDoc() {
  setSeed('about');
  return [
    sec('Page header', [shell([eyebrow('About', 'dot'), h('h1', 'A small studio with a long memory.', { highlight: 'a long memory.' }), T('Fieldnote began in 2017 as one designer and one laptop. Today we are four people who would rather do fewer projects well.', 'lede')], 'page-head', { animation: fade })], S0),
    sec('Story', [shell([
      box([eyebrow('Our story'), h('h2', 'Good work is mostly good listening.'), ], { classNames: 'story-head', animation: fade }),
      box([T('We started Fieldnote after years inside agencies where the person you pitched to was never the person doing the work. We wanted the opposite: senior people, close to the project, with time to think.', ''), T('That is still the whole idea. We keep the team small, take a handful of projects a quarter, and build sites that clients can run without us.', '')], { classNames: 'story-body', animation: fade }),
    ], 'block block-after two-col')], S0),
    sec('Values', [shell([
      head('What we believe', 'Four habits we keep.', '', 'we keep.'),
      box(values.map(([title, body]) => box([h('h3', title), T(body, '')], { classNames: 'value-card', animation: fade })), { classNames: 'values' }),
    ], 'block')], SAND),
    sec('Team', [shell([
      head('The studio', 'Meet the team.', 'Four people, one table, no account managers.', 'the team.'),
      box(team.map(([name, role, initials]) => box([T(initials, 'avatar'), h('h3', name), T(role, 'team-role')], { classNames: 'team-card', animation: fade })), { classNames: 'team' }),
    ], 'block')], S0),
    sec('Numbers', [shell([blk('core.stats', { items: [
      { value: 2017, label: 'Founded in Antwerp', prefix: '', suffix: '', decimals: 0 },
      { value: 64, label: 'Projects shipped', prefix: '', suffix: '+', decimals: 0 },
      { value: 11, label: 'Countries worked in', prefix: '', suffix: '', decimals: 0 },
      { value: 4, label: 'People, no account managers', prefix: '', suffix: '', decimals: 0 },
    ] }, { classNames: 'proof-stats on-dark', animation: fade })], 'proof on-dark')], DARK),
    sec('Call to action', [shell([box([h('h2', 'Come and say hello.', { highlight: 'say hello.' }), T('We love a good first conversation. Tell us what you are making.', 'lede'), box([modalCta('start-about'), btn('Contact details', '/contact', { variant: 'outline' })], { classNames: 'cta-actions' })], { classNames: 'next-band', animation: fade })], 'block')], S0),
  ];
}

// ── Contact ─────────────────────────────────────────────────────────────────
export function contactDoc() {
  setSeed('contact');
  return [
    sec('Page header', [shell([eyebrow('Contact', 'dot'), h('h1', 'Tell us what you are making.', { highlight: 'what you are making.' }), T('A few lines is plenty. You will hear back within one working day.', 'lede')], 'page-head', { animation: fade })], S0),
    sec('Form and details', [shell([
      box([blk('core.form', { action: '/contact', method: 'post', form_name: 'contact', submit_text: 'Send message', submit_variant: 'primary' }, {
        classNames: 'enquiry-form enquiry-form--page',
        children: [
          field('name', 'Your name', { required: true, ph: 'Ada Lovelace' }),
          field('email', 'Email', { type: 'email', required: true, ph: 'you@company.com' }),
          field('company', 'Company or project', { ph: 'What are we working on?' }),
          field('budget', 'Rough budget', { ph: 'For example €5,000 to €10,000' }),
          field('message', 'How can we help?', { type: 'textarea', required: true, ph: 'Goals, timing, anything that helps.', cls: 'form-field form-field--wide' }),
        ],
      })], { classNames: 'form-card', animation: fade }),
      box([
        box([T('Email', 'fact-k'), link(site.email, `mailto:${site.email}`, { linkStyle: 'plain' })], { classNames: 'contact-item' }),
        box([T('Studio', 'fact-k'), T(`${site.city}. Visits by appointment.`, '')], { classNames: 'contact-item' }),
        box([T('Hours', 'fact-k'), T('Monday to Thursday, 9:00 to 17:30 CET.', '')], { classNames: 'contact-item' }),
        box([blk('core.map', { latitude: site.lat, longitude: site.lon, zoom: 14, height: 'md', title: 'Fieldnote studio location' })], { classNames: 'map-wrap' }),
      ], { classNames: 'contact-side', animation: fade }),
    ], 'block block-after two-col contact-grid')], S0),
    sec('FAQ', [shell([
      head('Before you write', 'Quick answers.', '', 'Quick answers.'),
      box([blk('core.accordion', { items: faq.map(([heading, body]) => ({ heading, body })), allow_multiple: true })], { classNames: 'faq', animation: fade }),
    ], 'block')], SAND),
  ];
}

export const EXTRA_PAGES = [
  { slug: 'work', title: 'Work — Fieldnote', doc: workDoc, seo: { description: 'Selected brand, web and product projects from Fieldnote Studio.' } },
  ...projects.map((p, i) => ({ slug: p.slug, title: `${p.name} — Fieldnote`, doc: () => caseDoc(p, i), seo: { description: p.summary } })),
  { slug: 'services', title: 'Services — Fieldnote', doc: servicesDoc, seo: { description: 'Brand identity, websites, product design and care from a small senior studio.' } },
  { slug: 'about', title: 'About — Fieldnote', doc: aboutDoc, seo: { description: 'Fieldnote is a four-person independent design and web studio in Antwerp.' } },
  { slug: 'contact', title: 'Contact — Fieldnote', doc: contactDoc, seo: { description: 'Tell us about your project. We reply within one working day.' } },
];
