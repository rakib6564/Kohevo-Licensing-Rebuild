<?php
/**
 * Coaching — SVG chart helpers.
 *
 * Pure-SVG output — no CDN, no external JS. Matches Slate's "no external
 * dependencies at runtime" policy. Each method takes a customer id + a
 * date range and returns an <svg> string suitable to echo directly into
 * a page. Colors reference core admin CSS variables where possible; hard
 * values are chosen from a palette that reads well on both light + dark
 * backgrounds.
 *
 * Not covered here (deferred to Wave 3.5):
 *   • Emotion → hunger/satiety correlation
 *   • Emotion → food type correlation stacked bars
 *   • Rolling weekly / monthly averages
 */

class CoachingCharts {

    /** Palette used across all charts. */
    public static function palette(): array {
        return [
            'primary'   => '#2563EB',
            'water'     => '#0EA5E9',
            'fruits_vegetables' => '#10B981',
            'starches'  => '#F59E0B',
            'proteins'  => '#EF4444',
            'dairy'     => '#8B5CF6',
            'fats'      => '#EAB308',
            'pleasure'  => '#EC4899',
            'other'     => '#94A3B8',
            'joy'         => '#FBBF24',
            'stress'      => '#EF4444',
            'fatigue'     => '#94A3B8',
            'anxiety'     => '#F97316',
            'boredom'     => '#78716C',
            'anger'       => '#DC2626',
            'sadness'     => '#3B82F6',
            'serenity'    => '#10B981',
            'neutrality'  => '#A3A3A3',
            'muted'       => '#CBD5E1',
            'grid'        => '#E2E8F0',
            'axis'        => '#64748B',
            'success'     => '#10B981',
            'warning'     => '#F59E0B',
            'danger'      => '#EF4444',
            'info'        => '#3B82F6',
        ];
    }

    // ── Hydration ────────────────────────────────────────────────────────

    /** Line chart of hydration litres per day. */
    public static function hydrationLine(int $customerId, string $from, string $to): string {
        $tid  = current_tenant_id();
        $rows = Database::rows(
            "SELECT day, liters FROM coaching_hydration
              WHERE tenant_id = ? AND customer_id = ? AND day BETWEEN ? AND ?
              ORDER BY day",
            [$tid, $customerId, $from, $to]
        );

        // Build a dense day-by-day series (0 for missing days).
        $series = [];
        $fromTs = strtotime($from);
        $toTs   = strtotime($to);
        for ($d = $fromTs; $d <= $toTs; $d += 86400) {
            $series[date('Y-m-d', $d)] = 0.0;
        }
        foreach ($rows as $r) {
            $series[$r['day']] = (float)$r['liters'];
        }

        if (!$series) return self::emptyChart('No hydration logged in this range.');

        $vals = array_values($series);
        $keys = array_keys($series);
        $maxY = max(2.5, max($vals));  // never squash — a 2.5 L visual floor

        return self::renderLineChart($keys, $vals, [
            'height'      => 220,
            'stroke'      => self::palette()['water'],
            'fill'        => 'rgba(14,165,233,0.15)',
            'y_max'       => $maxY,
            'y_ticks'     => [0, 0.5, 1, 1.5, 2, 2.5, max(2.5, ceil($maxY))],
            'y_label'     => 'litres',
            'y_format'    => fn($v) => number_format($v, 1),
        ]);
    }

    // ── Food group distribution (pie) ────────────────────────────────────

    public static function foodDistributionPie(int $customerId, string $from, string $to): string {
        $tid  = current_tenant_id();
        $rows = Database::rows(
            "SELECT f.category, COUNT(*) AS n
               FROM coaching_diary_food f
               JOIN coaching_diary_entry e ON e.id = f.entry_id
              WHERE e.tenant_id = ? AND e.customer_id = ?
                AND e.day BETWEEN ? AND ?
                AND f.category IS NOT NULL
              GROUP BY f.category",
            [$tid, $customerId, $from, $to]
        );
        if (!$rows) return self::emptyChart('No classified foods yet.');

        $labels = CoachingAPI::foodCategories();
        $slices = [];
        foreach ($rows as $r) {
            $slices[] = [
                'label' => $labels[$r['category']] ?? $r['category'],
                'value' => (int)$r['n'],
                'color' => self::palette()[$r['category']] ?? self::palette()['other'],
            ];
        }
        return self::renderPieChart($slices, ['height' => 260]);
    }

    // ── Emotions (pie) ───────────────────────────────────────────────────

    public static function emotionPie(int $customerId, string $from, string $to): string {
        $tid  = current_tenant_id();
        $rows = Database::rows(
            "SELECT emotion, COUNT(*) AS n
               FROM coaching_diary_entry
              WHERE tenant_id = ? AND customer_id = ?
                AND day BETWEEN ? AND ? AND emotion IS NOT NULL
              GROUP BY emotion
              ORDER BY n DESC",
            [$tid, $customerId, $from, $to]
        );
        if (!$rows) return self::emptyChart('No emotions logged yet.');

        $labels = CoachingAPI::emotions();
        $slices = [];
        foreach ($rows as $r) {
            $slices[] = [
                'label' => $labels[$r['emotion']] ?? $r['emotion'],
                'value' => (int)$r['n'],
                'color' => self::palette()[$r['emotion']] ?? self::palette()['other'],
            ];
        }
        return self::renderPieChart($slices, ['height' => 260]);
    }

    // ── Emotion → food-type correlation (stacked bars) ───────────────────

    public static function emotionFoodCorrelation(int $customerId, string $from, string $to): string {
        $tid  = current_tenant_id();
        $rows = Database::rows(
            "SELECT e.emotion, f.category, COUNT(*) AS n
               FROM coaching_diary_entry e
               JOIN coaching_diary_food  f ON f.entry_id = e.id
              WHERE e.tenant_id = ? AND e.customer_id = ?
                AND e.day BETWEEN ? AND ?
                AND e.emotion IS NOT NULL AND f.category IS NOT NULL
              GROUP BY e.emotion, f.category",
            [$tid, $customerId, $from, $to]
        );
        if (!$rows) return self::emptyChart('Not enough labeled entries yet.');

        // Pivot rows into [emotion => [category => n]] shape.
        $matrix = [];
        foreach ($rows as $r) {
            $matrix[$r['emotion']][$r['category']] = (int)$r['n'];
        }

        $emotions = CoachingAPI::emotions();
        $cats     = CoachingAPI::foodCategories();

        return self::renderStackedBars($matrix, $emotions, $cats, ['height' => 280]);
    }

    // ── Goal progress ────────────────────────────────────────────────────

    public static function goalProgress(int $customerId, string $from, string $to): string {
        $tid  = current_tenant_id();
        $rows = Database::rows(
            "SELECT day, status, COUNT(*) AS n
               FROM coaching_goal_checkin
              WHERE tenant_id = ? AND customer_id = ? AND day BETWEEN ? AND ?
              GROUP BY day, status
              ORDER BY day",
            [$tid, $customerId, $from, $to]
        );
        if (!$rows) return self::emptyChart('No goal check-ins yet.');

        // Pivot to per-day counts.
        $byDay = [];
        for ($d = strtotime($from); $d <= strtotime($to); $d += 86400) {
            $byDay[date('Y-m-d', $d)] = ['achieved' => 0, 'partial' => 0, 'exceeded' => 0, 'not_achieved' => 0];
        }
        foreach ($rows as $r) {
            $byDay[$r['day']][$r['status']] = (int)$r['n'];
        }

        $stackOrder = ['exceeded' => 'Exceeded', 'achieved' => 'Achieved', 'partial' => 'Partial', 'not_achieved' => 'Missed'];
        $colors     = ['exceeded' => self::palette()['info'],
                       'achieved' => self::palette()['success'],
                       'partial'  => self::palette()['warning'],
                       'not_achieved' => self::palette()['danger']];

        return self::renderDailyStack($byDay, $stackOrder, $colors, ['height' => 240]);
    }

    // ── Primitive: line chart ────────────────────────────────────────────

    private static function renderLineChart(array $labels, array $values, array $opts = []): string {
        $w = 640; $h = (int)($opts['height'] ?? 220);
        $padL = 44; $padR = 12; $padT = 12; $padB = 32;
        $iw = $w - $padL - $padR;
        $ih = $h - $padT - $padB;
        $maxY = (float)($opts['y_max'] ?? max($values) ?: 1);
        $ticks = $opts['y_ticks'] ?? [0, $maxY / 2, $maxY];
        $stroke = $opts['stroke'] ?? self::palette()['primary'];
        $fill   = $opts['fill']   ?? 'rgba(37,99,235,0.15)';
        $palette = self::palette();

        $n = count($values);
        $stepX = $n > 1 ? $iw / ($n - 1) : $iw;

        // Points.
        $pts = [];
        foreach ($values as $i => $v) {
            $x = $padL + $stepX * $i;
            $y = $padT + $ih - ($maxY > 0 ? ($v / $maxY) * $ih : 0);
            $pts[] = round($x, 1) . ',' . round($y, 1);
        }

        // Filled path (drop to baseline).
        $areaPath = 'M' . $pts[0] . ' L' . implode(' ', $pts)
                  . ' L' . round($padL + $iw, 1) . ',' . round($padT + $ih, 1)
                  . ' L' . round($padL, 1) . ',' . round($padT + $ih, 1) . ' Z';

        $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid meet" style="width:100%;max-width:100%;font-family:inherit;">';

        // Y grid + tick labels.
        foreach ($ticks as $t) {
            $y = $padT + $ih - ($maxY > 0 ? ($t / $maxY) * $ih : 0);
            $svg .= '<line x1="' . $padL . '" x2="' . ($padL + $iw) . '" y1="' . round($y,1) . '" y2="' . round($y,1) . '" stroke="' . $palette['grid'] . '"/>';
            $label = isset($opts['y_format']) ? $opts['y_format']($t) : (string)$t;
            $svg .= '<text x="' . ($padL - 6) . '" y="' . round($y+4,1) . '" font-size="11" fill="' . $palette['axis'] . '" text-anchor="end">' . e($label) . '</text>';
        }

        // Area + line.
        $svg .= '<path d="' . $areaPath . '" fill="' . $fill . '"/>';
        $svg .= '<polyline points="' . implode(' ', $pts) . '" fill="none" stroke="' . $stroke . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>';
        // Point circles + hover titles.
        foreach ($values as $i => $v) {
            [$px, $py] = explode(',', $pts[$i]);
            $svg .= '<circle cx="' . $px . '" cy="' . $py . '" r="3" fill="' . $stroke . '">';
            $svg .= '<title>' . e($labels[$i]) . ': ' . (isset($opts['y_format']) ? $opts['y_format']($v) : $v) . '</title></circle>';
        }
        // X labels — show ~6 evenly-spaced dates.
        $labelEvery = max(1, (int) floor($n / 6));
        foreach ($labels as $i => $lbl) {
            if ($i % $labelEvery !== 0 && $i !== $n - 1) continue;
            [$px] = explode(',', $pts[$i]);
            $svg .= '<text x="' . $px . '" y="' . ($padT + $ih + 16) . '" font-size="10" fill="' . $palette['axis'] . '" text-anchor="middle">' . e(I18n::localDate('j M', strtotime($lbl))) . '</text>';
        }
        $svg .= '</svg>';
        return $svg;
    }

    // ── Primitive: pie chart ─────────────────────────────────────────────

    private static function renderPieChart(array $slices, array $opts = []): string {
        $h = (int)($opts['height'] ?? 260);
        $w = 480;
        $cx = 130; $cy = $h / 2; $r = min($cy, 110);

        $total = 0; foreach ($slices as $s) $total += $s['value'];
        if ($total <= 0) return self::emptyChart('No data.');

        $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid meet" style="width:100%;max-width:100%;font-family:inherit;">';

        $startAngle = -M_PI / 2;
        foreach ($slices as $s) {
            $frac = $s['value'] / $total;
            $endAngle = $startAngle + $frac * 2 * M_PI;
            $x1 = $cx + $r * cos($startAngle);
            $y1 = $cy + $r * sin($startAngle);
            $x2 = $cx + $r * cos($endAngle);
            $y2 = $cy + $r * sin($endAngle);
            $largeArc = $frac > 0.5 ? 1 : 0;
            $d = 'M ' . $cx . ' ' . $cy
               . ' L ' . round($x1, 2) . ' ' . round($y1, 2)
               . ' A ' . $r . ' ' . $r . ' 0 ' . $largeArc . ' 1 ' . round($x2, 2) . ' ' . round($y2, 2)
               . ' Z';
            $svg .= '<path d="' . $d . '" fill="' . e($s['color']) . '" stroke="#fff" stroke-width="1.5">';
            $svg .= '<title>' . e($s['label']) . ': ' . (int)$s['value'] . ' (' . round($frac * 100) . '%)</title>';
            $svg .= '</path>';
            $startAngle = $endAngle;
        }

        // Legend on the right.
        $ly = 20;
        foreach ($slices as $s) {
            $frac = $s['value'] / $total;
            $svg .= '<rect x="270" y="' . ($ly - 10) . '" width="12" height="12" rx="2" fill="' . e($s['color']) . '"/>';
            $svg .= '<text x="290" y="' . ($ly) . '" font-size="12" fill="#334155">' . e($s['label'])
                  . ' <tspan fill="#94A3B8">· ' . round($frac * 100) . '%</tspan></text>';
            $ly += 22;
            if ($ly > $h - 10) break;
        }

        $svg .= '</svg>';
        return $svg;
    }

    // ── Primitive: stacked bars per row-key ──────────────────────────────

    private static function renderStackedBars(array $matrix, array $rowLabels, array $stackLabels, array $opts = []): string {
        $w = 640; $h = (int)($opts['height'] ?? 280);
        $padL = 100; $padR = 12; $padT = 12; $padB = 32;
        $iw = $w - $padL - $padR;
        $ih = $h - $padT - $padB;
        $palette = self::palette();

        $rowKeys = array_keys($matrix);
        if (!$rowKeys) return self::emptyChart('No data.');
        $barH = ($ih / max(1, count($rowKeys))) * 0.7;
        $rowStep = $ih / max(1, count($rowKeys));

        // Compute per-row totals for percentage normalization.
        $rowTotals = [];
        foreach ($matrix as $rk => $cols) $rowTotals[$rk] = array_sum($cols);
        $maxTotal = max($rowTotals) ?: 1;

        $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid meet" style="width:100%;font-family:inherit;">';

        $i = 0;
        foreach ($matrix as $rowKey => $cols) {
            $rowTotal = $rowTotals[$rowKey];
            if ($rowTotal <= 0) { $i++; continue; }
            $y = $padT + $rowStep * $i + ($rowStep - $barH) / 2;

            // Bar width proportional to how many total entries this row saw.
            $rowW = $iw * ($rowTotal / $maxTotal);
            $cursor = $padL;

            foreach ($stackLabels as $catKey => $catLabel) {
                $val = $cols[$catKey] ?? 0;
                if ($val <= 0) continue;
                $segW = $rowW * ($val / $rowTotal);
                $color = $palette[$catKey] ?? $palette['other'];
                $svg .= '<rect x="' . round($cursor,1) . '" y="' . round($y,1) . '" width="' . round($segW,1) . '" height="' . round($barH,1) . '" fill="' . $color . '"><title>' . e($rowLabels[$rowKey] ?? $rowKey) . ' + ' . e($catLabel) . ': ' . $val . '</title></rect>';
                $cursor += $segW;
            }

            $rowLabel = $rowLabels[$rowKey] ?? $rowKey;
            $svg .= '<text x="' . ($padL - 8) . '" y="' . round($y + $barH/2 + 4, 1) . '" font-size="12" fill="' . $palette['axis'] . '" text-anchor="end">' . e($rowLabel) . '</text>';
            $i++;
        }

        // Compact legend under the chart.
        $lx = $padL; $ly = $padT + $ih + 18;
        foreach ($stackLabels as $catKey => $catLabel) {
            $color = $palette[$catKey] ?? $palette['other'];
            $svg .= '<rect x="' . $lx . '" y="' . ($ly - 8) . '" width="10" height="10" rx="2" fill="' . $color . '"/>';
            $svg .= '<text x="' . ($lx + 14) . '" y="' . $ly . '" font-size="10" fill="' . $palette['axis'] . '">' . e($catLabel) . '</text>';
            $lx += 14 + strlen($catLabel) * 6 + 12;
        }

        $svg .= '</svg>';
        return $svg;
    }

    // ── Primitive: daily stack (goal progress) ───────────────────────────

    private static function renderDailyStack(array $byDay, array $stackOrder, array $colors, array $opts = []): string {
        $w = 640; $h = (int)($opts['height'] ?? 240);
        $padL = 44; $padR = 12; $padT = 12; $padB = 30;
        $iw = $w - $padL - $padR;
        $ih = $h - $padT - $padB;
        $palette = self::palette();

        $days = array_keys($byDay);
        $n = count($days);
        if ($n === 0) return self::emptyChart('No data.');

        $maxY = 0;
        foreach ($byDay as $d => $counts) $maxY = max($maxY, array_sum($counts));
        if ($maxY < 3) $maxY = 3;

        $stepX = $iw / $n;
        $barW = $stepX * 0.7;

        $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid meet" style="width:100%;font-family:inherit;">';

        // Y grid at every integer.
        for ($t = 0; $t <= $maxY; $t++) {
            $y = $padT + $ih - ($t / $maxY) * $ih;
            $svg .= '<line x1="' . $padL . '" x2="' . ($padL + $iw) . '" y1="' . round($y,1) . '" y2="' . round($y,1) . '" stroke="' . $palette['grid'] . '"/>';
            $svg .= '<text x="' . ($padL - 6) . '" y="' . round($y+4,1) . '" font-size="11" fill="' . $palette['axis'] . '" text-anchor="end">' . $t . '</text>';
        }

        $i = 0;
        foreach ($byDay as $day => $counts) {
            $cx = $padL + $stepX * $i + $stepX / 2;
            $cursor = $padT + $ih;
            foreach ($stackOrder as $key => $label) {
                $val = $counts[$key] ?? 0;
                if ($val <= 0) continue;
                $segH = ($val / $maxY) * $ih;
                $y = $cursor - $segH;
                $svg .= '<rect x="' . round($cx - $barW/2, 1) . '" y="' . round($y,1) . '" width="' . round($barW,1) . '" height="' . round($segH,1) . '" fill="' . $colors[$key] . '"><title>' . e(I18n::localDate('j M', strtotime($day))) . ' · ' . e($label) . ': ' . $val . '</title></rect>';
                $cursor = $y;
            }
            $i++;
        }

        // X labels — every 3-4 days.
        $labelEvery = max(1, (int) floor($n / 6));
        foreach ($days as $i => $day) {
            if ($i % $labelEvery !== 0 && $i !== $n - 1) continue;
            $cx = $padL + $stepX * $i + $stepX / 2;
            $svg .= '<text x="' . round($cx,1) . '" y="' . ($padT + $ih + 16) . '" font-size="10" fill="' . $palette['axis'] . '" text-anchor="middle">' . e(I18n::localDate('j M', strtotime($day))) . '</text>';
        }

        // Legend.
        $lx = $padL; $ly = $padT + $ih + 26;
        foreach ($stackOrder as $key => $label) {
            $svg .= '<rect x="' . $lx . '" y="' . ($ly - 8) . '" width="10" height="10" rx="2" fill="' . $colors[$key] . '"/>';
            $svg .= '<text x="' . ($lx + 14) . '" y="' . $ly . '" font-size="10" fill="' . $palette['axis'] . '">' . e($label) . '</text>';
            $lx += 14 + strlen($label) * 6 + 12;
        }

        $svg .= '</svg>';
        return $svg;
    }

    // ── Primitive: empty-state placeholder ───────────────────────────────

    private static function emptyChart(string $message): string {
        return '<div style="padding:32px 20px;text-align:center;color:#94a3b8;background:rgba(148,163,184,0.05);border-radius:12px;font-size:14px;">'
             . e($message) . '</div>';
    }
}
