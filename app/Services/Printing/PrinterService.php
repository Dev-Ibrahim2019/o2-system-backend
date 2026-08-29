<?php

namespace App\Services\Printing;

use App\Models\Printer;
use App\Services\Printing\Contracts\PrinterDriverInterface;
use Illuminate\Support\Facades\Log;

class PrinterService
{
    private PrinterDriverInterface $driver;

    public function __construct(PrinterDriverInterface $driver)
    {
        $this->driver = $driver;
    }

    /**
     * Print raw text content to a specific printer.
     */
    public function printRaw(Printer $printer, string $content): array
    {
        if (!$printer->is_active) {
            return $this->inactive($printer);
        }

        Log::info("Sending raw print to [{$printer->name}]", [
            'printer_id' => $printer->id,
            'ip' => $printer->ip_address,
        ]);

        return $this->driver->send($printer, $content);
    }

    /**
     * Execute a callback with the raw ESC/POS printer object.
     */
    public function printWithEscPos(Printer $printer, callable $callback): array
    {
        if (!$printer->is_active) {
            return $this->inactive($printer);
        }

        Log::info("Sending ESC/POS job to [{$printer->name}]", [
            'printer_id' => $printer->id,
        ]);

        return $this->driver->sendWithClosure($printer, $callback);
    }

    /**
     * Print a complete receipt image (the entire receipt as one PNG).
     * This is the primary method for Arabic printing.
     */
    public function printReceiptImage(Printer $printer, string $imagePath): array
    {
        if (!$printer->is_active) {
            return $this->inactive($printer);
        }

        Log::info("Sending receipt image to [{$printer->name}]", [
            'printer_id' => $printer->id,
            'image_size' => file_exists($imagePath) ? filesize($imagePath) : 0,
        ]);

        return $this->driver->printReceiptImage($printer, $imagePath);
    }

    /**
     * Print an image to the printer (no cut).
     */
    public function printImage(Printer $printer, string $imagePath): array
    {
        if (!$printer->is_active) {
            return $this->inactive($printer);
        }

        return $this->driver->printImage($printer, $imagePath);
    }

    /**
     * Send a test print to verify printer connectivity.
     */
    public function testPrint(Printer $printer): array
    {
        return $this->driver->testPrint($printer);
    }

    /**
     * فتح صندوق النقدية المربوط بطابعة الكاشير (F9 عند الأمين).
     */
    public function openCashDrawer(Printer $printer): array
    {
        if (!$printer->is_active) {
            return $this->inactive($printer);
        }

        Log::info("Opening cash drawer via [{$printer->name}]", ['printer_id' => $printer->id]);

        return $this->driver->openDrawer($printer);
    }

    /**
     * Test TCP connection to a printer.
     */
    public function testConnection(Printer $printer): array
    {
        return $printer->testConnection();
    }

    private function inactive(Printer $printer): array
    {
        return [
            'success' => false,
            'printer' => $printer->name,
            'message' => 'الطابعة غير مفعّلة',
        ];
    }
}
