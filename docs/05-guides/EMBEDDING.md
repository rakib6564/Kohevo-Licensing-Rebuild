# Embedding the booking widget on another website

The public booking flow (`/book/`) can be shown inside an `<iframe>` on any site you control — for example a
WordPress page — while sign-in, confirmation and payment safely happen on the Kohevo domain.

## Why steps leave the iframe

Browsers do not send a third-party site's cookies inside a cross-site iframe. Kohevo's session cookie
(`SLATE_SID`, `SameSite=Lax`, `Secure`, `HttpOnly`) is therefore missing in the frame, which breaks anything that
needs a session or a CSRF token: the member login page would load *inside* the frame and a booking submit would
fail with **"Security check failed"**.

So the widget is split:

| Step | Where it runs | Needs a session? |
|---|---|---|
| Choose service, provider, date and time slot | **inside the iframe** | no |
| Details / confirm / pay, member login | **top-level window** on the Kohevo domain (`target="_top"`) | yes |
| Success and "payment done" pages | top-level, with a **← Back to the site** link | — |

Entry points that would otherwise render a session step in a frame call `bookpub_break_out()`, which navigates
the top window (`window.top.location`) to the same step, carrying the `return` address. A CSRF failure inside a
frame breaks out the same way instead of showing a dead-end error.

## Set up

1. **Allow the embedding site.** *Admin → Booking → Settings → Embedding on other websites* (French:
   *Intégration sur d'autres sites*). Enter one origin per line, e.g. `https://www.example.com`.
   - `https://` only, exact origin: scheme + host (+ port). No path, no wildcard, no credentials.
   - `https://example.com` and `https://www.example.com` are **different** origins — list both if you use both.
   - The value is stored in the `embed_allowed_origins` setting (database), so a **fresh install must set it again**.
2. **Add the embed code** on the embedding site. The exact, ready-to-paste code is in the admin
   (*Booking → dashboard → Public booking*, and *Booking → Settings → Embedding on other websites*, both with a **Copy**
   button):

   ```html
   <iframe src="https://app.customer.com/book?embed=1"
           data-kohevo-booking
           title="Book an appointment"
           style="width:100%;min-height:560px;border:0;display:block"
           loading="lazy"></iframe>
   <script src="https://app.customer.com/plugins/booking/assets/js/embed.js" async></script>
   ```

   - `embed=1` removes the outer page chrome and footer.
   - `data-kohevo-booking` marks the iframe for the helper script (an iframe whose `src` is this site's `/book…` is
     also recognised, so older embeds keep working once the script is added).
   - **Keep both lines.** Without the script the iframe stays at its fixed `min-height` and taller steps are cut off.
   - Optional: `&chrome=0` on the `src` gives a flat look (no card border, shadow or padding) for hosts that draw
     their own frame. `&return=<encoded page URL>` sets where the *Back to the site* link goes; it is validated
     against the allow-list, so it can not be used as an open redirect.

## Sizing: how the iframe fits its content

A cross-origin iframe can not measure itself, so the two sides cooperate:

1. The widget measures its own shell and posts `{type:"kohevo-booking-height", height, first}` to the parent, on load,
   on resize and whenever content changes (`bookpub_embed_reporter_js()` in `plugins/booking/public/router.php`).
   It measures the shell, **not** `<body>`/`<html>`: this app's CSS makes those fill the viewport, so they mirror the
   iframe's current height and the frame could never shrink again.
2. `assets/js/embed.js` on the host page applies the height. It only trusts a message that comes from that iframe's
   own window **and** origin, clamps heights to 50–20 000 px, and never writes message content into the page.
3. On the first message after each page load the script replies `{type:"kohevo-booking-host"}`; only then does the
   widget hide its own scrollbar, so a host that forgot the script never gets a clipped widget.
4. When the visitor moves to the next step (a new page inside the frame) and the widget's top is scrolled out of view,
   the script scrolls it back into view (smoothly, unless the visitor prefers reduced motion). It does not move the
   page when the widget is already visible.

The embedded card is centred and capped in width (760 px, 900 px from a 720 px frame), with the calendar and the times
side by side on wide frames and stacked on phones. `tests/unit/BookingEmbedAutoHeightTest.php` executes `embed.js` in
a sandbox to prove the origin/source/clamp rules.

## Frame policy (what the server sends)

| Pages | `Content-Security-Policy: frame-ancestors` | `X-Frame-Options` |
|---|---|---|
| Everything by default (admin, portal, login, …) | `'self'` | `SAMEORIGIN` |
| Public booking (`/book/…`) | `'self'` + your allow-list | removed when a cross-site parent is allowed |
| Public forms (`/forms/<slug>`) | `*` (forms are meant to be embedded anywhere) | — |

Implemented in `includes/helpers.php` (`slate_send_frame_policy()`, `slate_embed_origin_allowed()`,
`slate_normalize_embed_origins()`), called from `config.php` for the default and from
`plugins/booking/public/router.php` for the widget. `slate_embed_origin_allowed()` rejects non-https URLs,
embedded credentials, backslashes and CR/LF injection, and compares **exact origins** (never a prefix).

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| Widget is cut off, or has its own scrollbar | The `<script>` line is missing (or blocked by the host's content policy), so the frame keeps its fixed height. |
| Frame is blank / *"refused to connect"* | The embedding origin is not in the allow-list (check `https`, `www`, port). |
| *"Security check failed"* after choosing a slot | The site is running an older build without the break-out (< 1.6), or the visitor's browser blocks first-party cookies too. |
| Back link missing on the success page | `return` was not supplied or is not in the allow-list. |
| Login page appears inside the frame | Same as above — update to ≥ 1.6. |

## Verification status

Covered by `tests/unit/EmbedFramePolicyTest.php` (allow-list edge cases and router source checks),
`tests/unit/BookingEmbedAutoHeightTest.php` (auto-height) and a headless
Chromium run against a stand-in embedding origin. **Not yet tested:** Safari and Firefox, and a real production
embedding site — check those in a browser before announcing the widget.
