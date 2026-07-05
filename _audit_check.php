<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== ACCOUNT 4120 CHECK ===\n";
$account4120 = DB::table('accounts')->where('code', '4120')->first();
if ($account4120) {
    echo json_encode($account4120, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    echo "NOT FOUND\n";
}

echo "\n=== ACCOUNT 4110 CHECK ===\n";
$account4110 = DB::table('accounts')->where('code', '4110')->first();
if ($account4110) {
    echo json_encode($account4110, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    echo "NOT FOUND\n";
}

echo "\n=== INVOICE INV-20260625-0002 ===\n";
$invoice = DB::table('invoices')->where('number', 'INV-20260625-0002')->first();
if ($invoice) {
    echo json_encode($invoice, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

    // Get its payments
    echo "\n--- Payments ---\n";
    $payments = DB::table('payments')->where('invoice_id', $invoice->id)->get();
    echo json_encode($payments, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

    // Get transaction (journal entry)
    echo "\n--- Transaction (Journal Entry Header) ---\n";
    $transaction = DB::table('transactions')
        ->where('source_type', 'App\Models\Order')
        ->where('source_id', $invoice->order_id)
        ->where('type', 'sale')
        ->first();
    if ($transaction) {
        echo json_encode($transaction, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

        // Get entries
        echo "\n--- Journal Entry Lines ---\n";
        $entries = DB::table('entries')
            ->where('transaction_id', $transaction->id)
            ->orderBy('sort_order')
            ->get();
        foreach ($entries as $entry) {
            $acct = DB::table('accounts')->where('id', $entry->account_id)->first();
            $acctCode = $acct ? $acct->code : '?';
            $acctName = $acct ? $acct->name : '?';
            echo "  account_id={$entry->account_id} code={$acctCode} name={$acctName} debit={$entry->debit} credit={$entry->credit} desc={$entry->description}\n";
        }

        // Calculate totals
        $totalDebit = DB::table('entries')->where('transaction_id', $transaction->id)->sum('debit');
        $totalCredit = DB::table('entries')->where('transaction_id', $transaction->id)->sum('credit');
        echo "\n  TOTAL DEBIT:  {$totalDebit}\n";
        echo "  TOTAL CREDIT: {$totalCredit}\n";
        echo "  DIFFERENCE:   " . ($totalDebit - $totalCredit) . "\n";
    } else {
        echo "NO TRANSACTION FOUND\n";
    }
} else {
    echo "NOT FOUND\n";

    // Show last 3 invoices
    echo "\n=== LAST 3 INVOICES ===\n";
    $invoices = DB::table('invoices')->orderBy('id', 'desc')->limit(3)->get();
    echo json_encode($invoices, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}
