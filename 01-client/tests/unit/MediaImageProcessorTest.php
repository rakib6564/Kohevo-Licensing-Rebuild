<?php
/**
 * Unit tests for the media library's image work (Slate\Services\Media\ImageProcessor), media library Phase 2.
 *
 * GD only, no database. Images are drawn in a temporary folder: WebP copies at the configured widths, rotate, flip
 * and crop in place, and a phone photo with an EXIF orientation turned upright with its EXIF removed.
 */

declare(strict_types=1);

use Slate\Services\Media\ImageProcessor;

/** A fresh temporary folder that stands in for the site root. */
function mip_root(): string
{
    $dir = sys_get_temp_dir() . '/mip-' . bin2hex(random_bytes(4));
    mkdir($dir . '/uploads/media', 0775, true);
    return $dir;
}

function mip_rm(string $dir): void
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

/** A $w × $h image, left half red and right half blue, saved as $mime. */
function mip_image(string $abs, int $w, int $h, string $mime = 'image/jpeg'): void
{
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, intdiv($w, 2) - 1, $h - 1, imagecolorallocate($im, 255, 0, 0));
    imagefilledrectangle($im, intdiv($w, 2), 0, $w - 1, $h - 1, imagecolorallocate($im, 0, 0, 255));
    match ($mime) {
        'image/png'  => imagepng($im, $abs),
        'image/webp' => imagewebp($im, $abs),
        default      => imagejpeg($im, $abs, 95),
    };
}

/** The colour at a point, as [r, g, b]. */
function mip_rgb(string $abs, int $x, int $y): array
{
    $im = imagecreatefromstring((string) file_get_contents($abs));
    $c = imagecolorsforindex($im, imagecolorat($im, $x, $y));
    return [$c['red'], $c['green'], $c['blue']];
}

function mip_is_red(array $rgb): bool
{
    return $rgb[0] > 200 && $rgb[2] < 60;
}

/** A JPEG with an EXIF block that holds only an orientation tag (what a phone writes for a sideways shot). */
function mip_jpeg_with_orientation(string $abs, int $orientation): void
{
    $tiff = "II*\0" . pack('V', 8) . pack('v', 1) . pack('vvVv', 0x0112, 3, 1, $orientation) . "\0\0" . pack('V', 0);
    $app1 = "Exif\0\0" . $tiff;
    $jpeg = (string) file_get_contents($abs);
    file_put_contents($abs, substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2));
}

unit('images: copies are made only at the widths narrower than the original', function (): void {
    assert_eq([480, 960, 1600], ImageProcessor::widthsFor(4000));
    assert_eq([480, 960], ImageProcessor::widthsFor(1600), 'a 1600 px image needs no 1600 copy');
    assert_eq([], ImageProcessor::widthsFor(480));
    assert_eq('/uploads/media/2026/11/abc-960w.webp', ImageProcessor::variantPath('/uploads/media/2026/11/abc.jpg', 960));
    assert_true(ImageProcessor::supports('image/jpeg') && ImageProcessor::supports('image/png') && ImageProcessor::supports('image/webp'));
    assert_false(ImageProcessor::supports('image/gif'), 'GIF is left alone: it may be animated');
    assert_false(ImageProcessor::supports('image/svg+xml'));
});

unit('images: WebP copies are written next to the original at the right sizes', function (): void {
    $root = mip_root();
    try {
        mip_image($root . '/uploads/media/photo.png', 1000, 500, 'image/png');
        $made = ImageProcessor::makeVariants($root, '/uploads/media/photo.png', 'image/png');
        assert_eq([480 => '/uploads/media/photo-480w.webp', 960 => '/uploads/media/photo-960w.webp'], $made);
        $info = getimagesize($root . '/uploads/media/photo-480w.webp');
        assert_eq([480, 240, 'image/webp'], [$info[0], $info[1], $info['mime']], 'keeps the shape');
        assert_true(mip_is_red(mip_rgb($root . '/uploads/media/photo-960w.webp', 10, 10)), 'and the picture');
        assert_eq([], ImageProcessor::makeVariants($root, '/uploads/media/missing.png', 'image/png'), 'no file, no copies');
    } finally {
        mip_rm($root);
    }
});

unit('images: rotate, flip and crop change the file in place and report its new size', function (): void {
    $root = mip_root();
    $abs = $root . '/uploads/media/edit.jpg';
    try {
        mip_image($abs, 200, 100);
        assert_eq(['width' => 100, 'height' => 200], ImageProcessor::transform($abs, 'image/jpeg', ['rotate' => 90]));
        assert_true(mip_is_red(mip_rgb($abs, 50, 20)), 'clockwise: the red left half is now on top');
        assert_eq(['width' => 100, 'height' => 200], ImageProcessor::transform($abs, 'image/jpeg', ['flip' => 'v']));
        assert_true(mip_is_red(mip_rgb($abs, 50, 180)), 'flipped: red at the bottom');
        assert_eq(['width' => 60, 'height' => 50], ImageProcessor::transform($abs, 'image/jpeg', ['crop' => [10, 150, 60, 50]]));
        assert_true(mip_is_red(mip_rgb($abs, 30, 25)), 'the crop kept the red corner');
        assert_eq(null, ImageProcessor::transform($abs, 'image/gif', ['rotate' => 90]), 'unsupported format: nothing done');
        $before = md5_file($abs);
        assert_eq(null, ImageProcessor::transform($abs, 'image/jpeg', ['crop' => [0, 0, 60, 50], 'rotate' => 45]), 'nothing to change: no rewrite');
        assert_eq($before, md5_file($abs));
    } finally {
        mip_rm($root);
    }
});

unit('images: a crop box is clamped inside the image; an empty or whole-image box is no crop', function (): void {
    assert_eq(['x' => 0, 'y' => 0, 'width' => 100, 'height' => 50], ImageProcessor::cropBox([-20, -5, 100, 50], 200, 100));
    assert_eq(['x' => 150, 'y' => 50, 'width' => 50, 'height' => 50], ImageProcessor::cropBox([150, 50, 999, 999], 200, 100));
    assert_eq(null, ImageProcessor::cropBox([0, 0, 200, 100], 200, 100));
    assert_eq(null, ImageProcessor::cropBox([0, 0, 4, 4], 200, 100), 'too small to be meant');
    assert_eq(null, ImageProcessor::cropBox('10,10,50,50', 200, 100));
});

unit('images: a sideways phone photo is turned upright and its EXIF removed', function (): void {
    if (!function_exists('exif_read_data')) {
        return; // the exif extension is optional; without it nothing is rewritten
    }
    $root = mip_root();
    $abs = $root . '/uploads/media/phone.jpg';
    try {
        mip_image($abs, 200, 100);
        mip_jpeg_with_orientation($abs, 6);
        assert_eq(6, (int) (@exif_read_data($abs)['Orientation'] ?? 0), 'the test photo carries orientation 6');
        assert_true(ImageProcessor::normalize($abs, 'image/jpeg'));
        $info = getimagesize($abs);
        assert_eq([100, 200], [$info[0], $info[1]], 'upright: portrait now');
        assert_true(mip_is_red(mip_rgb($abs, 50, 20)), 'orientation 6 means turn clockwise: red on top');
        $after = @exif_read_data($abs);
        assert_false(is_array($after) && isset($after['Orientation']), 'no EXIF left');
        assert_false(ImageProcessor::normalize($abs, 'image/jpeg'), 'a clean JPEG is not rewritten again');
        mip_image($root . '/uploads/media/plain.png', 50, 50, 'image/png');
        assert_false(ImageProcessor::normalize($root . '/uploads/media/plain.png', 'image/png'), 'PNG is left as it is');
    } finally {
        mip_rm($root);
    }
});
