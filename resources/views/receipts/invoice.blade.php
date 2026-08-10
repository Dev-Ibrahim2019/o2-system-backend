<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=550, initial-scale=1.0">
    <title>فاتورة كاشير مطورة</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@600;700;800&display=swap" rel="stylesheet">
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
            -webkit-font-smoothing: antialiased;
            padding: 0;
        }

        .receipt-container {
            width: 550px;
            background: #fff;
            padding: 5px 12px;
            margin: 0 auto;
        }

        /* الهيدر بدون إطار خارجي وبمسافة مضغوطة */
        .header-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 6px 4px;
            margin-bottom: 10px;
        }

        .header-info {
            display: flex;
            flex-direction: column;
            gap: 5px;
            font-size: 19px; /* تكبير الخط بشكل ملحوظ */
            font-weight: 700; /* زيادة سُمك الخط ليكون واضحاً */
        }

        .en-text {
            font-family: 'Arial', sans-serif;
            font-weight: 800;
        }

        .header-badges {
            display: flex;
            flex-direction: column;
            gap: 8px;
            width: 150px;
        }

        /* مربع رقم الطاولة - خط أسود غامق متصل وليس منقط */
        .badge-box {
            border: 4px solid #000000; /* جعل الإطار أكثر سُمكاً ووضوحاً */
            border-radius: 12px;
            padding: 8px;
            text-align: center;
        }

        .badge-box .title {
            font-size: 15px; /* تكبير خط عنوان الطاولة */
            color: #000000;
            font-weight: 800;
            margin-bottom: 2px;
        }

        .badge-box .value {
            font-size: 26px; /* تكبير رقم الطاولة ليكون ضخماً وواضحاً */
            font-weight: 800;
            color: #000;
        }

        /* نوع الخدمة سفري / صالة */
        .badge-box.service-type {
            background: #000000;
            color: #ffffff;
            border: none;
        }

        .badge-box.service-type .value {
            color: #ffffff;
            font-size: 19px; /* تكبير الخط */
            font-family: 'Arial', sans-serif;
        }

        /* كرت العميل المحدد */
        .customer-card {
            border: 3px dashed #000000;
            text-align: center;
            font-size: 22px; /* تكبير خط اسم العميل */
            font-weight: 800;
            padding: 10px;
            border-radius: 12px;
            margin-bottom: 12px;
        }

        /* كرت الجدول محاط بالكامل بإطار دائري صريح */
        .table-card {
            border: 3px solid #000000; /* زيادة سُمك إطار الجدول */
            border-radius: 14px;
            padding: 0;
            margin-bottom: 12px;
            overflow: hidden;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
        }

        /* تباين رأس الجدول بـ خطوط سوداء صريحة وخط كبير */
        .items-table th {
            background: #f2f2f2;
            border-bottom: 3px solid #000000;
            padding: 10px 6px;
            font-size: 18px; /* تكبير خط العناوين (الصنف، السعر...) */
            font-weight: 800;
        }

        .col-name { text-align: right; width: 45%; font-weight: 800; }
        .col-price { text-align: center; width: 18%; font-family: 'Arial', sans-serif; }
        .col-qty { text-align: center; width: 15%; font-family: 'Arial', sans-serif; }
        .col-total { text-align: left; width: 22%; font-family: 'Arial', sans-serif; font-weight: 800; }

        /* خطوط منقطة سوداء حادة لتقرأها الطابعة بوضوح مع خط كبير جداً ومريح للأصناف */
        .items-table td {
            padding: 12px 6px; /* مسافة داخلية ممتازة للخطوط الكبيرة */
            border-bottom: 3px dotted #000000; /* جعل نقاط الفصل أكثر سُمكاً ووضوحاً */
            font-size: 19px; /* تكبير خط الأصناف والأسعار داخل الجدول */
            font-weight: 700;
            vertical-align: middle;
        }

        .items-table tr:last-child td {
            border-bottom: none;
        }

        .item-notes {
            font-size: 15px; /* تكبير خط الملاحظات */
            color: #000000;
            margin-top: 3px;
            font-weight: 700;
        }

        /* صندوق المجموع النهائي الفخم والواضح جداً */
        .total-box {
            border: 3px solid #000000; /* زيادة سُمك الإطار */
            border-radius: 14px;
            padding: 14px 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }

        .total-label {
            font-size: 22px; /* تكبير كلمة المجموع الإجمالي */
            font-weight: 800;
        }

        .total-amount {
            font-size: 34px; /* تكبير خط الرقم النهائي ليكون ضخماً جداً وواضحاً */
            font-weight: 800;
            font-family: 'Arial', sans-serif;
        }

        .employee-card {
            border: 2px solid #000000;
            border-radius: 10px;
            padding: 8px 14px;
            font-size: 16px; /* تكبير خط اسم الموظف */
            font-weight: 700;
            margin-bottom: 12px;
            text-align: right;
        }

        .footer {
            text-align: center;
            font-size: 16px; /* تكبير خط التذييل */
            font-weight: 700;
            border-top: 3px dashed #000000;
            padding-top: 10px;
            margin-top: 6px;
        }
    </style>
</head>
<body>

    <div class="receipt-container">
        <div class="header-section">
            <div class="header-info">
                <div>التاريخ: <span class="en-text">{{ date('d/m/Y') }}</span></div>
                <div>الوقت: <span class="en-text">{{ date('h:i A') }}</span></div>
                <div>الرقم: <span class="en-text">#{{ $order->order_number ?? $order->id }}</span></div>
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

        <div class="customer-card">اسم العميل: {{ $order->customer_name ?? 'زبون خارجي' }}</div>

        <div class="table-card">
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
                    @php
                        $displayItems = $filteredItems ?? $order->items;
                    @endphp
                    @foreach($displayItems as $item)
                    <tr>
                        <td class="col-name">
                            <div>{{ $item->item_name_ar ?? $item->item_name }}</div>
                            @if(!empty($item->notes))
                            <div class="item-notes">// ملاحظة: {{ $item->notes }}</div>
                            @endif
                        </td>
                        <td class="col-price">₪{{ number_format($item->price, 2) }}</td>
                        <td class="col-qty">{{ $item->quantity }}</td>
                        <td class="col-total">₪{{ number_format($item->total ?? ($item->price * $item->quantity), 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="total-box">
            <span class="total-label">المجموع الإجمالي</span>
            <span class="total-amount">₪{{ number_format($filteredTotal ?? $order->total ?? 0, 2) }}</span>
        </div>

        @if(!empty($order->printer->name))
        <div class="employee-card">
            طُبعت بواسطة: {{ $order->printer->name }}
            <br>
            <small>{{ $order->printed_at ? $order->printed_at->format('d/m/Y h:i A') : date('d/m/Y h:i A') }}</small>
        </div>
        @elseif(!empty($order->cashier->name))
        <div class="employee-card">الموظف: {{ $order->cashier->name }}</div>
        @endif

        <div class="footer">
            <div>شكراً لطلبكم .. نتمنى لكم تجربة رائعة ❤️</div>
        </div>
    </div>

</body>
</html>
