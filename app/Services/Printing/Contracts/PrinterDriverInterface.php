<?php

namespace App\Services\Printing\Contracts;

use App\Models\Printer;

interface PrinterDriverInterface
{
    /**
     * Send raw content to a printer.
     */
    public function send(Printer $printer, string $content): array;

    /**
     * Send ESC/POS commands via a closure for fine-grained control.
     */
    public function sendWithClosure(Printer $printer, callable $callback): array;

    /**
     * Print the entire receipt as a single image (primary method for Arabic).
     * Handles image rendering, feed, and cut.
     */
    public function printReceiptImage(Printer $printer, string $imagePath): array;

    /**
     * Print an image file to the printer (no cut).
     */
    public function printImage(Printer $printer, string $imagePath): array;

    /**
     * Send a test print to verify connectivity.
     */
    public function testPrint(Printer $printer): array;
}
