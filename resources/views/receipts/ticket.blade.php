<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=550, initial-scale=1.0">
    <title>تذكرة تحضير قسم</title>
    <style>
        /* إلغاء هوامش الصفحة الافتراضية عند الطباعة */
        @page {
            margin: 0; /* يمنع الفراغ الكبير في أعلى وأسفل الورقة من المتصفح */
        }

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
            -webkit-font-smoothing: antialiased;
            padding: 0;
            margin: 0;
        }

        /* حاوية مضغوطة جداً ومحاذية للأعلى تماماً */
        .receipt-container {
            width: 550px;
            background: #fff;
            padding: 2px 8px; /* تقليص الحشو الداخلي لأقل درجة */
            margin: 0 auto;
        }

        /* ترويسة علوية ملتصقة بالأعلى */
        .restaurant-title {
            text-align: center;
            font-size: 16px;
            font-weight: 800;
            border-bottom: 2px dashed #000;
            padding: 2px 0 4px 0; /* مسافات صغيرة جداً */
            margin-bottom: 6px;
        }

        /* معلومات الطلب مضغوطة وبمسافة سفلية صغيرة */
        .info-bar {
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .en-text {
            font-family: 'Arial', sans-serif;
            font-weight: 800;
        }

        /* جدول الأصناف المضغوط للغاية */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
        }

        /* ترويسة الجدول ناعمة وملتصقة */
        .items-table th {
            border-top: 2px solid #000;
            border-bottom: 2px solid #000;
            padding: 3px;
            font-size: 12px;
            font-weight: 800;
            background: #f9f9f9;
        }

        /* أسطر ضيقة جداً */
        .items-table td {
            padding: 4px 2px; /* تقليل الطول الكلي لكل سطر */
            border-bottom: 1.5px dotted #000;
            font-size: 14px;
            font-weight: 700;
            vertical-align: middle;
        }

        .items-table tr:last-child td {
            border-bottom: 2px solid #000;
        }

        /* شارة الكمية الصغيرة الأنيقة */
        .qty-badge {
            background: #000;
            color: #fff;
            font-family: 'Arial', sans-serif;
            font-weight: 800;
            padding: 1px 6px;
            border-radius: 4px;
            font-size: 13px;
            display: inline-block;
        }

        /* مربع القسم الصغير جداً */
        .dept-tag {
            display: inline-block;
            border: 1.5px solid #000;
            border-radius: 5px;
            padding: 0px 4px;
            font-size: 10px;
            font-weight: 800;
            background: #f2f2f2;
            margin-right: 4px;
            vertical-align: middle;
        }

        .item-notes {
            font-size: 11px;
            color: #cc0000;
            margin-top: 1px;
            font-weight: 800;
        }

        /* تذييل ناعم وبدون أي فراغات سفلية ضخمة */
        .footer {
            text-align: center;
            font-size: 11px;
            font-weight: 700;
            padding: 4px 0 2px 0;
            margin-top: 2px;
        }
    </style>
</head>
<body>

    <div class="receipt-container">

        <div class="restaurant-title">
            تذكرة تحضير مطعم O2
        </div>

        <div class="info-bar">
            <div>طلب: <span class="en-text">#{{ $order->order_number ?? $order->id }}</span></div>
            @if(!empty($order->table_number))
            <div>طاولة: <span class="en-text">{{ $order->table_number }}</span></div>
            @endif
            <div>الوقت: <span class="en-text">{{ date('h:i A') }}</span></div>
        </div>

        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 15%; text-align: center;">الكمية</th>
                    <th style="width: 85%; text-align: right;">الصنف والتعليمات / القسم</th>
                </tr>
            </thead>
            <tbody>
                @foreach($ticket->ticketItems as $ticketItem)
                <tr>
                    <td style="text-align: center;">
                        <span class="qty-badge">{{ $ticketItem->quantity }}x</span>
                    </td>
                    <td>
                        <div style="display: flex; align-items: center; justify-content: space-between;">
                            <span style="font-weight: 800;">
                                {{ $ticketItem->orderItem->item_name_ar ?? $ticketItem->orderItem->item_name }}
                            </span>

                            <span class="dept-tag">
                                {{ $ticket->department->name ?? 'تحضير' }}
                            </span>
                        </div>

                        @if(!empty($ticketItem->notes))
                        <div class="item-notes">⚠️ {{ $ticketItem->notes }}</div>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <div class="footer">
            <div>عدد عناصر التيكت: <span class="en-text">{{ $ticket->ticketItems->count() }}</span></div>
        </div>
    </div>

</body>
</html>
