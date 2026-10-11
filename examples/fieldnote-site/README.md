# Fieldnote, a studio website built with Kohevo Studio

A complete, interactive website for a fictional design and web studio, built entirely from Kohevo Studio blocks:
every heading, paragraph, button, form, tab set and accordion on the nine pages is an ordinary, editable block.
All the copy, names and figures are **sample content**: replace them with your own.

| Page | Studio page (slug) |
| --- | --- |
| Home | `home` (homepage) |
| Work | `work` |
| Case studies | `work-aurora-coffee`, `work-kite-health`, `work-northbound-rail`, `work-maple-and-co` |
| Services | `services` |
| About | `about` |
| Contact | `contact` |
| header / footer | the site-wide `default` header and footer partials |

## What is interactive (no custom JavaScript)

- **Mobile menu**: an Offcanvas drawer with a Nav menu inside.
- **Enquiry form in a modal**: opened from the hero, the call-to-action bands and the other pages.
- **Process tabs**, **FAQ accordions**, a **testimonial carousel** and a live **countdown** to the next project slot.
- **Count-up statistics**, scroll-in animations and hover effects on cards, links and buttons.
- A sticky header and a clickable OpenStreetMap on the contact page.

## How it is put together

- `src/content.mjs`: the copy and project data.
- `src/dsl.mjs`: a tiny helper that writes canonical Studio documents (sections → blocks) with stable ids.
- `src/pages.mjs`: one function per page; each block carries a CSS class (Advanced → CSS classes in the builder).
- `src/css/*.css`: the design (tokens, type, buttons, forms, header, footer, every section, and the 900 px / 560 px
  breakpoints), installed as the site's **Custom CSS**.
- `tools/install.mjs`: creates or updates everything through the builder's own authoring API (`save_tokens`,
  `save_custom_css`, `create_page`, `save_draft`, `publish`) and sets the fonts `<link>` in **Code & tracking**.
  `tools/wipe.mjs` archives every page of a local sandbox first (Studio has no hard delete), `tools/shoot.mjs` takes
  full-page screenshots (desktop 1440 and mobile 390, in readable slices).

Design tokens: paper `#faf6ef`, ink `#1b1713`, accent `#e4572e`, forest `#14231f`, with Fraunces and Inter.

## Install

```bash
npm install
# A local sandbox (see 01-client/plugins/studio-builder/ui/e2e/sandbox.sh): signs in with its throw-away credentials.
FN_BASE=http://localhost:8201 node tools/wipe.mjs      # optional: archive the pages that are there
FN_BASE=http://localhost:8201 node tools/install.mjs

# A real site: a browser window opens and YOU sign in; the script never sees a password.
FN_BASE=https://example.com/site node tools/install.mjs
node tools/install.mjs home work                       # only those slugs
```

Re-running updates the existing pages in place (a new draft revision, then publish).

## Notes

- Needs the Nav menu and Map elements, the modal and drawer runtime fixes, and StudioStylesheet version 9 or later.
- Page slugs are flat (`work-aurora-coffee`, not `work/aurora-coffee`).
- The contact and enquiry forms are Studio form blocks; delivery is whatever the site's form handling provides.
- "Powered by Kohevo" is the platform signature Studio renders on a site without a white-label licence.
