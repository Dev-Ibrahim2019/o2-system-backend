<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=550, initial-scale=1.0">
    <title>فاتورة كاشير مطورة</title>
    <!-- استدعاء خط Cairo الاحترافي للنسخ العربي الفخم -->
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
            color: #1a1a1a;
            direction: rtl;
            -webkit-font-smoothing: antialiased;
        }

        .receipt-container {
            width: 550px;
            background: #fff;
            padding: 10px 5px;
            margin: 0 auto;
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

        .header-badges {
            display: flex;
            flex-direction: column;
            gap: 10px;
            width: 150px;
        }

        /* المربعات القوسية اللطيفة بالخلفية المنقطة الخفيفة */
        .badge-box {
            background: #f2f2f2;
            border: 1px solid #dbdbdb;
            border-radius: 14px;
            padding: 8px;
            text-align: center;
        }

        .badge-box .title {
            font-size: 15px;
            color: #666;
            margin-bottom: 2px;
            font-weight: 600;
        }

        .badge-box .value {
            font-size: 26px;
            font-weight: 700;
            color: #000;
        }

        .badge-box.service-type {
            background: #222;
            color: #fff;
            border: none;
        }

        .badge-box.service-type .value {
            color: #fff;
            font-size: 22px;
            font-family: 'Arial', sans-serif;
            letter-spacing: 1px;
        }

        /* صندوق العميل المطور */
        .customer-box {
            background: #f9f9f9;
            border: 1px dashed #cccccc;
            border-radius: 14px;
            padding: 14px 18px;
            text-align: center;
            font-size: 22px;
            font-weight: 600;
            margin-bottom: 18px;
        }

        /* ═══════════════════════════════════════════
           TABLE STYLE (New Column Ordering)
           ═══════════════════════════════════════════ */
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

        /* ترتيب الأعمدة المحدث: صنف -> سعر -> كمية -> إجمالي */
        .col-name { text-align: right; width: 45%; }
        .col-price { text-align: center; width: 18%; font-family: 'Arial', sans-serif; }
        .col-qty { text-align: center; width: 15%; font-family: 'Arial', sans-serif; }
        .col-total { text-align: left; width: 22%; font-family: 'Arial', sans-serif; font-weight: 700; }

        .items-table td {
            padding: 14px 8px;
            border-bottom: 1px dashed #e0e0e0;
            font-size: 20px;
            vertical-align: middle;
        }

        .item-notes {
            font-size: 15px;
            color: #cc0000;
            margin-top: 4px;
            font-weight: 600;
        }

        /* المجموع والتذييل */
        .totals-container {
            border-top: 2px solid #000;
            padding-top: 12px;
            margin-bottom: 18px;
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f2f2f2;
            border: 1px solid #dbdbdb;
            border-radius: 14px;
            padding: 14px 20px;
        }

        .total-label { font-size: 22px; font-weight: 700; }
        .total-amount { font-size: 32px; font-weight: 700; font-family: 'Arial', sans-serif; }

        .employee-box {
            border: 1px solid #dbdbdb;
            border-radius: 14px;
            background: #f9f9f9;
            padding: 12px 16px;
            margin-bottom: 18px;
            text-align: right;
            font-size: 20px;
            font-weight: 600;
        }

        .footer {
            text-align: center;
            font-size: 18px;
            font-weight: 600;
            border-top: 1px dashed #ccc;
            padding-top: 12px;
        }
    </style>
</head>
<body>

    <div class="receipt-container">
        <div class="header-section">
            <div class="header-info">
                <div><strong>التاريخ:</strong> <span class="en-text">{{ date('d/m/Y') }}</span></div>
                <div><strong>الوقت:</strong> <span class="en-text">{{ date('h:i A') }}</span></div>
                <div><strong>الرقم:</strong> <span class="en-text">{{ $order->order_number ?? $order->id }}</span></div>
            </div>
            <div class="header-badges">
                <div class="badge-box service-type">
                    <div class="value">{{ strtoupper($order->order_type ?? 'T.W') }}</div>
                </div>
                @if(!empty($order->table_number))
                <div class="badge-box">
                    <div class="title">رقم الطاولة</div>
                    <div class="value en-text">{{ $order->table_number }}</div>
                </div>
                @endif
            </div>
        </div>

        <div class="customer-box">اسم العميل: {{ $order->customer_name ?? 'زبون خارجي' }}</div>

        <table class="items-table">
            <thead>
                <tr>
                    <th class="col-name">الصنف</th>
                    <th class="col-price">السعر</th>
                    <th class="col-qty">الكمية</th>
                    <th class="col-total">الإجمالي</th>
                </tr>
            </thead>
            <tbody>
                @foreach($order->items as $item)
                <tr>
                    <td class="col-name">
                        <div>{{ $item->item_name_ar ?? $item->item_name }}</div>
                        @if(!empty($item->notes))
                        <div class="item-notes">// ملاحظة: {{ $item->notes }}</div>
                        @endif
                    </td>
                    <td class="col-price">₪{{ number_format($item->price, 2) }}</td>
                    <td class="col-qty">{{ $item->quantity }}</td>
                    <td class="col-total">₪{{ number_format(($item->price * $item->quantity), 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <div class="totals-container">
            <div class="total-row">
                <span class="total-label">المجموع الإجمالي</span>
                <span class="total-amount">₪{{ number_format($order->total ?? 0, 2) }}</span>
            </div>
        </div>

        @if(!empty($order->cashier->name))
        <div class="employee-box">الموظف : {{ $order->cashier->name }}</div>
        @endif

        <div class="footer">
            <div>شكراً لطلبكم .. نتمنى لكم تجربة رائعة ❤️</div>
        </div>
    </div>

</body>
</html>
