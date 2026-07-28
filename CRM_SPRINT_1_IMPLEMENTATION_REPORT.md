# CRM Sprint 1 Implementation Report

## النتيجة

تم تنفيذ أساس **Customer Identity & Security Foundation** في مشروعي Laravel وReact، مع إبقاء الحقول القديمة والبيانات المالية دون حذف أو إعادة كتابة. طُبقت المهاجرات الإضافية بنجاح على قاعدة التطوير.

## الحالة قبل التنفيذ

- الفرع في المشروعين: `posandhos`.
- Laravel: `12.56.0`، PHP: `8.2.12`.
- فحص TypeScript الأساسي نجح.
- اختبارات Backend الأساسية كانت متوقفة مبكراً بسبب أوامر MySQL غير المتوافقة مع SQLite، وسجلت `29 failed`, و`3 risky`, و`11 passed`.
- كانت مجموعة `/api/customers` خارج `auth:sanctum`.
- كان ملف migration إنشاء `orders` مؤرخاً بعد migrations تعتمد عليه، وهو سبب خطأ المفتاح الأجنبي في `customer_notes`.
- تغييرات المستخدم السابقة المحفوظة: تعديل migration شكاوى العملاء، وملف تحليل CRM غير المتتبع في Frontend.

## ما تم تنفيذه

### أمن الـ API

- نقل جميع مسارات إدارة العملاء والتقارير المالية الداخلية تحت `auth:sanctum`.
- إضافة صلاحيات CRM دقيقة للعرض والإنشاء والتعديل والحذف والبيانات المالية وكشف الحساب والتصدير والائتمان والملاحظات الحساسة.
- توزيع الصلاحيات افتراضياً على الأدوار الحالية المناسبة دون تغيير مسارات QR العامة.
- منع إظهار الملاحظات المصنفة `sensitive` لمن لا يملك صلاحيتها.
- إضافة allow-list للفرز وحد أقصى لحجم صفحات قائمة العملاء.

### هوية العميل والهاتف

- إضافة جدول `customer_phones` مع `normalized_phone` فريد، وهاتف أساسي، ونوع الهاتف، وحالة التحقق، وSoft Deletes.
- إضافة `PhoneNormalizer` موحد يدعم صيغ فلسطين المحلية و`+970` و`00970`، مع دعم E.164 العام.
- إضافة `CustomerIdentityService` موحد للإنشاء والتعديل والتحقق من التكرار ومزامنة الهواتف والعناوين الافتراضية داخل transactions.
- توجيه الإنشاء المالي وإنشاء الكول سنتر إلى الخدمة الموحدة.
- إبقاء `customers.phone` و`customers.mobile` للتوافق الرجعي.

### نموذج العميل والتكامل

- إضافة علاقات `phones`, `primaryPhone`, `addresses`, `address`, `notes`, `occasions`, `complaints`, `orders`, و`invoices`.
- إبقاء حساب `balance` متوافقاً، مع تحويل قائمة العملاء إلى استعلام aggregate واحد لتجنب N+1.
- التحقق من أن `customer_id` في الطلب والفاتورة يشير إلى عميل موجود وغير محذوف.
- أخذ snapshot لاسم وهاتف العميل عند إنشاء الطلب.
- منع إنشاء فاتورة لعميل يخالف عميل الطلب، مع توريث العميل من الطلب.

### Frontend

- إضافة عقد مركزي في `src/types/customer.ts`.
- جعل النوع المالي ونوع بحث الكول سنتر يرثان من عقد هوية مشترك مع الحفاظ على الحقول الخاصة بكل شاشة.

### استقرار الاختبارات والمهاجرات

- تقديم migration إنشاء `orders` زمنياً قبل الجداول التي تشير إليه.
- جعل migrations التي تستخدم قيود MySQL أو `MODIFY` متوافقة مع SQLite الخاص بالاختبارات.
- استكمال `username` في `UserFactory`.
- إضافة اختبارات أمن وهوية وتكرار الهاتف والعميل المحذوف.

## قاعدة البيانات

تم تشغيل:

```text
php artisan migrate --force
```

ونجحت:

```text
2026_07_27_100000_create_customer_phones_table
2026_07_27_100001_add_crm_customer_permissions
```

لم يتم تشغيل migrate:fresh أو rollback أو أي حذف للبيانات.

## نتائج التحقق

- PHP syntax: ناجح لكل الملفات المعدلة الأساسية.
- اختبارات CRM المحددة: `7 passed (20 assertions)`.
- TypeScript: `npx tsc --noEmit` ناجح.
- مجموعة Backend كاملة بعد الإصلاح: `41 passed`, و`3 risky`, و`4 failed`.
- الأعطال الأربعة المتبقية خارج نطاق CRM:
  - اختبار قديم يتوقع قص نسبة خصم 150% إلى 100%.
  - ثلاثة اختبارات محاسبة خصومات تعتمد على بيانات اختبار ناقصة (`production_tickets.department_id` ومستخدم `created_by`).
- تحسن خط الأساس من 29 اختباراً فاشلاً إلى 4 أعطال غير مرتبطة بهذه المرحلة.
- `npm run build` لم يبدأ بسبب منع بيئة التنفيذ قراءة مسار أب أعلى أثناء تحميل `vite.config.ts`.
- لا يوجد script باسم `lint` في `package.json`.

## الملفات الرئيسية

- `app/Services/CustomerIdentityService.php`
- `app/Services/Support/PhoneNormalizer.php`
- `app/Models/Customer.php`
- `app/Models/CustomerPhone.php`
- `app/Http/Requests/Api/StoreCustomerRequest.php`
- `app/Http/Requests/Api/UpdateCustomerRequest.php`
- `routes/api.php`
- `database/migrations/2026_07_27_100000_create_customer_phones_table.php`
- `database/migrations/2026_07_27_100001_add_crm_customer_permissions.php`
- `tests/Feature/CustomerIdentitySecurityTest.php`
- `src/types/customer.ts`

## ملاحظات انتقالية

- لم تُرحّل الهواتف التاريخية تلقائياً إلى `customer_phones` لتجنب دمج أو تعديل سجلات قديمة دون مراجعة. الخدمة تفحص الحقول القديمة عند منع التكرار، وكل إنشاء أو تعديل جديد يزامن الجدول الجديد.
- يُنصح في مرحلة منفصلة بعمل أداة profiling/read-only للهواتف القديمة، ثم backfill مراقب يعرض التعارضات للمراجعة البشرية.
- لم يتم تنفيذ Loyalty أو Customer Portal أو OTP أو segmentation أو أي وحدة خارج نطاق Sprint 1.
- لم يتم تنفيذ commit أو push.
