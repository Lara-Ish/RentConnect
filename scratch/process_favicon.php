<?php
$srcPath = 'C:/Users/User/.gemini/antigravity/brain/cd9f4584-2946-41db-a6bb-af73cfc076d3/.user_uploaded/media_1792662016745.png';
$im = imagecreatefrompng($srcPath);
$width = imagesx($im);
$height = imagesy($im);

// Content Bounding Box: minX=268, maxX=498, minY=303, maxY=654
// Let's add 2px margin around bounding box if possible
$minX = max(0, 268 - 2);
$maxX = min($width - 1, 498 + 2);
$minY = max(0, 303 - 2);
$maxY = min($height - 1, 654 + 2);

$cropW = $maxX - $minX + 1;
$cropH = $maxY - $minY + 1;

// Crop the logo area
$crop = imagecreatetruecolor($cropW, $cropH);
imagealphablending($crop, false);
imagesavealpha($crop, true);
$transparent = imagecolorallocatealpha($crop, 0, 0, 0, 127);
imagefill($crop, 0, 0, $transparent);

// For each pixel in crop area, remove white background:
// If pixel is near white, calculate transparency based on brightness so edges are smooth
for ($y = 0; $y < $cropH; $y++) {
    for ($x = 0; $x < $cropW; $x++) {
        $srcX = $minX + $x;
        $srcY = $minY + $y;
        $rgba = imagecolorat($im, $srcX, $srcY);
        $colors = imagecolorsforindex($im, $rgba);
        $r = $colors['red'];
        $g = $colors['green'];
        $b = $colors['blue'];
        $origAlpha = $colors['alpha'];
        
        // Calculate lightness / proximity to white
        // White is (255, 255, 255)
        // If distance to white is small, blend alpha
        $minColor = min($r, $g, $b);
        $maxColor = max($r, $g, $b);
        $distFromWhite = 255 - $minColor;
        
        if ($r >= 250 && $g >= 250 && $b >= 250) {
            // Pure white -> fully transparent
            $newColor = imagecolorallocatealpha($crop, 0, 0, 0, 127);
            imagesetpixel($crop, $x, $y, $newColor);
        } else if ($r > 220 && $g > 220 && $b > 220) {
            // Transition zone (anti-aliased edges against white)
            // The whiter it is, the more transparent
            // distFromWhite is between 1 and 35
            $factor = $distFromWhite / 35.0; // 0 (at white) to 1 (at 220)
            $alpha = (int)round(127 * (1.0 - $factor));
            $alpha = max(0, min(127, $alpha));
            // De-whiten the color: remove white blend component
            $newR = (int)round(($r - 255 * (1 - $factor)) / max(0.01, $factor));
            $newG = (int)round(($g - 255 * (1 - $factor)) / max(0.01, $factor));
            $newB = (int)round(($b - 255 * (1 - $factor)) / max(0.01, $factor));
            $newR = max(0, min(255, $newR));
            $newG = max(0, min(255, $newG));
            $newB = max(0, min(255, $newB));
            $newColor = imagecolorallocatealpha($crop, $newR, $newG, $newB, $alpha);
            imagesetpixel($crop, $x, $y, $newColor);
        } else {
            // Foreground pixel (the blue / navy logo)
            $newColor = imagecolorallocatealpha($crop, $r, $g, $b, $origAlpha);
            imagesetpixel($crop, $x, $y, $newColor);
        }
    }
}

// Now create square canvases for favicon (e.g. 512x512, 192x192, 64x64, 32x32)
function createSquareFavicon($cropImg, $size) {
    $target = imagecreatetruecolor($size, $size);
    imagealphablending($target, false);
    imagesavealpha($target, true);
    $trans = imagecolorallocatealpha($target, 0, 0, 0, 127);
    imagefill($target, 0, 0, $trans);
    imagealphablending($target, true);
    
    $w = imagesx($cropImg);
    $h = imagesy($cropImg);
    
    // Fit within ($size - padding) maintaining aspect ratio
    $padding = (int)round($size * 0.08); // 8% padding
    $maxDim = $size - 2 * $padding;
    
    $scale = min($maxDim / $w, $maxDim / $h);
    $newW = (int)round($w * $scale);
    $newH = (int)round($h * $scale);
    
    $dstX = (int)round(($size - $newW) / 2);
    $dstY = (int)round(($size - $newH) / 2);
    
    imagecopyresampled($target, $cropImg, $dstX, $dstY, 0, 0, $newW, $newH, $w, $h);
    return $target;
}

$fav512 = createSquareFavicon($crop, 512);
$fav192 = createSquareFavicon($crop, 192);
$fav64  = createSquareFavicon($crop, 64);
$fav32  = createSquareFavicon($crop, 32);

if (!is_dir('c:/xampp/htdocs/RentConnect/assets/images')) {
    mkdir('c:/xampp/htdocs/RentConnect/assets/images', 0777, true);
}

imagepng($fav512, 'c:/xampp/htdocs/RentConnect/assets/images/favicon.png');
imagepng($fav192, 'c:/xampp/htdocs/RentConnect/assets/images/favicon-192.png');
imagepng($fav64,  'c:/xampp/htdocs/RentConnect/assets/images/favicon-64.png');
imagepng($fav32,  'c:/xampp/htdocs/RentConnect/assets/images/favicon-32.png');
imagepng($fav64,  'c:/xampp/htdocs/RentConnect/favicon.png');
imagepng($fav32,  'c:/xampp/htdocs/RentConnect/favicon.ico'); // PNG in .ico format is standard for modern browsers

echo "Favicons successfully generated with transparent background!\n";
