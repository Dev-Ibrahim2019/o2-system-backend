<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=550, initial-scale=1.0">
    <title>فاتورة كاشير مطورة</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Tahoma', 'Arial', sans-serif;
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
            padding: 3px 10px;
            margin: 0 auto;
        }

        /* شعار O2 أعلى الفاتورة */
        .brand-header {
            text-align: center;
            padding: 8px 0 6px;
            margin-bottom: 4px;
            border-bottom: 2px dashed #000;
        }

        .o2-logo {
            font-family: 'Arial Black', 'Arial', sans-serif;
            font-weight: 900;
            font-size: 60px;
            line-height: 1;
            color: #e2001a;
            letter-spacing: -1px;
        }

        .o2-logo sub {
            font-size: 32px;
            font-weight: 900;
            vertical-align: sub;
        }

        /* الهيدر بدون إطار خارجي وبمسافة مضغوطة */
        .header-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 3px 2px;
            margin-bottom: 5px;
        }

        .header-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
            font-size: 20px;
            font-weight: 800;
        }

        .en-text {
            font-family: 'Arial', sans-serif;
            font-weight: 800;
        }

        .header-badges {
            display: flex;
            flex-direction: column;
            gap: 4px;
            width: 155px;
            flex-shrink: 0;
        }

        /* مربع رقم الطاولة - خط أسود غامق متصل وليس منقط */
        .badge-box {
            border: 2px solid #000000;
            border-radius: 8px;
            padding: 3px;
            text-align: center;
        }

        .badge-box .title {
            font-size: 13px;
            color: #000000;
            font-weight: 800;
            margin-bottom: 1px;
        }

        .badge-box .value {
            font-size: 24px;
            font-weight: 800;
            color: #000;
        }

        /* كرت العميل المحدد */
        .customer-card {
            border: 2px dashed #000000;
            text-align: center;
            font-size: 20px;
            font-weight: 800;
            padding: 7px;
            border-radius: 8px;
            margin-bottom: 6px;
        }

        /* كرت الجدول محاط بالكامل بإطار دائري صريح */
        .table-card {
            border: 1.5px solid #000000;
            border-radius: 8px;
            padding: 0;
            margin-bottom: 5px;
            overflow: hidden;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
        }

        /* تباين رأس الجدول بـ خطوط سوداء صريحة */
        .items-table th {
            background: #f2f2f2;
            border-bottom: 2px solid #000000;
            padding: 7px 4px;
            font-size: 19px;
            font-weight: 800;
        }

        .col-name { text-align: right; width: 45%; font-weight: 800; }
        .col-price { text-align: center; width: 18%; font-family: 'Arial', sans-serif; }
        .col-qty { text-align: center; width: 15%; font-family: 'Arial', sans-serif; }
        .col-total { text-align: left; width: 22%; font-family: 'Arial', sans-serif; font-weight: 800; }

        /* خطوط منقطة سوداء حادة لتقرأها الطابعة بوضوح */
        .items-table td {
            padding: 8px 4px;
            border-bottom: 1.5px dotted #000000;
            font-size: 21px;
            font-weight: 800;
            vertical-align: middle;
        }

        .items-table tr:last-child td {
            border-bottom: none;
        }

        .item-notes {
            font-size: 16px;
            color: #000000;
            margin-top: 2px;
            font-weight: 800;
        }

        /* صندوق المجموع النهائي */
        .total-box {
            border: 1.5px solid #000000;
            border-radius: 8px;
            padding: 5px 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 5px;
        }

        .total-label {
            font-size: 20px;
            font-weight: 800;
        }

        .total-amount {
            font-size: 30px;
            font-weight: 800;
            font-family: 'Arial', sans-serif;
        }

        .employee-card {
            border: 1.5px solid #000000;
            border-radius: 6px;
            padding: 6px 8px;
            font-size: 16px;
            font-weight: 800;
            margin-bottom: 4px;
            text-align: right;
        }

        .footer {
            text-align: center;
            font-size: 16px;
            font-weight: 800;
            border-top: 2px dashed #000000;
            padding-top: 6px;
            margin-top: 4px;
        }
    </style>
</head>
<body>

    <div class="receipt-container">
        <div class="brand-header">
            <span class="o2-logo">O<sub>2</sub></span>
        </div>
        <div class="header-section">
            <div class="header-info">
                <div>التاريخ: <span class="en-text">{{ date('d/m/Y') }}</span></div>
                <div>الوقت: <span class="en-text">{{ date('h:i A') }}</span></div>
                <div>الرقم: <span class="en-text">#{{ $order->order_number ?? $order->id }}</span></div>
            </div>
            <div class="header-badges">
                @if(!empty($order->table_number))
                <div class="badge-box">
                    <div class="title">رقم الطاولة</div>
                    <div class="value en-text">{{ $order->table_number }}</div>
                </div>
                @endif
            </div>
        </div>

        @isset($filteredItems)
            {{-- نسخة القسم (محلي) — نعرض اسم القسم بدل اسم العميل --}}
            <div class="customer-card">القسم: {{ ltrim(str_replace('طابعة', '', $printerName ?? ''), ' ') ?: 'قسم' }}</div>
        @else
            <div class="customer-card">اسم الزبون: {{ $order->customer_name ?? 'زبون خارجي' }}</div>
        @endisset

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

        @php
            // الخصم إجمالي (تلقائي من محرك الخصومات + يدوي) يُعرض بس بفاتورة
            // الطلب الكاملة (مش بالنسخ المفلترة لكل قسم، لأنه ما بينقسم بشكل منطقي).
            $totalDiscount = !isset($filteredItems)
                ? (float) ($order->engine_discount_amount ?? 0) + (float) ($order->discount_amount ?? 0)
                : 0;
        @endphp

        @if($totalDiscount > 0)
        <div class="total-box" style="margin-bottom: 3px;">
            <span class="total-label" style="font-size: 17px;">المجموع الفرعي</span>
            <span class="total-amount" style="font-size: 21px;">₪{{ number_format($order->subtotal ?? 0, 2) }}</span>
        </div>
        <div class="total-box" style="margin-bottom: 3px;">
            <span class="total-label" style="font-size: 17px;">الخصم</span>
            <span class="total-amount" style="font-size: 21px;">-₪{{ number_format($totalDiscount, 2) }}</span>
        </div>
        @endif

        <div class="total-box">
            <span class="total-label">المجموع الإجمالي</span>
            <span class="total-amount">₪{{ number_format($filteredTotal ?? $order->total ?? 0, 2) }}</span>
        </div>

        <div class="employee-card">
            @if(!empty($order->printedByUser->name))
                طُبعت بواسطة: {{ $order->printedByUser->name }}
            @elseif(!empty($order->cashier->name))
                طُبعت بواسطة: {{ $order->cashier->name }}
            @else
                طُبعت بواسطة: —
            @endif
            <br>
            <small>{{ $order->printed_at ? $order->printed_at->format('d/m/Y h:i A') : date('d/m/Y h:i A') }}</small>
        </div>

        <div class="footer">
            <div>شكراً لطلبكم .. نتمنى لكم تجربة رائعة ❤️</div>
        </div>
    </div>

</body>
</html>
