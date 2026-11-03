<?php
/**
 * Slate — image work for the media library, on GD (no Imagick needed).
 *
 *   - normalize(): turn a camera photo upright from its EXIF orientation and
 *     re-encode it, which drops EXIF (GPS position, camera serial) from JPEGs.
 *   - makeVariants(): WebP copies at a few fixed widths, only those narrower
 *     than the original, written next to it as <name>-<width>w.webp.
 *   - transform(): rotate, flip and crop in place, keeping the file's format.
 *
 * Static and filesystem-only: the Media service owns the database rows. Each
 * method returns null/false instead of throwing when GD cannot do the work,
 * so an upload never fails because of an optional step.
 */

declare(strict_types=1);

namespace Slate\Services\Media;

final class ImageProcessor
{
    /** Widths of the WebP copies. Kept short: every copy is one more file on disk. */
    public const WIDTHS = [480, 960, 1600];

    /** Above this many pixels GD would need too much memory; such files are left as they are. */
    public const MAX_PIXELS = 40_000_000;

    public const JPEG_QUALITY = 88;
    public const WEBP_QUALITY = 80;

    /** Formats GD can read and write safely. GIF is left alone (animation), SVG is not raster. */
    public const MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public static function available(): bool
    {
        return function_exists('imagecreatetruecolor') && function_exists('imagewebp');
    }

    public static function supports(string $mime): bool
    {
        return self::available() && in_array(strtolower($mime), self::MIMES, true);
    }

    /**
     * The widths to make for an image this wide: every configured width below it.
     *
     * @return list<int>
     */
    public static function widthsFor(int $width): array
    {
        return array_values(array_filter(self::WIDTHS, static fn(int $w): bool => $w < $width));
    }

    /** Where the copy of $relPath at $width lives (same folder, WebP). */
    public static function variantPath(string $relPath, int $width): string
    {
        $dir  = dirname($relPath);
        $base = pathinfo($relPath, PATHINFO_FILENAME);
        return ($dir === '.' ? '' : rtrim($dir, '/')) . '/' . $base . '-' . $width . 'w.webp';
    }

    /**
     * Upright a JPEG from its EXIF orientation and strip its metadata by
     * re-encoding. PNG and WebP are left as they are (no EXIF orientation in
     * practice). Returns true when the file was rewritten.
     */
    public static function normalize(string $absPath, string $mime): bool
    {
        if (!self::supports($mime) || strtolower($mime) !== 'image/jpeg' || !self::fits($absPath)) {
            return false;
        }
        $orientation = 1;
        $hasExif = false;
        if (function_exists('exif_read_data')) {
            // exif_read_data() answers for any JPEG; only real EXIF sections (IFD0, EXIF, GPS) count.
            $exif = @exif_read_data($absPath);
            if (is_array($exif) && preg_match('/\b(IFD0|EXIF|GPS)\b/', (string) ($exif['SectionsFound'] ?? '')) === 1) {
                $hasExif = true;
                $orientation = (int) ($exif['Orientation'] ?? 1);
            }
        }
        if (!$hasExif) {
            return false;
        }
        $img = @imagecreatefromjpeg($absPath);
        if ($img === false) {
            return false;
        }
        $img = self::orient($img, $orientation);
        $ok = @imagejpeg($img, $absPath, self::JPEG_QUALITY);
        return $ok;
    }

    /**
     * Write the WebP copies of an image. $relPath is the stored /uploads/... path.
     *
     * @return array<int, string> width => stored path of each copy written
     */
    public static function makeVariants(string $root, string $relPath, string $mime): array
    {
        $abs = $root . $relPath;
        if (!self::supports($mime) || !is_file($abs) || !self::fits($abs)) {
            return [];
        }
        $info = @getimagesize($abs);
        if (!is_array($info) || ($info[0] ?? 0) <= 0) {
            return [];
        }
        [$srcW, $srcH] = [(int) $info[0], (int) $info[1]];
        $widths = self::widthsFor($srcW);
        if ($widths === []) {
            return [];
        }
        $src = self::open($abs, $mime);
        if ($src === null) {
            return [];
        }
        $out = [];
        foreach ($widths as $w) {
            $h = max(1, (int) round($srcH * $w / $srcW));
            $dst = imagecreatetruecolor($w, $h);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $srcW, $srcH);
            $rel = self::variantPath($relPath, $w);
            if (@imagewebp($dst, $root . $rel, self::WEBP_QUALITY)) {
                @chmod($root . $rel, 0644);
                $out[$w] = $rel;
            }
        }
        return $out;
    }

    /**
     * Rotate, flip and/or crop an image in place, keeping its format.
     * $ops keys (all optional, applied in this order):
     *   rotate  90 | 180 | 270   (clockwise)
     *   flip    'h' | 'v'
     *   crop    [x, y, w, h] in pixels of the image as it is after rotate/flip
     *
     * @return array{width: int, height: int}|null the new size, or null when nothing could be done or nothing would change
     */
    public static function transform(string $absPath, string $mime, array $ops): ?array
    {
        if (!self::supports($mime) || !is_file($absPath) || !self::fits($absPath)) {
            return null;
        }
        $img = self::open($absPath, $mime);
        if ($img === null) {
            return null;
        }
        $changed = false;
        $rotate = (int) ($ops['rotate'] ?? 0);
        if (in_array($rotate, [90, 180, 270], true)) {
            $changed = true;
            // imagerotate() turns counter-clockwise.
            $img = self::replace($img, imagerotate($img, 360 - $rotate, self::clear($img)));
        }
        $flip = (string) ($ops['flip'] ?? '');
        if ($flip === 'h' || $flip === 'v') {
            $changed = true;
            imageflip($img, $flip === 'h' ? IMG_FLIP_HORIZONTAL : IMG_FLIP_VERTICAL);
        }
        $crop = self::cropBox($ops['crop'] ?? null, imagesx($img), imagesy($img));
        if ($crop !== null) {
            $changed = true;
            $img = self::replace($img, imagecrop($img, $crop));
        }
        if ($img === null || !$changed) {
            return null;
        }
        $size = ['width' => imagesx($img), 'height' => imagesy($img)];
        $ok = self::save($img, $absPath, $mime);
        return $ok ? $size : null;
    }

    /**
     * A crop rectangle clamped inside the image, or null when it is missing, empty or the whole image.
     *
     * @return array{x: int, y: int, width: int, height: int}|null
     */
    public static function cropBox(mixed $box, int $imgW, int $imgH): ?array
    {
        if (!is_array($box) || count($box) !== 4) {
            return null;
        }
        [$x, $y, $w, $h] = array_map(static fn($v): int => (int) round((float) $v), array_values($box));
        $x = max(0, min($imgW - 1, $x));
        $y = max(0, min($imgH - 1, $y));
        $w = max(1, min($imgW - $x, $w));
        $h = max(1, min($imgH - $y, $h));
        if ($w < 8 || $h < 8 || ($x === 0 && $y === 0 && $w === $imgW && $h === $imgH)) {
            return null;
        }
        return ['x' => $x, 'y' => $y, 'width' => $w, 'height' => $h];
    }

    private static function fits(string $absPath): bool
    {
        $info = @getimagesize($absPath);
        return is_array($info) && (int) $info[0] * (int) $info[1] <= self::MAX_PIXELS;
    }

    private static function open(string $absPath, string $mime): ?\GdImage
    {
        $img = match (strtolower($mime)) {
            'image/jpeg' => @imagecreatefromjpeg($absPath),
            'image/png'  => @imagecreatefrompng($absPath),
            'image/webp' => @imagecreatefromwebp($absPath),
            default      => false,
        };
        if (!$img instanceof \GdImage) {
            return null;
        }
        if (!imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }
        imagealphablending($img, false);
        imagesavealpha($img, true);
        return $img;
    }

    private static function save(\GdImage $img, string $absPath, string $mime): bool
    {
        // Write beside the file, then swap, so a failed write never leaves half an image.
        $tmp = $absPath . '.tmp-' . bin2hex(random_bytes(4));
        $ok = match (strtolower($mime)) {
            'image/jpeg' => @imagejpeg($img, $tmp, self::JPEG_QUALITY),
            'image/png'  => @imagepng($img, $tmp, 6),
            'image/webp' => @imagewebp($img, $tmp, 90),
            default      => false,
        };
        if (!$ok || !@rename($tmp, $absPath)) {
            @unlink($tmp);
            return false;
        }
        @chmod($absPath, 0644);
        return true;
    }

    /** Undo an EXIF orientation: rotate first, then mirror (5 and 7 are a rotation plus a mirror). */
    private static function orient(\GdImage $img, int $orientation): \GdImage
    {
        $angle = match ($orientation) {
            3       => 180,
            5, 6    => 270, // imagerotate() is counter-clockwise: 270 = 90° clockwise
            7, 8    => 90,
            default => 0,
        };
        if ($angle !== 0) {
            $img = self::replace($img, imagerotate($img, $angle, 0)) ?? $img;
        }
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($img, $orientation === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
        }
        return $img;
    }

    private static function replace(?\GdImage $old, \GdImage|false $new): ?\GdImage
    {
        if (!$new instanceof \GdImage) {
            return $old;
        }
        if ($old !== null && $old !== $new) {
        }
        imagealphablending($new, false);
        imagesavealpha($new, true);
        return $new;
    }

    private static function clear(\GdImage $img): int
    {
        return imagecolorallocatealpha($img, 0, 0, 0, 127);
    }
}
