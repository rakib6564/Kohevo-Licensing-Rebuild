<?php
/**
 * Slate Forms — branded submission PDF.
 *
 * A self-contained PDF writer (no Composer / no external library). Produces a
 * modern, professional, brand-styled one-or-more-page document for a single
 * form submission:
 *
 *   ┌───────────────────────────────────────────────┐
 *   │  ▓▓ accent header band ▓▓   logo / business     │
 *   │                              SUBMISSION · REF   │
 *   ├───────────────────────────────────────────────┤
 *   │  Form title                                     │
 *   │  Submitted 31 May 2026, 14:02                   │
 *   │  ── meta chips: Reference · Date · Email ──     │
 *   │                                                 │
 *   │  SECTION HEADING                                │
 *   │  Label            Value                         │
 *   │  Label            Value (zebra-striped)         │
 *   │  Signature        [ image ]  Signed · date      │
 *   ├───────────────────────────────────────────────┤
 *   │  Business · address · phone        Page 1 / 1   │
 *   └───────────────────────────────────────────────┘
 *
 * Core (Helvetica) fonts only, so nothing to embed; images are flattened to
 * JPEG via GD and embedded with DCTDecode (the simplest reliable PDF image
 * path). Content streams are FlateDecode-compressed when zlib is present.
 */

class FormsPdf {

    // A4 in PostScript points.
    private const PAGE_W = 595.28;
    private const PAGE_H = 841.89;
    private const MARGIN = 50.0;
    private const HEADER_H = 96.0;
    private const FOOTER_H = 56.0;
    private const COL_GAP  = 28.0;   // gap between the two body columns

    /** @var array<int,string> page content operator buffers */
    private array $pages = [];
    /** Operator buffer for the page currently being written. */
    private string $buf = '';
    /** @var array<int,array{data:string,w:int,h:int}> embedded JPEG XObjects */
    private array $images = [];
    private float $y = 0.0;          // top-down cursor (0 = top of page)
    private float $headerExtra = 0.0; // extra header height when a hero band is drawn
    private array $accent = [0.145, 0.388, 0.922];   // #2563EB
    private array $ink    = [0.06, 0.09, 0.16];      // #0F172A
    private array $label  = [0.28, 0.33, 0.41];      // #475569 — row labels
    private array $muted  = [0.45, 0.51, 0.60];      // #738096 — eyebrows/footer
    private array $line   = [0.90, 0.92, 0.945];     // #E6EAF0 — hairlines
    private array $rule   = [0.82, 0.85, 0.89];      // #D2D8E1 — header divider
    private array $stripe = [0.969, 0.976, 0.984];   // (legacy, unused)
    private array $brand  = [];
    private float $pw = self::PAGE_W;   // page width  (pt) — set per page size
    private float $ph = self::PAGE_H;   // page height (pt)
    private array $sender = [];          // e-signature sender profile
    private bool  $signEnabled = true;   // render the Execution / signature block at all
    private bool  $signBoth    = false;  // two-party (client + company) vs single signature line
    private string $headingOverride = '';// per-form heading (defaults to form title)
    private string $introOverride   = '';// per-form recital paragraph (defaults to the built-in sentence)
    private string $footerNote      = '';// extra footer line beside the business line

    /**
     * Render a submission to PDF bytes.
     *
     * @param array  $form  forms_definitions row (needs title, fields)
     * @param array  $data  decoded submission data (post-handleSignatures)
     * @param string $ref   submission reference (SUB-XXXXXXXX)
     * @param array  $opts  ['brand'=>[...], 'accent'=>'#hex', 'submitted_at'=>'Y-m-d H:i:s']
     */
    public static function render(array $form, array $data, string $ref, array $opts = []): string {
        $pdf = new self();
        $pdf->brand = $opts['brand'] ?? [];
        $pdf->sender = is_array($opts['sender'] ?? null) ? $opts['sender'] : [];
        $pdf->signEnabled = array_key_exists('sign', $opts) ? (bool)$opts['sign'] : true;
        $pdf->signBoth    = !empty($opts['sign_both']);
        $pdf->headingOverride = trim((string)($opts['heading']     ?? ''));
        $pdf->introOverride   = trim((string)($opts['intro']       ?? ''));
        $pdf->footerNote      = trim((string)($opts['footer_note'] ?? ''));
        switch ((string)($opts['page'] ?? 'a4')) {
            case 'letter': $pdf->pw = 612.0; $pdf->ph = 792.0;  break;   // 8.5 × 11 in
            case 'legal':  $pdf->pw = 612.0; $pdf->ph = 1008.0; break;   // 8.5 × 14 in
            // 'a4' keeps the 595.28 × 841.89 defaults.
        }
        $hex = self::pickAccent($opts, $pdf->brand);
        if ($hex) $pdf->accent = self::hexToRgbF($hex);
        return $pdf->build($form, $data, $ref, (string)($opts['submitted_at'] ?? slate_db_now()));
    }

    /**
     * Assemble the brand block from the site's global settings. Used by both
     * the public submit path (email attachment) and the admin download.
     *
     * Delegates to the shared \BrandedEmail::brand() (the same settings read,
     * promoted to core so every plugin's emails read the identical palette)
     * rather than keeping its own copy.
     */
    public static function brandFromSettings(): array {
        return \BrandedEmail::brand();
    }

    private static function pickAccent(array $opts, array $brand): string {
        foreach ([$opts['accent'] ?? '', $brand['accent'] ?? ''] as $c) {
            $c = trim((string)$c);
            if (preg_match('/^#?[0-9a-f]{6}$/i', $c)) return $c;
        }
        return '';
    }

    // ───────────────────────── high-level layout ─────────────────────────

    private function build(array $form, array $data, string $ref, string $submittedAt): string {
        $fields = $form['fields'] ?? [];
        $title  = $this->headingOverride !== ''
                ? $this->headingOverride
                : (string)($form['title'] ?? 'Form submission');

        $submitter = $this->findSubmitterEmail($fields, $data);
        $tsLabel   = $this->fmtDate($submittedAt);

        // Only show the signing / execution area when the form actually HAS a
        // signature field. Forms with no signature field get no signature block
        // (the pdf_sign toggle no longer forces a blank line onto them).
        $hasSigField = false;
        foreach ($fields as $f) { if (($f['type'] ?? '') === 'signature') { $hasSigField = true; break; } }
        $showSign = $this->signEnabled && $hasSigField;

        $this->newPage();
        $this->drawHeader($ref);

        // ── Title block (compact, left-aligned, modern) ──────────────
        $this->y = self::HEADER_H + $this->headerExtra + 34;
        $this->text($title, self::MARGIN, $this->y, 18, true, $this->ink);
        $this->y += 15;
        $this->text('Reference ' . $ref . '    ·    Dated ' . $tsLabel, self::MARGIN, $this->y, 9, false, $this->muted, 0.3);
        $this->y += 14;
        $this->lineAt($this->y, $this->line, 0.8);
        $this->y += 18;

        // ── Recital (light, minimal) ─────────────────────────────────
        if ($this->introOverride !== '') {
            $recital = $this->introOverride;
        } else {
            $ackParty = $this->signBoth ? 'the parties acknowledge' : 'the signer acknowledges';
            $recital = 'Particulars submitted through the "' . $title . '" form'
                     . ($submitter ? ', provided by the client (' . $submitter . ')' : '')
                     . ($showSign
                           ? '. By signing below, ' . $ackParty . ' these details as true and correct.'
                           : '.');
        }
        $this->paragraphMuted($recital, 9.5, 14);
        $this->y += 12;

        // ── Particulars ──────────────────────────────────────────────
        $this->sectionHeading('Particulars');

        $signatures = [];
        $clientName = $this->findClientName($fields, $data);

        // Two-column compact layout: short answers pair up two-per-row (stacked
        // label/value cells); long/multi-line answers take a full-width row.
        $halfW   = ($this->pw - 2 * self::MARGIN - self::COL_GAP) / 2;
        $pending = null; // a [label,value] cell waiting for a partner
        foreach ($fields as $f) {
            $type = (string)($f['type'] ?? '');
            if ($type === 'hidden') continue;

            if ($type === 'signature') {
                $val = $data[$f['name'] ?? ''] ?? '';
                if (is_array($val) && !empty($val['signature'])) $signatures[] = $val;
                continue;   // signatures belong in the Execution block below
            }

            if ($type === 'step' || $type === 'heading') {
                if ($pending !== null) { $this->fieldStackRow([$pending]); $pending = null; }
                $label = trim((string)($f['label'] ?? ''));
                if ($label !== '') $this->subHeading($label);
                continue;
            }

            $name = (string)($f['name'] ?? '');
            if ($name === '') continue;
            $label = trim((string)($f['label'] ?? ''));
            if ($label === '') $label = $this->humanize($name);
            $value = $this->stringifyValue($data[$name] ?? '');

            if (count($this->wrap($value !== '' ? $value : '—', $halfW, 10.5, false)) > 1) {
                // Long → full width; flush any half-row first.
                if ($pending !== null) { $this->fieldStackRow([$pending]); $pending = null; }
                $this->fieldStackRow([[$label, $value]]);
            } elseif ($pending === null) {
                $pending = [$label, $value];
            } else {
                $this->fieldStackRow([$pending, [$label, $value]]);
                $pending = null;
            }
        }
        if ($pending !== null) $this->fieldStackRow([$pending]);

        // ── Execution / signatures ───────────────────────────────────
        // Shown only when the form has a signature field (or a signature was
        // actually captured). A form with no signature field gets no block.
        if ($showSign || !empty($signatures)) {
            $this->y += 22;
            $this->ensureSpace(150);
            $this->sectionHeading('Execution');
            $this->paragraph(
                $this->signBoth
                    ? 'Signed by the parties as of the date stated above.'
                    : 'Signed as of the date stated above.',
                10, 14.5);
            $this->y += 14;
            $this->executionBlock($signatures, $clientName, $tsLabel);
        }

        return $this->emit();   // footers + page numbers are stamped here
    }

    private function drawHeader(string $ref): void {
        $name = (string)($this->brand['name'] ?? 'Kohevo');
        $sub  = trim((string)($this->brand['sublabel'] ?? ''));
        $logo = $this->loadLogo();

        // Hero cover header: a darkened photo fills the whole header band with the
        // transparent logo composited straight onto it (no chip / background) and
        // the reference on the right. Falls back to a white letterhead with no hero.
        $hero = $this->loadHeroBand($this->pw / self::HEADER_H, true, true);
        if ($hero) {
            $white = [1.0, 1.0, 1.0];
            $faint = [0.82, 0.86, 0.92];
            $this->image($hero['id'], 0, 0, $this->pw, self::HEADER_H);
            // Logo is baked into the image above; only the reference sits as text.
            $this->textRight('SUBMISSION', $this->pw - self::MARGIN, 47, 8, true, $faint, 1, 1.6);
            $this->textRight($ref, $this->pw - self::MARGIN, 67, 14, true, $white);
            // Crisp accent seam along the bottom of the header.
            $this->rect(0, self::HEADER_H - 3, $this->pw, 3, $this->accent);
        } else {
            $this->rect(0, 0, $this->pw, 4, $this->accent);
            $tx = self::MARGIN;
            if ($logo) {
                $h = 40.0; $w = min($h * ($logo['w'] / max(1, $logo['h'])), 150.0);
                $this->image($logo['id'], self::MARGIN, 30, $w, $h);
                $tx = self::MARGIN + $w + 16;
                $this->text($name, $tx, $sub !== '' ? 50 : 56, 15, true, $this->ink);
                if ($sub !== '') $this->text($sub, $tx, 66, 9, false, $this->muted, 1, 0.4);
            } else {
                $this->text($name, $tx, $sub !== '' ? 49 : 57, 20, true, $this->ink);
                if ($sub !== '') $this->text($sub, $tx, 69, 9.5, false, $this->muted, 1, 0.4);
            }
            $this->textRight('SUBMISSION', $this->pw - self::MARGIN, 47, 8, true, $this->muted, 1, 1.6);
            $this->textRight($ref, $this->pw - self::MARGIN, 67, 14, true, $this->ink);
            $this->lineAt(self::HEADER_H, $this->rule, 1.0);
        }
        $this->headerExtra = 0.0; // the hero IS the header zone — no extra offset
    }

    /**
     * Load the hero image and GD cover-crop it to the given aspect ratio (w/h)
     * so the band fills cleanly without distortion. When $darken is set, a
     * semi-transparent navy overlay is composited in so white text stays legible
     * on top (used for the cover header). Returns an image descriptor or null.
     */
    private function loadHeroBand(float $aspect, bool $darken = false, bool $withLogo = false): ?array {
        $p = trim((string)($this->brand['hero_path'] ?? ''));
        if ($p === '' || !defined('SLATE_ROOT') || $aspect <= 0) return null;
        $disk = SLATE_ROOT . '/' . ltrim($p, '/');
        if (!is_file($disk) || !function_exists('imagecreatefromstring')) return null;
        $raw = @file_get_contents($disk);
        if ($raw === false || $raw === '') return null;
        $im = @imagecreatefromstring($raw);
        if (!$im) return null;
        $sw = imagesx($im); $sh = imagesy($im);
        if ($sw < 1 || $sh < 1) { imagedestroy($im); return null; }

        // Cover-crop the centre of the source to the target aspect ratio.
        $srcAspect = $sw / $sh;
        if ($srcAspect > $aspect) { $cw = (int)round($sh * $aspect); $ch = $sh; }
        else                      { $cw = $sw; $ch = (int)round($sw / $aspect); }
        $cw = max(1, min($cw, $sw)); $ch = max(1, min($ch, $sh));
        $cx = (int)max(0, round(($sw - $cw) / 2));
        $cy = (int)max(0, round(($sh - $ch) / 2));

        $ow = (int)min($cw, 1400); $oh = max(1, (int)round($ow / $aspect));
        $dst = imagecreatetruecolor($ow, $oh);
        imagecopyresampled($dst, $im, 0, 0, $cx, $cy, $ow, $oh, $cw, $ch);
        imagedestroy($im);

        if ($darken) {
            // ~55% dark-navy overlay → reliable legibility, independent of how
            // light the photo is. Works without imagefilter.
            imagealphablending($dst, true);
            $ov = imagecolorallocatealpha($dst, 8, 16, 30, 58);
            imagefilledrectangle($dst, 0, 0, $ow, $oh, $ov);
        }

        // Composite the TRANSPARENT logo straight onto the photo — no chip, no
        // background fill. The header maps PDF points → pixels at $ow/$pw.
        if ($withLogo) {
            $lp = trim((string)($this->brand['logo_path'] ?? ''));
            $ld = $lp !== '' ? SLATE_ROOT . '/' . ltrim($lp, '/') : '';
            if ($ld !== '' && is_file($ld)) {
                $lg = @imagecreatefromstring((string) @file_get_contents($ld));
                if ($lg) {
                    $lw = imagesx($lg); $lh = imagesy($lg);
                    if ($lw > 0 && $lh > 0) {
                        $scale = $ow / $this->pw;                 // pixels per PDF point
                        $ph    = 40.0;                            // logo height in points
                        $pwd   = min($ph * ($lw / $lh), 150.0);   // logo width in points
                        $dx = (int) round(self::MARGIN * $scale);
                        $dy = (int) round(((self::HEADER_H - $ph) / 2) * $scale);
                        $dw = max(1, (int) round($pwd * $scale));
                        $dh = max(1, (int) round($ph * $scale));
                        imagealphablending($dst, true);
                        imagecopyresampled($dst, $lg, $dx, $dy, 0, 0, $dw, $dh, $lw, $lh);
                    }
                    imagedestroy($lg);
                }
            }
        }

        ob_start(); imagejpeg($dst, null, 88); $jpeg = (string) ob_get_clean();
        imagedestroy($dst);
        if ($jpeg === '') return null;

        return $this->loadImageRaw($jpeg, false); // already opaque JPEG → register as-is
    }

    private function sectionHeading(string $label): void {
        $this->text(strtoupper($label), self::MARGIN, $this->y + 10, 9, true, $this->accent, 1, 0.8);
        $this->y += 18;
        $this->hr();
        $this->y += 10;
    }

    /** A lighter in-section sub-heading (form-defined heading/step labels). */
    private function subHeading(string $label): void {
        $this->y += 8;
        $this->ensureSpace(24);
        $this->y += 14;
        $this->text($label, self::MARGIN, $this->y, 11, true, $this->ink);
        $this->y += 8;
    }

    /** A left-aligned wrapped prose paragraph in ink. */
    private function paragraph(string $text, float $size, float $leading): void {
        $w = $this->pw - 2 * self::MARGIN;
        foreach ($this->wrap($text, $w, $size, false) as $ln) {
            $this->ensureSpace($leading);
            $this->y += $leading;
            $this->text($ln, self::MARGIN, $this->y, $size, false, $this->ink);
        }
    }

    /** A left-aligned wrapped prose paragraph in the muted tone. */
    private function paragraphMuted(string $text, float $size, float $leading): void {
        $w = $this->pw - 2 * self::MARGIN;
        foreach ($this->wrap($text, $w, $size, false) as $ln) {
            $this->ensureSpace($leading);
            $this->y += $leading;
            $this->text($ln, self::MARGIN, $this->y, $size, false, $this->muted);
        }
    }

    private function fieldRow(string $label, string $value): void {
        $labelW = 150.0;
        $valX   = self::MARGIN + $labelW + 20;
        $valW   = $this->pw - self::MARGIN - $valX;

        $lines  = $this->wrap($value !== '' ? $value : '—', $valW, 10.5, false);
        $labelLines = $this->wrap($label, $labelW, 9.5, true);
        $rowH   = max(30.0, max(count($lines) * 14.5, count($labelLines) * 13) + 16);
        $this->ensureSpace($rowH);

        $ly = $this->y + 20;
        foreach ($labelLines as $ll) { $this->text($ll, self::MARGIN, $ly, 9.5, true, $this->label); $ly += 13; }

        $vy = $this->y + 20;
        foreach ($lines as $vl) { $this->text($vl, $valX, $vy, 10.5, false, $this->ink); $vy += 14.5; }

        $this->y += $rowH;
        $this->lineAt($this->y, $this->line, 0.8);   // hairline row separator
    }

    /**
     * Render one body row of 1 or 2 stacked label/value cells. Two short answers
     * sit side-by-side (compact); a single long answer spans the full width.
     * $cells = [[label, value], (optional)[label, value]].
     */
    private function fieldStackRow(array $cells): void {
        $cells = array_values($cells);
        $two   = count($cells) === 2;
        $colW  = $two ? ($this->pw - 2 * self::MARGIN - self::COL_GAP) / 2
                      : ($this->pw - 2 * self::MARGIN);
        $xs    = $two ? [self::MARGIN, self::MARGIN + $colW + self::COL_GAP] : [self::MARGIN];

        $rowH = 0.0;
        foreach ($cells as $c) $rowH = max($rowH, $this->cellHeight((string)$c[0], (string)$c[1], $colW));
        $this->ensureSpace($rowH + 6);

        foreach ($cells as $i => $c) $this->drawCell($xs[$i], $colW, (string)$c[0], (string)$c[1]);

        $this->y += $rowH;
        $this->lineAt($this->y, $this->line, 0.8);
    }

    /**
     * Height of a stacked cell: the wrapped label lines + the wrapped value
     * lines. The label used to be assumed single-line, which let a long
     * field label draw past its column width unwrapped and visually bleed
     * into the next column — this keeps the row tall enough for however
     * many lines the label actually wraps to.
     */
    private function cellHeight(string $label, string $value, float $colW): float {
        $labelLines = max(1, count($this->wrap($label, $colW, 8.5, true)));
        $valueLines = max(1, count($this->wrap($value !== '' ? $value : '—', $colW, 10.5, false)));
        return 30.0 + ($labelLines - 1) * 10.0 + ($valueLines - 1) * 14.5 + 10.0;
    }

    /** Draw a stacked cell: an uppercase-muted label above the value lines. */
    private function drawCell(float $x, float $colW, string $label, string $value): void {
        $labelLines = $this->wrap($label, $colW, 8.5, true);
        $ly = $this->y + 13;
        foreach ($labelLines as $ll) {
            $this->text($ll, $x, $ly, 8.5, true, $this->label, 1, 0.4);
            $ly += 10.0;
        }
        $vy = $this->y + 30 + (count($labelLines) - 1) * 10.0;
        foreach ($this->wrap($value !== '' ? $value : '—', $colW, 10.5, false) as $vl) {
            $this->text($vl, $x, $vy, 10.5, false, $this->ink);
            $vy += 14.5;
        }
    }

    /**
     * Execution block. Defaults to a single signature line (the signer); when
     * "both parties" is enabled it becomes the two-column agreement block —
     * left is the client, right is the company counter-signature.
     *
     * A captured signature image (from a form signature field, or the sender
     * profile) is drawn above its line when present; otherwise the line is left
     * blank so the document can be signed by hand.
     */
    private function executionBlock(array $signatures, string $clientName, string $dateLabel): void {
        // Resolve the client's captured signature + a fallback name once; used
        // by both layouts.
        $sig = $signatures[0] ?? null;
        if ($sig && $clientName === '' && !empty($sig['name'])) {
            $clientName = (string)$sig['name'];
        }

        if (!$this->signBoth) {
            $this->executionSingle($sig, $clientName, $dateLabel);
            return;
        }

        $colGap = 36.0;
        $colW   = ($this->pw - 2 * self::MARGIN - $colGap) / 2;
        $leftX  = self::MARGIN;
        $rightX = self::MARGIN + $colW + $colGap;
        $sigH   = 56.0;                       // space above the line for the image
        $this->ensureSpace($sigH + 70);
        $top    = $this->y;

        // Captured client signature image sits just above the left line.
        if ($sig) {
            $img = $this->loadImageFromUploadPath((string)($sig['path'] ?? ''));
            if ($img) {
                $maxW = $colW; $maxH = $sigH - 4;
                $ratio = min($maxW / max(1, $img['w']), $maxH / max(1, $img['h']));
                $dw = $img['w'] * $ratio; $dh = $img['h'] * $ratio;
                $this->image($img['id'], $leftX, $top + ($sigH - $dh), $dw, $dh);
            }
        }

        // Sender (counter-signature) — preset from the E-Signature sender profile:
        // their captured signature above the right line, plus name + date below.
        $senderName    = trim((string)($this->sender['name'] ?? ''));
        $senderCompany = trim((string)($this->sender['company'] ?? '')) ?: (string)($this->brand['name'] ?? '');
        $senderSig     = (string)($this->sender['sig'] ?? '');
        if ($senderSig !== '') {
            $simg = $this->loadImageFromDataUrl($senderSig);
            if ($simg) {
                $maxW = $colW; $maxH = $sigH - 4;
                $ratio = min($maxW / max(1, $simg['w']), $maxH / max(1, $simg['h']));
                $dw = $simg['w'] * $ratio; $dh = $simg['h'] * $ratio;
                $this->image($simg['id'], $rightX, $top + ($sigH - $dh), $dw, $dh);
            }
        }

        $lineY = $top + $sigH;
        $this->sigLine($leftX, $lineY, $colW);
        $this->sigLine($rightX, $lineY, $colW);

        $this->text(strtoupper('Client'), $leftX, $lineY + 13, 8, true, $this->muted, 1, 0.7);
        $this->text(strtoupper($senderCompany !== '' ? 'For ' . $senderCompany : 'Authorised representative'),
                    $rightX, $lineY + 13, 8, true, $this->muted, 1, 0.7);

        $this->text('Name:  ' . $clientName, $leftX, $lineY + 30, 10, false, $this->ink);
        $this->text('Name:  ' . $senderName, $rightX, $lineY + 30, 10, false, $this->ink);
        $this->text('Date:  ' . $dateLabel, $leftX, $lineY + 45, 10, false, $this->ink);
        $this->text('Date:  ' . $dateLabel, $rightX, $lineY + 45, 10, false, $this->ink);

        $this->y = $lineY + 52;
    }

    /**
     * Single-party signature line. Used when "both parties" is off — including
     * the case where the form has no signature field at all, in which case the
     * line is simply left blank for a hand-written signature.
     *
     * @param array|null $sig captured signature {path, name} or null
     */
    private function executionSingle(?array $sig, string $clientName, string $dateLabel): void {
        $colW = min(280.0, $this->pw - 2 * self::MARGIN);
        $x    = self::MARGIN;
        $sigH = 56.0;
        $this->ensureSpace($sigH + 70);
        $top  = $this->y;

        // Draw the captured signature image above the line, if we have one.
        if ($sig) {
            $img = $this->loadImageFromUploadPath((string)($sig['path'] ?? ''));
            if ($img) {
                $maxW = $colW; $maxH = $sigH - 4;
                $ratio = min($maxW / max(1, $img['w']), $maxH / max(1, $img['h']));
                $dw = $img['w'] * $ratio; $dh = $img['h'] * $ratio;
                $this->image($img['id'], $x, $top + ($sigH - $dh), $dw, $dh);
            }
        }

        $lineY = $top + $sigH;
        $this->sigLine($x, $lineY, $colW);
        $this->text(strtoupper('Signature'), $x, $lineY + 13, 8, true, $this->muted, 1, 0.7);
        $this->text('Name:  ' . $clientName, $x, $lineY + 30, 10, false, $this->ink);
        $this->text('Date:  ' . $dateLabel, $x, $lineY + 45, 10, false, $this->ink);

        $this->y = $lineY + 52;
    }

    /** A solid signature rule from x to x+w at top-down y. */
    private function sigLine(float $x, float $y, float $w): void {
        $this->buf .= sprintf("%.3f %.3f %.3f RG\n0.9 w\n%.2f %.2f m\n%.2f %.2f l\nS\n",
            $this->label[0], $this->label[1], $this->label[2],
            $x, $this->fy($y), $x + $w, $this->fy($y));
    }

    /** Best-effort "client name" from a name-like text field. */
    private function findClientName(array $fields, array $data): string {
        foreach ($fields as $f) {
            if (($f['type'] ?? '') !== 'text') continue;
            $key = strtolower((string)($f['label'] ?? '') . ' ' . (string)($f['name'] ?? ''));
            if (strpos($key, 'name') !== false) {
                $v = trim((string)($data[$f['name']] ?? ''));
                if ($v !== '') return $v;
            }
        }
        return '';
    }

    private function footerLine(): string {
        $bits = array_filter([
            (string)($this->brand['name'] ?? ''),
            trim((string)($this->brand['address'] ?? '')),
            trim((string)($this->brand['phone'] ?? '')),
            $this->footerNote,
        ]);
        return implode('   ·   ', $bits);
    }

    // ───────────────────────── value formatting ──────────────────────────

    private function stringifyValue($val): string {
        if (is_bool($val)) return $val ? 'Yes' : 'No';
        if (is_array($val)) {
            if (isset($val['path']) && isset($val['original'])) {
                $o = (string)$val['original'];
                return ($o !== '' ? $o : basename((string)$val['path'])) . ' (attached)';
            }
            return implode(', ', array_map(fn($v) => is_scalar($v) ? (string)$v : json_encode($v), $val));
        }
        return trim((string)$val);
    }

    private function findSubmitterEmail(array $fields, array $data): string {
        foreach ($fields as $f) {
            if (($f['type'] ?? '') === 'email') {
                $v = $data[$f['name']] ?? '';
                if (is_string($v) && filter_var($v, FILTER_VALIDATE_EMAIL)) return $v;
            }
        }
        return '';
    }

    private function fmtDate(string $ts): string {
        $t = strtotime($ts) ?: time();
        return I18n::localDate('j M Y, H:i', $t);
    }

    // ───────────────────────── image loading ─────────────────────────────

    private function loadLogo(): ?array {
        $p = trim((string)($this->brand['logo_path'] ?? ''));
        if ($p === '') return null;
        $disk = defined('SLATE_ROOT') ? (SLATE_ROOT . '/' . ltrim($p, '/')) : '';
        if ($disk === '' || !is_file($disk)) return null;
        return $this->loadImageFile($disk, true);   // keep transparency → white
    }

    private function loadImageFromUploadPath(string $urlPath): ?array {
        $urlPath = trim($urlPath);
        if ($urlPath === '' || !defined('SLATE_ROOT')) return null;
        $disk = SLATE_ROOT . '/' . ltrim($urlPath, '/');
        if (!is_file($disk)) return null;
        return $this->loadImageFile($disk, true);
    }

    /** Load an image file, flatten to JPEG via GD, register as an XObject. */
    private function loadImageFile(string $disk, bool $onWhite): ?array {
        $raw = @file_get_contents($disk);
        if ($raw === false || $raw === '') return null;
        return $this->loadImageRaw($raw, $onWhite);
    }

    /** Load an image from a base64 data URL (e.g. a captured PNG signature). */
    private function loadImageFromDataUrl(string $dataUrl, bool $onWhite = true): ?array {
        $dataUrl = trim($dataUrl);
        $pos = strpos($dataUrl, 'base64,');
        if ($pos === false) return null;
        $raw = base64_decode(substr($dataUrl, $pos + 7), true);
        if ($raw === false || $raw === '') return null;
        return $this->loadImageRaw($raw, $onWhite);
    }

    /** Flatten raw image bytes to JPEG via GD and register as an XObject. */
    private function loadImageRaw(string $raw, bool $onWhite): ?array {
        if (!function_exists('imagecreatefromstring')) return null;
        $im = @imagecreatefromstring($raw);   // png/jpg/gif/webp (not svg)
        if (!$im) return null;
        $w = imagesx($im); $h = imagesy($im);
        if ($w < 1 || $h < 1) { imagedestroy($im); return null; }

        if ($onWhite) {
            $bg = imagecreatetruecolor($w, $h);
            $white = imagecolorallocate($bg, 255, 255, 255);
            imagefilledrectangle($bg, 0, 0, $w, $h, $white);
            imagecopy($bg, $im, 0, 0, 0, 0, $w, $h);
            imagedestroy($im);
            $im = $bg;
        }
        ob_start();
        imagejpeg($im, null, 88);
        $jpeg = (string) ob_get_clean();
        imagedestroy($im);
        if ($jpeg === '') return null;

        $id = count($this->images) + 1;
        $this->images[$id] = ['data' => $jpeg, 'w' => $w, 'h' => $h];
        return ['id' => $id, 'w' => $w, 'h' => $h];
    }

    // ───────────────────────── drawing primitives ────────────────────────

    private function newPage(): void {
        if ($this->buf !== '') { $this->pages[] = $this->buf; $this->buf = ''; }
        $this->y = 0;
    }

    private function ensureSpace(float $need): void {
        if ($this->y + $need > $this->ph - self::FOOTER_H) {
            $this->pages[] = $this->buf;
            $this->buf = '';
            // Slim continuation header
            $this->rect(0, 0, $this->pw, 8, $this->accent);
            $this->y = 44;
        }
    }

    /** Convert top-down y to PDF bottom-up. */
    private function fy(float $y): float { return $this->ph - $y; }

    private function rect(float $x, float $y, float $w, float $h, array $rgb): void {
        $this->buf .= sprintf("%.3f %.3f %.3f rg\n%.2f %.2f %.2f %.2f re\nf\n",
            $rgb[0], $rgb[1], $rgb[2], $x, $this->fy($y + $h), $w, $h);
    }

    /** Rounded rectangle (fill, optional stroke). */
    private function roundRect(float $x, float $y, float $w, float $h, float $r, array $fill, ?array $stroke = null, float $sw = 1): void {
        $r = min($r, $w / 2, $h / 2);
        $yb = $this->fy($y + $h);   // bottom in PDF space
        $yt = $this->fy($y);        // top in PDF space
        $x2 = $x + $w;
        $k  = 0.5523 * $r;
        $b  = "%.3f %.3f %.3f rg\n";
        $this->buf .= sprintf($b, $fill[0], $fill[1], $fill[2]);
        if ($stroke) {
            $this->buf .= sprintf("%.3f %.3f %.3f RG\n%.2f w\n", $stroke[0], $stroke[1], $stroke[2], $sw);
        }
        // Path clockwise from bottom-left+r
        $this->buf .= sprintf("%.2f %.2f m\n", $x + $r, $yb);
        $this->buf .= sprintf("%.2f %.2f l\n", $x2 - $r, $yb);
        $this->buf .= sprintf("%.2f %.2f %.2f %.2f %.2f %.2f c\n", $x2 - $r + $k, $yb, $x2, $yb + $r - $k, $x2, $yb + $r);
        $this->buf .= sprintf("%.2f %.2f l\n", $x2, $yt - $r);
        $this->buf .= sprintf("%.2f %.2f %.2f %.2f %.2f %.2f c\n", $x2, $yt - $r + $k, $x2 - $r + $k, $yt, $x2 - $r, $yt);
        $this->buf .= sprintf("%.2f %.2f l\n", $x + $r, $yt);
        $this->buf .= sprintf("%.2f %.2f %.2f %.2f %.2f %.2f c\n", $x + $r - $k, $yt, $x, $yt - $r + $k, $x, $yt - $r);
        $this->buf .= sprintf("%.2f %.2f l\n", $x, $yb + $r);
        $this->buf .= sprintf("%.2f %.2f %.2f %.2f %.2f %.2f c\n", $x, $yb + $r - $k, $x + $r - $k, $yb, $x + $r, $yb);
        $this->buf .= $stroke ? "b\n" : "f\n";
    }

    private function hr(): void {
        $this->lineAt($this->y, $this->line, 0.8);
    }

    /** Horizontal hairline across the content width at a top-down y. */
    private function lineAt(float $y, array $rgb, float $width = 0.8): void {
        $this->buf .= sprintf("%.3f %.3f %.3f RG\n%.2f w\n%.2f %.2f m\n%.2f %.2f l\nS\n",
            $rgb[0], $rgb[1], $rgb[2], $width,
            self::MARGIN, $this->fy($y), $this->pw - self::MARGIN, $this->fy($y));
    }

    private function humanize(string $s): string {
        $s = trim(str_replace(['_', '-'], ' ', $s));
        return $s === '' ? '' : ucwords($s);
    }

    private function image(int $id, float $x, float $y, float $w, float $h): void {
        $this->buf .= sprintf("q\n%.2f 0 0 %.2f %.2f %.2f cm\n/Im%d Do\nQ\n",
            $w, $h, $x, $this->fy($y + $h), $id);
    }

    private function text(string $s, float $x, float $y, float $size, bool $bold, array $rgb, float $alpha = 1, float $tracking = 0): void {
        $s = $this->enc($s);
        if ($s === '') return;
        $font = $bold ? '/F2' : '/F1';
        $tc = $tracking != 0 ? sprintf("%.2f Tc\n", $tracking) : '';
        $this->buf .= sprintf("BT\n%s %.2f Tf\n%.3f %.3f %.3f rg\n%s%.2f %.2f Td\n(%s) Tj\nET\n%s",
            $font, $size, $rgb[0], $rgb[1], $rgb[2], $tc, $x, $this->fy($y), $s, $tracking != 0 ? "0 Tc\n" : '');
    }

    private function textRight(string $s, float $rx, float $y, float $size, bool $bold, array $rgb, float $alpha = 1, float $tracking = 0): void {
        $w = $this->textWidth($s, $size, $bold) + ($tracking != 0 ? $tracking * max(0, mb_strlen($s) - 1) : 0);
        $this->text($s, $rx - $w, $y, $size, $bold, $rgb, $alpha, $tracking);
    }

    /** Draw a string horizontally centred on $cx at top-down $y. */
    private function textCenter(string $s, float $cx, float $y, float $size, bool $bold, array $rgb, float $tracking = 0): void {
        $w = $this->textWidth($s, $size, $bold) + ($tracking != 0 ? $tracking * max(0, mb_strlen($s) - 1) : 0);
        $this->text($s, $cx - $w / 2, $y, $size, $bold, $rgb, 1, $tracking);
    }

    // ───────────────────────── text measurement ──────────────────────────

    /** Approximate Helvetica advance width (avg factor — good enough for wrap). */
    private function textWidth(string $s, float $size, bool $bold): float {
        $factor = $bold ? 0.545 : 0.512;
        return mb_strlen($this->enc($s)) * $size * $factor;
    }

    /** Word-wrap a string to a max width; returns lines. */
    private function wrap(string $s, float $maxW, float $size, bool $bold): array {
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $out = [];
        foreach (explode("\n", $s) as $para) {
            $words = preg_split('/\s+/', trim($para)) ?: [];
            if ($words === [''] || $words === []) { $out[] = ''; continue; }
            $cur = '';
            foreach ($words as $word) {
                // A single token longer than the line gets hard-broken.
                if ($this->textWidth($word, $size, $bold) > $maxW) {
                    if ($cur !== '') { $out[] = $cur; $cur = ''; }
                    $pieces = $this->hardBreak($word, $maxW, $size, $bold);
                    $cur = (string) array_pop($pieces);
                    foreach ($pieces as $p) $out[] = $p;
                    continue;
                }
                $try = $cur === '' ? $word : $cur . ' ' . $word;
                if ($cur === '' || $this->textWidth($try, $size, $bold) <= $maxW) {
                    $cur = $try;
                } else {
                    $out[] = $cur;
                    $cur = $word;
                }
            }
            if ($cur !== '') $out[] = $cur;
        }
        return $out === [] ? [''] : $out;
    }

    private function hardBreak(string $word, float $maxW, float $size, bool $bold): array {
        $pieces = []; $cur = '';
        $len = mb_strlen($word);
        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($word, $i, 1);
            if ($this->textWidth($cur . $ch, $size, $bold) > $maxW && $cur !== '') {
                $pieces[] = $cur; $cur = $ch;
            } else {
                $cur .= $ch;
            }
        }
        if ($cur !== '') $pieces[] = $cur;
        return $pieces;
    }

    /** UTF-8 → WinAnsi + PDF string escaping. */
    private function enc(string $s): string {
        if (function_exists('iconv')) {
            $c = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s);
            if ($c !== false) $s = $c;
        }
        return strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']);
    }

    // ───────────────────────── assembly / xref ───────────────────────────

    private function emit(): string {
        $this->pages[] = $this->buf;   // flush last
        $this->buf = '';

        // Append footer to each page buffer now that page count is known.
        $total = count($this->pages);
        $footTxt = $this->footerLine();
        foreach ($this->pages as $i => &$pbuf) {
            $save = $this->buf; $this->buf = $pbuf;
            $this->buf .= sprintf("%.3f %.3f %.3f RG\n0.8 w\n%.2f %.2f m\n%.2f %.2f l\nS\n",
                $this->line[0], $this->line[1], $this->line[2],
                self::MARGIN, self::FOOTER_H - 8, $this->pw - self::MARGIN, self::FOOTER_H - 8);
            if ($footTxt !== '') $this->text($footTxt, self::MARGIN, $this->ph - 22, 8, false, $this->muted);
            $this->textRight('Page ' . ($i + 1) . ' / ' . $total, $this->pw - self::MARGIN, $this->ph - 22, 8, false, $this->muted);
            $pbuf = $this->buf; $this->buf = $save;
        }
        unset($pbuf);

        $objs = [];
        $useFlate = function_exists('gzcompress');

        // Reserve object numbers:
        // 1 Catalog, 2 Pages, then per page: page dict + content stream,
        // then 2 fonts, then images.
        $nPages = count($this->pages);
        $pageObjStart = 3;
        $contentObjStart = $pageObjStart + $nPages;
        $fontF1 = $contentObjStart + $nPages;
        $fontF2 = $fontF1 + 1;
        $imgStart = $fontF2 + 1;

        $kids = [];
        for ($i = 0; $i < $nPages; $i++) $kids[] = ($pageObjStart + $i) . ' 0 R';

        $objs[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objs[2] = "<< /Type /Pages /Count {$nPages} /Kids [" . implode(' ', $kids) . "] >>";

        // Shared resources
        $imgRes = '';
        foreach ($this->images as $id => $img) {
            $imgRes .= "/Im{$id} " . ($imgStart + $id - 1) . " 0 R ";
        }
        $resources = "<< /Font << /F1 {$fontF1} 0 R /F2 {$fontF2} 0 R >>"
                   . ($imgRes !== '' ? " /XObject << {$imgRes}>>" : '')
                   . " /ProcSet [/PDF /Text /ImageC] >>";

        for ($i = 0; $i < $nPages; $i++) {
            $pageObj    = $pageObjStart + $i;
            $contentObj = $contentObjStart + $i;
            $objs[$pageObj] = "<< /Type /Page /Parent 2 0 R "
                . sprintf("/MediaBox [0 0 %.2f %.2f] ", $this->pw, $this->ph)
                . "/Resources {$resources} /Contents {$contentObj} 0 R >>";

            $stream = $this->pages[$i];
            if ($useFlate) {
                $comp = gzcompress($stream, 6);
                $objs[$contentObj] = "<< /Length " . strlen($comp) . " /Filter /FlateDecode >>\nstream\n" . $comp . "\nendstream";
            } else {
                $objs[$contentObj] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
            }
        }

        $objs[$fontF1] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objs[$fontF2] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

        foreach ($this->images as $id => $img) {
            $obj = $imgStart + $id - 1;
            $objs[$obj] = "<< /Type /XObject /Subtype /Image /Width {$img['w']} /Height {$img['h']} "
                . "/ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($img['data']) . " >>\n"
                . "stream\n" . $img['data'] . "\nendstream";
        }

        // Serialize with xref
        ksort($objs);
        $out = "%PDF-1.5\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        $maxId = max(array_keys($objs));
        foreach ($objs as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= "{$id} 0 obj\n{$body}\nendobj\n";
        }
        $xrefPos = strlen($out);
        $count = $maxId + 1;
        $out .= "xref\n0 {$count}\n0000000000 65535 f \n";
        for ($id = 1; $id <= $maxId; $id++) {
            if (isset($offsets[$id])) {
                $out .= sprintf("%010d 00000 n \n", $offsets[$id]);
            } else {
                $out .= "0000000000 65535 f \n";
            }
        }
        $out .= "trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xrefPos}\n%%EOF";
        return $out;
    }

    private static function hexToRgbF(string $hex): array {
        $hex = ltrim(trim($hex), '#');
        return [hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255];
    }
}
