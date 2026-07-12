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
    public function buildKotReceipt(Order $order, string $printerName, ?array $sectionItems = null): string
    {
        $viewData = [
            'order'    => $order,
            'printJob' => (object) [
                'printer' => (object) ['name' => $printerName],
            ],
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
                ->windowSize(550, 850)
                ->deviceScaleFactor(2)
                ->hideBackground()
                ->waitUntilNetworkIdle()
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
            ]);

            $browsershot->save($rawPath);

            // ── Resize to printer width ──────────────────────────────────
            // The raw image is ~1100px wide (550×2). We must resize it to
            // exactly $targetWidth (e.g. 576) so the ESC/POS bitImage
            // raster data rows match the printer's physical head width.
            $resizedPath = $this->resizeToPrinterWidth($rawPath, $targetWidth);

            if ($resizedPath) {
                @unlink($rawPath);
                $actualPath = $resizedPath;
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
     * Resize a PNG image to the exact printer dot width, maintaining aspect ratio.
     *
     * @param  string $sourcePath  Path to the source PNG.
     * @param  int    $targetWidth Target width in pixels (e.g. 576 for 80mm).
     * @return string|null         Path to resized file, or null on failure.
     */
    private function resizeToPrinterWidth(string $sourcePath, int $targetWidth): ?string
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

        if ($origWidth <= 0 || $origHeight <= 0 || $origWidth === $targetWidth) {
            imagedestroy($image);
            return $origWidth === $targetWidth ? $sourcePath : null;
        }

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

        $destPath = storage_path('app/receipt_' . uniqid('', true) . '.png');
        $ok = imagepng($resized, $destPath, 6);

        imagedestroy($image);
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
     * Generate a preview PNG for visual inspection (not for printing).
     * Returns the absolute path to the preview image.
     */
    public function generatePreview(Order $order): string
    {
        return $this->buildInvoiceReceipt($order);
    }
}
