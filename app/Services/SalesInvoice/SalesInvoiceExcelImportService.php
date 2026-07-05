<?php

namespace App\Services\SalesInvoice;

use App\Models\Account;
use App\Models\Item;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\HeadingRowImport;

class SalesInvoiceExcelImportService
{
    /**
     * Import sales invoices from an Excel file.
     *
     * Expected columns:
     *   *DueDate, Total, InventoryItemCode, *Description,
     *   *Quantity, *UnitAmount, Discount, *AccountCode,
     *   *TaxType, TaxAmount, TrackingName1, TrackingOption1
     *
     * @return array{success: int, errors: array, invoice_id: int|null}
     */
    public function import(string $filePath, array $options, User $user): array
    {
        $results = ['success' => 0, 'errors' => [], 'invoice_id' => null, 'imported_items' => []];

        try {
            $rows = $this->parseExcel($filePath);

            if (empty($rows)) {
                $results['errors'][] = 'الملف فارغ أو لا يحتوي على بيانات صحيحة';
                return $results;
            }

            // Group rows by invoice (if multiple invoices in one file, use InvoiceNumber or DueDate)
            $groupedRows = $this->groupRowsByInvoice($rows);

            DB::beginTransaction();

            foreach ($groupedRows as $invoiceKey => $rows) {
                $invoiceData = $this->buildInvoiceFromRows($rows, $options, $user);
                $invoice = SalesInvoice::create($invoiceData['invoice']);

                foreach ($invoiceData['items'] as $itemData) {
                    $item = new SalesInvoiceItem($itemData);
                    $item->recalculate();
                    $invoice->items()->save($item);
                    $results['imported_items'][] = $item->item_name;
                }

                $invoice->recalculateTotals();
                $results['success']++;
                $results['invoice_id'] = $invoice->id;
            }

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Excel import failed: ' . $e->getMessage());
            $results['errors'][] = 'فشل الاستيراد: ' . $e->getMessage();
        }

        return $results;
    }

    /**
     * Parse Excel file into array of rows.
     */
    protected function parseExcel(string $filePath): array
    {
        $rows = [];

        try {
            // Use Maatwebsite Excel to import
            $import = new class () extends HeadingRowImport {
                protected array $rows = [];

                public function collection($row)
                {
                    $this->rows[] = $row->toArray();
                }

                public function getRows(): array
                {
                    return $this->rows;
                }
            };

            Excel::import($import, $filePath);
            $rows = $import->getRows();
        } catch (\Exception $e) {
            // Fallback: try reading as CSV
            $rows = $this->parseCsv($filePath);
        }

        return $rows;
    }

    /**
     * Fallback CSV parser.
     */
    protected function parseCsv(string $filePath): array
    {
        $rows = [];
        $handle = fopen($filePath, 'r');

        if (! $handle) {
            return [];
        }

        $headers = fgetcsv($handle);
        if (! $headers) {
            fclose($handle);
            return [];
        }

        // Normalize headers
        $headers = array_map(fn ($h) => trim(str_replace('*', '', $h)), $headers);

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) === count($headers)) {
                $rows[] = array_combine($headers, $row);
            }
        }

        fclose($handle);
        return $rows;
    }

    /**
     * Group rows by invoice identifier.
     */
    protected function groupRowsByInvoice(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            // Use InvoiceNumber, CustomerName, or DueDate as grouping key
            $key = $row['InvoiceNumber'] ?? $row['CustomerName'] ?? $row['DueDate'] ?? 'default';
            $grouped[$key][] = $row;
        }
        return $grouped;
    }

    /**
     * Build invoice data from a group of rows.
     */
    protected function buildInvoiceFromRows(array $rows, array $options, User $user): array
    {
        $firstRow = $rows[0];
        $isInclusive = ($options['tax_treatment'] ?? 'exclusive') === 'inclusive';
        $updateContact = $options['update_contact'] ?? false;

        $subtotal = 0;
        $taxTotal = 0;
        $discountTotal = 0;
        $total = 0;
        $items = [];

        foreach ($rows as $row) {
            $quantity = (float) ($row['Quantity'] ?? 1);
            $unitAmount = (float) ($row['UnitAmount'] ?? 0);
            $discount = (float) ($row['Discount'] ?? 0);
            $taxRate = $this->parseTaxType($row['TaxType'] ?? 'None');
            $taxAmount = (float) ($row['TaxAmount'] ?? 0);

            $lineSubtotal = $quantity * $unitAmount;
            $lineBeforeTax = $lineSubtotal - $discount;

            if ($taxAmount === 0 && $taxRate > 0) {
                $taxAmount = $lineBeforeTax * ($taxRate / 100);
            }

            $lineTotal = $isInclusive ? $lineBeforeTax : $lineBeforeTax + $taxAmount;

            $subtotal += $lineBeforeTax;
            $taxTotal += $taxAmount;
            $discountTotal += $discount;
            $total += $lineTotal;

            // Resolve account
            $account = Account::where('code', $row['AccountCode'] ?? null)->first();

            // Resolve item
            $item = null;
            if (! empty($row['InventoryItemCode'])) {
                $item = Item::where('code', $row['InventoryItemCode'])->first();
            }

            $items[] = [
                'item_id' => $item?->id,
                'item_name' => $row['Description'] ?? $item?->name ?? 'صنف',
                'description' => $row['Description'] ?? null,
                'quantity' => $quantity,
                'unit_price' => $unitAmount,
                'discount' => $discount,
                'discount_percent' => $lineSubtotal > 0 ? round(($discount / $lineSubtotal) * 100, 2) : 0,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'total_before_tax' => $lineBeforeTax,
                'total' => $lineTotal,
                'account_id' => $account?->id,
                'tracking_name' => $row['TrackingName1'] ?? null,
                'tracking_option' => $row['TrackingOption1'] ?? null,
            ];
        }

        // Build invoice
        $invoiceData = [
            'type' => 'tax_invoice',
            'tax_treatment' => $isInclusive ? 'inclusive' : 'exclusive',
            'customer_name' => $firstRow['CustomerName'] ?? null,
            'customer_phone' => $firstRow['CustomerPhone'] ?? null,
            'customer_vat_number' => $firstRow['CustomerVATNumber'] ?? null,
            'invoice_date' => $firstRow['IssueDate'] ?? now(),
            'due_date' => $firstRow['DueDate'] ?? null,
            'supply_date' => $firstRow['SupplyDate'] ?? null,
            'currency' => 'ILS',
            'branch_id' => $firstRow['BranchId'] ?? 1,
            'source' => 'excel_import',
            'notes' => 'مستورد من ملف Excel',
            'status' => 'draft',
        ];

        return ['invoice' => $invoiceData, 'items' => $items];
    }

    /**
     * Parse tax type string to rate.
     */
    protected function parseTaxType(string $taxType): float
    {
        return match (strtolower(trim($taxType))) {
            'none', 'exempt', 'غير خاضع' => 0,
            'standard', '15%', 'ضريبة 15' => 15,
            'reduced', '5%', 'ضريبة 5' => 5,
            'zero', '0%', 'صفر' => 0,
            default => 0,
        };
    }
}
