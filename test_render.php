<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$builder = new \App\Services\Printing\Renderers\ReceiptImageBuilder();

$path = $builder->buildInvoiceReceipt([
    'restaurant_name'    => 'مطعم O2',
    'order_type_label'   => 'فاتورة كاشير',
    'order_number_label' => 'رقم الطلب:',
    'order_number'       => '274',
    'date_label'         => 'التاريخ:',
    'date'               => '2026-07-12 16:33',
    'table_label'        => 'طاولة:',
    'table_number'       => '105',
    'cashier_label'      => 'الكاشير:',
    'cashier_name'       => 'محمود',
    'customer_label'     => 'الاسم:',
    'customer_name'      => 'فرح جربوع',
    'phone_label'        => 'الهاتف:',
    'customer_phone'     => '',
    'note_label'         => 'ملاحظة:',
    'note'               => '',
    'items'              => [
        ['name' => 'كنافة نابلسية',   'quantity' => 1, 'price' => 25.00, 'total' => 25.00, 'notes' => 'بدون سكر'],
        ['name' => 'بيتزا مارجريتا',  'quantity' => 2, 'price' => 30.00, 'total' => 60.00, 'notes' => ''],
        ['name' => 'سلطة قيصر',       'quantity' => 1, 'price' => 15.00, 'total' => 15.00, 'notes' => ''],
        ['name' => 'دجاج مشوي مع أرز وخضار', 'quantity' => 1, 'price' => 35.00, 'total' => 35.00, 'notes' => ''],
        ['name' => 'عصير برتقال',     'quantity' => 3, 'price' => 8.00,  'total' => 24.00, 'notes' => ''],
        ['name' => 'اسبريسو',         'quantity' => 2, 'price' => 5.50,  'total' => 11.00, 'notes' => ''],
    ],
    'discount_label'     => 'الخصم:',
    'discount_amount'    => '5.00',
    'total_label'        => 'الاجمالي',
    'total'              => '165.00',
    'currency'           => 'NIS',
]);

echo "=== Invoice Receipt Preview ===\n";
echo "Image: $path\n";
echo "Size: " . filesize($path) . " bytes\n";

$img = getimagesize($path);
echo "Width: " . $img[0] . " px\n";
echo "Height: " . $img[1] . " px\n";

// Check content
$gd = imagecreatefrompng($path);
$blackPx = 0;
$totalPx = 0;
for ($y = 0; $y < imagesy($gd); $y += 3) {
    for ($x = 0; $x < imagesx($gd); $x += 3) {
        $totalPx++;
        $rgb = imagecolorat($gd, $x, $y);
        if (($rgb & 0xFF) < 200) $blackPx++;
    }
}
imagedestroy($gd);
echo "Non-white pixels: " . round($blackPx / $totalPx * 100, 1) . "%\n";
echo "SUCCESS\n";
