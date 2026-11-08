<?php
/**
 * Kohevo Studio — `core.testimonial_grid` renderer.
 *
 * Testimonials in a grid that pages like a carousel. Every testimonial is in the HTML (a crawler and a visitor
 * without JavaScript read them all, in full); studio-runtime.js then shows one page at a time (per_page, or the
 * tablet's and phone's own number), draws the numbered pages and arrows, clamps each text to `clamp_lines` with a
 * "read full review" button that opens the whole text in a <dialog>, and moves the progress line. The bar's count
 * reads "{from}–{to} of {total}" with the numbers padded to two digits, as written by the server for the whole list.
 *
 * Source: typed on the block (`items`), or the published items of a content type (TestimonialItems), whose title is
 * the reviewer's name and whose fields are mapped by key. The block is dynamic, so a new or edited review shows at
 * once. On the canvas only the first page is drawn.
 *
 * The first card of each page can stand out (`is-first`, the "Standout card" part); the runtime marks it per page,
 * the server marks the first of the list.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Provider\TestimonialItems;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;
use Slate\Module\StudioBuilder\Render\RenderMode;

final class TestimonialGridRenderer implements BlockRendererInterface
{
    public const ORDERS = ['newest', 'oldest', 'title', 'longest'];
    public const FIELD_PATTERN = '/^([a-z][a-z0-9_]{0,63})?$/';
    /** Testimonials one block shows, all pages together. */
    public const MAX_ITEMS = 200;

    public function type(): string
    {
        return 'core.testimonial_grid';
    }

    public function isDynamic(): bool
    {
        return true;
    }

    public function render(BlockRenderScope $scope): string
    {
        $items = $this->items($scope);
        $num = static fn(string $key, int $default, int $min, int $max): int => is_int($v = $scope->prop($key)) && $v >= $min && $v <= $max ? $v : $default;
        $per = [$num('per_page', 6, 1, 24), $num('per_page_tablet', 4, 1, 24), $num('per_page_mobile', 3, 1, 24)];
        $cols = [$num('columns', 3, 1, 4), $num('columns_tablet', 2, 1, 4), $num('columns_mobile', 1, 1, 2)];
        $clamp = $num('clamp_lines', 6, 0, 20);
        $total = count($items);
        if ($total === 0) {
            return $scope->mode() === RenderMode::Editor
                ? '<div class="sb-tgrid sb-tgrid--empty"><p>' . Html::e(Html::t('studio_tgrid_empty', 'No testimonials yet: add some to the content type, or type them in.')) . '</p></div>'
                : '';
        }
        if ($scope->mode() === RenderMode::Editor) {
            $items = array_slice($items, 0, $per[0]);
        }

        $id = $scope->domId('tg');
        $label = $scope->string('count_label', '{from}–{to} of {total} reviews');
        $pad = static fn(int $n): string => sprintf('%02d', $n);
        $count = strtr($label, ['{from}' => $pad(1), '{to}' => $pad($total), '{total}' => (string) $total]);
        $first = $scope->bool('highlight_first', true);
        $quote = $scope->bool('show_quote', true);
        $stars = $scope->bool('show_stars', true);
        $more = $scope->string('read_more_label', 'Read full review');

        $cards = '';
        foreach ($items as $i => $t) {
            $cards .= $this->card($t, $i, $first && $i === 0, $quote, $stars, $more);
        }
        $classes = ['sb-tgrid', $clamp > 0 ? 'sb-tgrid--clamp' : '', $first ? 'sb-tgrid--first' : ''];
        $style = '--sb-tg-cols:' . $cols[0] . ';--sb-tg-cols-md:' . $cols[1] . ';--sb-tg-cols-sm:' . $cols[2] . ($clamp > 0 ? ';--sb-tg-clamp:' . $clamp : '');
        $arrow = static fn(string $dir, string $text): string => '<button type="button" class="sb-tgrid__arrow sb-tgrid__arrow--' . $dir . '" data-sb-tg-' . $dir . ' aria-label="' . Html::e($text) . '">'
            . '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . ($dir === 'prev' ? '<path d="M19 12H5M11 6l-6 6 6 6"/>' : '<path d="M5 12h14M13 6l6 6-6 6"/>') . '</svg></button>';

        return '<div' . Html::classAttr($classes) . ' id="' . Html::e($id) . '" data-sb-tgrid data-sb-tg-per="' . implode(',', $per) . '" style="' . $style . '">'
            . '<div class="sb-tgrid__bar">'
            . '<p class="sb-tgrid__count" data-sb-tg-count data-sb-tg-label="' . Html::e($label) . '" aria-live="polite">' . Html::e($count) . '</p>'
            . '<nav class="sb-tgrid__nav" aria-label="' . Html::e(Html::t('studio_tgrid_pages', 'Review pages')) . '" hidden>'
            . $arrow('prev', Html::t('studio_tgrid_prev', 'Previous reviews'))
            . '<div class="sb-tgrid__pages" data-sb-tg-pages></div>'
            . $arrow('next', Html::t('studio_tgrid_next', 'Next reviews'))
            . '</nav></div>'
            . '<div class="sb-tgrid__grid">' . $cards . '</div>'
            . ($scope->bool('show_progress', true) ? '<div class="sb-tgrid__progress" hidden><i></i></div>' : '')
            . '<dialog class="sb-tgrid__dialog" aria-labelledby="' . Html::e($id) . '-who" data-sb-tg-note="' . Html::e($scope->string('source_note', '')) . '">'
            . '<button type="button" class="sb-tgrid__close" data-sb-tg-close aria-label="' . Html::e(Html::t('studio_close', 'Close')) . '">'
            . '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18"/></svg></button>'
            . '<blockquote class="sb-tgrid__full" data-sb-tg-full></blockquote>'
            . '<p class="sb-tgrid__dialog-who" id="' . Html::e($id) . '-who" data-sb-tg-who></p>'
            . '</dialog>'
            . '</div>';
    }

    /** @param array{name: string, text: string, rating: ?float, meta: string, date: string, photo: string} $t */
    private function card(array $t, int $i, bool $first, bool $quote, bool $stars, string $more): string
    {
        $top = '';
        if ($quote || ($stars && $t['rating'] !== null)) {
            $top = '<div class="sb-tgrid__top">'
                . ($quote ? '<span class="sb-tgrid__quote" aria-hidden="true">“</span>' : '<span></span>')
                . ($stars && $t['rating'] !== null ? self::stars($t['rating']) : '')
                . '</div>';
        }
        $photo = $t['photo'] !== '' ? Html::safeUrl($t['photo']) : null;
        $avatar = $photo !== null && preg_match('#^(mailto|tel):#i', $photo) !== 1
            ? '<span class="sb-tgrid__avatar"><img src="' . Html::e($photo) . '" alt="" width="42" height="42" loading="lazy" decoding="async"></span>'
            : '<span class="sb-tgrid__avatar" aria-hidden="true">' . Html::e(self::initials($t['name'])) . '</span>';
        return '<figure' . Html::classAttr(['sb-tgrid__card', $first ? 'is-first' : '']) . ' data-sb-tg-i="' . $i . '">'
            . $top
            . '<blockquote class="sb-tgrid__text">' . Html::e($t['text']) . '</blockquote>'
            . ($more !== '' ? '<button type="button" class="sb-tgrid__more" data-sb-tg-more hidden>' . Html::e($more) . '</button>' : '')
            . '<figcaption class="sb-tgrid__by">' . $avatar
            . '<span class="sb-tgrid__who"><span class="sb-tgrid__name">' . Html::e($t['name']) . '</span>'
            . ($t['meta'] !== '' ? '<span class="sb-tgrid__meta">' . Html::e($t['meta']) . '</span>' : '') . '</span>'
            . ($t['date'] !== '' ? '<span class="sb-tgrid__date">' . Html::e($t['date']) . '</span>' : '')
            . '</figcaption>'
            . '</figure>';
    }

    /** Five stars, the rating's share of them lit; read out as "4.5 out of 5". */
    public static function stars(float $rating): string
    {
        $lit = (int) round($rating);
        $label = sprintf(Html::t('studio_tgrid_stars', '%s out of 5'), rtrim(rtrim(number_format($rating, 1, '.', ''), '0'), '.'));
        return '<span class="sb-tgrid__stars" role="img" aria-label="' . Html::e($label) . '">'
            . str_repeat('★', $lit) . ($lit < 5 ? '<span class="sb-tgrid__star-off">' . str_repeat('★', 5 - $lit) . '</span>' : '')
            . '</span>';
    }

    /** "Jonathan Cooper" → JC, "doomreboot" → DO, "evburgessjr_01" → EV: two words give their first letters, one word (a username) its first two. */
    public static function initials(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            return '·';
        }
        $pick = count($words) >= 2 ? mb_substr($words[0], 0, 1) . mb_substr($words[1], 0, 1) : mb_substr($words[0], 0, 2);
        return mb_strtoupper($pick);
    }

    /**
     * The testimonials: the content type's items, or the ones typed on the block, in the chosen order.
     *
     * @return list<array{name: string, text: string, rating: ?float, meta: string, date: string, photo: string}>
     */
    private function items(BlockRenderScope $scope): array
    {
        $order = in_array($scope->string('order', 'newest'), self::ORDERS, true) ? $scope->string('order', 'newest') : 'newest';
        $min = is_int($m = $scope->prop('min_length')) && $m >= 0 ? $m : 0;
        $limit = is_int($l = $scope->prop('limit')) && $l >= 1 && $l <= self::MAX_ITEMS ? $l : 60;
        $key = static fn(string $v): string => preg_match(self::FIELD_PATTERN, $v) === 1 ? $v : '';

        if ($scope->string('source', 'items') === 'content_type') {
            $type = $scope->string('content_type', '');
            if (preg_match('/^[a-z][a-z0-9_-]{0,39}$/', $type) !== 1) {
                return [];
            }
            $meta = array_values(array_filter(array_map(static fn(string $k): string => preg_match(self::FIELD_PATTERN, trim($k)) === 1 ? trim($k) : '', explode(',', $scope->string('meta_fields', ''))), static fn(string $k): bool => $k !== ''));
            $map = ['text' => $key($scope->string('text_field', 'text')), 'rating' => $key($scope->string('rating_field', 'rating')), 'meta' => $meta, 'date' => $key($scope->string('date_field', '')), 'photo' => $key($scope->string('photo_field', ''))];
            if ($map['text'] === '') {
                return [];
            }
            try {
                return TestimonialItems::ofType($scope->context()->tenantId, $type, $map, $order, $min, $limit);
            } catch (\Throwable) {
                return [];
            }
        }

        $out = [];
        foreach ((array) $scope->prop('items', []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $photo = $scope->image($row['photo'] ?? null);
            // Typed text is plain: escaped first, so the cleaning meant for a rich text field keeps "<" and "&" as written.
            $item = TestimonialItems::item((string) ($row['name'] ?? ''), ['text' => htmlspecialchars((string) ($row['text'] ?? ''), ENT_QUOTES, 'UTF-8'), 'rating' => $row['rating'] ?? null, 'meta' => $row['meta'] ?? '', 'date' => $row['date'] ?? ''], ['text' => 'text', 'rating' => 'rating', 'meta' => ['meta'], 'date' => 'date', 'photo' => '']);
            if ($item !== null && mb_strlen($item['text']) >= $min) {
                $item['photo'] = $photo !== null ? $photo->url : '';
                $out[] = $item;
            }
        }
        if ($order === 'title') {
            usort($out, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        } elseif ($order === 'longest') {
            $keyed = array_map(null, $out, array_keys($out));
            usort($keyed, static fn(array $a, array $b): int => [mb_strlen($b[0]['text']), $a[1]] <=> [mb_strlen($a[0]['text']), $b[1]]);
            $out = array_column($keyed, 0);
        } elseif ($order === 'oldest') {
            $out = array_reverse($out);
        }
        return array_slice($out, 0, $limit);
    }
}
