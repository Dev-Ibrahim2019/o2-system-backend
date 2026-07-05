<!DOCTYPE html>
<html dir="rtl" lang="ar">

<head>
    <meta charset="UTF-8">
    <title>كشف حساب — {{ $employeeName }}</title>
    <style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: 'DejaVu Sans', 'Arial', sans-serif;
        font-size: 9pt;
        color: #1e293b;
        line-height: 1.5;
        direction: rtl;
        background: #fff;
    }

    .erp-header {
        border: 2px solid #000;
        padding: 10px 15px;
        margin-bottom: 8px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .erp-header-right {
        text-align: right;
    }

    .erp-header-right .company-name {
        font-size: 22pt;
        font-weight: bold;
        color: #000;
    }

    .erp-header-right .company-sub {
        font-size: 10pt;
        color: #333;
    }

    .erp-header-center {
        text-align: center;
    }

    .erp-header-center .company-logo {
        font-size: 28pt;
        font-weight: bold;
        color: #cc0000;
    }

    .erp-header-left {
        text-align: left;
    }

    .erp-header-left .erp-name {
        font-size: 10pt;
        color: #333;
        font-weight: bold;
    }

    .erp-header-left .erp-year {
        font-size: 10pt;
        color: #333;
    }

    .erp-title {
        text-align: center;
        font-size: 14pt;
        font-weight: bold;
        margin: 8px 0;
        padding: 5px;
        border: 1px solid #000;
        background: #f0f0f0;
    }

    .info-grid {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 10px;
        border: 1px solid #000;
    }

    .info-grid td {
        padding: 4px 8px;
        font-size: 9pt;
        border: 1px solid #000;
    }

    .info-grid .info-label {
        font-weight: bold;
        background: #f0f0f0;
        width: 33%;
        text-align: center;
    }

    .info-grid .info-value {
        background: #fff;
    }

    /* ═══════════════════════════════════════════════════════════════════
           MAIN TABLE — Single continuous table
           To avoid mPDF rowspan+page-break issues, each transaction is wrapped
           in its own <tbody> with page-break-inside: avoid.
           ═══════════════════════════════════════════════════════════════════ */
    .main-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 8pt;
        margin-bottom: 12px;
    }

    .main-table th,
    .main-table td {
        border: 1px solid #000;
        padding: 4px 6px;
        text-align: center;
        vertical-align: middle;
    }

    .main-table thead th {
        background: #e8e8e8;
        font-weight: bold;
        font-size: 8pt;
        color: #000;
    }

    .main-table thead th.detail-header {
        background: #d0d0d0;
    }

    .main-table .txn-row td {
        background: #fff;
        white-space: nowrap;
    }

    .main-table .txn-row td.col-main {
        background: #f8f8f8;
        font-weight: bold;
    }

    .main-table .item-row td {
        background: #fff;
        font-size: 7.5pt;
        padding: 3px 6px;
    }

    .main-table .item-row td.item-desc {
        text-align: right;
        padding-right: 8px;
        white-space: normal;
        word-break: break-word;
    }

    .main-table .summary-row td {
        background: #f5f5f5;
        font-weight: bold;
        font-size: 7.5pt;
        border-top: 1px solid #999;
        padding: 4px 8px;
    }

    .main-table .empty-detail {
        background: #fafafa;
    }

    .main-table .date-cell {
        white-space: nowrap;
    }

    /* Each transaction block is its own tbody, avoid page-break inside */
    .main-table tbody.txn-block {
        page-break-inside: avoid;
    }

    .main-table .txn-row td:first-child {
        background: #f8f8f8;
        font-weight: bold;
    }

    .mono {
        font-family: 'Courier New', monospace;
    }

    .text-right {
        text-align: right;
    }

    .text-center {
        text-align: center;
    }

    .bold {
        font-weight: bold;
    }

    .small {
        font-size: 7pt;
        color: #666;
    }

    .closing-row td {
        background: #e8e8e8;
        font-weight: bold;
        font-size: 9pt;
        color: #000;
        border-top: 2px solid #000;
    }

    .closing-desc {
        font-size: 8pt;
        white-space: nowrap;
    }

    .signature-line {
        margin-top: 6px;
        font-size: 6.5pt;
        color: #999;
        text-align: center;
    }

    .wrap-text {
        white-space: normal;
        word-break: break-word;
    }

    .page-break {
        page-break-before: always;
    }

    .avoid-break {
        page-break-inside: avoid;
    }
    </style>
</head>

<body>

    @if(($pdfStyle ?? 'detailed') === 'simple')
    {{-- ═══════════════════════════════════════════════════════════════════
         SIMPLE STATEMENT — Compact Table Layout
         ═══════════════════════════════════════════════════════════════════ --}}
    <div class="erp-header">
        <div class="erp-header-right">
            <div class="company-name">{{ $companyName ?? 'O2' }}</div>
            <div class="company-sub">{{ $companyLocation ?? 'فلسطين' }}</div>
        </div>
        <div class="erp-header-center">
            <div class="company-logo">O2</div>
        </div>
        <div class="erp-header-left">
            <div class="erp-year">{{ date('Y') }}</div>
            <div class="erp-name">{{ $erpName ?? 'O2 ERP System' }}</div>
        </div>
    </div>

    <div class="erp-title">كشف حساب</div>

    <table class="info-grid">
        <tr>
            <td class="info-label">رقم الحساب</td>
            <td class="info-value mono">{{ $employeeId }}</td>
            <td class="info-label">اسم الحساب</td>
            <td class="info-value">{{ $employeeName }}</td>
            <td class="info-label">مقدم بعملة</td>
            <td class="info-value">{{ $currency ?? 'شيكل' }}</td>
        </tr>
    </table>

    <table class="main-table">
        <thead>
            <tr>
                <th style="width:3%">م</th>
                <th style="width:8%">التاريخ</th>
                <th style="width:14%">وصف القيد</th>
                <th style="width:7%">مدين</th>
                <th style="width:7%">دائن</th>
                <th style="width:8%">الرصيد</th>
                <th style="width:18%">الوصف</th>
                <th style="width:9%">الوحدة</th>
                <th style="width:7%">الكمية</th>
                <th style="width:8%">السعر</th>
                <th style="width:11%">المبلغ</th>
            </tr>
        </thead>
        <tbody>
            @forelse($entries as $idx => $entry)
            @php
            $hasItems = !empty($entry['items']) && count($entry['items']) > 0;
            @endphp
            <tr class="txn-row">
                <td>{{ $idx + 1 }}</td>
                <td class="mono">{{ $entry['date'] ?? '—' }}</td>
                <td class="wrap-text" style="text-align:right;padding-right:5px">
                    {{ $entry['transaction_number'] ?? $entry['description'] ?? '—' }}
                </td>
                <td class="mono">{{ ($entry['debit'] ?? 0) > 0 ? number_format($entry['debit'], 2) : '0.00' }}</td>
                <td class="mono">{{ ($entry['credit'] ?? 0) > 0 ? number_format($entry['credit'], 2) : '0.00' }}</td>
                <td class="mono">
                    {{ ($entry['running_balance'] ?? 0) < 0 ? '(' . number_format(abs($entry['running_balance']), 2) . ')' : number_format($entry['running_balance'] ?? 0, 2) }}
                </td>
                @if($hasItems)
                <td colspan="5" class="empty-detail" style="text-align:right;padding-right:5px">
                    {{ $entry['description'] ?? '' }}
                </td>
                @else
                <td colspan="5" class="empty-detail"></td>
                @endif
            </tr>

            @if($hasItems)
            @foreach($entry['items'] as $itemIdx => $item)
            <tr class="item-row">
                <td colspan="6" class="empty-detail"></td>
                <td class="item-desc text-right">{{ $itemIdx + 1 }}
                    {{ $item['product_name_ar'] ?? $item['product_name'] ?? $item['item_name'] ?? '—' }}
                </td>
                <td>{{ $item['department'] ?? '' }}</td>
                <td class="mono">{{ number_format($item['quantity'] ?? 0, 2) }}</td>
                <td class="mono">{{ number_format($item['unit_price'] ?? 0, 2) }}</td>
                <td class="mono bold">{{ number_format(($item['total'] ?? 0), 2) }}</td>
            </tr>
            @endforeach

            @php
            $itemTotal = collect($entry['items'])->sum('total');
            $itemDiscount = collect($entry['items'])->sum('discount_amount');
            $invoiceDiscount = $entry['invoice_details']['discount'] ?? 0;
            $totalDiscount = $itemDiscount + $invoiceDiscount;
            $totalBeforeDiscount = $itemTotal + $totalDiscount;
            @endphp
            <tr class="summary-row">
                <td colspan="6" class="empty-detail"></td>
                <td colspan="5" style="text-align:right;padding-right:8px">
                    الإجمالي: {{ number_format($totalBeforeDiscount, 2) }} الخصم:
                    {{ $totalDiscount > 0 ? number_format($totalDiscount, 2) : '0.00' }} المبلغ الصافي:
                    {{ number_format($itemTotal, 2) }}
                </td>
            </tr>
            @endif
            @empty
            <tr>
                <td colspan="11" style="text-align:center;padding:20px;color:#999;">لا توجد حركات في هذه الفترة</td>
            </tr>
            @endforelse
        </tbody>
    </table>

    @else
    {{-- ═══════════════════════════════════════════════════════════════════
         DETAILED STATEMENT — Single Continuous Table with Inline Items
         ═══════════════════════════════════════════════════════════════════ --}}

    {{-- HEADER --}}
    <div class="erp-header">
        <div class="erp-header-right">
            <div class="company-name">{{ $companyName ?? 'O2' }}</div>
            <div class="company-sub">{{ $companyLocation ?? 'فلسطين' }}</div>
        </div>
        <div class="erp-header-center">
            <div class="company-logo">O2</div>
        </div>
        <div class="erp-header-left">
            <div class="erp-year">{{ date('Y') }}</div>
        </div>
    </div>

    {{-- TITLE --}}
    <div class="erp-title">كشف حساب مفصل</div>

    {{-- INFO GRID --}}
    <table class="info-grid">
        <tr>
            <td class="info-label">رقم الحساب</td>
            <td class="info-value mono">{{ $employeeId }}</td>
            <td class="info-label">اسم الحساب</td>
            <td class="info-value">{{ $employeeName }}</td>
            <td class="info-label">مقيم بعملة</td>
            <td class="info-value">{{ $currency ?? 'شيكل' }}</td>
        </tr>
    </table>

    {{-- MAIN TABLE --}}
    <table class="main-table">
        <thead>
            <tr>
                <th rowspan="2" style="width:3%">م</th>
                <th rowspan="2" style="width:9%">التاريخ</th>
                <th rowspan="2" style="width:16%">وصف القيد</th>
                <th rowspan="2" style="width:7%">مدين</th>
                <th rowspan="2" style="width:7%">دائن</th>
                <th rowspan="2" style="width:9%">الرصيد</th>
                <th colspan="5" class="detail-header" style="width:49%">تفاصيل الفاتورة</th>
            </tr>
            <tr>
                <th class="detail-header" style="width:18%">الوصف</th>
                <th class="detail-header" style="width:9%">الوحدة</th>
                <th class="detail-header" style="width:7%">الكمية</th>
                <th class="detail-header" style="width:7%">السعر</th>
                <th class="detail-header" style="width:8%">المبلغ</th>
            </tr>
        </thead>

        @forelse($entries as $idx => $entry)
        @php
        $rawItems = $entry['items'] ?? [];
        $items = $rawItems;
        $hasItems = !empty($items) && count($items) > 0;

        $isVoided = !empty($entry['invoice_details']['invoice_status'])
        && $entry['invoice_details']['invoice_status'] === 'cancelled';

        $voidLabel = '';
        if ($isVoided) {
        $invoiceNum = $entry['invoice_details']['invoice_number'] ?? $entry['transaction_number'] ?? '';
        $voidLabel = 'الغاء فاتورة مبيعات رقم: ' . $invoiceNum;
        }

        $txnDescription = $voidLabel ?: ($hasItems ? ($entry['description'] ?? $entry['transaction_number'] ?? '—') :
        ($entry['transaction_number'] ?? $entry['description'] ?? '—'));

        if ($isVoided) {
        $debitVal = 0.0;
        $creditVal = max((float) ($entry['debit'] ?? 0), (float) ($entry['credit'] ?? 0));
        } else {
        $debitVal = (float) ($entry['debit'] ?? 0);
        $creditVal = (float) ($entry['credit'] ?? 0);
        }

        $rawBalance = (float) ($entry['running_balance'] ?? 0);
        $isNegative = $rawBalance < 0; $balanceDisplay=$isNegative ? '(' . number_format(abs($rawBalance), 2) . ')' :
            number_format($rawBalance, 2); $itemTotal=$hasItems ? collect($items)->sum('total') : 0;
            $itemDiscount = $hasItems ? collect($items)->sum('discount_amount') : 0;
            $invoiceDiscount = $entry['invoice_details']['discount'] ?? 0;
            $totalDiscount = $itemDiscount + $invoiceDiscount;
            $totalBeforeDiscount = $itemTotal + $totalDiscount;
            @endphp

            {{-- ══════════════════════════════════════════════════════════════
             Each transaction is wrapped in its own <tbody class="txn-block">
             with page-break-inside: avoid — mPDF-friendly: no rowspan
             across page boundaries.
             ══════════════════════════════════════════════════════════════ --}}
            <tbody class="txn-block">
                {{-- TRANSACTION HEADER ROW (no rowspan — first 6 cols hold the main row data) --}}
                <tr class="txn-row">
                    <td>{{ $idx + 1 }}</td>
                    <td class="mono date-cell">{{ $entry['date'] ?? '—' }}</td>
                    <td class="wrap-text" style="text-align:right;padding-right:6px;font-size:7.5pt">
                        {{ $txnDescription }}
                    </td>
                    <td class="mono">{{ $debitVal > 0 ? number_format($debitVal, 2) : '0.00' }}</td>
                    <td class="mono">{{ $creditVal > 0 ? number_format($creditVal, 2) : '0.00' }}</td>
                    <td class="mono">{{ $balanceDisplay }}</td>

                    @if($hasItems)
                    @php $firstItem = $items[0]; @endphp
                    <td class="item-desc text-right" style="font-size:7pt">1
                        {{ $firstItem['product_name_ar'] ?? $firstItem['product_name'] ?? $firstItem['item_name'] ?? '—' }}
                    </td>
                    <td style="font-size:7pt">{{ $firstItem['department'] ?? '' }}</td>
                    <td class="mono" style="font-size:7pt">{{ number_format($firstItem['quantity'] ?? 0, 2) }}</td>
                    <td class="mono" style="font-size:7pt">{{ number_format($firstItem['unit_price'] ?? 0, 2) }}</td>
                    <td class="mono bold" style="font-size:7pt">{{ number_format(($firstItem['total'] ?? 0), 2) }}</td>
                    @else
                    <td colspan="5" class="empty-detail" style="text-align:right;padding-right:6px;font-size:7.5pt">
                        {{ $entry['description'] ?? '' }}
                    </td>
                    @endif
                </tr>

                {{-- REMAINING ITEM ROWS (first 6 columns empty via colspan=6) --}}
                @if($hasItems)
                @foreach($items as $itemIdx => $item)
                @if($itemIdx > 0)
                <tr class="item-row">
                    <td colspan="6" class="empty-detail"></td>
                    <td class="item-desc text-right">{{ $itemIdx + 1 }}
                        {{ $item['product_name_ar'] ?? $item['product_name'] ?? $item['item_name'] ?? '—' }}
                    </td>
                    <td>{{ $item['department'] ?? '' }}</td>
                    <td class="mono">{{ number_format($item['quantity'] ?? 0, 2) }}</td>
                    <td class="mono">{{ number_format($item['unit_price'] ?? 0, 2) }}</td>
                    <td class="mono bold">{{ number_format(($item['total'] ?? 0), 2) }}</td>
                </tr>
                @endif
                @endforeach

                {{-- SUMMARY ROW --}}
                <tr class="summary-row">
                    <td colspan="6" class="empty-detail"></td>
                    <td colspan="5" style="text-align:right;padding-right:10px">
                        الإجمالي: <span class="mono">{{ number_format($totalBeforeDiscount, 2) }}</span>
                        &nbsp;&nbsp;&nbsp; الخصم: <span
                            class="mono">{{ $totalDiscount > 0 ? number_format($totalDiscount, 2) : '0.00' }}</span>
                        &nbsp;&nbsp;&nbsp; المبلغ الصافي: <span class="mono">{{ number_format($itemTotal, 2) }}</span>
                    </td>
                </tr>
                @endif
            </tbody>
            @empty
            <tbody>
                <tr>
                    <td colspan="11" style="text-align:center;padding:20px;color:#999;">لا توجد حركات في هذه الفترة</td>
                </tr>
            </tbody>
            @endforelse

            {{-- ════════════════════════════════════
             CLOSING ROW — inside the SAME <table>
             ════════════════════════════════════ --}}
            @php
            $netBalanceText = $closingBalance >= 0
            ? 'رصيد مدين ' . number_format($closingBalance, 2)
            : 'رصيد دائن ' . number_format(abs($closingBalance), 2);
            @endphp
            <tbody>
                <tr class="closing-row">
                    <td colspan="6" style="text-align:right;padding-right:8px">الرصيد:</td>
                    <td colspan="5" style="text-align:right;padding-right:8px" class="mono">
                        {{ number_format($totalDebit, 2) }} | {{ number_format($totalCredit, 2) }} |
                        {{ ($closingBalance < 0) ? '(' . number_format(abs($closingBalance), 2) . ')' : number_format($closingBalance, 2) }}
                        &nbsp;&nbsp; {{ $netBalanceText }}
                    </td>
                </tr>
            </tbody>
    </table>

    {{-- SIGNATURE --}}
    <div class="signature-line">نظام o2 للمحاسبة @رتاج</div>

    @endif

</body>

</html>