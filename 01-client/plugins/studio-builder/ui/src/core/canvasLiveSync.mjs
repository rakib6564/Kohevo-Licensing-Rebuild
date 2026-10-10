// Kohevo Studio builder — 0ms Real-Time Live Canvas DOM Synchronizer.
//
// In visual site builders, every keystroke in the property panel or style sidebar
// must reflect on the screen in 0 milliseconds without waiting for server round-trips
// or full iframe reloads.
//
// This module inspects working document changes and directly syncs:
//   1. Section surfaces, padding tokens, and colors.
//   2. Block text props (heading, title, subtitle, eyebrow, text, content, buttonText, label, etc.)
//   3. Complex block items (feature lists, cards, columns, portfolio items)
//   4. Block alignment (classes). Every other style key is CSS the server writes (scoped, content-addressed
//      rules), so a change to it repaints the canvas from the server instead of being patched here.
//   5. Breakpoint visibility tokens and animation variables
//
// When changes are non-structural (only props or styles changed), iframe reloads
// are completely suppressed — guaranteeing instantaneous preview response.

import { nodeIds } from './doc.mjs';

const BREAKPOINTS = ['base', 'sm', 'md', 'lg'];
const HIDE_PREFIX = 'sb-hide-';
const ALIGN_PREFIX = 'sb-align-';

function escapeHtml(str) {
  if (typeof str !== 'string') return '';
  return str
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function cssEscape(id) {
  return String(id).replace(/[^a-zA-Z0-9_-]/g, (c) => `\\${c}`);
}

function isPlainObject(v) {
  return Boolean(v) && typeof v === 'object' && !Array.isArray(v);
}

/**
 * Checks whether changes between prevDoc and nextDoc require a full structural
 * iframe reload (e.g. sections or blocks added, removed, reordered, or types changed).
 *
 * If this returns false, the document structure is identical and `syncLiveDOM`
 * has already updated all visuals in 0ms — no iframe reload is needed!
 */
export function isStructuralChange(prevDoc, nextDoc) {
  if (!prevDoc || !nextDoc) return true;
  const prevSections = Array.isArray(prevDoc.sections) ? prevDoc.sections : [];
  const nextSections = Array.isArray(nextDoc.sections) ? nextDoc.sections : [];
  if (prevSections.length !== nextSections.length) return true;

  for (let s = 0; s < nextSections.length; s += 1) {
    const prevSec = prevSections[s];
    const nextSec = nextSections[s];
    if (!prevSec || !nextSec || prevSec.id !== nextSec.id) return true;
    if (differs(prevSec.animation, nextSec.animation) || differs(prevSec.interactions, nextSec.interactions)) return true; // section motion: classes written by the server
    if (differs(prevSec.style, nextSec.style)) return true; // section background/padding: scoped CSS from the server
    if (blocksChanged(prevSec.blocks, nextSec.blocks)) return true;
  }
  return false;
}

const asHighlight = (block) => isPlainObject(block.props) && typeof block.props.highlight === 'string' && block.props.highlight.trim() !== '';

function differs(a, b) {
  return a !== b && JSON.stringify(a === undefined ? null : a) !== JSON.stringify(b === undefined ? null : b);
}

/** A block's style minus `align`, the one style key the live patch applies as classes (everything else is server CSS). */
function styleWithoutAlign(style) {
  if (!isPlainObject(style)) return null;
  const { align, ...rest } = style; // eslint-disable-line no-unused-vars
  return Object.keys(rest).length ? rest : null;
}

/** Block fields whose effect is CSS, a wrapper tag or attributes written only by the server's renderer. */
const SERVER_PAINTED_FIELDS = ['style_states', 'tag', 'classNames', 'attributes'];

/**
 * Blocks whose markup is built only on the server (no heuristic live patch exists for them): any
 * property edit repaints the canvas from the server instead of being patched in place.
 */
export const SERVER_RENDERED_TYPES = new Set(['core.icon', 'core.list', 'core.quote', 'core.link', 'core.card', 'core.table', 'core.countdown']);

/**
 * Types whose heading can carry a highlighted word: the server wraps it in a span, which the text patch
 * (textContent) would erase, so while a highlight is set any prop change repaints from the server.
 */
const HIGHLIGHT_TYPES = new Set(['core.heading', 'core.hero']);

/** The per-device style overrides (`responsive.tablet|mobile.style`): @media rules written only by the server. */
function deviceStyles(block) {
  const r = isPlainObject(block.responsive) ? block.responsive : {};
  return { tablet: isPlainObject(r.tablet) ? r.tablet.style : undefined, mobile: isPlainObject(r.mobile) ? r.mobile.style : undefined };
}

/** Compare two block lists to any depth: ids, types, child counts and the props of server-rendered types. */
function blocksChanged(prev, next) {
  const prevBlocks = Array.isArray(prev) ? prev : [];
  const nextBlocks = Array.isArray(next) ? next : [];
  if (prevBlocks.length !== nextBlocks.length) return true;
  for (let i = 0; i < nextBlocks.length; i += 1) {
    const pb = prevBlocks[i];
    const nb = nextBlocks[i];
    if (!pb || !nb || pb.id !== nb.id || pb.type !== nb.type) return true;
    if (differs(styleWithoutAlign(pb.style), styleWithoutAlign(nb.style))) return true;
    if (SERVER_PAINTED_FIELDS.some((f) => differs(pb[f], nb[f]))) return true;
    if (differs(deviceStyles(pb), deviceStyles(nb))) return true;
    if (HIGHLIGHT_TYPES.has(nb.type) && (asHighlight(pb) || asHighlight(nb)) && differs(pb.props, nb.props)) return true;
    if (SERVER_RENDERED_TYPES.has(nb.type) && pb.props !== nb.props && JSON.stringify(pb.props) !== JSON.stringify(nb.props)) return true;
    if (blocksChanged(pb.children, nb.children)) return true;
  }
  return false;
}

/**
 * Indexes all blocks in a document tree by id.
 */
function indexAllBlocks(doc) {
  const map = new Map();
  const sections = Array.isArray(doc && doc.sections) ? doc.sections : [];
  sections.forEach((s) => {
    const walk = (blocks) => {
      (Array.isArray(blocks) ? blocks : []).forEach((b) => {
        if (b && typeof b.id === 'string') map.set(b.id, b);
        if (b && Array.isArray(b.children)) walk(b.children);
      });
    };
    walk(s && s.blocks);
  });
  return map;
}

/**
 * Synchronize live working document changes directly into the canvas iframe DOM in 0ms.
 *
 * @param {Document} canvasDoc
 * @param {Object} prevDoc
 * @param {Object} nextDoc
 * @param {string} [activeBreakpoint='base']
 * @returns {number} count of updated nodes
 */
export function syncLiveDOM(canvasDoc, prevDoc, nextDoc, activeBreakpoint = 'base') {
  if (!canvasDoc || !canvasDoc.querySelector || !nextDoc) return 0;

  let updateCount = 0;

  try {
    // 1. Sync Sections
    const prevSections = new Map(
      (Array.isArray(prevDoc && prevDoc.sections) ? prevDoc.sections : []).map((s) => [s.id, s])
    );
    const nextSections = Array.isArray(nextDoc.sections) ? nextDoc.sections : [];

    nextSections.forEach((sec) => {
      if (!sec || !sec.id) return;
      const prevSec = prevSections.get(sec.id);
      if (prevSec === sec) return; // Unchanged reference

      const secEl = canvasDoc.querySelector(`[data-sb-node="${cssEscape(sec.id)}"]`);
      if (!secEl) return;

      // Surface token update
      if (sec.surface_token !== (prevSec && prevSec.surface_token)) {
        Array.from(secEl.classList).forEach((cls) => {
          if (cls.startsWith('sb-bg--surface-') || cls.startsWith('sb-bg--accent-')) {
            secEl.classList.remove(cls);
          }
        });
        if (sec.surface_token) {
          secEl.classList.add(`sb-bg--${sec.surface_token}`);
        }
        updateCount += 1;
      }

      // Section padding update
      const padTop = sec.padding_top || 'lg';
      const padBottom = sec.padding_bottom || 'lg';
      const prevPadTop = prevSec ? (prevSec.padding_top || 'lg') : null;
      const prevPadBottom = prevSec ? (prevSec.padding_bottom || 'lg') : null;

      if (padTop !== prevPadTop || padBottom !== prevPadBottom) {
        Array.from(secEl.classList).forEach((cls) => {
          if (/^sb-(py|pt|pb)-/.test(cls)) {
            secEl.classList.remove(cls);
          }
        });
        if (padTop === padBottom) {
          secEl.classList.add(`sb-py-${padTop}`);
        } else {
          secEl.classList.add(`sb-pt-${padTop}`, `sb-pb-${padBottom}`);
        }
        updateCount += 1;
      }
    });

    // 2. Sync Blocks
    const prevBlocks = indexAllBlocks(prevDoc);
    const nextBlocks = indexAllBlocks(nextDoc);

    for (const [id, nextBlock] of nextBlocks) {
      const prevBlock = prevBlocks.get(id);
      if (prevBlock === nextBlock) continue;

      if (SERVER_RENDERED_TYPES.has(nextBlock.type)) continue; // repainted from the server (see isStructuralChange)
      if (HIGHLIGHT_TYPES.has(nextBlock.type) && (asHighlight(nextBlock) || (prevBlock && asHighlight(prevBlock)))) continue;

      const el = canvasDoc.querySelector(`[data-sb-node="${cssEscape(id)}"]`);
      if (!el) continue;

      const nextProps = isPlainObject(nextBlock.props) ? nextBlock.props : {};
      const prevProps = prevBlock && isPlainObject(prevBlock.props) ? prevBlock.props : {};
      const nextStyle = isPlainObject(nextBlock.style) ? nextBlock.style : {};
      const prevStyle = prevBlock && isPlainObject(prevBlock.style) ? prevBlock.style : {};

      // ── TEXT & CONTENT PROPS ──
      // Headings & Titles (checks heading, title, or text for heading block types)
      const isHeadingBlock = nextBlock.type === 'core.heading' || (typeof nextBlock.type === 'string' && nextBlock.type.includes('heading'));
      const titleVal = nextProps.heading !== undefined ? nextProps.heading
        : (nextProps.title !== undefined ? nextProps.title : (isHeadingBlock ? nextProps.text : undefined));

      if (titleVal !== undefined) {
        const titleEl = el.querySelector(
          '.sb-hero__heading, .sb-feature-list__title, .sb-heading, h1, h2, h3, h4, h5, h6, [class*="heading"], [class*="title"]'
        ) || (/^h[1-6]$/i.test(el.tagName) ? el : null);
        if (titleEl && titleEl.textContent !== titleVal) {
          titleEl.textContent = titleVal;
          updateCount += 1;
        }
      }

      // Eyebrow / Badges
      const eyebrowVal = nextProps.eyebrow !== undefined ? nextProps.eyebrow : nextProps.badge;
      if (eyebrowVal !== undefined && eyebrowVal !== (prevProps.eyebrow || prevProps.badge)) {
        const eyeEl = el.querySelector('.sb-hero__eyebrow, [class*="eyebrow"], [class*="badge"]');
        if (eyeEl) {
          eyeEl.textContent = eyebrowVal;
          updateCount += 1;
        }
      }

      // Subheading / Subtitle / Lead
      const subVal = nextProps.subheading !== undefined ? nextProps.subheading : nextProps.subtitle;
      if (subVal !== undefined && subVal !== (prevProps.subheading || prevProps.subtitle)) {
        const subEl = el.querySelector('.sb-hero__subheading, [class*="subheading"], [class*="subtitle"], .sb-lead');
        if (subEl) {
          subEl.textContent = subVal;
          updateCount += 1;
        }
      }

      // Body text / paragraph / content
      const textVal = nextProps.text !== undefined ? nextProps.text : (nextProps.content !== undefined ? nextProps.content : nextProps.body);
      const prevTextVal = prevProps.text !== undefined ? prevProps.text : (prevProps.content !== undefined ? prevProps.content : prevProps.body);
      if (textVal !== undefined && textVal !== prevTextVal) {
        const textEl = el.querySelector('.sb-text, .sb-feature__body, p, [class*="description"], [class*="content"]')
          || (/^h[1-6]|p|span|a|button$/i.test(el.tagName) ? el : null);
        if (textEl) {
          textEl.textContent = textVal;
          updateCount += 1;
        } else if (!el.children || el.children.length === 0) {
          el.textContent = textVal;
          updateCount += 1;
        }
      }

      // Button text / Label
      const btnVal = nextProps.buttonText !== undefined ? nextProps.buttonText : nextProps.label;
      const prevBtnVal = prevProps.buttonText !== undefined ? prevProps.buttonText : prevProps.label;
      if (btnVal !== undefined && btnVal !== prevBtnVal) {
        const btnEl = el.querySelector('.sb-button, .sb-btn, a, button, [class*="button"]');
        if (btnEl) {
          btnEl.textContent = btnVal;
          updateCount += 1;
        }
      }

      // Links / URLs
      if (nextProps.url !== undefined && nextProps.url !== prevProps.url) {
        const linkEl = el.querySelector('a') || (el.tagName.toLowerCase() === 'a' ? el : null);
        if (linkEl) {
          linkEl.setAttribute('href', nextProps.url);
          updateCount += 1;
        }
      }

      // Primary CTA
      if (nextProps.primary_cta && typeof nextProps.primary_cta === 'object') {
        const ctaEl = el.querySelector('.sb-button-row a, .sb-button, a');
        if (ctaEl) {
          if (nextProps.primary_cta.label) ctaEl.textContent = nextProps.primary_cta.label;
          if (nextProps.primary_cta.url) ctaEl.setAttribute('href', nextProps.primary_cta.url);
          updateCount += 1;
        }
      }

      // List / Card / Feature items (core.feature_list, portfolios, grids)
      if (Array.isArray(nextProps.items)) {
        const cardEls = el.querySelectorAll('.sb-feature, .sb-feature-list__items > li, .sb-card, [class*="feature"], [class*="card"], li');
        nextProps.items.forEach((item, idx) => {
          const cardEl = cardEls[idx];
          if (!cardEl || !item || typeof item !== 'object') return;
          if (item.heading !== undefined || item.title !== undefined) {
            const h = cardEl.querySelector('.sb-feature__heading, h2, h3, h4, h5, [class*="heading"], [class*="title"]');
            if (h) h.textContent = item.heading || item.title || '';
          }
          if (item.body !== undefined || item.description !== undefined || item.text !== undefined) {
            const b = cardEl.querySelector('.sb-feature__body, p, [class*="body"], [class*="description"], [class*="text"]');
            if (b) b.textContent = item.body || item.description || item.text || '';
          }
          if (item.url !== undefined) {
            const a = cardEl.querySelector('.sb-feature__link, a');
            if (a) a.setAttribute('href', item.url);
          }
        });
        updateCount += 1;
      }

      // Grid columns
      if (nextProps.columns !== undefined && nextProps.columns !== prevProps.columns) {
        const listEl = el.querySelector('.sb-feature-list__items, [class*="items"]');
        if (listEl) {
          Array.from(listEl.classList).forEach((c) => {
            if (c.startsWith('sb-md-cols-') || c.startsWith('sb-cols-')) listEl.classList.remove(c);
          });
          listEl.classList.add('sb-cols-1', `sb-md-cols-${nextProps.columns}`);
          updateCount += 1;
        }
      }

      // Image / Media
      const srcVal = typeof nextProps.media === 'string'
        ? nextProps.media
        : (nextProps.media && nextProps.media.url ? nextProps.media.url : (nextProps.src || nextProps.image));
      if (srcVal) {
        const imgEl = el.querySelector('img') || (el.tagName.toLowerCase() === 'img' ? el : null);
        if (imgEl && imgEl.src !== srcVal) {
          imgEl.src = srcVal;
          updateCount += 1;
        }
      }

      // Rakib Portfolio custom showcase widget
      if (nextBlock.type === 'portfolio.rakib_showcase') {
        if (nextProps.brand_text) {
          const pf = el.querySelector('.e-pf');
          if (pf) pf.textContent = nextProps.brand_text;
        }
        if (nextProps.first_name || nextProps.last_name) {
          const name = el.querySelector('.e-name');
          if (name) name.innerHTML = `${escapeHtml(nextProps.first_name || 'rakib')}<br>${escapeHtml(nextProps.last_name || 'hasan')}`;
        }
        if (nextProps.role) {
          const role = el.querySelector('.e-role');
          if (role) role.innerHTML = escapeHtml(nextProps.role).replace(/\n/g, '<br>');
        }
        if (nextProps.location) {
          const loc = el.querySelector('.e-loc');
          if (loc) loc.innerHTML = escapeHtml(nextProps.location).replace(/\n/g, '<br>');
        }
        if (nextProps.coordinates) {
          const coord = el.querySelector('.e-coord');
          if (coord) coord.innerHTML = escapeHtml(nextProps.coordinates).replace(/\n/g, '<br>');
        }
        if (nextProps.domain) {
          const dom = el.querySelector('.e-dom');
          if (dom) dom.textContent = nextProps.domain;
        }
        if (nextProps.email) {
          const mail = el.querySelector('.e-mail');
          if (mail) mail.textContent = nextProps.email;
        }
        updateCount += 1;
      }

      // ── STYLES ──
      // Alignment
      const align = nextStyle.align;
      if (align && typeof align === 'object') {
        Array.from(el.classList).forEach((c) => {
          if (c.startsWith(ALIGN_PREFIX) || c.startsWith('sb-heading--align-') || c.startsWith('sb-text--align-')) {
            el.classList.remove(c);
          }
        });
        Object.entries(align).forEach(([bp, val]) => {
          if (val) {
            el.classList.add(`${ALIGN_PREFIX}${bp}-${val}`);
            el.classList.add(`sb-heading--align-${val}`);
            el.classList.add(`sb-text--align-${val}`);
          }
        });
        const activeVal = align[activeBreakpoint] || align.base || 'left';
        el.style.textAlign = activeVal;
        updateCount += 1;
      }

      // Visibility devices
      if (nextBlock.visibility && Array.isArray(nextBlock.visibility.devices)) {
        BREAKPOINTS.forEach((bp) => {
          const cls = `${HIDE_PREFIX}${bp}`;
          if (!nextBlock.visibility.devices.includes(bp)) {
            el.classList.add(cls);
          } else {
            el.classList.remove(cls);
          }
        });
      }
    }
  } catch (err) {
    // Fail-safe: a sync error must never disrupt the editor
    if (typeof console !== 'undefined' && console.warn) {
      console.warn('syncLiveDOM failed:', err);
    }
  }

  return updateCount;
}

/**
 * Node ids the canvas shows that the document does not have. The live patch and the server's repaint can
 * disagree (a change the patch cannot express, a patch that threw, a reload that never landed); a node the
 * editor cannot find is the one sure sign, because the server only tags nodes that are in the document.
 */
export function ghostNodeIds(domIds, doc) {
  const known = nodeIds(doc);
  return domIds.filter((id) => id && !known.has(id));
}
