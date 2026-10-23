<?php
$in = __DIR__ . '/lena.jpg';
$out = __DIR__ . '/test_binarized.jpg';
$rawBytes = file_get_contents($in);
$img = imagecreatefromstring($rawBytes);
$width = imagesx($img);
$height = imagesy($img);

$t1 = microtime(true);
imagefilter($img, IMG_FILTER_GRAYSCALE);
imagefilter($img, IMG_FILTER_CONTRAST, -40);

$binImg = imagecreatetruecolor($width, $height);
$white = imagecolorallocate($binImg, 255, 255, 255);
$black = imagecolorallocate($binImg, 0, 0, 0);
$threshold = 135;

for ($y = 0; $y < $height; $y++) {
    for ($x = 0; $x < $width; $x++) {
        $rgb = imagecolorat($img, $x, $y);
        $gray = $rgb & 0xFF; // since it was already grayscaled, r=g=b
        imagesetpixel($binImg, $x, $y, ($gray >= $threshold) ? $white : $black);
    }
}
imagejpeg($binImg, $out, 95);
$t2 = microtime(true);
echo "Pixel loop elapsed: " . round(($t2 - $t1) * 1000, 2) . "ms, File: " . filesize($out) . " bytes\n";
