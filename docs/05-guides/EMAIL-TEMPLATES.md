# Notification emails

Every transactional email the platform sends uses **one** branded layout: a cream page, a white rounded card with the
site logo (or name) on a soft band, a padded body and a quiet footer (`Site · year` plus the platform signature).
It lives in `src/Services/Notifications/EmailTemplate.php` and is identical in `01-client` and `02-licensing`.

## Who uses it

| Email | Sent by |
|---|---|
| Booking confirmation, awaiting-approval, payment-failed, reminder, follow-up, cancellation, reschedule and staff notifications | `plugins/booking/BookingAPI.php`, `Booking.php`, `public/message.php` |
| Membership purchase / cancellation | `plugins/membership/MembershipAPI.php` |
| Account verification, password reset | `src/Services/Auth/Auth.php` (`sendCustomerVerification`, `sendCustomerPasswordReset`) |
| SMTP test message | `admin/settings.php` |

Form submission emails keep their own layout (`BrandedEmail::shell()` in the Forms plugin).

## Branding inputs

Read from *Settings → General* and *Settings → Branding*, never hard-coded: `site_name`, `brand_logo_path`, `brand_accent_color`
(buttons and the fallback wordmark; defaults to `#111111` when unset or invalid). The footer signature comes from
`PlatformIdentity::signature()`; its label ("Powered by" / « Propulsé par ») follows the active language. The
template never lets a settings or i18n failure stop an email from being sent.

## Composing an email

(Translation keys below are illustrative.)

```php
use Slate\Services\Notifications\EmailTemplate as T;

$html = T::shell(
      T::heading(__('email_reset_heading', 'Reset your password'))
    . T::greeting($name)                                   // "Hello Ada," / "Bonjour Ada,"
    . T::paragraph(e(__('email_reset_body', 'Use the button below to choose a new password.')))
    . T::infoCard([[__('when', 'When'), e($when)]])        // optional details card
    . T::button($url, __('email_reset_cta', 'Choose a password'))
    . T::hint(e(__('email_link_fallback', 'Or paste this link into your browser:')) . ' ' . e($url)),
    __('email_reset_preheader', 'Reset your password')     // hidden inbox preview text
);
```

or `T::compose($heading, $bodyHtml, $preheader)` for the common heading + body case.

**Escaping contract:** `heading()`, `greeting()` and the `button()` label take **plain text** and escape it;
`shell()`, `paragraph()`, `hint()` and the values of `infoCard()` take **already-escaped HTML**. Pass user data
through `e()` before it goes into those.

## SMTP

Configure under *Settings → SMTP*: host, port, encryption, username and password. The password is stored
**encrypted** (keyed from `APP_SECRET`). Use the test-email form on that tab — the message goes through the same
template, so a correct-looking test proves branding, encryption and delivery together. Mail is sent with PHPMailer (added at
packaging time; see [RELEASING](../06-operations/RELEASING.md)).

## Testing

- `tests/unit/PlatformSignatureEmailSurfaceTest.php` asserts every sender goes through `EmailTemplate` and renders
  the signature.
- In tests, declare a stub `Mailer` **before** `config.php` loads so nothing is actually sent, and never override SMTP
  settings in a shared database to "capture" mail.
