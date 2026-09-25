<?php
$root = __DIR__ . '/_ag';
@mkdir($root . '/uploads/forms', 0777, true);
@mkdir($root . '/uploads/brand', 0777, true);
define('SLATE_ROOT', $root);
require __DIR__ . '/lib/FormsPdf.php';

$lg = imagecreatetruecolor(120, 120);
imagefilledrectangle($lg, 0, 0, 120, 120, imagecolorallocate($lg, 17, 17, 17));
$wht = imagecolorallocate($lg, 255, 255, 255);
imagefilledellipse($lg, 60, 50, 46, 46, $wht);
imagefilledrectangle($lg, 40, 78, 80, 96, $wht);
imagepng($lg, $root . '/uploads/brand/logo.png');
imagedestroy($lg);

// A drawn signature for case 2
$sg = imagecreatetruecolor(300, 90);
imagefilledrectangle($sg, 0, 0, 300, 90, imagecolorallocate($sg, 255, 255, 255));
$blk = imagecolorallocate($sg, 20, 20, 30);
imagesetthickness($sg, 3);
imageline($sg, 20, 60, 80, 25, $blk); imageline($sg, 80, 25, 140, 70, $blk);
imageline($sg, 140, 70, 210, 30, $blk); imageline($sg, 210, 30, 270, 55, $blk);
imagepng($sg, $root . '/uploads/forms/sig.png');
imagedestroy($sg);

$brand = ['name' => 'Rakib Hasaan', 'sublabel' => 'Estimates & Service',
          'logo_path' => '/uploads/brand/logo.png',
          'address' => 'Dhaka, Bangladesh', 'phone' => '+880 1234 568644'];

$fieldsBase = [
    ['type' => 'text',  'name' => 'full_name', 'label' => 'Full Name'],
    ['type' => 'email', 'name' => 'email', 'label' => 'Email Address'],
    ['type' => 'tel',   'name' => 'phone', 'label' => 'Phone Number'],
    ['type' => 'text',  'name' => 'street', 'label' => 'Street Address'],
    ['type' => 'select','name' => 'service', 'label' => 'Service Type'],
    ['type' => 'date',  'name' => 'start', 'label' => 'Preferred Start Date'],
];
$dataBase = [
    'full_name' => 'Rakib Hasan', 'email' => 'rakib.h6564@gmail.com',
    'phone' => '+8801234568644', 'street' => 'Distinctio Quo sit',
    'service' => 'Plumbing', 'start' => '2026-05-30',
];

// Case 1 — no signature (blank execution lines)
$pdf1 = FormsPdf::render(['title' => 'Estimate form', 'fields' => $fieldsBase], $dataBase, 'SUB-12511B2E',
    ['accent' => '#111111', 'brand' => $brand, 'submitted_at' => '2026-05-29 13:32:00']);
file_put_contents($root . '/nosig.pdf', $pdf1);

// Case 2 — with captured signature
$f2 = $fieldsBase; $f2[] = ['type' => 'signature', 'name' => 'sig', 'label' => 'Signature'];
$d2 = $dataBase; $d2['sig'] = ['signature' => true, 'path' => '/uploads/forms/sig.png', 'mode' => 'draw', 'name' => ''];
$pdf2 = FormsPdf::render(['title' => 'Estimate form', 'fields' => $f2], $d2, 'SUB-12511B2E',
    ['accent' => '#111111', 'brand' => $brand, 'submitted_at' => '2026-05-29 13:32:00']);
file_put_contents($root . '/sig.pdf', $pdf2);

echo "nosig=" . strlen($pdf1) . " sig=" . strlen($pdf2) . "\n";
