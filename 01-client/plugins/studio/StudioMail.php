<?php
/**
 * Studio — parent notifications.
 *
 * Listens to the enrollment lifecycle hooks StudioAPI emits and emails the
 * billing parent. Kept out of StudioAPI deliberately: that class is the data
 * layer, and a failed SMTP connection must never be the reason an enrollment
 * or a payment doesn't get written.
 *
 * Three moments matter to a parent:
 *   registered  — you're in (or you're on the waitlist, and where)
 *   promoted    — a seat opened and it's yours. Without this the waitlist is a
 *                 black hole: the promotion already happens silently.
 *   paid        — a receipt, so tuition doesn't feel like it vanished.
 *
 * Students are children and rarely have an address of their own, so everything
 * goes to the family's primary parent (StudioAPI::parentForStudent).
 *
 * Every send is wrapped: a bounced or misconfigured mailer is logged, never
 * thrown. Sends are synchronous because core has no queue — acceptable at a
 * studio's volume, and the alternative is inventing one.
 */

declare(strict_types=1);

require_once __DIR__ . '/StudioAPI.php';

class StudioMail
{
    /** Wire the listeners. Called from Studio::boot(). */
    public static function register(): void
    {
        Hook::addAction('studio_enrollment_created',  [self::class, 'onCreated']);
        Hook::addAction('studio_enrollment_promoted', [self::class, 'onPromoted']);
        Hook::addAction('studio_enrollment_paid',     [self::class, 'onPaid']);
    }

    /** Studio-wide off switch, so a tenant mid-setup isn't emailing families. */
    public static function enabled(): bool
    {
        // Default ON: a studio that has configured SMTP expects these to go out.
        return (string) Database::setting('studio.notify_disabled') !== '1';
    }

    // ── Listeners ─────────────────────────────────────────────

    public static function onCreated(array $e): void
    {
        $waitlisted = ($e['status'] ?? '') === 'waitlist';
        $class      = (string) ($e['series_name'] ?? '');

        self::toParent((int) ($e['student_id'] ?? 0), function (string $student) use ($waitlisted, $class): array {
            if ($waitlisted) {
                return [
                    sprintf(__('studio_mail_wait_subj', '%s is on the waitlist for %s'), $student, $class),
                    sprintf(__('studio_mail_wait_h', '%s is on the waitlist'), $student),
                    sprintf(__('studio_mail_wait_p', '%s is full right now, so %s has a place in the queue. We\'ll email you the moment a spot opens — there\'s nothing you need to do.'), $class, $student),
                ];
            }
            return [
                sprintf(__('studio_mail_enrolled_subj', '%s is enrolled in %s'), $student, $class),
                sprintf(__('studio_mail_enrolled_h', '%s is enrolled'), $student),
                sprintf(__('studio_mail_enrolled_p', '%s has a place in %s. You can see the schedule and settle tuition any time from your studio portal.'), $student, $class),
            ];
        });
    }

    public static function onPromoted(array $p): void
    {
        $class = (string) ($p['series_name'] ?? '');
        self::toParent((int) ($p['student_id'] ?? 0), fn (string $student): array => [
            sprintf(__('studio_mail_promo_subj', 'A spot opened — %s is in %s'), $student, $class),
            sprintf(__('studio_mail_promo_h', 'A spot opened for %s'), $student),
            sprintf(__('studio_mail_promo_p', 'Good news — a place came free in %s and %s has been moved off the waitlist into the class. Tuition is now due; you can pay from your studio portal.'), $class, $student),
        ]);
    }

    public static function onPaid(array $e): void
    {
        $enrollmentId = (int) ($e['id'] ?? 0);
        $row = Database::row(
            "SELECT en.student_id, s.name AS series_name
               FROM studio_enrollments en
               JOIN studio_class_series s ON s.id = en.series_id AND s.tenant_id = en.tenant_id
              WHERE en.tenant_id = ? AND en.id = ?",
            [current_tenant_id(), $enrollmentId]
        );
        if ($row === null) { return; }

        $amount = '$' . number_format(((int) ($e['amount_cents'] ?? 0)) / 100, 2);
        $class  = (string) $row['series_name'];

        self::toParent((int) $row['student_id'], fn (string $student): array => [
            sprintf(__('studio_mail_paid_subj', 'Receipt — %s tuition for %s'), $amount, $student),
            __('studio_mail_paid_h', 'Thank you — payment received'),
            sprintf(__('studio_mail_paid_p', 'We\'ve received %s for %s\'s place in %s. Nothing further is needed; this email is your receipt.'), $amount, $student, $class),
        ]);
    }

    /**
     * One reminder email for a family's outstanding fees.
     *
     * Addressed to the family rather than a student, because the money is: a
     * recital fee belongs to the family and a parent with eleven costume
     * instalments needs one statement, not eleven nudges.
     *
     * Returns true only when the mailer accepted it. The caller stamps the
     * fees as reminded on true and leaves them alone on false, so a send that
     * failed is retried next run instead of being silently swallowed.
     *
     * @param array $group one entry from StudioAPI::feeRemindersDue()
     */
    public static function sendFeeReminder(array $group): bool
    {
        try {
            if (!self::enabled()) { return false; }

            $email = trim((string) ($group['parent']['email'] ?? ''));
            if ($email === '' || !str_contains($email, '@')) { return false; }

            $fees = $group['fees'] ?? [];
            if (!$fees) { return false; }

            $step  = (int) ($group['step'] ?? 0);
            $tone  = \Slate\Module\Studio\Domain\ReminderPolicy::label($step);
            $name  = trim((string) ($group['parent']['name'] ?? ''));
            $first = $name !== '' ? explode(' ', $name)[0] : __('studio_there', 'there');

            $total = 0;
            foreach ($fees as $f) { $total += (int) $f['amount_cents']; }
            $money = static fn (int $c): string => '$' . number_format($c / 100, 2);

            [$subject, $heading, $opening] = match ($tone) {
                'upcoming' => [
                    __('studio_rm_sub_up',  'A payment is coming up'),
                    __('studio_rm_head_up', 'Coming up'),
                    sprintf(__('studio_rm_body_up',
                        'Hi %s — a quick heads-up that %s is due shortly. No action needed yet.'),
                        $first, $money($total)),
                ],
                'due' => [
                    __('studio_rm_sub_due',  'A payment is due today'),
                    __('studio_rm_head_due', 'Due today'),
                    sprintf(__('studio_rm_body_due',
                        'Hi %s — %s is due today. You can see the full breakdown in your studio account.'),
                        $first, $money($total)),
                ],
                default => [
                    __('studio_rm_sub_od',  'An outstanding balance'),
                    __('studio_rm_head_od', 'Outstanding balance'),
                    sprintf(__('studio_rm_body_od',
                        'Hi %s — our records show %s still outstanding. If you have already paid at the studio, please ignore this note.'),
                        $first, $money($total)),
                ],
            };

            // Itemise: a parent asked to pay must be able to see what for.
            $rows = '';
            foreach ($fees as $f) {
                $due = $f['due_date'] ? date('j M Y', strtotime((string) $f['due_date'])) : '';
                $rows .= '<tr>'
                       . '<td style="padding:6px 0;font-size:14px;color:#334155">' . e((string) $f['label']) . '</td>'
                       . '<td style="padding:6px 0;font-size:13px;color:#64748B;white-space:nowrap">' . e($due) . '</td>'
                       . '<td style="padding:6px 0;font-size:14px;font-weight:600;text-align:right;white-space:nowrap">'
                       . e($money((int) $f['amount_cents'])) . '</td>'
                       . '</tr>';
            }
            $table = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"'
                   . ' style="margin:14px 0;border-top:1px solid #E2E8F0;border-bottom:1px solid #E2E8F0">'
                   . $rows
                   . '<tr><td style="padding:8px 0;font-size:14px;font-weight:700">'
                   . e(__('studio_rm_total', 'Total')) . '</td><td></td>'
                   . '<td style="padding:8px 0;font-size:15px;font-weight:800;text-align:right">'
                   . e($money($total)) . '</td></tr></table>';

            $note = __('studio_rm_note',
                'Fees are collected at the studio — speak to the front desk to settle a balance.');

            $p = static fn (string $html, string $extra = ''): string =>
                '<p style="margin:0 0 14px;font-size:14.5px;line-height:1.6;color:#3C4250;' . $extra . '">'
                . $html . '</p>';

            Mailer::send($email, $subject, self::wrapRich($heading,
                $p(e($opening)) . $table . $p(e($note), 'font-size:13px;color:#64748B;')
            ), $name);
            return true;
        } catch (\Throwable $ex) {
            slate_log('Studio: fee reminder failed: ' . $ex->getMessage(), 'error');
            return false;
        }
    }

    /**
     * One announcement to one parent, in the studio's branding.
     *
     * Deliberately NOT gated on self::enabled(). That switch exists to stop
     * automated transactional mail while a tenant is mid-setup; an
     * announcement is a person deciding to send something right now, and
     * silently swallowing it would be baffling.
     *
     * Line breaks are converted after escaping, so a parent's message keeps
     * its paragraphs without the composer becoming an HTML injection point.
     */
    public static function sendAnnouncement(string $email, string $name, string $subject, string $body): bool
    {
        try {
            $html = nl2br(e($body), false);
            return Mailer::send($email, $subject, self::wrapRich($subject,
                '<div style="margin:0 0 20px;font-size:14.5px;line-height:1.6;color:#3C4250;">'
                . $html . '</div>'
            ), $name);
        } catch (\Throwable $ex) {
            slate_log('Studio: announcement failed: ' . $ex->getMessage(), 'error');
            return false;
        }
    }

    // ── Plumbing ──────────────────────────────────────────────

    /**
     * Resolve the parent, build the message from a callback given the student's
     * name, and send. The callback returns [subject, heading, paragraph].
     */
    private static function toParent(int $studentId, callable $compose): void
    {
        try {
            if (!self::enabled() || $studentId <= 0) { return; }

            $parent = StudioAPI::parentForStudent($studentId);
            if ($parent === null) { return; }   // no family, or no address on file

            $student = (string) Database::value(
                'SELECT display_name FROM contacts WHERE tenant_id = ? AND id = ?',
                [current_tenant_id(), $studentId]
            );
            if ($student === '') { $student = __('studio_your_dancer', 'Your dancer'); }

            [$subject, $heading, $body] = $compose($student);
            Mailer::send($parent['email'], $subject, self::wrap($heading, $body), $parent['name']);
        } catch (\Throwable $ex) {
            slate_log('Studio: notification failed: ' . $ex->getMessage(), 'error');
        }
    }

    /**
     * Branded HTML wrapper. Table-based and inline-styled on purpose — email
     * clients are not browsers, and a flex layout would collapse in Outlook.
     */
    private static function wrap(string $heading, string $body): string
    {
        // Plain-text body: escaped, then wrapped in one paragraph. Unchanged
        // behaviour for every existing caller.
        return self::shell($heading,
            '<p style="margin:0 0 20px;font-size:14.5px;line-height:1.6;color:#3C4250;">'
            . e($body) . '</p>');
    }

    /**
     * Same chrome, but the body is trusted HTML the caller has already escaped.
     *
     * Needed because a fee reminder has to itemise what is owed — a parent
     * asked for money must be able to see what for — and an escaped body would
     * render the table as visible markup.
     */
    private static function wrapRich(string $heading, string $bodyHtml): string
    {
        return self::shell($heading, $bodyHtml);
    }

    /** The branded shell shared by wrap() and wrapRich(). */
    private static function shell(string $heading, string $bodyHtml): string
    {
        $site   = e((string) (Database::setting('site_name') ?: 'Studio'));
        $accent = (string) Database::setting('brand_accent_color');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) { $accent = '#2563EB'; }

        $portal = e(SLATE_URL . '/studio?view=portal');
        $cta    = e(__('studio_mail_cta', 'Open my studio'));

        // Dark text on the brand, for the same reason the portal does it: a
        // light brand with white button text is unreadable, and an email has no
        // stylesheet to fix it later.
        $onAccent = self::readableOn($accent);

        return '<div style="margin:0;padding:24px;background:#F4F5F7;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;margin:0 auto;background:#ffffff;border-radius:14px;overflow:hidden;">'
            . '<tr><td style="padding:22px 26px;border-bottom:1px solid #ECEEF1;font-weight:700;font-size:15px;color:#15181E;">' . $site . '</td></tr>'
            . '<tr><td style="padding:26px;">'
            . '<h1 style="margin:0 0 10px;font-size:19px;line-height:1.3;color:#15181E;">' . e($heading) . '</h1>'
            . $bodyHtml
            . '<a href="' . $portal . '" style="display:inline-block;padding:11px 20px;border-radius:10px;'
            . 'background:' . e($accent) . ';color:' . $onAccent . ';font-weight:700;font-size:14px;text-decoration:none;">' . $cta . '</a>'
            . '</td></tr>'
            . '<tr><td style="padding:16px 26px;border-top:1px solid #ECEEF1;font-size:12px;color:#737886;">'
            . e(sprintf(__('studio_mail_foot', 'You\'re receiving this because you have a dancer registered at %s.'), (string) (Database::setting('site_name') ?: 'our studio')))
            . '</td></tr></table></div>';
    }

    /** #fff or near-black, whichever is legible on the given hex. */
    private static function readableOn(string $hex): string
    {
        $h = ltrim($hex, '#');
        $f = static fn (float $v): float => $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
        $l = 0.2126 * $f(hexdec(substr($h, 0, 2)) / 255)
           + 0.7152 * $f(hexdec(substr($h, 2, 2)) / 255)
           + 0.0722 * $f(hexdec(substr($h, 4, 2)) / 255);
        // Contrast against white vs against near-black; pick the winner.
        return (1.05 / ($l + 0.05)) >= (($l + 0.05) / 0.0846) ? '#ffffff' : '#15181E';
    }
}
