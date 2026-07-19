<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=550, initial-scale=1.0">
    <title>تيكيت قسم</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@700;800&display=swap" rel="stylesheet">
    <style>
        @page { margin: 0; }

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

        .ticket {
            width: 550px;
            background: #fff;
            padding: 6px 8px;
            margin: 0 auto;
            border: 2px dashed #000;
            border-radius: 10px;
        }

        .ticket-header {
            text-align: center;
            font-size: 15px;
            font-weight: 800;
            border-bottom: 2px dashed #000;
            padding-bottom: 4px;
            margin-bottom: 6px;
        }

        .ticket-dept {
            display: inline-block;
            background: #000;
            color: #fff;
            font-size: 13px;
            font-weight: 800;
            padding: 2px 10px;
            border-radius: 6px;
            margin-bottom: 6px;
        }

        .ticket-info {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 6px;
            padding: 0 2px;
        }

        .en { font-family: 'Arial', sans-serif; font-weight: 800; }

        .items-list {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 4px;
        }

        .items-list th {
            border-top: 1.5px solid #000;
            border-bottom: 1.5px solid #000;
            padding: 2px 4px;
            font-size: 11px;
            font-weight: 800;
            background: #f5f5f5;
        }

        .items-list td {
            padding: 4px 4px;
            border-bottom: 1px dotted #bbb;
            font-size: 13px;
            font-weight: 700;
            vertical-align: middle;
        }

        .items-list tr:last-child td {
            border-bottom: 1.5px solid #000;
        }

        .qty {
            background: #000;
            color: #fff;
            font-family: 'Arial', sans-serif;
            font-weight: 800;
            padding: 1px 6px;
            border-radius: 4px;
            font-size: 12px;
            display: inline-block;
            min-width: 24px;
            text-align: center;
        }

        .item-notes {
            font-size: 10px;
            color: #cc0000;
            font-weight: 800;
            margin-top: 1px;
        }

        .ticket-footer {
            text-align: center;
            font-size: 10px;
            font-weight: 700;
            border-top: 1.5px solid #000;
            padding-top: 3px;
        }
    </style>
</head>
<body>

    <div class="ticket">
        <div class="ticket-header">{{ $sectionName }}</div>

        <div class="ticket-info">
            <div>طلب: <span class="en">#{{ $order->order_number ?? $order->id }}</span></div>
            @if(!empty($order->table_number))
            <div>طاولة: <span class="en">{{ $order->table_number }}</span></div>
            @endif
            <div><span class="en">{{ date('h:i A') }}</span></div>
        </div>

        <table class="items-list">
            <thead>
                <tr>
                    <th style="width: 15%; text-align: center;">الكمية</th>
                    <th style="width: 85%; text-align: right;">الصنف</th>
                </tr>
            </thead>
            <tbody>
                @foreach($sectionItems as $item)
                <tr>
                    <td style="text-align: center;">
                        <span class="qty">{{ $item->quantity }}x</span>
                    </td>
                    <td>
                        <div style="font-weight: 800;">{{ $item->item_name_ar ?? $item->item_name ?? $item->name ?? '—' }}</div>
                        @if(!empty($item->notes))
                        <div class="item-notes">⚠️ {{ $item->notes }}</div>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <div class="ticket-footer">
            عدد: <span class="en">{{ count($sectionItems) }}</span> صنف
        </div>
    </div>

</body>
</html>
