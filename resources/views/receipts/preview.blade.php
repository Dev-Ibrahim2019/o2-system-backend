<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>معاينة القوالب المطورة والحديثة</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Cairo', 'Tahoma', sans-serif;
        }

        body {
            background: #e5e5e5;
            padding: 40px 20px;
            direction: rtl;
            -webkit-font-smoothing: antialiased;
        }

        .page-title {
            text-align: center;
            margin-bottom: 35px;
            font-size: 22px;
            color: #222;
            font-weight: 700;
        }

        .preview-grid {
            display: flex;
            gap: 40px;
            justify-content: center;
            flex-wrap: wrap;
            align-items: flex-start;
        }

        .preview-card {
            background: #fff;
            border-radius: 20px;
            padding: 15px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.08);
        }

        .preview-card h3 {
            text-align: center;
            margin-top: 5px;
            margin-bottom: 20px;
            color: #555;
            font-size: 16px;
            border-bottom: 2px dashed #ddd;
            padding-bottom: 10px;
        }

        /* ═══════════════════════════════════════════
           STYLE FOR BOTH RECEIPTS (80mm - 550px)
           ═══════════════════════════════════════════ */
        .receipt-container {
            width: 550px;
            background: #fff;
            padding: 10px 5px;
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
            gap: 6px;
            font-size: 15px;
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
            font-size: 12px;
            color: #666;
            margin-bottom: 2px;
            font-weight: 600;
        }

        .badge-box .value {
            font-size: 20px;
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
            font-size: 16px;
            font-family: 'Arial', sans-serif;
            letter-spacing: 1px;
        }

        /* صندوق العميل المطور */
        .customer-box {
            background: #f9f9f9;
            border: 1px dashed #cccccc;
            border-radius: 14px;
            padding: 12px 16px;
            text-align: center;
            font-size: 17px;
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
            padding: 10px 8px;
            font-size: 14px;
            font-weight: 700;
        }

        /* ترتيب الأعمدة المحدث: صنف -> سعر -> كمية -> إجمالي */
        .col-name { text-align: right; width: 45%; }
        .col-price { text-align: center; width: 18%; font-family: 'Arial', sans-serif; }
        .col-qty { text-align: center; width: 15%; font-family: 'Arial', sans-serif; }
        .col-total { text-align: left; width: 22%; font-family: 'Arial', sans-serif; font-weight: 700; }

        .items-table td {
            padding: 12px 8px;
            border-bottom: 1px dashed #e0e0e0;
            font-size: 15px;
            vertical-align: middle;
        }

        .item-notes {
            font-size: 12px;
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
            padding: 12px 20px;
        }

        .total-label { font-size: 16px; font-weight: 700; }
        .total-amount { font-size: 24px; font-weight: 700; font-family: 'Arial', sans-serif; }

        .employee-box {
            border: 1px solid #dbdbdb;
            border-radius: 14px;
            background: #f9f9f9;
            padding: 10px 16px;
            margin-bottom: 18px;
            text-align: right;
            font-size: 15px;
            font-weight: 600;
        }

        .footer {
            text-align: center;
            font-size: 14px;
            font-weight: 600;
            border-top: 1px dashed #ccc;
            padding-top: 12px;
        }

        /* ═══════════════════════════════════════════
           KITCHEN TICKET SPECIFIC
           ═══════════════════════════════════════════ */
        .kitchen-title-box {
            background: #f2f2f2;
            border: 2px dashed #000;
            border-radius: 14px;
            padding: 12px;
            text-align: center;
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 18px;
        }

        .qty-badge {
            background: #222;
            color: #fff;
            font-family: 'Arial', sans-serif;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 8px;
            font-size: 15px;
        }
    </style>
</head>
<body>

    <div class="page-title">لوحة معاينة قوالب الطباعة الحرارية المطور (80mm)</div>

    <div class="preview-grid">

        {{-- 1️⃣ كرت معاينة فاتورة الكاشير المحدثة --}}
        <div class="preview-card">
            <h3>فاتورة كاشير - Cashier Invoice</h3>
            <div class="receipt-container">
                <div class="header-section">
                    <div class="header-info">
                        <div><strong>التاريخ:</strong> <span class="en-text">12/07/2026</span></div>
                        <div><strong>الوقت:</strong> <span class="en-text">04:33 PM</span></div>
                        <div><strong>الرقم:</strong> <span class="en-text">274</span></div>
                    </div>
                    <div class="header-badges">
                        <div class="badge-box service-type">
                            <div class="value">T.W</div>
                        </div>
                        <div class="badge-box">
                            <div class="title">رقم الطاولة</div>
                            <div class="value en-text">105</div>
                        </div>
                    </div>
                </div>

                <div class="customer-box">اسم العميل: فرح جربوع</div>

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
                        <tr>
                            <td class="col-name">
                                <div>كنافة نابلسية 50 نابلزية</div>
                                <div class="item-notes">// واكتب عليها مبارك التخرج عبود</div>
                            </td>
                            <td class="col-price">₪50.00</td>
                            <td class="col-qty">1</td>
                            <td class="col-total">₪50.00</td>
                        </tr>
                        <tr>
                            <td class="col-name"><div>بيتزا مارجريتا عائلي</div></td>
                            <td class="col-price">₪30.00</td>
                            <td class="col-qty">2</td>
                            <td class="col-total">₪60.00</td>
                        </tr>
                    </tbody>
                </table>

                <div class="totals-container">
                    <div class="total-row">
                        <span class="total-label">المجموع الإجمالي</span>
                        <span class="total-amount">₪110.00</span>
                    </div>
                </div>

                <div class="employee-box">الموظف : محمود فروانة</div>

                <div class="footer">
                    <div>شكراً لطلبكم .. نتمنى لكم تجربة رائعة ❤️</div>
                </div>
            </div>
        </div>

        {{-- 2️⃣ كرت معاينة بون المطبخ المحدث --}}
        <div class="preview-card">
            <h3>بون المطبخ - Kitchen Ticket</h3>
            <div class="receipt-container">
                <div class="kitchen-title-box">مطبخ رئيسي</div>

                <div class="header-section" style="background: #f9f9f9; border: 1px solid #e0e0e0; border-radius: 14px; padding: 12px; margin-bottom: 18px;">
                    <div class="header-info" style="flex-direction: row; justify-content: space-between; font-size: 14px; width: 100%;">
                        <div><strong>طلب رقم:</strong> <span class="en-text">#274</span></div>
                        <div><strong>طاولة:</strong> <span class="en-text">105</span></div>
                        <div><strong>الوقت:</strong> <span class="en-text">04:33 PM</span></div>
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
                        <tr>
                            <td style="text-align: center;"><span class="qty-badge">1x</span></td>
                            <td>
                                <div style="font-weight: 600; font-size: 16px;">كنافة نابلسية 50 نابلزية</div>
                                <div class="item-notes">⚠️ تنبيه: واكتب عليها مبارك التخرج عبود</div>
                            </td>
                        </tr>
                        <tr>
                            <td style="text-align: center;"><span class="qty-badge">2x</span></td>
                            <td><div style="font-weight: 600; font-size: 16px;">بيتزا مارجريتا عائلي</div></td>
                        </tr>
                    </tbody>
                </table>

                <div class="footer" style="border-top: 2px solid #000; padding-top: 15px;">
                    <strong>عدد الأصناف: <span class="en-text">2</span></strong>
                </div>
            </div>
        </div>

    </div>

</body>
</html>
