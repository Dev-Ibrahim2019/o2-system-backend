<?php

require __DIR__ . '/vendor/autoload.php';

use Mike42\Escpos\Printer;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;

$printerName = 'XP-80C';

try {
    $connector = new WindowsPrintConnector($printerName);
    $printer = new Printer($connector);

    $printer->setJustification(Printer::JUSTIFY_CENTER);
    $printer->selectPrintMode(Printer::MODE_EMPHASIZED);
    $printer->text("O2 SYSTEM\n");
    $printer->text("--------------------------------\n");
    $printer->text("Test Print Success!\n");
    $printer->text("Printer: {$printerName}\n");
    $printer->text("Date: " . date('Y-m-d H:i:s') . "\n");
    $printer->text("--------------------------------\n");
    $printer->feed(4);
    $printer->cut();
    $printer->close();

    echo "OK: sent test print to {$printerName}\n";
} catch (\Throwable $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
