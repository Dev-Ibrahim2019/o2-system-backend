<?php

require __DIR__ . '/vendor/autoload.php';

use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;

$printerName = 'XP-80C';
$listenAddr = '127.0.0.1:9100';

$server = stream_socket_server("tcp://{$listenAddr}", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "Failed to bind {$listenAddr}: {$errstr} ({$errno})\n");
    exit(1);
}

echo "Print bridge listening on {$listenAddr}, forwarding to '{$printerName}'\n";

while (true) {
    $client = @stream_socket_accept($server, -1);
    if (!$client) {
        continue;
    }

    $data = '';
    while (!feof($client)) {
        $chunk = fread($client, 8192);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $data .= $chunk;
    }
    fclose($client);

    if ($data === '') {
        continue;
    }

    try {
        $connector = new WindowsPrintConnector($printerName);
        $connector->write($data);
        $connector->finalize();
        echo "[" . date('Y-m-d H:i:s') . "] Forwarded " . strlen($data) . " bytes to {$printerName}\n";
    } catch (\Throwable $e) {
        fwrite(STDERR, "[" . date('Y-m-d H:i:s') . "] Print failed: " . $e->getMessage() . "\n");
    }
}
