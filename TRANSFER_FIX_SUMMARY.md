# ملخص إصلاح عملية نقل الطاولات (Order Transfer)

## المشاكل التي تم اكتشافها في `OrderController::transfer()`

### 1. **عدم التحقق من حالات الطلب النهائية** ❌
**المشكلة:** الكود كان يمنع النقل فقط للحالات `paid` و `cancelled`، لكنه كان يسمح بالنقل لحالات أخرى لا ينبغي السماح بها مثل:
- `served` (تم تسليمه)
- `pending_payment` (مؤجل للدفع)

**الإصلاح:** ✅
```php
// قبل
if (in_array($order->status, ['paid', 'cancelled'], true)) {

// بعد
if (in_array($order->status, ['paid', 'cancelled', 'served', 'pending_payment'], true)) {
```

---

### 2. **عدم التحقق من ارتباط الطلب بطاولة** ❌
**المشكلة:** الكود كان يسمح بمحاولة نقل طلبات الـ `takeaway` (التي لا ترتبط بطاولة)، مما قد يسبب أخطاء.

**الإصلاح:** ✅
```php
// التحقق من أن الطلب مرتبط بطاولة (ليس takeaway)
if (! $order->dining_table_id && ! $order->table_number) {
    \Log::warning('[ORDER TRANSFER] Order not associated with any table', ['order_id' => $order->id]);
    return $this->error('هذا الطلب غير مرتبط بطاولة.', 422);
}
```

---

### 3. **منطق معقد وخاطئ للعثور على الطاولة القديمة** ❌
**المشكلة:** الكود كان يستخدم 3 خطوات للبحث عن الطاولة القديمة:
1. عبر `dining_table_id` من الطلب
2. عبر `current_order_id` من الطاولة
3. عبر `table_number` و `branch_id`

**المشكلة في الخطوة 3:** إذا فشلت الخطوتان 1 و 2، فإن الخطوة 3 تبحث عن أي طاولة بنفس رقم الطاولة في نفس الفرع - **وقد تجد طاولة خاطئة!**

**الإصلاح:** ✅
```php
// قبل - 3 خطوات مع خطوة خطيرة
$oldTable = null;
if ($order->dining_table_id) {
    $oldTable = DiningTable::find($order->dining_table_id);
}
if (! $oldTable) {
    $oldTable = DiningTable::where('current_order_id', $order->id)->first();
}
if (! $oldTable && $order->table_number && $order->branch_id) {
    $oldTable = DiningTable::whereRaw('LOWER(table_number) = LOWER(?)', [$order->table_number])
        ->where('branch_id', $order->branch_id)
        ->first(); // ⚠️ قد تجد طاولة خاطئة!
}

// بعد - طريقة مباشرة وآمنة
$oldTable = null;
if ($order->dining_table_id) {
    $oldTable = DiningTable::find($order->dining_table_id);
}
// نعتمد فقط على dining_table_id الذي تم التحقق منه مسبقاً
```

---

### 4. **عدم منع النقل لنفس الطاولة** ❌
**المشكلة:** الكود لم يتحقق مما إذا كان المستخدم يحاول النقل لنفس الطاولة، مما قد يسبب حلقات لا ضرورة لها.

**الإصلاح:** ✅
```php
// منع النقل لنفس الطاولة
if ($order->dining_table_id && $order->dining_table_id == $toTable->id) {
    \Log::warning('[ORDER TRANSFER] Same table transfer attempt', [
        'order_id' => $order->id,
        'table_id' => $toTable->id
    ]);
    return $this->error('لا يمكن النقل لنفس الطاولة.', 422);
}
```

---

### 5. **فقدان بيانات العملاء (customer_count)** ❌
**المشكلة:** إذا لم يتم العثور على الطاولة القديمة، كان الكود يستخدم:
```php
'customer_count' => $oldTable?->customer_count ?? $toTable->customer_count,
```
هذا يعني أنه سيستخدم عدد عملاء الطاولة **الجديدة** (غالباً 0) بدلاً من الحفاظ على عدد العملاء من الطلب.

**الإصلاح:** ✅
```php
// حفظ بيانات العملاء من الطلب أو الطاولة القديمة
$customerCount = $order->customer_count
    ?? $oldTable?->customer_count
    ?? $toTable->customer_count
    ?? 0;
$seatedAt = $oldTable?->seated_at
    ?? $toTable->seated_at
    ?? now();
```

الآن نعطي الأولوية لـ:
1. `order->customer_count` (إذا كان موجوداً في الطلب)
2. `oldTable->customer_count` (من الطاولة القديمة)
3. `toTable->customer_count` (من الطاولة الجديدة)
4. `0` (قيمة افتراضية)

---

### 6. **رسائل خطأ غير واضحة** ❌
**المشكلة:** بعض رسائل الخطأ كانت عامة وغير واضحة.

**الإصلاح:** ✅
```php
// قبل
return $this->error('الطاولة المستهدفة غير موجودة.', 404);

// بعد
return $this->error('الطاولة المستهدفة غير موجودة في هذا الفرع.', 404);

// قبل
return $this->error('لا يمكن النقل لهذه الطاولة مشغولة.', 422);

// بعد
return $this->error('لا يمكن النقل لهذه الطاولة - ليست متاحة.', 422);
```

---

### 7. **تحسين الـ Logging** ✅
**الإضافة:** إضافة المزيد من البيانات للـ logs لتسهيل التتبع والتصحيح:

```php
\Log::info('[ORDER TRANSFER] Transfer completed', [
    'order_id' => $order->id,
    'old_table_id' => $oldTable?->id,
    'old_table_number' => $oldTable?->table_number,
    'new_dining_table_id' => $toTable->id,
    'new_table_number' => $toTable->table_number,
    'customer_count' => $customerCount,
    'seated_at' => $seatedAt,
]);
```

---

### 8. **تحسين الاستجابة** ✅
**الإضافة:** إضافة علاقة `diningTable` للبيانات المرجعة:
```php
// قبل
new OrderResource($order->fresh()->load(['items.department']))

// بعد
new OrderResource($order->fresh()->load(['items.department', 'diningTable']))
```

---

## الخلاصة

### عدد المشاكل التي تم إصلاحها: **8 مشاكل رئيسية**

1. ✅ إضافة حالات `served` و `pending_payment` للقائمة المحظورة
2. ✅ التحقق من أن الطلب مرتبط بطاولة
3. ✅ إزالة منطق البحث الخطير عن الطاولة القديمة
4. ✅ منع النقل لنفس الطاولة
5. ✅ إصلاح فقدان بيانات العملاء
6. ✅ تحسين رسائل الخطأ
7. ✅ تحسين الـ logging والاستجابة
8. ✅ إضافة أعمدة `customer_count` و `seated_at` لجدول الطلبات لحفظ بيانات العملاء

### الملفات المعدلة:
- `app/Http/Controllers/Api/OrderController.php` - دالة `transfer()`
- `app/Models/Order.php` - إضافة `customer_count` و `seated_at` للـ fillable و casts
- `database/migrations/2026_09_28_000002_add_customer_data_to_orders_table.php` - migration جديدة

### الحالة: ✅ **تم الإصلاح بنجاح - جاهز للاستخدام**

## 📝 ملاحظات هامة:

### قبل الاستخدام:
```bash
php artisan migrate
```

### بعد النقل:
- ✅ الطاولة القديمة تصير فارغة تماماً (AVAILABLE)
- ✅ الطاولة الجديدة تصير مشغولة (OCCUPIED) مع كل البيانات
- ✅ الطلب ينتقل للطاولة الجديدة مع:
  - `dining_table_id` الجديد
  - `table_number` الجديد
  - `customer_count` (عدد الأشخاص)
  - `seated_at` (وقت التسكين)
