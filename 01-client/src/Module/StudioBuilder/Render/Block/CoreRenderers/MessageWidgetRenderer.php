<?php
/**
 * Kohevo Studio — `core.message_widget` renderer.
 *
 * A floating "message us" button that opens a small panel: who answers (name, photo), whether they are online now
 * (from a time zone, working hours and days the author sets), topic chips, and a short form. The form is a Forms form
 * like `core.form`'s (StudioFormSync writes it on publish from this block's settings: name, email, topic, message), so
 * it posts to `/forms/sb-<block id>` and the runtime's form script sends it in place.
 *
 * Without JavaScript the button is a link to the panel (`#id`), which `:target` opens, and the form still posts.
 * The runtime adds opening after a delay or some scrolling, Escape and focus handling, and keeps the online status
 * right on a cached page (the server's answer is only as fresh as the cache).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;
use Slate\Module\StudioBuilder\Render\Icon\IconLibrary;
use Slate\Module\StudioBuilder\Render\RenderMode;
use Slate\Module\StudioBuilder\Service\StudioFormSync;

final class MessageWidgetRenderer implements BlockRendererInterface
{
    public const POSITIONS = ['right', 'left'];
    /** Auto follows the site's theme colours; Light and Dark fix the panel's and the button's (StudioStylesheet). */
    public const THEMES = ['auto', 'light', 'dark'];
    /** Which days the hours apply to. */
    public const DAYS = ['weekdays', 'mon_sat', 'everyday'];
    public const MAX_TOPICS = 8;
    public const MAX_DELAY = 600;
    public const TIME_PATTERN = '/^([01]\d|2[0-3]):[0-5]\d$/';
    public const ZONE_PATTERN = '/^$|^[A-Za-z][A-Za-z0-9_+\-]*(\/[A-Za-z0-9_+\-]+){0,2}$/';

    public function type(): string
    {
        return 'core.message_widget';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    /** The time zone to read the hours in: the block's when PHP knows it, else the server's. */
    public static function zone(string $raw): string
    {
        $raw = trim($raw);
        if ($raw !== '' && in_array($raw, \DateTimeZone::listIdentifiers(), true)) {
            return $raw;
        }
        return date_default_timezone_get();
    }

    /** @return list<int> ISO weekdays (1 = Monday) the hours apply to */
    public static function weekdays(string $days): array
    {
        return match ($days) {
            'everyday' => [1, 2, 3, 4, 5, 6, 7],
            'mon_sat'  => [1, 2, 3, 4, 5, 6],
            default    => [1, 2, 3, 4, 5],
        };
    }

    /**
     * Online at `$now` in that zone: on one of the days, from the start time up to (not including) the end. An end
     * before the start runs past midnight (22:00–02:00), and counts from the day it started.
     *
     * @param list<int> $days
     */
    public static function isOnline(\DateTimeImmutable $now, string $zone, string $start, string $end, array $days): bool
    {
        $local = $now->setTimezone(new \DateTimeZone($zone));
        $minute = (int) $local->format('G') * 60 + (int) $local->format('i');
        $from = self::minutes($start, 9 * 60);
        $to = self::minutes($end, 18 * 60);
        $day = (int) $local->format('N');
        if ($from === $to) {
            return in_array($day, $days, true);
        }
        if ($from < $to) {
            return in_array($day, $days, true) && $minute >= $from && $minute < $to;
        }
        $yesterday = $day === 1 ? 7 : $day - 1;
        return (in_array($day, $days, true) && $minute >= $from) || (in_array($yesterday, $days, true) && $minute < $to);
    }

    private static function minutes(string $time, int $fallback): int
    {
        return preg_match(self::TIME_PATTERN, $time) === 1 ? (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2) : $fallback;
    }

    /** @return list<string> the topic chips, one per comma or line, at most MAX_TOPICS */
    public static function topics(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[,\n]/', $raw) ?: [] as $t) {
            $t = mb_substr(trim($t), 0, 60);
            if ($t !== '' && !in_array($t, $out, true)) {
                $out[] = $t;
            }
        }
        return array_slice($out, 0, self::MAX_TOPICS);
    }

    /**
     * The Forms fields this widget's form sends, in order; StudioFormSync writes them on publish.
     *
     * @param array<string, mixed> $props
     * @return list<array<string, mixed>>
     */
    public static function formFields(array $props): array
    {
        $fields = [];
        if (($props['ask_name'] ?? true) !== false) {
            $fields[] = ['type' => 'text', 'name' => 'name', 'label' => (string) ($props['name_label'] ?? 'Your name') ?: 'Your name', 'required' => false];
        }
        $fields[] = ['type' => 'email', 'name' => 'email', 'label' => (string) ($props['email_label'] ?? 'Your email') ?: 'Your email', 'required' => true];
        $topics = self::topics((string) ($props['topics'] ?? ''));
        if ($topics !== []) {
            $fields[] = ['type' => 'radio', 'name' => 'topic', 'label' => (string) ($props['topics_label'] ?? 'Topic') ?: 'Topic', 'required' => false, 'options' => $topics];
        }
        $fields[] = ['type' => 'textarea', 'name' => 'message', 'label' => (string) ($props['message_label'] ?? 'Message') ?: 'Message', 'required' => true];
        return $fields;
    }

    public function render(BlockRenderScope $scope): string
    {
        $id = $scope->domId('msg');
        $position = in_array($scope->string('position', 'right'), self::POSITIONS, true) ? $scope->string('position', 'right') : 'right';
        $zone = self::zone($scope->string('timezone'));
        $start = preg_match(self::TIME_PATTERN, $scope->string('hours_start')) === 1 ? $scope->string('hours_start') : '09:00';
        $end = preg_match(self::TIME_PATTERN, $scope->string('hours_end')) === 1 ? $scope->string('hours_end') : '18:00';
        $daysKey = in_array($scope->string('days', 'weekdays'), self::DAYS, true) ? $scope->string('days', 'weekdays') : 'weekdays';
        $days = self::weekdays($daysKey);
        $online = self::isOnline(new \DateTimeImmutable('now'), $zone, $start, $end, $days);
        $onlineText = $scope->string('online_text', 'Online now');
        $awayText = $scope->string('away_text', 'Away · I reply within a day');
        $editor = $scope->mode() === RenderMode::Editor;

        $delay = (int) $scope->prop('open_after_seconds', 0);
        $delay = $delay >= 0 && $delay <= self::MAX_DELAY ? $delay : 0;
        $scroll = (int) $scope->prop('open_after_scroll', 0);
        $scroll = $scroll >= 0 && $scroll <= 100 ? $scroll : 0;

        // The launcher: a link to the panel, so it opens with `:target` before (or without) the script.
        $label = $scope->string('launcher_label', 'Message me');
        $icon = $scope->string('launcher_icon', 'message-circle');
        $icon = IconLibrary::has($icon) ? $icon : 'message-circle';
        $iconSvg = static fn(string $name, int $size): string => '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . IconLibrary::markup($name) . '</svg>';
        // Who answers.
        $who = $scope->string('name', '');
        $avatarRef = $scope->prop('avatar');
        $avatar = $scope->image($avatarRef);

        // The button may show the photo instead of the icon, and lines under its text that take turns (the runtime
        // turns them; the first is the server's). `{status}` is the online or away text, `{time}` the owner's time.
        $photo = $scope->bool('launcher_photo', false) && $avatar !== null;
        // The photo shows at 40-44px: the library's smallest WebP copy, not the whole file.
        $small = $avatar !== null && $avatar->srcset !== [] ? $avatar->srcset[min(array_keys($avatar->srcset))] : ($avatar !== null ? $avatar->url : '');
        $lines = self::lines($scope->string('launcher_lines', ''), $online ? $onlineText : $awayText, $zone);
        $sub = $lines !== []
            ? '<span class="sb-msg__launcher-sub" data-sb-msg-lines="' . Html::e((string) json_encode(array_column($lines, 'raw'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"'
                . ' data-sb-online="' . Html::e($onlineText) . '" data-sb-away="' . Html::e($awayText) . '">' . Html::e($lines[0]['text']) . '</span>'
            : '';
        $launcherClasses = ['sb-msg__launcher', $photo ? 'sb-msg__launcher--photo' : '', $sub !== '' ? 'sb-msg__launcher--lines' : ''];
        $launcher = '<a href="#' . Html::e($id) . '"' . Html::classAttr($launcherClasses) . ' data-sb-msg-open aria-controls="' . Html::e($id) . '" aria-expanded="false"'
            . ($label === '' ? ' aria-label="' . Html::e(Html::t('studio_msg_open', 'Send a message')) . '"' : '') . '>'
            . ($photo
                ? '<span class="sb-msg__launcher-photo"><img src="' . Html::e($small) . '" alt="" width="44" height="44" loading="lazy"><span class="sb-msg__dot" data-sb-msg-dot aria-hidden="true"></span></span>'
                : $iconSvg($icon, 22))
            . ($label !== '' || $sub !== ''
                ? '<span class="sb-msg__launcher-text">' . ($label !== '' ? '<span class="sb-msg__launcher-label">' . Html::e($label) . '</span>' : '') . $sub . '</span>'
                : '')
            . ($photo ? '' : '<span class="sb-msg__dot" data-sb-msg-dot aria-hidden="true"></span>')
            . '</a>';
        $title = $scope->string('title', 'Let’s talk');
        $intro = $scope->string('intro', '');
        $head = '<header class="sb-msg__head">'
            . ($avatar !== null ? '<img class="sb-msg__avatar" src="' . Html::e($small) . '" alt="" width="40" height="40" loading="lazy">' : '')
            . '<div class="sb-msg__who">'
            . '<h2 class="sb-msg__title" id="' . Html::e($id) . '-title">' . Html::e($title) . '</h2>'
            . '<p class="sb-msg__status" data-sb-msg-status data-sb-online="' . Html::e($onlineText) . '" data-sb-away="' . Html::e($awayText) . '">'
            . '<span class="sb-msg__status-dot" aria-hidden="true"></span>'
            . '<span data-sb-msg-status-text>' . Html::e(($who !== '' ? $who . ' · ' : '') . ($online ? $onlineText : $awayText)) . '</span>'
            . '</p>'
            . '</div>'
            . '<a href="#" class="sb-msg__close" data-sb-msg-close aria-label="' . Html::e(Html::t('studio_close', 'Close')) . '">' . $iconSvg('x', 18) . '</a>'
            . '</header>';

        $html = '<div class="sb-msg__panel" id="' . Html::e($id) . '" role="dialog" aria-modal="false" aria-labelledby="' . Html::e($id) . '-title"' . ($editor ? '' : ' hidden') . '>'
            . $head
            . '<div class="sb-msg__body">'
            . ($intro !== '' ? '<p class="sb-msg__intro">' . Html::e($intro) . '</p>' : '')
            . $this->form($scope, $id)
            . '</div>'
            . '</div>';

        $attrs = ' data-sb-msg data-sb-tz="' . Html::e($zone) . '" data-sb-hours="' . Html::e($start . '-' . $end) . '"'
            . ' data-sb-days="' . Html::e(implode(',', $days)) . '"'
            . ' data-sb-who="' . Html::e($who) . '"'
            . ($delay > 0 ? ' data-sb-open-after="' . $delay . '"' : '')
            . ($scroll > 0 ? ' data-sb-open-scroll="' . $scroll . '"' : '')
            . ($scope->bool('remember_closed', true) ? ' data-sb-msg-remember' : '')
            . ($scope->bool('compact_on_scroll', false) ? ' data-sb-msg-compact' : '');
        $theme = in_array($t = $scope->string('theme', 'auto'), self::THEMES, true) ? $t : 'auto';
        $classes = ['sb-msg', 'sb-msg--' . $position, $theme !== 'auto' ? 'sb-msg--' . $theme : '', $online ? 'is-online' : 'is-away', $editor ? 'sb-msg--editor' : ''];
        return '<div' . Html::classAttr($classes) . $attrs . '>' . $html . $launcher . '</div>';
    }

    /**
     * The button's lines, each with `{status}` and `{time}` filled in for the server's render. Up to four lines of up to
     * 80 characters; blank lines are dropped.
     *
     * @return list<array{raw: string, text: string}>
     */
    public static function lines(string $raw, string $status, string $zone): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || mb_strlen($line) > 80) {
                continue;
            }
            $time = '';
            if (str_contains($line, '{time}')) {
                try {
                    $time = (new \DateTimeImmutable('now', new \DateTimeZone($zone !== '' ? $zone : 'UTC')))->format('g:i A');
                } catch (\Throwable) {
                    $time = '';
                }
            }
            $out[] = ['raw' => $line, 'text' => strtr($line, ['{status}' => $status, '{time}' => $time])];
            if (count($out) === 4) {
                break;
            }
        }
        return $out;
    }

    /** The form: the same Forms form markup as `core.form`, from this block's settings. */
    private function form(BlockRenderScope $scope, string $id): string
    {
        $slug = StudioFormSync::slugFor($scope->nodeId());
        $props = $scope->props();
        $rows = '';
        foreach (self::formFields($props) as $field) {
            $name = (string) $field['name'];
            $inputId = $id . '-' . $name;
            $req = $field['required'] ? ' required' : '';
            $mark = $field['required'] ? ' <span class="sb-required" aria-hidden="true">*</span>' : '';
            if ($field['type'] === 'radio') {
                $rows .= '<div class="sb-form-group sb-form-group--radio sb-msg__topics" data-sb-field="topic"><fieldset class="sb-form-choices"><legend>' . Html::e((string) $field['label']) . '</legend>';
                foreach ($field['options'] as $i => $topic) {
                    $rows .= '<label class="sb-msg__chip"><input type="radio" name="topic" value="' . Html::e($topic) . '" id="' . Html::e($inputId . '-' . $i) . '" data-sb-msg-topic><span>' . Html::e($topic) . '</span></label>';
                }
                $rows .= '</fieldset><span class="sb-form-error" data-sb-field-error hidden></span></div>';
                continue;
            }
            $rows .= '<div class="sb-form-group sb-form-group--' . $field['type'] . '" data-sb-field="' . Html::e($name) . '">'
                . '<label for="' . Html::e($inputId) . '">' . Html::e((string) $field['label']) . $mark . '</label>';
            if ($field['type'] === 'textarea') {
                $rows .= '<textarea name="message" id="' . Html::e($inputId) . '" rows="3"' . $req . ' placeholder="' . Html::e($scope->string('message_placeholder', '')) . '"></textarea>';
            } else {
                $rows .= '<input type="' . $field['type'] . '" name="' . Html::e($name) . '" id="' . Html::e($inputId) . '"' . $req
                    . ' autocomplete="' . ($name === 'email' ? 'email' : 'name') . '">';
            }
            $rows .= '<span class="sb-form-error" data-sb-field-error hidden></span></div>';
        }
        $success = $scope->string('success_message', 'Thanks! I’ll get back to you soon.');
        $note = $scope->showsDiagnostics() ? '<p class="sb-form__note" role="note">' . Html::e(StudioFormSync::available()
            ? Html::t('studio_form_note', 'Submissions go to Forms › Submissions once the page is published.')
            : Html::t('studio_form_note_off', 'Turn on the Forms module so this form can receive submissions.')) . '</p>' : '';
        return '<form class="sb-form sb-msg__form" action="' . Html::e($scope->site()->absoluteUrl('forms/' . $slug)) . '" method="post"'
            . ' data-sb-form="message" data-sb-form-slug="' . Html::e($slug) . '" enctype="multipart/form-data"'
            . ' data-sb-label-sending="' . Html::e(Html::t('studio_form_sending', 'Sending…')) . '"'
            . ' data-sb-label-failed="' . Html::e(Html::t('studio_form_failed', 'The form could not be sent. Check your connection and try again.')) . '">'
            . '<div class="sb-hp" aria-hidden="true"><label>Website <input type="text" name="hp_field" tabindex="-1" autocomplete="off"></label></div>'
            . '<input type="hidden" name="return_to" value="" data-sb-return-to>'
            . $rows
            . '<div class="sb-form__status" role="status" aria-live="polite" data-sb-form-status' . ($success !== '' ? ' data-sb-success="' . Html::e($success) . '"' : '') . ' hidden></div>'
            . '<div class="sb-form__actions"><button type="submit" class="sb-button sb-button--primary">'
            . Html::e($scope->string('submit_text', 'Send')) . '</button></div>'
            . '</form>' . $note;
    }
}
