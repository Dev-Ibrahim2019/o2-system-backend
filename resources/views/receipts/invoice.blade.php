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
            padding: 0;
            margin: 0 auto;
        }

        /* شعار O2 أعلى الفاتورة */
        .brand-header {
            text-align: center;
            padding: 0 0 3px;
            margin-bottom: 3px;
            border-bottom: 2px dashed #000;
        }

        .o2-logo {
            font-family: 'Arial Black', 'Arial', sans-serif;
            font-weight: 900;
            font-size: 46px;
            line-height: 1;
            color: #e2001a;
            letter-spacing: -1px;
        }

        .o2-logo sub {
            font-size: 25px;
            font-weight: 900;
            vertical-align: sub;
        }

        /* الهيدر بدون إطار خارجي وبمسافة مضغوطة */
        .header-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 2px;
            margin-bottom: 4px;
        }

        .header-info {
            display: flex;
            flex-direction: column;
            gap: 3px;
            font-size: 18px;
            font-weight: 800;
        }

        .en-text {
            font-family: 'Arial', sans-serif;
            font-weight: 800;
        }

        .header-badges {
            display: flex;
            flex-direction: column;
            gap: 3px;
            width: 120px;
            flex-shrink: 0;
        }

        /* مربع رقم الطاولة - خط أسود غامق متصل وليس منقط */
        .badge-box {
            border: 2px solid #000000;
            border-radius: 6px;
            padding: 2px;
            text-align: center;
        }

        .badge-box .title {
            font-size: 11px;
            color: #000000;
            font-weight: 800;
            margin-bottom: 1px;
        }

        .badge-box .value {
            font-size: 20px;
            font-weight: 800;
            color: #000;
        }

        /* كرت العميل المحدد */
        .customer-card {
            border: 2px dashed #000000;
            text-align: center;
            font-size: 18px;
            font-weight: 800;
            padding: 5px;
            border-radius: 6px;
            margin-bottom: 4px;
        }

        /* كرت الجدول محاط بالكامل بإطار دائري صريح */
        .table-card {
            border: 1.5px solid #000000;
            border-radius: 6px;
            padding: 0;
            margin-bottom: 4px;
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
            padding: 5px 3px;
            font-size: 17px;
            font-weight: 800;
        }

        .col-name { text-align: right; width: 45%; font-weight: 800; }
        .col-price { text-align: center; width: 18%; font-family: 'Arial', sans-serif; }
        .col-qty { text-align: center; width: 15%; font-family: 'Arial', sans-serif; }
        .col-total { text-align: left; width: 22%; font-family: 'Arial', sans-serif; font-weight: 800; }

        /* خطوط منقطة سوداء حادة لتقرأها الطابعة بوضوح */
        .items-table td {
            padding: 6px 3px;
            border-bottom: 1.5px dotted #000000;
            font-size: 19px;
            font-weight: 800;
            vertical-align: middle;
        }

        .items-table tr:last-child td {
            border-bottom: none;
        }

        .item-notes {
            font-size: 14px;
            color: #000000;
            margin-top: 1px;
            font-weight: 800;
        }

        /* صندوق المجموع النهائي */
        .total-box {
            border: 1.5px solid #000000;
            border-radius: 6px;
            padding: 4px 6px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 4px;
        }

        .total-label {
            font-size: 19px;
            font-weight: 800;
        }

        .total-amount {
            font-size: 27px;
            font-weight: 800;
            font-family: 'Arial', sans-serif;
        }

        .employee-card {
            border: 1.5px solid #000000;
            border-radius: 5px;
            padding: 3px 6px;
            font-size: 13px;
            font-weight: 800;
            margin-bottom: 2px;
            text-align: right;
        }

        /* ملاحظة الطلب (فاتورة الفوري والمحلي) */
        .order-note {
            border: 2px dashed #000000;
            border-radius: 6px;
            padding: 5px 7px;
            font-size: 17px;
            font-weight: 800;
            text-align: right;
            margin-bottom: 4px;
            white-space: pre-wrap;
            word-wrap: break-word;
        }

        .order-note .order-note-label {
            font-size: 12px;
            display: block;
            margin-bottom: 2px;
        }

        .footer {
            text-align: center;
            font-size: 15px;
            font-weight: 800;
            border-top: 2px dashed #000;
            padding-top: 3px;
            margin-top: 2px;
            padding-bottom: 0;
            margin-bottom: 0;
        }

        .footer div {
            line-height: 1.15;
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

        @php
            // نسخة قسم بلا أسعار (زر "طباعة" بمحلي → نسخ الأقسام) — بس الاسم
            // والكمية والملاحظات، بدون عمود السعر ولا الإجمالي ولا المجاميع.
            $hidePrices = !empty($hidePrices);
        @endphp

        <div class="table-card">
            <table class="items-table">
                <thead>
                    <tr>
                        <th class="col-name">الصنف</th>
                        @unless($hidePrices)<th class="col-price">السعر</th>@endunless
                        <th class="col-qty">الكمية</th>
                        @unless($hidePrices)<th class="col-total">الإجمالي</th>@endunless
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
                        @unless($hidePrices)<td class="col-price">₪{{ number_format($item->price, 2) }}</td>@endunless
                        <td class="col-qty">{{ rtrim(rtrim(number_format((float) $item->quantity, 4, '.', ''), '0'), '.') }}</td>
                        @unless($hidePrices)<td class="col-total">₪{{ number_format($item->total ?? ($item->price * $item->quantity), 2) }}</td>@endunless
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if(!empty($order->note))
        <div class="order-note">
            <span class="order-note-label">ملاحظة:</span>{{ $order->note }}
        </div>
        @endif

        @php
            // نسخة قسم مفلترة (وضع "فوري")؟ أو فاتورة الطلب الكاملة (محلي/مدمجة)؟
            $isFilteredSlice = isset($filteredItems);

            $totalDiscount = (float) ($order->engine_discount_amount ?? 0) + (float) ($order->discount_amount ?? 0);
        @endphp

        {{-- نسخ الأقسام بلا أسعار (زر "طباعة" بمحلي): ما بنعرض أي مجاميع نهائياً. --}}
        @unless($hidePrices)

        {{-- سطر "المجموع الفرعي" + "الخصم" يُعرض فقط بالفاتورة الكاملة.
             نسخ "فوري" المقسّمة للأقسام ما بتعرض سطر خصم — الخصم مخزّن ومخصوم
             من إجمالي الطلب/الفاتورة بقاعدة البيانات فقط. --}}
        @if($totalDiscount > 0 && ! $isFilteredSlice)
        <div class="total-box" style="margin-bottom: 2px;">
            <span class="total-label" style="font-size: 13px;">المجموع الفرعي</span>
            <span class="total-amount" style="font-size: 16px;">₪{{ number_format($order->subtotal ?? 0, 2) }}</span>
        </div>
        <div class="total-box" style="margin-bottom: 2px;">
            <span class="total-label" style="font-size: 13px;">الخصم</span>
            <span class="total-amount" style="font-size: 16px;">-₪{{ number_format($totalDiscount, 2) }}</span>
        </div>
        @endif

        {{-- المجموع الإجمالي:
             • فاتورة كاملة (محلي/مدمجة) → صافي الطلب بعد الخصم
             • نسخة قسم بفوري → مجموع أصناف هذا القسم فقط (الخصم مخزّن بقاعدة
               البيانات على الفاتورة الإجمالية، مش معروض هون) --}}
        <div class="total-box">
            <span class="total-label">المجموع الإجمالي</span>
            <span class="total-amount">₪{{ number_format($isFilteredSlice ? ($filteredTotal ?? 0) : ($order->total ?? 0), 2) }}</span>
        </div>

        @endunless

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
