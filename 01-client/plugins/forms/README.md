# Forms

A Slate plugin for public-facing forms — contact, quote requests,
intake, anything where a visitor fills in fields and you want the
result in an admin inbox.

## What it does

- **Admin builder** with a textarea-DSL for the field list (no
  drag-drop yet — that's a follow-up)
- **Public form** at `/forms/<slug>` and an iframe embed via
  `/forms/<slug>?embed=1`
- **Submissions inbox** in the admin — filter by form + read state,
  open into a right-rail detail view (kv-list + audit-trail
  components)
- **Email notification** to a configured address per form
- **Optional submitter confirmation** email
- **Webhooks** — POST JSON on submit; failures logged into
  `forms_webhook_log`
- **CSV export** per form

## Field types

Supported: `text`, `email`, `tel`, `url`, `number`, `date`, `time`,
`textarea`, `select`, `radio`, `checkbox`, `range`, `rating`, `file`,
`signature`, `heading`, `hidden`.

- **range** — slider; options column holds `min,max,step` (default `0,100,1`).
- **rating** — 1–N stars; options column holds the max (default `5`).
- **heading** — a section title (+ optional subtitle in the placeholder
  column); display-only, collects no value.
- **hidden** — a preset value carried with the submission (value in the
  placeholder column).
- **calc** — read-only calculated field; a formula like `{qty} * {price}`
  over numeric fields. Computed live and **recomputed server-side** on
  submit (the client value is never trusted).
- **step** — a step break; the form becomes a multi-step wizard with a
  progress bar and Back/Next, split at each marker.

### Advanced field logic

Set in the **visual builder** (these don't fit the flat DSL, so the
builder saves a JSON field model — `fields_json_data` — that the server
normalises via `FormsAPI::normalizeFields()`; the DSL textarea remains a
basic-field fallback):

- **Conditional visibility** — each field has a "show this field when
  `<field>` `is / is not / > / < / filled / empty>` `<value>`" rule
  (`show_if`). Evaluated live by `forms-logic.js` (hidden fields are
  disabled so they don't submit) and re-checked server-side in
  `validateSubmission` (`FormsAPI::conditionMet()`), so a hidden field is
  never required.
- **Multi-step** — drop `step` breaks between fields.
- **Calculated** — see `calc` above (`FormsAPI::evalFormula()` is a safe
  shunting-yard evaluator — `+ - * / ( )` only, no `eval`).

Deferred to follow-up sessions: payment field.

### Signature

The `signature` type renders a pad on the public form with two modes:

- **Draw** — freehand with mouse or finger (touch).
- **Type** — type a name and it's rendered in a script face.

Either way the pad is captured as a PNG, decoded server-side, and
stored under `uploads/forms/` (a folder hardened against PHP
execution). The submission detail shows the signature image inline,
the notification email embeds it, and CSV export records its URL.
Add it from the builder palette ("Signature") or in the DSL:

```
signature|agreement|Sign here|required
```

## Field DSL

One field per line, pipe-separated:

```
type|name|label|required|placeholder|options
```

Lines starting with `#` are comments. Examples:

```
# Contact form
text|full_name|Your name|required
email|email|Email|required
tel|phone|Phone
select|reason|Reason for contact||Choose…|sales,support,other
textarea|message|Tell us more|required
checkbox|consent|I agree to be contacted|required
```

- `name` must start with a letter and only contain letters, digits,
  underscores. Used as the form input name and the CSV column.
- `required` is the literal word `required`. Anything else means
  optional.
- `placeholder` shows as the input's grey placeholder text (and as
  the empty `<option>` label on selects).
- `options` is comma-separated. Required for `select` and `radio`,
  ignored elsewhere.

The DSL round-trips: editing a saved form re-renders the same DSL
text in the editor.

## Hooks

| Hook | Type | Args | When |
|---|---|---|---|
| `forms_submitted` | action | `(int $submissionId, int $formId, array $data)` | After a submission is saved and webhooks fire |

```php
Hook::addAction('forms_submitted', function (int $submissionId, int $formId, array $data) {
    // e.g. push to your CRM
});
```

## Webhooks

Configure one URL per line in the form's "Webhooks" textarea. Each
submission POSTs JSON:

```json
{
  "event": "forms.submitted",
  "submission_id": 42,
  "ref": "SUB-ABCD1234",
  "form": {"id": 1, "slug": "contact-us", "title": "Contact Us"},
  "submitted_at": "2026-05-28T14:22:00+00:00",
  "data": { "full_name": "Mariko", "email": "m@example.com", "message": "Hi!" }
}
```

Headers include `X-Slate-Form: <id>` and `X-Slate-Submission: <id>`
for routing on the receiver side. Timeout is 10s, no follow on
redirects. Each delivery is logged into `forms_webhook_log` with
status code + first 4KB of the response body.

## Embedding

```html
<iframe src="https://yoursite.com/forms/contact-us?embed=1"
        style="width:100%;border:0;min-height:520px"></iframe>
```

`?embed=1` strips the outer canvas and footer so the form sits
cleanly inside the host page.

## Permissions

| Key | Allows |
|---|---|
| `forms.view`   | See forms list, submissions inbox, submission detail |
| `forms.manage` | Create / edit / publish / delete forms; delete submissions |
| `forms.export` | Download a form's submissions as CSV |

Super-admins (role_id=1) get everything via short-circuit.

## Tables

- `forms_definitions` — title, slug, fields_json, settings, status
- `forms_submissions` — ref, data_json, submitter_email, ip, ua, country, read_at
- `forms_webhooks` — url, is_active, form_id
- `forms_webhook_log` — delivery attempts (status, response, error)
- `forms_spam_log` — blocked submissions (code, reason, ip, country, snippet)

All tenant-scoped via `tenant_id`.

## Anti-spam & security

Every submission passes through a layered gate (`FormsSpamGuard`) before
it touches the database. Each layer is per-form, configured under the
**Spam & Security** tab in the form editor. Defaults are off / lenient,
so existing forms behave exactly as before until you turn something on.

Always-on baseline (no config):

- **Honeypot** — a hidden `website_url` field. Bots fill it; humans
  don't. A filled honeypot is dropped silently (success page still
  shown, nothing saved).
- **CSRF** — `csrf_verify()` on every POST.

Configurable layers, in the order they run (cheapest first):

1. **IP blocklist** — exact IPs or CIDR ranges (v4 / v6). *Silent drop.*
2. **Time-trap** — a signed hidden timestamp records render time;
   submissions faster than your minimum fill time are bots. *Silent drop.*
3. **Content rules** — keyword blocklist, a max-links cap, disposable
   email rejection, and an email blocklist (`addr` or `@domain`).
   *Silent drop.*
4. **Country allow / deny** — ISO-3166-1 alpha-2 list, resolved via a
   proxy header (Cloudflare `CF-IPCountry`), the free ip-api.com lookup,
   a bundled MaxMind GeoLite2 `.mmdb`, or `auto` (header → MaxMind →
   API). An unknown country never blocks (fails open). *Visible message.*
5. **Rate limit** — configurable max submissions per IP per window.
   *Visible message.*
6. **CAPTCHA** — Cloudflare Turnstile, Google reCAPTCHA v3 (with a score
   threshold), or hCaptcha. Paste the site + secret keys; the widget
   renders just above the submit button and is verified server-side.
   *Visible message.*

Silent drops show the normal success page (a bot can't tell it was
caught); visible rejections show a message and let the visitor retry.
Every block is recorded in `forms_spam_log` (toggleable), and the
resolved country is stored on each accepted submission.

### MaxMind note

The MaxMind path uses the official `\MaxMind\Db\Reader` if it's
installed via Composer; otherwise a compact built-in reader parses the
`.mmdb` directly. Both fail safe — a parse error yields "unknown
country", which never blocks.

## Shipped since v0.1

- Visual field builder (drag to reorder, per-field settings) with an
  Advanced-DSL fallback
- File upload field
- Signature field (draw or type) — see above
- Webhook HMAC-SHA256 signatures + SSRF-guarded delivery
- Multi-step forms, conditional logic, calculated fields
- Per-submission branded PDF (attach to emails / download)
- Full anti-spam & security suite — see above
- Spam log admin page (`admin/spam_log.php`) — filter blocked attempts by
  form + reason, paginated, clear per-form or wholesale (`forms.manage`)

## Not yet built

- Stripe payment field

Tracked under Phase 3 of `SLATE_ROADMAP.md` for follow-up sessions.
