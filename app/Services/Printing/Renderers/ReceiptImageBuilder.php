<?php

namespace App\Services\Printing\Renderers;

use App\Models\Order;
use Spatie\Browsershot\Browsershot;
use Illuminate\Support\Facades\Log;

/**
 * Builds thermal receipt images from Blade templates using Browsershot.
 *
 * This completely replaces the old GD-based rendering approach.
 * Arabic text, RTL layout, and styling are handled by the HTML/CSS/Blade templates
 * and rendered to a high-resolution PNG via Chrome/Puppeteer (Browsershot).
 *
 * Designed for 80mm (550px width) thermal printers via ESC/POS graphics commands.
 */
class ReceiptImageBuilder
{
    /**
     * Render a cashier invoice receipt from the invoice.blade.php template.
     *
     * @param  Order  $order  The order model with items, totals, etc.
     * @return string         Absolute path to the generated PNG image.
     */
    public function buildInvoiceReceipt(Order $order): string
    {
        $html = view('receipts.invoice', compact('order'))->render();

        return $this->renderHtmlToImage($html);
    }

    /**
     * Render a filtered invoice receipt — only specific items for a cashier printer.
     *
     * @param  Order  $order           The order model.
     * @param  string $printerName     Name of the destination printer.
     * @param  array  $items           Filtered items: ['item_id','name','quantity','price','total','notes']
     * @param  bool   $showOrderTotals إظهار الخصم والمجموع الكلي الحقيقي للطلب (مش بس مجموع
     *                                 هالنسخة المفلترة) — تُستخدم لآخر نسخة بوضع "فوري" لأنها
     *                                 كلها فاتورة الزبون نفسها منقسمة لأقسام على نفس الطابعة،
     *                                 مش تذاكر أقسام منفصلة فعلياً زي وضع "محلي".
     * @return string                  Absolute path to the generated PNG image.
     */
    public function buildFilteredInvoiceReceipt(Order $order, string $printerName, array $items, bool $showOrderTotals = false, bool $hidePrices = false): string
    {
        // Convert items to objects so the Blade template can use -> property access
        $filteredItems = array_map(function ($item) {
            if (is_object($item)) {
                return $item;
            }
            return (object) [
                'item_id'     => $item['item_id'] ?? 0,
                'item_name'   => $item['name'] ?? $item['item_name'] ?? 'صنف',
                'item_name_ar'=> $item['name_ar'] ?? $item['item_name_ar'] ?? $item['name'] ?? 'صنف',
                'quantity'    => $item['quantity'] ?? 1,
                'price'       => $item['price'] ?? 0,
                'total'       => $item['total'] ?? ($item['price'] ?? 0) * ($item['quantity'] ?? 1),
                'notes'       => $item['notes'] ?? null,
            ];
        }, $items);

        // مجموع أصناف هذه النسخة (يُستخدم فقط بالفاتورة الكاملة؛ نسخ "فوري"
        // المقسّمة ما بتعرض أي مجاميع).
        $filteredTotal = array_sum(array_map(fn($i) => $i->total, $filteredItems));

        $viewData = [
            'order'            => $order,
            'filteredItems'    => $filteredItems,
            'filteredTotal'    => $filteredTotal,
            'printerName'      => $printerName,
            'showOrderTotals'  => $showOrderTotals,
            'hidePrices'       => $hidePrices,
        ];

        $html = view('receipts.invoice', $viewData)->render();

        return $this->renderHtmlToImage($html);
    }

    /**
     * Render a kitchen order ticket (KOT) from the kot.blade.php template.
     *
     * @param  Order     $order         The order model.
     * @param  string    $printerName   Name of the destination printer (e.g. "مطبخ رئيسي").
     * @param  array|null $sectionItems Optional filtered items for section-specific KOTs.
     *                                  Each item should be an object or array with:
     *                                  - item_name_ar / item_name
     *                                  - quantity
     *                                  - notes
     * @return string                   Absolute path to the generated PNG image.
     */
    public function buildKotReceipt(Order $order, string $printerName, ?array $sectionItems = null, ?array $meta = null): string
    {
        $viewData = [
            'order'    => $order,
            'printJob' => (object) [
                'printer' => (object) ['name' => $printerName],
            ],
            'kotMeta'  => $meta,
        ];

        // If section-specific items are provided (for department-filtered KOTs),
        // pass them so the template can render the filtered list instead of all items.
        // The Blade template checks for $sectionItems ?? $order->items.
        if ($sectionItems !== null) {
            // Convert arrays to stdClass objects so the template's Eloquent-style
            // property access ($item->item_name_ar, $item->quantity, etc.) works.
            $viewData['sectionItems'] = array_map(function ($item) {
                if (is_object($item)) {
                    return $item;
                }
                return (object) [
                    'item_name_ar' => $item['item_name_ar'] ?? $item['item_name'] ?? 'صنف',
                    'item_name'    => $item['item_name'] ?? $item['item_name_ar'] ?? '',
                    'quantity'     => (int) ($item['quantity'] ?? 1),
                    'notes'        => $item['notes'] ?? '',
                    'price'        => (float) ($item['price'] ?? 0),
                ];
            }, $sectionItems);
        }

        $html = view('receipts.kot', $viewData)->render();

        return $this->renderHtmlToImage($html);
    }

    /**
     * Convert HTML string to a high-resolution PNG image using Browsershot,
     * then resize it to match the printer's dot width.
     *
     * Browsershot renders at 2x scale for crisp text, then we downscale
     * to exactly `dots_per_line` pixels wide so the ESC/POS raster data
     * matches the printer's physical head width.
     *
     * @param  string  $html  The full HTML document to render.
     * @return string         Absolute path to the saved PNG file.
     */
    private function renderHtmlToImage(string $html): string
    {
        $this->cleanupOldTempFiles();

        $rawPath     = storage_path('app/receipt_raw_' . uniqid('', true) . '.png');
        $targetWidth = (int) config('printing.dots_per_line', 576);

        try {
            $browsershot = Browsershot::html($html)
                // ارتفاع نافذة كبير حتى الطلبات الطويلة (خط أكبر = صفوف أطول)
                // ما تنقصّ قبل ما يشتغل الـ crop — الـ crop بيشيل الزيادة بأي حال.
                ->windowSize(550, 2200)
                ->deviceScaleFactor(1)
                ->noSandbox();

            if ($chromePath = config('printing.browsershot_chrome_path')) {
                $browsershot->setChromePath($chromePath);
            }
            if ($nodePath = config('printing.browsershot_node_path')) {
                $browsershot->setNodePath($nodePath);
            }
            if ($npmPath = config('printing.browsershot_npm_path')) {
                $browsershot->setNpmPath($npmPath);
            }

            $puppeteerDir = base_path('node_modules/puppeteer');
            if (is_dir($puppeteerDir)) {
                $browsershot->setNodeModulePath(base_path('node_modules'));
            }

            $browsershot->addChromiumArguments([
                'disable-gpu',
                'disable-dev-shm-usage',
                'disable-extensions',
                'disable-background-networking',
                'disable-sync',
                'disable-translate',
                'mute-audio',
                'no-first-run',
            ]);

            $browsershot->save($rawPath);

            // ── Resize + Crop to content ────────────────────────────────
            // The raw image is ~1100px wide (550×2). We resize to
            // $targetWidth (e.g. 576) then crop empty space from the
            // bottom so the paper length matches the actual order content.
            $processedPath = $this->resizeAndCrop($rawPath, $targetWidth);

            if ($processedPath) {
                @unlink($rawPath);
                $actualPath = $processedPath;
            } else {
                $actualPath = $rawPath;
            }

            Log::info('Receipt image rendered via Browsershot', [
                'path'         => $actualPath,
                'size'         => file_exists($actualPath) ? filesize($actualPath) : 0,
                'target_width' => $targetWidth,
            ]);

            return $actualPath;

        } catch (\Exception $e) {
            Log::error('Browsershot rendering failed', [
                'error' => $e->getMessage(),
            ]);
            @unlink($rawPath);
            throw $e;
        }
    }

    /**
     * Resize a PNG to the printer width, then crop empty whitespace from the bottom.
     * This ensures the paper length matches the actual order content.
     *
     * @param  string $sourcePath  Path to the source PNG.
     * @param  int    $targetWidth Target width in pixels (e.g. 576 for 80mm).
     * @return string|null         Path to processed file, or null on failure.
     */
    private function resizeAndCrop(string $sourcePath, int $targetWidth): ?string
    {
        if (!function_exists('imagecreatefrompng')) {
            return null;
        }

        $image = @imagecreatefrompng($sourcePath);
        if (!$image) {
            return null;
        }

        $origWidth  = imagesx($image);
        $origHeight = imagesy($image);

        if ($origWidth <= 0 || $origHeight <= 0) {
            imagedestroy($image);
            return null;
        }

        // ── Step 1: Resize to printer width ────────────────────────────
        $targetHeight = (int) round($origHeight * ($targetWidth / $origWidth));

        $resized = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefilledrectangle($resized, 0, 0, $targetWidth, $targetHeight, $transparent);

        imagecopyresampled(
            $resized, $image,
            0, 0, 0, 0,
            $targetWidth, $targetHeight,
            $origWidth, $origHeight
        );
        imagedestroy($image);

        // ── Step 2: Find the content bounding box (trim white on all 4 sides) ──
        // نلقّي أول/آخر صف وعمود فيهم محتوى (نص، خطوط، إطارات) — أي شي أغمق
        // من الأبيض تقريباً — ونقصّ كل الفراغ حوالين المحتوى.
        $isContent = static function (int $rgb): bool {
            return (($rgb >> 16) & 0xFF) < 245 || (($rgb >> 8) & 0xFF) < 245 || ($rgb & 0xFF) < 245;
        };

        $topRow = $bottomRow = -1;
        $leftCol = $targetWidth;
        $rightCol = -1;

        for ($y = 0; $y < $targetHeight; $y++) {
            $rowHit = false;
            for ($x = 0; $x < $targetWidth; $x++) {
                if ($isContent(imagecolorat($resized, $x, $y))) {
                    $rowHit = true;
                    if ($x < $leftCol) {
                        $leftCol = $x;
                    }
                    if ($x > $rightCol) {
                        $rightCol = $x;
                    }
                }
            }
            if ($rowHit) {
                if ($topRow === -1) {
                    $topRow = $y;
                }
                $bottomRow = $y;
            }
        }

        // ما لقينا محتوى إطلاقاً (صورة فاضية) — نرجّع كما هي.
        if ($topRow === -1 || $rightCol === -1) {
            $destPath = storage_path('app/receipt_' . uniqid('', true) . '.png');
            $ok = imagepng($resized, $destPath, 6);
            imagedestroy($resized);
            return $ok ? $destPath : null;
        }

        // هامش 1px من كل جهة حتى ما ينقص أول/آخر خط منقّط أو حرف.
        $topRow   = max(0, $topRow - 1);
        $leftCol  = max(0, $leftCol - 1);
        $bottomRow = min($targetHeight - 1, $bottomRow + 1);
        $rightCol  = min($targetWidth - 1, $rightCol + 1);

        $boxW = $rightCol - $leftCol + 1;
        $boxH = $bottomRow - $topRow + 1;

        // ── Step 3: Crop للـ bounding box ثم مطّه أفقياً لعرض الطابعة الكامل ──
        // نقصّ الفراغ حوالين المحتوى، وبعدين نعيد عرضه لـ $targetWidth حتى
        // يملأ الورقة من الحافة للحافة (بلا هوامش جانبية).
        $cropped = imagecreatetruecolor($boxW, $boxH);
        $bgC = imagecolorallocate($cropped, 255, 255, 255);
        imagefilledrectangle($cropped, 0, 0, $boxW, $boxH, $bgC);
        imagecopy($cropped, $resized, 0, 0, $leftCol, $topRow, $boxW, $boxH);
        imagedestroy($resized);

        if ($boxW !== $targetWidth) {
            $finalHeight = (int) round($boxH * ($targetWidth / $boxW));
            $stretched = imagecreatetruecolor($targetWidth, $finalHeight);
            $bgS = imagecolorallocate($stretched, 255, 255, 255);
            imagefilledrectangle($stretched, 0, 0, $targetWidth, $finalHeight, $bgS);
            imagecopyresampled(
                $stretched, $cropped,
                0, 0, 0, 0,
                $targetWidth, $finalHeight,
                $boxW, $boxH
            );
            imagedestroy($cropped);
            $resized = $stretched;
        } else {
            $resized = $cropped;
        }

        $destPath = storage_path('app/receipt_' . uniqid('', true) . '.png');
        $ok = imagepng($resized, $destPath, 6);
        imagedestroy($resized);

        return $ok ? $destPath : null;
    }

    /**
     * Remove previously generated receipt images to prevent file access conflicts.
     *
     * Windows can throw "Access is denied" if an old file handle is still held
     * by the printer driver. Cleaning up before creating a new file helps avoid this.
     */
    private function cleanupOldTempFiles(): void
    {
        $pattern = storage_path('app/receipt_*.png');
        $files = glob($pattern);

        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * Render a department production ticket from ticket.blade.php.
     *
     * @param  Order     $order  The order model.
     * @param  object    $ticket The ProductionTicket model with department and ticketItems loaded.
     * @return string            Absolute path to the generated PNG image.
     */
    public function buildTicketReceipt(Order $order, object $ticket): string
    {
        $html = view('receipts.ticket', compact('order', 'ticket'))->render();

        return $this->renderHtmlToImage($html);
    }

    /**
     * Build a compact department ticket for cashier printer sections.
     * Uses department-ticket.blade.php — a small, compressed ticket
     * showing section name + filtered items.
     *
     * @param  Order  $order       The order model.
     * @param  string $sectionName The department/section name (e.g., "مطبخ الشاورما").
     * @param  array  $sectionItems Array of formatted items (name, quantity, notes, etc.).
     * @return string              Absolute path to the generated PNG image.
     */
    public function buildDepartmentTicketReceipt(
        Order $order,
        string $sectionName,
        array $sectionItems
    ): string {
        // Convert arrays to stdClass so template property access works
        $items = array_map(function ($item) {
            if (is_object($item)) {
                return $item;
            }
            return (object) [
                'item_name_ar' => $item['item_name_ar'] ?? $item['item_name'] ?? $item['name'] ?? '—',
                'item_name'    => $item['item_name'] ?? $item['name'] ?? '',
                'quantity'     => (int) ($item['quantity'] ?? 1),
                'notes'        => $item['notes'] ?? '',
                'price'        => (float) ($item['price'] ?? 0),
                'total'        => (float) ($item['total'] ?? 0),
            ];
        }, $sectionItems);

        $html = view('receipts.department-ticket', [
            'order'        => $order,
            'sectionName'  => $sectionName,
            'sectionItems' => $items,
        ])->render();

        return $this->renderHtmlToImage($html);
    }

    /**
     * Generate a preview PNG for visual inspection (not for printing).
     * Returns the absolute path to the preview image.
     */
    public function generatePreview(Order $order): string
    {
        return $this->buildInvoiceReceipt($order);
    }
}
