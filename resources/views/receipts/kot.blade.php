<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=550, initial-scale=1.0">
    <title>بون تحضير المطبخ</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
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
            padding: 10px 5px;
            margin: 0 auto;
        }

        .kitchen-title-box {
            background: #f2f2f2;
            border: 2px dashed #000;
            border-radius: 14px;
            padding: 14px;
            text-align: center;
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 18px;
        }

        .header-section {
            display: flex;
            gap: 15px;
            margin-bottom: 18px;
        }

        .header-info {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 8px;
            font-size: 19px;
        }

        .en-text {
            font-family: 'Arial', sans-serif;
            font-weight: 600;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 18px;
        }

        .items-table th {
            background: #f2f2f2;
            border-top: 1px solid #000;
            border-bottom: 1px solid #000;
            padding: 12px 8px;
            font-size: 18px;
            font-weight: 700;
        }

        .items-table td {
            padding: 16px 10px;
            border-bottom: 1px dashed #cccccc;
            font-size: 22px;
            vertical-align: top;
        }

        .qty-badge {
            background: #222;
            color: #fff;
            font-family: 'Arial', sans-serif;
            font-weight: 700;
            padding: 4px 14px;
            border-radius: 8px;
            font-size: 18px;
            display: inline-block;
        }

        .item-notes {
            font-size: 16px;
            color: #cc0000;
            margin-top: 4px;
            font-weight: bold;
        }

        .footer {
            text-align: center;
            font-size: 18px;
            font-weight: 600;
            border-top: 2px solid #000;
            padding-top: 15px;
        }
    </style>
</head>
<body>

    <div class="receipt-container">
        <div class="kitchen-title-box">{{ $printJob->printer->name ?? 'مطبخ رئيسي' }}</div>

        <div class="header-section" style="background: #f9f9f9; border: 1px solid #e0e0e0; border-radius: 14px; padding: 12px; margin-bottom: 18px;">
            <div class="header-info" style="flex-direction: row; justify-content: space-between; font-size: 14px; width: 100%;">
                <div><strong>طلب رقم:</strong> <span class="en-text">#{{ $order->order_number ?? $order->id }}</span></div>
                @if(!empty($order->table_number))
                <div><strong>طاولة:</strong> <span class="en-text">{{ $order->table_number }}</span></div>
                @endif
                <div><strong>الوقت:</strong> <span class="en-text">{{ date('h:i A') }}</span></div>
            </div>
        </div>

        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 20%; text-align: center;">الكمية</th>
                    <th style="width: 80%; text-align: right;">الصنف والملاحظات</th>
                </tr>
            </thead>
            <tbody>
                @foreach($sectionItems ?? $order->items as $item)
                <tr>
                    <td style="text-align: center;"><span class="qty-badge">{{ $item->quantity }}x</span></td>
                    <td>
                        <div style="font-weight: 600; font-size: 16px;">{{ $item->item_name_ar ?? $item->item_name }}</div>
                        @if(!empty($item->notes))
                        <div class="item-notes">⚠️ تنبيه: {{ $item->notes }}</div>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <div class="footer">
            <strong>عدد الأصناف: <span class="en-text">{{ count($sectionItems ?? $order->items) }}</span></strong>
        </div>
    </div>

</body>
</html>
