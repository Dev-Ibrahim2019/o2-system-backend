<?php

namespace App\Services\Accounting;

use Symfony\Component\HttpFoundation\StreamedResponse;

class StatementExportService
{
    /**
     * @param array<string,mixed> $payload
     */
    public function exportCsv(array $payload, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($payload) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($out, [
                'التاريخ', 'رقم المستند', 'نوع الحركة', 'نوع المستند', 'البيان',
                'مدين', 'دائن', 'الرصيد الجاري', 'الفرع', 'الحالة',
            ]);

            foreach ($payload['all_lines'] ?? [] as $line) {
                fputcsv($out, [
                    $line['date'] ?? '',
                    $line['transaction_number'] ?? '',
                    $line['movement_label'] ?? $line['movement_type'] ?? '',
                    $line['document_type'] ?? '',
                    $line['description'] ?? '',
                    $line['debit'] ?? 0,
                    $line['credit'] ?? 0,
                    $line['running_balance'] ?? 0,
                    $line['branch_name'] ?? '',
                    $line['status'] ?? 'posted',
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function exportExcelXml(array $payload, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($payload) {
            echo $this->buildSpreadsheetXml($payload);
        }, $filename, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
        ]);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function buildSpreadsheetXml(array $payload): string
    {
        $lines = $payload['all_lines'] ?? [];
        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<?mso-application progid="Excel.Sheet"?>';
        $xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">';
        $xml .= $this->worksheet('Summary', $this->summaryRows($payload));
        $xml .= $this->worksheet('Transactions', $this->transactionRows($lines));
        $xml .= $this->worksheet('Products', $this->productRows($lines));
        $xml .= $this->worksheet('Discounts', $this->discountRows($lines));
        $xml .= '</Workbook>';

        return $xml;
    }

    /**
     * @param array<int,array<int,string|float>> $rows
     */
    private function worksheet(string $name, array $rows): string
    {
        $xml = '<Worksheet ss:Name="' . htmlspecialchars($name, ENT_XML1) . '"><Table>';
        foreach ($rows as $row) {
            $xml .= '<Row>';
            foreach ($row as $cell) {
                $type = is_numeric($cell) ? 'Number' : 'String';
                $xml .= '<Cell><Data ss:Type="' . $type . '">' . htmlspecialchars((string) $cell, ENT_XML1) . '</Data></Cell>';
            }
            $xml .= '</Row>';
        }
        $xml .= '</Table></Worksheet>';

        return $xml;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,array<int,string|float>>
     */
    private function summaryRows(array $payload): array
    {
        $totals = $payload['totals'] ?? [];
        $employee = $payload['employee'] ?? [];
        $period = $payload['period'] ?? [];

        return [
            ['كشف حساب', $employee['name'] ?? ''],
            ['من', $period['from'] ?? ''],
            ['إلى', $period['to'] ?? ''],
            ['الرصيد الافتتاحي', $totals['opening_balance'] ?? 0],
            ['إجمالي مدين', $totals['total_debit'] ?? 0],
            ['إجمالي دائن', $totals['total_credit'] ?? 0],
            ['الرصيد الختامي', $totals['closing_balance'] ?? 0],
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $lines
     * @return array<int,array<int,string|float>>
     */
    private function transactionRows(array $lines): array
    {
        $rows = [[
            'التاريخ', 'رقم المستند', 'نوع الحركة', 'نوع المستند', 'البيان',
            'مدين', 'دائن', 'الرصيد', 'الفرع',
        ]];

        foreach ($lines as $line) {
            $rows[] = [
                $line['date'] ?? '',
                $line['transaction_number'] ?? '',
                $line['movement_label'] ?? '',
                $line['document_type'] ?? '',
                $line['description'] ?? '',
                $line['debit'] ?? 0,
                $line['credit'] ?? 0,
                $line['running_balance'] ?? 0,
                $line['branch_name'] ?? '',
            ];
        }

        return $rows;
    }

    /**
     * @param array<int,array<string,mixed>> $lines
     * @return array<int,array<int,string|float>>
     */
    private function productRows(array $lines): array
    {
        $rows = [['رقم المستند', 'الصنف', 'الكمية', 'السعر', 'الخصم', 'الإجمالي']];

        foreach ($lines as $line) {
            foreach ($line['items'] ?? [] as $item) {
                $rows[] = [
                    $line['transaction_number'] ?? '',
                    $item['product_name'] ?? '',
                    $item['quantity'] ?? 0,
                    $item['unit_price'] ?? 0,
                    $item['discount_amount'] ?? 0,
                    $item['total'] ?? 0,
                ];
            }
        }

        return $rows;
    }

    /**
     * @param array<int,array<string,mixed>> $lines
     * @return array<int,array<int,string|float>>
     */
    private function discountRows(array $lines): array
    {
        $rows = [['رقم المستند', 'الصنف', 'قيمة الخصم', 'نسبة الخصم', 'الاستراتيجية']];

        foreach ($lines as $line) {
            foreach ($line['items'] ?? [] as $item) {
                if ((float) ($item['discount_amount'] ?? 0) <= 0) {
                    continue;
                }
                $rows[] = [
                    $line['transaction_number'] ?? '',
                    $item['product_name'] ?? '',
                    $item['discount_amount'] ?? 0,
                    $item['discount_percent'] ?? 0,
                    $item['discount_apply_strategy'] ?? '',
                ];
            }
        }

        return $rows;
    }
}
