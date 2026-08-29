<?php

namespace App\Services\Printing\Drivers;

use App\Models\Printer;
use App\Services\Printing\Contracts\PrinterDriverInterface;
use Illuminate\Support\Facades\Log;
use Mike42\Escpos\CapabilityProfile;
use Mike42\Escpos\EscposImage;
use Mike42\Escpos\Printer as EscposPrinter;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;

class EscPosPrinterDriver implements PrinterDriverInterface
{
    /**
     * Send raw ESC/POS text content to a printer.
     */
    public function send(Printer $printer, string $content): array
    {
        $escpos = null;

        try {
            $escpos = $this->connect($printer);
            $escpos->text($content);
            $escpos->feed(4);
            $escpos->cut();

            return $this->success($printer);
        } catch (\Exception $e) {
            return $this->error($printer, $e);
        } finally {
            $this->close($escpos);
        }
    }

    /**
     * Execute a callback with the raw ESC/POS printer object.
     * The callback must NOT call cut() or close() — the driver handles that.
     */
    public function sendWithClosure(Printer $printer, callable $callback): array
    {
        $escpos = null;

        try {
            $escpos = $this->connect($printer);
            $callback($escpos);
            $escpos->feed(4);
            $escpos->cut();

            return $this->success($printer);
        } catch (\Exception $e) {
            return $this->error($printer, $e);
        } finally {
            $this->close($escpos);
        }
    }

    /**
     * Print a receipt image (the entire receipt rendered as a single PNG).
     * This is the primary method for Arabic printing.
     */
    public function printReceiptImage(Printer $printer, string $imagePath): array
    {
        $escpos = null;

        try {
            $escpos = $this->connect($printer);

            $img = EscposImage::load($imagePath);

            Log::info('ESC/POS bitImage', [
                'printer'     => $printer->name,
                'image_path'  => $imagePath,
                'file_exists' => file_exists($imagePath),
                'file_size'   => file_exists($imagePath) ? filesize($imagePath) : 0,
                'img_width'   => $img->getWidth(),
                'img_height'  => $img->getHeight(),
                'width_bytes' => $img->getWidthBytes(),
            ]);

            $escpos->bitImage($img);

            $escpos->feed(4);
            $escpos->cut();

            return $this->success($printer);
        } catch (\Exception $e) {
            Log::error('ESC/POS bitImage failed', [
                'printer' => $printer->name,
                'path'    => $imagePath,
                'error'   => $e->getMessage(),
            ]);
            return $this->error($printer, $e);
        } finally {
            $this->close($escpos);
        }
    }

    /**
     * Print an image (standalone, no cut).
     */
    public function printImage(Printer $printer, string $imagePath): array
    {
        $escpos = null;

        try {
            $escpos = $this->connect($printer);
            $img = EscposImage::load($imagePath);
            $escpos->bitImage($img);

            return $this->success($printer);
        } catch (\Exception $e) {
            return $this->error($printer, $e);
        } finally {
            $this->close($escpos);
        }
    }

    /**
     * Send a test print to verify printer connectivity.
     */
    public function testPrint(Printer $printer): array
    {
        $escpos = null;

        try {
            $escpos = $this->connect($printer, 3);

            $escpos->setJustification(EscposPrinter::JUSTIFY_CENTER);
            $escpos->selectPrintMode(EscposPrinter::MODE_EMPHASIZED);
            $escpos->text("O2 SYSTEM\n");
            $escpos->text("--------------------------------\n");
            $escpos->text("Test Print Success!\n");
            $escpos->text("Printer: " . $printer->name . "\n");
            $escpos->text("Date: " . now()->format('Y-m-d H:i:s') . "\n");
            $escpos->text("--------------------------------\n");

            $escpos->cut();

            return $this->success($printer);
        } catch (\Exception $e) {
            return $this->error($printer, $e);
        } finally {
            $this->close($escpos);
        }
    }

    /**
     * فتح صندوق النقدية المربوط بمنفذ الدرج (drawer kick) — بدون تغذية ورق أو قص،
     * لأنه ما في إيصال منطبع أصلاً.
     */
    public function openDrawer(Printer $printer): array
    {
        $escpos = null;

        try {
            $escpos = $this->connect($printer, 3);
            $escpos->pulse();

            return $this->success($printer);
        } catch (\Exception $e) {
            return $this->error($printer, $e);
        } finally {
            $this->close($escpos);
        }
    }

    // ── Connection Helpers ──────────────────────────────────

    private function connect(Printer $printer, int $timeout = 5): EscposPrinter
    {
        $target = trim((string) $printer->ip_address);

        // إذا كانت خانة "IP" اسم طابعة ويندوز محلية (XP-80C) أو مشاركة
        // (smb://localhost/XP80) بدل عنوان IP رقمي → نطبع عبر مزود ويندوز
        // مباشرة على الطابعة الموصولة USB بنفس الجهاز الذي يشغّل الـ worker.
        if ($target !== '' && ! filter_var($target, FILTER_VALIDATE_IP)) {
            $connector = new WindowsPrintConnector($target);
        } else {
            $connector = new NetworkPrintConnector(
                $target,
                (int) ($printer->port ?? 9100),
                $timeout
            );
        }

        $profile = CapabilityProfile::load('default');

        return new EscposPrinter($connector, $profile);
    }

    private function close(?EscposPrinter $escpos): void
    {
        if ($escpos !== null) {
            try { $escpos->close(); } catch (\Exception $ex) {}
        }
    }

    private function success(Printer $printer): array
    {
        return [
            'success' => true,
            'printer' => $printer->name,
            'message' => 'تمت الطباعة بنجاح',
        ];
    }

    private function error(Printer $printer, \Exception $e): array
    {
        return [
            'success' => false,
            'printer' => $printer->name,
            'message' => 'خطأ في الطباعة: ' . $e->getMessage(),
        ];
    }
}
