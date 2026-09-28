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
2. **Add the iframe** on the embedding site:

   ```html
   <iframe
     src="https://app.customer.com/book/?embed=1&return=https%3A%2F%2Fwww.example.com%2Fonline-booking%2F"
     style="width:100%;min-height:760px;border:0" loading="lazy" title="Book an appointment"></iframe>
   ```

   - `embed=1` removes the outer page chrome and footer.
   - `return` is the page the visitor should be sent back to (URL-encoded). It is **validated against the allow-list**;
     anything else is ignored, so it can not be used as an open redirect.

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
| Frame is blank / *"refused to connect"* | The embedding origin is not in the allow-list (check `https`, `www`, port). |
| *"Security check failed"* after choosing a slot | The site is running an older build without the break-out (< 1.6), or the visitor's browser blocks first-party cookies too. |
| Back link missing on the success page | `return` was not supplied or is not in the allow-list. |
| Login page appears inside the frame | Same as above — update to ≥ 1.6. |

## Verification status

Covered by `tests/unit/EmbedFramePolicyTest.php` (allow-list edge cases and router source checks) and a headless
Chromium run against a stand-in embedding origin. **Not yet tested:** Safari and Firefox, and a real production
embedding site — check those in a browser before announcing the widget.
