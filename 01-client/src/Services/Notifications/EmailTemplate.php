<?php
/**
 * Slate — the notification email template.
 *
 * ONE on-brand layout for every transactional email the platform sends: a cream
 * page, a white rounded card with the site logo (or name) on a soft cream band, a
 * padded body, and a quiet footer ("Site · year" plus the platform signature).
 * This is the design Booking's confirmation emails have always had; it used to live
 * privately inside BookingAPI, so nothing else could reuse it. Booking now delegates
 * here (its output is unchanged) and so do the auth, membership, SMTP-test and
 * Booking staff notifications.
 *
 * Every email is composed the same way:
 *
 *     EmailTemplate::shell(
 *         EmailTemplate::heading('Appointment confirmed')
 *       . EmailTemplate::greeting($name)
 *       . EmailTemplate::paragraph(e('Your appointment is confirmed.'))
 *       . EmailTemplate::infoCard([['Service', e($service)], ['Date', e($when)]])
 *       . EmailTemplate::button($url, 'Manage my booking')
 *       . EmailTemplate::hint(e('Cancel or reschedule in one click.')),
 *       'Preheader text shown in the inbox list'
 *     );
 *
 * or, for the common heading + body case, EmailTemplate::compose($heading, $bodyHtml, $preheader).
 *
 * Callers pass already-escaped HTML to shell()/paragraph()/hint()/infoCard() values —
 * the same contract Booking's helpers always had. Plain-text arguments (heading(),
 * button() label, greeting() name) are escaped here.
 *
 * Forms emails intentionally keep their own layout (BrandedEmail::shell()).
 */

declare(strict_types=1);

namespace Slate\Services\Notifications;

class EmailTemplate
{
    /** Read a setting without ever letting an email fail because the settings layer is unavailable. */
    private static function setting(string $key): string
    {
        try {
            if (class_exists('Database')) {
                return trim((string) (\Database::setting($key) ?? ''));
            }
        } catch (\Throwable $ignored) {
        }
        return '';
    }

    /** Brand accent colour (buttons, fallback wordmark); a safe dark default when unset or invalid. */
    public static function accent(): string
    {
        $accent = self::setting('brand_accent_color');
        return preg_match('/^#[0-9a-fA-F]{3,8}$/', $accent) ? $accent : '#111111';
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    private static function lang(): string
    {
        try {
            if (class_exists('I18n')) {
                return (string) \I18n::currentLocale();
            }
        } catch (\Throwable $ignored) {
        }
        return 'en';
    }

    /**
     * Wrap inner content HTML in the branded chrome. $innerHtml is trusted HTML built by the
     * caller (dynamic values already escaped); $preheader is the hidden inbox-preview text.
     */
    public static function shell(string $innerHtml, string $preheader = ''): string
    {
        $siteName = self::setting('site_name') ?: 'Kohevo';
        $accent   = self::accent();
        $logoRel  = self::setting('brand_logo_path');
        $logoUrl  = ($logoRel !== '' && defined('SLATE_URL')) ? SLATE_URL . '/' . ltrim($logoRel, '/') : '';
        $year     = date('Y');

        $logoHtml = $logoUrl !== ''
            ? '<img src="' . self::esc($logoUrl) . '" alt="' . self::esc($siteName) . '" height="52" style="height:52px;max-height:52px;display:block;border:0;outline:none;">'
            : '<span style="font-family:Georgia,\'Times New Roman\',serif;font-size:26px;letter-spacing:.03em;color:' . self::esc($accent) . ';">' . self::esc($siteName) . '</span>';

        // Platform identity (Kohevo): plain-text signature, secondary to the tenant name/logo above.
        // Only its label is localised; guarded so a broken i18n/database can never stop an email.
        $signature = '';
        try {
            $signature = \Slate\Services\Content\PlatformIdentity::signature();
        } catch (\Throwable $ignored) {
        }

        return '<!DOCTYPE html><html lang="' . self::esc(self::lang()) . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>' . self::esc($siteName) . '</title></head>'
            . '<body style="margin:0;padding:0;background-color:#f3ede0;font-family:Arial,Helvetica,sans-serif;">'
            . ($preheader !== '' ? '<div style="display:none;max-height:0;overflow:hidden;opacity:0;mso-hide:all;">' . self::esc($preheader) . '</div>' : '')
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f3ede0;padding:32px 12px;">'
            . '<tr><td align="center">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background-color:#ffffff;border-radius:14px;overflow:hidden;">'
            . '<tr><td align="center" bgcolor="#faf5e8" style="background-color:#faf5e8;background-image:linear-gradient(135deg,#fffdf8 0%,#f6ecd4 100%);padding:34px 24px;border-bottom:1px solid #efe2c0;">' . $logoHtml . '</td></tr>'
            . '<tr><td style="padding:38px 34px;color:#2d2a26;font-size:15px;line-height:1.65;">' . $innerHtml . '</td></tr>'
            . '<tr><td style="padding:18px 34px 26px;border-top:1px solid #ece4d3;">'
            . '<p style="margin:0;color:#a49a86;font-size:12px;text-align:center;">' . self::esc($siteName) . ' &middot; ' . $year . '</p>'
            . '<p style="margin:4px 0 0;color:#c4b8a0;font-size:11px;text-align:center;">' . self::esc($signature) . '</p>'
            . '</td></tr>'
            . '</table>'
            . '</td></tr>'
            . '</table>'
            . '</body></html>';
    }

    /** The email's title line. Plain text — escaped here. */
    public static function heading(string $text): string
    {
        return '<h2 style="margin:0 0 18px;font-size:20px;font-weight:700;color:#2d2a26;">' . self::esc($text) . '</h2>';
    }

    /** "Hello Name," — or a generic "Hello," when there's no name. Plain-text name, escaped here. */
    public static function greeting(string $name): string
    {
        $name = trim($name);
        $text = $name !== ''
            ? sprintf(\__('email_greeting', 'Hello %s,'), self::esc($name))
            : \__('email_greeting_generic', 'Hello,');
        return '<p style="margin:0 0 14px;">' . $text . '</p>';
    }

    /** A body paragraph. $html is trusted (already escaped). */
    public static function paragraph(string $html, string $margin = '0 0 14px'): string
    {
        return '<p style="margin:' . $margin . ';">' . $html . '</p>';
    }

    /**
     * A soft accent-tinted "details" card. $rows is a list of [label, valueHtml] pairs;
     * the label is escaped here, valueHtml is trusted.
     */
    public static function infoCard(array $rows): string
    {
        $out = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#faf6ec;border:1px solid #ece0c4;border-radius:10px;margin:22px 0;">'
             . '<tr><td style="padding:18px 22px;">'
             . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">';
        foreach ($rows as [$label, $valueHtml]) {
            $out .= '<tr>'
                  . '<td style="padding:7px 0;color:#8a7d61;font-size:12.5px;width:130px;vertical-align:top;white-space:nowrap;">' . self::esc((string) $label) . '</td>'
                  . '<td style="padding:7px 0;color:#2d2a26;font-size:14px;font-weight:600;">' . $valueHtml . '</td>'
                  . '</tr>';
        }
        return $out . '</table></td></tr></table>';
    }

    /** Bulletproof branded CTA button (table + link, not a bare <a> — renders correctly in Outlook). */
    public static function button(string $url, string $label): string
    {
        return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0;"><tr><td style="border-radius:8px;background-color:' . self::esc(self::accent()) . ';">'
             . '<a href="' . self::esc($url) . '" style="display:inline-block;padding:13px 28px;color:#ffffff;font-size:14px;font-weight:700;'
             . 'text-decoration:none;border-radius:8px;font-family:Arial,Helvetica,sans-serif;">' . self::esc($label) . '</a>'
             . '</td></tr></table>';
    }

    /** A small muted line under a button (e.g. "Cancel or reschedule in one click."). $html is trusted. */
    public static function hint(string $html): string
    {
        return '<p style="margin:6px 0 0;font-size:13px;color:#8a7d61;">' . $html . '</p>';
    }

    /** Heading + body in one call — the common case. $bodyHtml is trusted HTML. */
    public static function compose(string $heading, string $bodyHtml, string $preheader = ''): string
    {
        return self::shell(self::heading($heading) . $bodyHtml, $preheader);
    }
}
