<?php

namespace App\Services\Printing\Renderers;

use App\Models\Order;
use App\Services\Printing\Contracts\ReceiptRendererInterface;
use Illuminate\Support\Facades\Log;

/**
 * Renders receipt data as a PNG image using ReceiptImageBuilder (Browsershot).
 *
 * The resulting image is a complete receipt rendered from Blade templates
 * via Chrome/Puppeteer, then sent to the printer via ESC/POS graphics command.
 */
class ArabicReceiptRenderer implements ReceiptRendererInterface
{
    private ReceiptImageBuilder $builder;

    public function __construct(ReceiptImageBuilder $builder)
    {
        $this->builder = $builder;
    }

    /**
     * Render a full invoice receipt as a PNG image file path.
     *
     * @param  Order  $data  The Order model (passed as $data for interface compatibility,
     *                        but treated as an Order internally).
     * @return string        Path to the generated PNG file
     */
    public function render($data): string
    {
        try {
            if (!$data instanceof Order) {
                throw new \InvalidArgumentException(
                    'ArabicReceiptRenderer::render() expects an App\Models\Order instance.'
                );
            }

            return $this->builder->buildInvoiceReceipt($data);
        } catch (\Exception $e) {
            Log::error('Failed to render receipt image', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Render a KOT (Kitchen Order Ticket) receipt as a PNG image file path.
     *
     * @param  Order       $order       The order model.
     * @param  string      $printerName Name of the destination printer.
     * @param  array|null  $sectionItems Optional filtered items for department-specific KOTs.
     * @return string                   Path to the generated PNG file
     */
    public function renderKot(Order $order, string $printerName, ?array $sectionItems = null): string
    {
        try {
            return $this->builder->buildKotReceipt($order, $printerName, $sectionItems);
        } catch (\Exception $e) {
            Log::error('Failed to render KOT image', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Clean up temporary receipt image files.
     */
    public function cleanup(string $path): void
    {
        if ($path && file_exists($path)) {
            @unlink($path);
        }
    }
}
