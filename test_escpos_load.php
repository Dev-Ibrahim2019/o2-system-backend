<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$path = 'C:\Users\97059\Herd\o2-system-backend\storage\app\receipt_18e8a3a2212dc9b7ee6d06d8af7b65d9.png';
echo "File exists: " . (file_exists($path) ? 'YES' : 'NO') . "\n";
echo "File size: " . filesize($path) . " bytes\n";

// Load via EscposImage
$img = \Mike42\Escpos\EscposImage::load($path);
echo "Class: " . get_class($img) . "\n";
echo "Width before toRasterFormat: " . $img->getWidth() . "\n";

// This triggers the actual image loading
$raster = $img->toRasterFormat();
echo "Width after toRasterFormat: " . $img->getWidth() . "\n";
echo "Height after toRasterFormat: " . $img->getHeight() . "\n";
echo "Raster data length: " . strlen($raster) . " bytes\n";

$expectedRasterBytes = (int)(ceil($img->getWidth() / 8) * $img->getHeight());
echo "Expected raster bytes: $expectedRasterBytes\n";
echo "Raster matches: " . (strlen($raster) == $expectedRasterBytes ? 'YES' : 'NO') . "\n";

// Check if the raster data is all zeros (blank image)
$nonZero = 0;
for ($i = 0; $i < strlen($raster); $i++) {
    if (ord($raster[$i]) !== 0) {
        $nonZero++;
    }
}
echo "Non-zero raster bytes: $nonZero / " . strlen($raster) . "\n";
echo "Image is blank (all white): " . ($nonZero === 0 ? 'YES - BUG!' : 'NO') . "\n";

// Verify GD image directly
echo "\n=== Direct GD verification ===\n";
$gdImg = imagecreatefrompng($path);
$w = imagesx($gdImg);
$h = imagesy($gdImg);
echo "GD width: $w, height: $h\n";

// Count non-white pixels
$blackPixels = 0;
$totalSampled = 0;
for ($y = 0; $y < $h; $y += 3) {
    for ($x = 0; $x < $w; $x += 3) {
        $rgb = imagecolorat($gdImg, $x, $y);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        $grey = (int)(($r + $g + $b) / 3);
        if ($grey < 128) {
            $blackPixels++;
        }
        $totalSampled++;
    }
}
echo "Black pixels (sampled): $blackPixels / $totalSampled\n";
echo "Black pixel ratio: " . round($blackPixels / $totalSampled * 100, 2) . "%\n";
imagedestroy($gdImg);

// Now test the actual image rendering pipeline end-to-end
echo "\n=== Arabic Rendering Deep Test ===\n";
$arabic = new \ArPHP\I18N\Arabic('Glyphs');

$testText = 'مشاوي مشكلة';
$shaped = $arabic->utf8Glyphs($testText);
echo "Original: $testText\n";
echo "Shaped: $shaped\n";
echo "Shaped bytes: " . strlen($shaped) . "\n";
echo "Shaped hex: " . bin2hex($shaped) . "\n";

// Render shaped text to GD and check
$testImg = imagecreatetruecolor(576, 60);
$white = imagecolorallocate($testImg, 255, 255, 255);
$black = imagecolorallocate($testImg, 0, 0, 0);
imagefill($testImg, 0, 0, $white);

$font = 'C:\Windows\Fonts\tahoma.ttf';
$result = imagettftext($testImg, 36, 0, 10, 45, $black, $font, $shaped);
echo "imagettftext result: " . ($result !== false ? 'SUCCESS' : 'FAILED') . "\n";

// Check for content
$hasContent = false;
$nonWhiteCount = 0;
for ($y = 0; $y < 60; $y += 2) {
    for ($x = 0; $x < 576; $x += 2) {
        $rgb = imagecolorat($testImg, $x, $y);
        $r = ($rgb >> 16) & 0xFF;
        if ($r < 200) {
            $nonWhiteCount++;
            $hasContent = true;
        }
    }
}
echo "Non-white pixels: $nonWhiteCount\n";
echo "Image has content: " . ($hasContent ? 'YES' : 'NO - THIS IS THE BUG') . "\n";

// Save test image
$testPath = storage_path('app/test_arabic_direct.png');
imagepng($testImg, $testPath);
imagedestroy($testImg);
echo "Test image: $testPath\n";
echo "Test image size: " . filesize($testPath) . " bytes\n";

// Try loading this through EscposImage too
$testEscpos = \Mike42\Escpos\EscposImage::load($testPath);
$testRaster = $testEscpos->toRasterFormat();
echo "EscposImage width: " . $testEscpos->getWidth() . "\n";
echo "EscposImage height: " . $testEscpos->getHeight() . "\n";
echo "Raster length: " . strlen($testRaster) . "\n";

$testNonZero = 0;
for ($i = 0; $i < strlen($testRaster); $i++) {
    if (ord($testRaster[$i]) !== 0) $testNonZero++;
}
echo "Non-zero raster bytes: $testNonZero\n";
echo "Raster has content: " . ($testNonZero > 0 ? 'YES' : 'NO') . "\n";

// Now the REAL test: create a full receipt and try to send it
echo "\n=== Full Receipt via EscposImage ===\n";
$builder = app(\App\Services\Printing\Renderers\ReceiptImageBuilder::class);
$receiptPath = $builder->buildInvoiceReceipt([
    'restaurant_name'    => 'مطعم O2',
    'order_type_label'   => 'كاشير',
    'order_number_label' => 'رقم الطلب:',
    'order_number'       => 'ORD-001',
    'date_label'         => 'التاريخ:',
    'date'               => '2026-07-12 14:30',
    'items'              => [
        ['name' => 'مشاوي مشكلة', 'quantity' => 2, 'price' => 45000, 'total' => 90000],
    ],
    'total_label'        => 'الاجمالي',
    'total'              => '90,000.00',
    'currency'           => 'NIS',
]);
echo "Receipt path: $receiptPath\n";
echo "Receipt size: " . filesize($receiptPath) . " bytes\n";

$escposImg = \Mike42\Escpos\EscposImage::load($receiptPath);
$rasterData = $escposImg->toRasterFormat();
echo "Escpos width: " . $escposImg->getWidth() . "\n";
echo "Escpos height: " . $escposImg->getHeight() . "\n";
echo "Raster length: " . strlen($rasterData) . " bytes\n";

$receiptNonZero = 0;
for ($i = 0; $i < strlen($rasterData); $i++) {
    if (ord($rasterData[$i]) !== 0) $receiptNonZero++;
}
echo "Non-zero raster bytes: $receiptNonZero\n";
echo "Raster has content: " . ($receiptNonZero > 0 ? 'YES' : 'NO') . "\n";

// Expected raster size
$expectedWidthBytes = (int)ceil($escposImg->getWidth() / 8);
$expectedRasterSize = $expectedWidthBytes * $escposImg->getHeight();
echo "Expected raster: $expectedWidthBytes bytes/row x " . $escposImg->getHeight() . " rows = $expectedRasterSize bytes\n";
echo "Raster matches expected: " . (strlen($rasterData) == $expectedRasterSize ? 'YES' : 'NO') . "\n";

// Cleanup
@unlink($testPath);
@unlink($receiptPath);
