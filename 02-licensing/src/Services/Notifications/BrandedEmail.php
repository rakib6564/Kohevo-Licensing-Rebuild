<?php
/**
 * Slate — shared branded email chrome.
 *
 * One consistent, on-brand HTML email design — a logo/site-name header band
 * (optionally over a hero image), the site's accent color, dark-mode
 * support, and a footer with the business's address/phone — that any
 * plugin can reuse instead of hand-rolling its own email markup. The brand
 * palette is read straight from the same Settings → Branding values used
 * everywhere else (site name, logo, accent color, hero image, business
 * address/phone): a caller never touches branding settings itself.
 *
 * Promoted out of the Forms plugin's `FormsAPI::emailShell()` /
 * `FormsPdf::brandFromSettings()`, which had by far the most complete email
 * design in the codebase (dark-mode CSS, hero image with an Outlook/VML
 * fallback, a logo chip, a business-info footer) — everything here is that
 * same design, generalized so it isn't Forms-specific. `FormsAPI` now
 * delegates its email builders to this class rather than keeping its own
 * copy, so its emails render byte-for-byte the same as before.
 *
 * Other plugins that currently hand-roll unstyled notification HTML (Auth's
 * account emails, Booking's confirmations/reminders, Membership, Studio,
 * ...) can move onto this the same way, one call site at a time, whenever
 * they're next touched — nothing about this class assumes Forms.
 *
 * Two ways to use it:
 *
 *   1. simple() — heading + one HTML body block, no table/chips/CTA. Covers
 *      most account and confirmation emails:
 *
 *          $html = BrandedEmail::simple('Your booking is confirmed', $bodyHtml, [
 *              'header_label' => 'Booking confirmed',
 *              'sender_label' => 'Booking',
 *              'footer_ref'   => 'Reference ' . $ref,
 *          ]);
 *          Mailer::send($to, $subject, $html);
 *
 *   2. shell() directly, for a richer layout — build your own <tr> rows
 *      (optionally using ctaButton()/metaChip()/detailTable() as building
 *      blocks) and hand them to shell(). See FormsAPI::submissionEmailHtml()
 *      for a full worked example (meta chips, a details table, a CTA button).
 */

declare(strict_types=1);

namespace Slate\Services\Notifications;

class BrandedEmail
{
    private const FONT = '-apple-system,BlinkMacSystemFont,&quot;Segoe UI&quot;,Roboto,Helvetica,Arial,sans-serif';

    // ── Brand palette ────────────────────────────────────────────

    /**
     * The site's current brand palette. Every value degrades gracefully
     * (never null) so a caller never needs its own fallback.
     */
    public static function brand(): array {
        return [
            'name'      => (string)(\Database::setting('site_name')              ?: 'Kohevo'),
            'sublabel'  => (string)(\Database::setting('brand_sublabel')         ?: ''),
            'logo_path' => (string)(\Database::setting('brand_logo_path')        ?: ''),
            'logo_dark_path' => (string)(\Database::setting('brand_logo_dark_path') ?: ''),
            'hero_path' => (string)(\Database::setting('brand_login_image_path') ?: ''),
            'accent'    => (string)(\Database::setting('brand_accent_color')     ?: ''),
            'address'   => (string)(\Database::setting('business_address')       ?: ''),
            'phone'     => (string)(\Database::setting('business_phone')         ?: ''),
        ];
    }

    /**
     * Resolve the working accent palette from $opts/brand: the accent
     * itself, a darker shade (header gradient + links), and a readable
     * on-accent text color (white or near-black, whichever has enough
     * contrast) — so a light brand color never ends up with unreadable
     * white-on-yellow button text.
     *
     * @param array $opts ['brand'=>array, 'accent'=>'#hex']
     * @return array{0:array,1:string,2:string,3:string} [brand, accent, accentDeep, onAccent]
     */
    public static function accent(array $opts = []): array {
        $brand  = is_array($opts['brand'] ?? null) ? $opts['brand'] : self::brand();
        $accent = self::sanitizeHex((string)($opts['accent'] ?? ''))
                ?: (self::sanitizeHex((string)($brand['accent'] ?? '')) ?: '#111111');
        $deep   = self::shadeHex($accent, -0.18);
        [$r, $g, $b] = self::hexToRgb($accent);
        $on     = (0.299 * $r + 0.587 * $g + 0.114 * $b) > 165 ? '#0f172a' : '#ffffff';
        return [$brand, $accent, $deep, $on];
    }

    // ── The shell ────────────────────────────────────────────────

    /**
     * The full branded HTML document: <head> + styles, an accent-color (or
     * hero-image) header band with your logo/site name, the caller's own
     * content rows, and a footer with business address/phone. $innerHtml
     * must be one or more `<tr>...</tr>` rows that plug directly into the
     * shell's 640px content table.
     *
     * @param array $opts [
     *   'brand'        => array   override brand() defaults
     *   'accent'       => string  override accent hex
     *   'title'        => string  <title> tag text (falls back to the brand name)
     *   'preheader'    => string  hidden inbox-preview text
     *   'header_label' => string  small-caps label shown top-right of the header band
     *   'sender_label' => string  e.g. "Booking" -> footer reads "Sent by {site} Booking"
     *   'footer_ref'   => string  extra footer detail (e.g. a reference number)
     * ]
     */
    public static function shell(array $opts, string $innerHtml): string {
        [$brand, $accent, $accentDeep, $onAccent] = self::accent($opts);

        $site        = (string)($brand['name'] ?? 'Kohevo');
        $title       = (string)($opts['title'] ?? $site);
        $preheader   = (string)($opts['preheader'] ?? '');
        $hdrLabel    = trim((string)($opts['header_label'] ?? ''));
        $senderLabel = trim((string)($opts['sender_label'] ?? ''));
        $footerRef   = trim((string)($opts['footer_ref'] ?? ''));
        $base        = defined('SLATE_URL') ? rtrim(\SLATE_URL, '/') : '';
        $sansFont    = self::FONT;

        // Brand mark: logo image (kept as-is, no background chip, so a
        // transparent/white/colored logo all sit correctly on the accent
        // band), or the site name in the on-accent color when no logo is set.
        $logoPath     = trim((string)($brand['logo_path'] ?? ''));
        $logoDarkPath = trim((string)($brand['logo_dark_path'] ?? ''));
        if ($logoPath !== '') {
            $logoUrl = preg_match('#^https?://#i', $logoPath) ? $logoPath : $base . '/' . ltrim($logoPath, '/');
            $imgStyle = 'display:block;border:0;outline:none;max-height:40px;width:auto;';
            if ($logoDarkPath !== '') {
                // Most clients that honour prefers-color-scheme support CSS
                // classes in <style> but not <picture>/srcset swapping, so
                // both logos ship and the dark-mode media block (see below)
                // toggles which one is visible — the light one is the
                // default, so a client with no dark-mode support just shows it.
                $logoDarkUrl = preg_match('#^https?://#i', $logoDarkPath) ? $logoDarkPath : $base . '/' . ltrim($logoDarkPath, '/');
                $brandMark = '<img class="fe-logo-light" src="' . e($logoUrl) . '" alt="' . e($site) . '" height="40" style="' . $imgStyle . '">'
                    . '<img class="fe-logo-dark" src="' . e($logoDarkUrl) . '" alt="' . e($site) . '" height="40" style="' . $imgStyle . 'display:none;">';
            } else {
                $brandMark = '<img src="' . e($logoUrl) . '" alt="' . e($site) . '" height="40" style="' . $imgStyle . '">';
            }
        } else {
            $brandMark = '<span style="font:700 20px/1.2 ' . $sansFont . ';color:' . $onAccent . ';letter-spacing:-0.02em;">' . e($site) . '</span>';
        }
        $label = $hdrLabel !== '' ? $hdrLabel : (trim((string)($brand['sublabel'] ?? '')) ?: $site);

        // Premium header band: a hero image (dark overlay + logo/label on
        // top, with a VML fallback so Outlook shows it too) when one is
        // configured, else the accent gradient band.
        $heroPath = trim((string)($brand['hero_path'] ?? ''));
        $heroUrl  = $heroPath !== ''
            ? (preg_match('#^https?://#i', $heroPath) ? $heroPath : $base . '/' . ltrim($heroPath, '/'))
            : '';
        $brandRow = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td align="left" style="vertical-align:middle;">' . $brandMark . '</td>'
            . '<td align="right" style="vertical-align:middle;font:700 10px/1.4 ' . $sansFont . ';letter-spacing:0.16em;text-transform:uppercase;color:' . $onAccent . ';">'
            . e($label) . '</td></tr></table>';
        if ($heroUrl !== '') {
            $headerBand = '<tr><td background="' . e($heroUrl) . '" style="background-color:' . e($accentDeep) . ';background-image:url(' . e($heroUrl) . ');background-size:cover;background-position:center;">'
                . '<!--[if gte mso 9]><v:rect xmlns:v="urn:schemas-microsoft-com:vml" fill="true" stroke="false" style="width:640px;height:128px;"><v:fill type="frame" src="' . e($heroUrl) . '" color="' . e($accentDeep) . '" /><v:textbox inset="0,0,0,0"><![endif]-->'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td style="background-color:rgba(8,18,28,0.46);padding:32px 28px;">'
                . $brandRow
                . '</td></tr></table>'
                . '<!--[if gte mso 9]></v:textbox></v:rect><![endif]-->'
                . '</td></tr>';
        } else {
            $headerBand = '<tr><td style="background:' . e($accent) . ';background-image:linear-gradient(135deg,' . e($accent) . ',' . e($accentDeep) . ');padding:22px 28px;">'
                . $brandRow . '</td></tr>';
        }

        $footBits = array_filter([
            trim((string)($brand['address'] ?? '')),
            trim((string)($brand['phone'] ?? '')),
        ]);
        $footBiz  = $footBits ? '<div style="margin-bottom:4px;">' . e(implode('  ·  ', $footBits)) . '</div>' : '';
        $footRef  = $footerRef !== '' ? ' · ' . e($footerRef) : '';
        $footFrom = sprintf(__('email_sent_by', 'Sent by %s'), '<strong style="color:#64748b;">' . e($site) . '</strong>') . ($senderLabel !== '' ? ' ' . e($senderLabel) : '');
        // Platform identity (Kohevo) — plain-text signature only, never an
        // image: this is the one line every email built through this shell
        // carries regardless of tenant branding, so it must survive clients
        // that block remote images and plain-text fallbacks alike. Tenant
        // branding above (logo/name/accent/address/phone) is primary; this
        // is secondary, same hierarchy as the web app's PlatformSignature.
        $footPlatform = '<div style="margin-top:6px;">' . e(\Slate\Services\Content\PlatformIdentity::signature()) . '</div>';

        return '<!DOCTYPE html><html lang="en" xmlns="http://www.w3.org/1999/xhtml"><head>'
            . '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="x-apple-disable-message-reformatting">'
            . '<meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark">'
            . '<title>' . e($title) . '</title>'
            . '<style>@media (max-width:660px){.fe-card{width:100%!important;border-radius:0!important}'
            . '.fe-pad{padding-left:18px!important;padding-right:18px!important}'
            . 'td.fe-label{width:42%!important}}'
            // Dark-mode support: clients that honour prefers-color-scheme
            // (Apple Mail, iOS Mail, Outlook.com) get a proper dark palette
            // instead of an auto-inverted mess. Light inline styles stay the
            // default for everything else.
            . '@media (prefers-color-scheme:dark){'
            . 'body,.fe-body{background:#0b1120!important}'
            . '.fe-card{background:#0f172a!important;border-color:#1e293b!important}'
            . '.fe-title{color:#f1f5f9!important}.fe-text{color:#cbd5e1!important}'
            . '.fe-label{color:#94a3b8!important;border-color:#1e293b!important}'
            . '.fe-val{color:#f1f5f9!important;border-color:#1e293b!important}'
            . '.fe-z0 .fe-label,.fe-z0 .fe-val{background:#0f172a!important}'
            . '.fe-z1 .fe-label,.fe-z1 .fe-val{background:#16213a!important}'
            . '.fe-sep{border-color:#1e293b!important}'
            . '.fe-foot{background:#0b1120!important;color:#64748b!important;border-color:#1e293b!important}'
            . '.fe-chip{background:#1e293b!important;color:#cbd5e1!important}.fe-chip span{color:#f8fafc!important}'
            . '.fe-logo-light{display:none!important}.fe-logo-dark{display:block!important}'
            . '}'
            . 'a{color:' . e($accentDeep) . ';}</style>'
            . '</head>'
            . '<body class="fe-body" style="margin:0;padding:0;background:#f4f5f7;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">'
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#f4f5f7;font-size:1px;line-height:1px;">' . e($preheader) . '</div>'
            . '<table role="presentation" class="fe-body" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f5f7;">'
            . '<tr><td align="center" style="padding:28px 16px;">'
            . '<table role="presentation" class="fe-card" width="640" cellpadding="0" cellspacing="0" border="0" '
            . 'style="width:640px;max-width:640px;background:#ffffff;border:1px solid #e6e9ef;border-radius:14px;overflow:hidden;">'
            . $headerBand
            . $innerHtml
            . '<tr><td class="fe-pad fe-foot" style="padding:18px 28px 24px;border-top:1px solid #eef0f4;background:#fbfbfc;'
            . 'font:400 12px/1.6 ' . $sansFont . ';color:#94a3b8;">'
            . $footBiz
            . '<div>' . $footFrom . $footRef . '</div>'
            . $footPlatform
            . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    // ── Convenience builders ─────────────────────────────────────

    /**
     * The common case: an eyebrow label + heading + one HTML body block —
     * no table, chips, or CTA. Covers most account and confirmation emails.
     * $bodyHtml is trusted HTML; escape plain text yourself (e.g.
     * `nl2br(e($text))`) before passing it in.
     *
     * @param array $opts see shell(), plus nothing extra required
     */
    public static function simple(string $heading, string $bodyHtml, array $opts = []): string {
        [, , $accentDeep] = self::accent($opts);
        $sansFont  = self::FONT;
        $label     = trim((string)($opts['header_label'] ?? ''));
        $footerRef = trim((string)($opts['footer_ref'] ?? ''));

        $inner = '<tr><td class="fe-pad" style="padding:26px 28px 2px;">'
            . ($label !== '' ? '<div style="font:700 11px/1.4 ' . $sansFont . ';letter-spacing:0.1em;text-transform:uppercase;color:' . e($accentDeep) . ';margin-bottom:6px;">' . e($label) . '</div>' : '')
            . '<h1 class="fe-title" style="margin:0;font:700 22px/1.25 ' . $sansFont . ';color:#0f172a;letter-spacing:-0.02em;">' . e($heading) . '</h1>'
            . '</td></tr>'
            . '<tr><td class="fe-pad fe-text" style="padding:8px 28px 22px;font:400 15px/1.65 ' . $sansFont . ';color:#334155;">'
            . $bodyHtml
            . ($footerRef !== '' ? '<div class="fe-sep" style="margin-top:18px;padding-top:14px;border-top:1px solid #eef0f4;font-size:13px;color:#94a3b8;">' . e($footerRef) . '</div>' : '')
            . '</td></tr>';

        return self::shell(array_merge($opts, [
            'title'     => $opts['title']     ?? $heading,
            'preheader' => $opts['preheader'] ?? $heading,
        ]), $inner);
    }

    /** A rounded, on-brand button `<table>` (not a bare `<a>` — needed for reliable rendering in Outlook). */
    public static function ctaButton(string $url, string $label, array $opts = []): string {
        [, $accent, , $onAccent] = self::accent($opts);
        $sansFont = self::FONT;
        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:20px 0 4px;">'
             . '<tr><td bgcolor="' . e($accent) . '" style="border-radius:10px;">'
             . '<a href="' . e($url) . '" style="display:inline-block;padding:12px 22px;'
             . 'font:700 14px/1 ' . $sansFont . ';color:' . $onAccent . ';text-decoration:none;border-radius:10px;">' . e($label) . ' &rarr;</a>'
             . '</td></tr></table>';
    }

    /** A small rounded "pill" showing a label + value (e.g. "Ref  ABC123") — for a meta-info row under a heading. */
    public static function metaChip(string $label, string $value): string {
        return '<span class="fe-chip" style="display:inline-block;margin:0 8px 8px 0;padding:5px 11px;background:#f1f5f9;border-radius:999px;'
             . 'font:600 12px/1.3 ' . self::FONT . ';color:#334155;">' . e($label) . ' <span style="color:#0f172a;font-weight:700;">' . e($value) . '</span></span>';
    }

    /**
     * A zebra-striped label/value table (Forms' "Submission details" table,
     * generalized) — useful for anything shaped like a details list: a
     * booking's service/provider/time, an invoice's line items, etc.
     * $valueHtml is trusted HTML (escape plain text yourself).
     *
     * @param array<int,array{0:string,1:string}> $rows [label, valueHtml]
     */
    public static function detailTable(array $rows): string {
        $sansFont = self::FONT;
        $out = '';
        $i = 0;
        foreach ($rows as $row) {
            [$label, $valueHtml] = $row;
            $bg  = ($i % 2 === 1) ? '#f9fafb' : '#ffffff';
            $zeb = ($i % 2 === 1) ? 'fe-z1' : 'fe-z0';
            $out .= '<tr class="' . $zeb . '">'
                 . '<td class="fe-label" style="padding:11px 24px;width:38%;vertical-align:top;background:' . $bg . ';'
                 . 'font:600 13px/1.5 ' . $sansFont . ';color:#475569;border-bottom:1px solid #eef0f4;">' . e($label) . '</td>'
                 . '<td class="fe-val" style="padding:11px 24px;vertical-align:top;background:' . $bg . ';'
                 . 'font:400 14px/1.55 ' . $sansFont . ';color:#0f172a;border-bottom:1px solid #eef0f4;">' . $valueHtml . '</td>'
                 . '</tr>';
            $i++;
        }
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">' . $out . '</table>';
    }

    // ── Color math ───────────────────────────────────────────────
    // Small and self-contained on purpose: plugins (Forms included) that
    // also need hex math for their own on-page CSS keep their own copies
    // rather than depending on this class for something unrelated to email.

    /** Validate a #rrggbb color; return '' if not a valid hex. */
    private static function sanitizeHex(string $s): string {
        $s = trim($s);
        return preg_match('/^#?([0-9a-f]{6})$/i', $s, $m) ? '#' . strtolower($m[1]) : '';
    }

    private static function hexToRgb(string $hex): array {
        $hex = ltrim($hex, '#');
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    /** Lighten (ratio > 0) or darken (ratio < 0) a hex color by a fraction (-1..1). */
    private static function shadeHex(string $hex, float $ratio): string {
        [$r, $g, $b] = self::hexToRgb(self::sanitizeHex($hex) ?: '#2563eb');
        $adj = function (int $c) use ($ratio): int {
            $c = $ratio < 0 ? $c * (1 + $ratio) : $c + (255 - $c) * $ratio;
            return max(0, min(255, (int)round($c)));
        };
        return sprintf('#%02x%02x%02x', $adj($r), $adj($g), $adj($b));
    }
}
