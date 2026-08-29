<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=550, initial-scale=1.0">
    <title>بون تحضير المطبخ</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Cairo', 'Tahoma', sans-serif;
        }

        body {
            background: #fff;
            color: #000;
            direction: rtl;
        }

        .receipt-container {
            width: 550px;
            background: #fff;
            padding: 4px 6px;
            margin: 0 auto;
        }

        /* شعار O2 */
        .brand-header {
            text-align: center;
            padding: 0 0 4px;
            margin-bottom: 6px;
            border-bottom: 2px dashed #000;
        }

        .o2-logo {
            font-family: 'Arial Black', 'Arial', sans-serif;
            font-weight: 900;
            font-size: 42px;
            line-height: 1;
            color: #e2001a;
            letter-spacing: -1px;
        }

        .o2-logo sub {
            font-size: 22px;
            font-weight: 900;
            vertical-align: sub;
        }

        .kitchen-title-box {
            background: #f2f2f2;
            border: 2px dashed #000;
            border-radius: 10px;
            padding: 7px;
            text-align: center;
            font-size: 26px;
            font-weight: 800;
            margin-bottom: 7px;
        }

        .header-row {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 2px 12px;
            font-size: 19px;
            font-weight: 800;
            padding: 4px 2px 7px;
            border-bottom: 1.5px solid #000;
            margin-bottom: 4px;
        }

        .en-text {
            font-family: 'Arial', sans-serif;
            font-weight: 800;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
        }

        .items-table th {
            background: #f2f2f2;
            border-bottom: 2px solid #000;
            padding: 5px 6px;
            font-size: 18px;
            font-weight: 800;
        }

        .items-table td {
            padding: 7px 6px;
            border-bottom: 1.5px dashed #bcbcbc;
            font-size: 23px;
            font-weight: 700;
            vertical-align: top;
        }

        .items-table tr:last-child td {
            border-bottom: none;
        }

        .item-name {
            font-weight: 800;
            font-size: 23px;
        }

        .qty-badge {
            background: #000;
            color: #fff;
            font-family: 'Arial', sans-serif;
            font-weight: 800;
            padding: 3px 12px;
            border-radius: 6px;
            font-size: 21px;
            display: inline-block;
        }

        .item-notes {
            font-size: 18px;
            color: #000;
            margin-top: 3px;
            font-weight: 800;
        }

        /* صندوق الإجمالي — مباشرة تحت الطلبات */
        .total-box {
            border: 2.5px solid #000;
            border-radius: 8px;
            padding: 7px 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 4px;
        }

        .total-box .label {
            font-size: 22px;
            font-weight: 800;
        }

        .total-box .amount {
            font-size: 30px;
            font-weight: 900;
            font-family: 'Arial', sans-serif;
        }

        .footer {
            text-align: center;
            font-size: 19px;
            font-weight: 800;
            border-top: 2px dashed #000;
            padding-top: 6px;
            margin-top: 4px;
        }

        .footer .printed-by {
            margin-top: 3px;
            font-size: 17px;
            font-weight: 700;
        }

        .footer .stamp {
            margin-top: 2px;
            font-size: 15px;
            font-weight: 700;
        }
    </style>
</head>
<body>

    <div class="receipt-container">
        <div class="brand-header">
            <span class="o2-logo">O<sub>2</sub></span>
        </div>

        <div class="kitchen-title-box">{{ $printJob->printer->name ?? 'مطبخ رئيسي' }}</div>

        @php($kotMeta = $kotMeta ?? null)
        @php($closedAt = ($kotMeta['closed_at'] ?? null) ?: now())
        <div class="header-row">
            <div>طلب: <span class="en-text">#{{ $order->order_number ?? $order->id }}</span></div>
            @if(!empty($order->table_number))
            <div>طاولة: <span class="en-text">{{ $order->table_number }}</span></div>
            @endif
            <div>الوقت: <span class="en-text">{{ $closedAt->format('h:i A') }}</span></div>
        </div>

        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 18%; text-align: center;">الكمية</th>
                    <th style="width: 60%; text-align: right;">الصنف والملاحظات</th>
                    <th style="width: 22%; text-align: left;">السعر</th>
                </tr>
            </thead>
            <tbody>
                @foreach($sectionItems ?? $order->items as $item)
                <tr>
                    <td style="text-align: center;"><span class="qty-badge">{{ $item->quantity }}x</span></td>
                    <td>
                        <div class="item-name">{{ $item->item_name_ar ?? $item->item_name }}</div>
                        @if(!empty($item->notes))
                        <div class="item-notes">⚠️ {{ $item->notes }}</div>
                        @endif
                    </td>
                    <td class="en-text" style="text-align: left;">{{ number_format($item->price ?? 0, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        {{-- إجمالي مبلغ أصناف هذا القسم مباشرة بعد الطلبات --}}
        <div class="total-box">
            <span class="label">إجمالي المبلغ</span>
            <span class="amount">₪{{ number_format(($kotMeta['section_total'] ?? null) ?: ($kotMeta['order_total'] ?? ($order->total ?? 0)), 2) }}</span>
        </div>

        <div class="footer">
            <div>عدد الأصناف: <span class="en-text">{{ count($sectionItems ?? $order->items) }}</span></div>

            @if($kotMeta && !empty($kotMeta['printed_by']))
            <div class="printed-by">طبعها: {{ $kotMeta['printed_by'] }}</div>
            @endif

            <div class="stamp en-text">{{ $closedAt->format('Y-m-d h:i A') }}</div>
        </div>
    </div>

</body>
</html>
