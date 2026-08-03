# PHASE 2B — Cashier Baseline handoff

## A. Baseline المعتمد

- بدأ العمل من نسختي `OrderController` و`InvoiceController` الموجودتين على القرص باعتبارهما Cashier Baseline النهائي، وليس من نسخة قديمة للمرحلة 2أ/2ب.
- مرساة سجل Git وقت التحليل: `e59f9a9 Fix call-center`، ثم `a00e1c5`، `f5ff754`، `008d060`، `5824751`. النسختان الأساسيتان كانتا تغييرات Working Tree فوق هذه المرساة، لذلك لا أدّعي أنهما مطابقتان بالكامل لملف داخل commit واحد.
- تم الحفاظ على: `table_number`، إنشاء وتحديث `is_takeaway`، حماية الأصناف المرسلة/المطبوعة، `skip_sync`، `is_printed_direct`، منع تكرار Ticket Items، نقل الطاولات وعدم تحرير طاولة فيها طلب نشط، ومسارات الطباعة المباشرة وطباعة الطلب والتذاكر.
- كشف التحليل عيبين في النسخة الأساسية وتم إصلاحهما ضمن النطاق: خطأ syntax في استعلام الفرع داخل `deferOrder()`، وعدم تطابق توقيع `createOrderItem()` مع تمرير `is_takeaway`.

## B. ما تم تغييره

| الملف | السبب | قبل | بعد | النطاق |
|---|---|---|---|---|
| `app/Http/Controllers/Api/OrderController.php` | دمج حقول الكول سنتر واستخراج التأكيد | إنشاء Tickets داخل Controller وحقول كول سنتر غير مكتملة | يخزن حقول CC وبيانات العميل الفعلية، ويفوض التأكيد والمزامنة إلى `OrderConfirmationService` مع حارس الدفع للكول سنتر | كلاهما |
| `app/Http/Controllers/Api/InvoiceController.php` | فصل Draft CC والحفاظ على تدفق POS | منطق Tickets ودفع مكرر داخل Controller | POS يطلق عبر الخدمة المشتركة؛ CC Draft يبقى pending/held؛ الدفع يفوض للخدمة مع منع الدفع المباشر لـCC | كلاهما |
| `app/Http/Requests/Api/StoreOrderRequest.php` | دعم baseline | لم يتحقق من `items.*.is_takeaway` | تحقق boolean | POS |
| `app/Http/Requests/Api/UpdateOrderRequest.php` | دعم baseline | لم يتحقق من `items.*.is_takeaway` | تحقق boolean | POS |
| `app/Http/Requests/Api/AddPaymentRequest.php` | عدم كسر وسائل الدفع القديمة | قائمة أضيق | يقبل `cash, card, credit_card, bank, wallet, app, account, mixed, customer, employee, supplier` | POS |
| `app/Services/Order/OrderConfirmationService.php` | توحيد منطق Tickets | منطق مكرر في Controllers | إطلاق transactional، تجميع حسب القسم، reuse، ومنع التكرار وتحديث الطاولة والحالة | كلاهما |
| `app/Services/Invoice/InvoicePaymentService.php` | توحيد الدفع والقيد | الدفع والقيد داخل Controller | دفع جزئي/كامل، إغلاق الفاتورة والقيد عند الاكتمال فقط، ولا يعدل order status | كلاهما |
| `app/Services/CallCenter/CallCenterOrderExecutionService.php` | تنفيذ المرحلتين المال/الإطلاق | لا orchestration آمن مكتمل | العمليات العامة الثلاث فقط، idempotency وخصم ذري ثم إطلاق مستقل | CC |
| `app/Models/IdempotencyRecord.php` و`app/Services/Support/IdempotencyService.php` | مطابقة التخزين مع التنفيذ | دعم غير مكتمل | hash، lock، financial_committed، result، conflict | CC |
| `app/Models/Order.php` و`app/Models/PaymentConfirmation.php` | حقول وعلاقات التنفيذ | غير متاحة بالكامل | casts/fillable وعلاقات التأكيد | CC |
| migrations الثلاثة المؤرخة `2026_08_02` | Schema المرحلة | لا حقول تنفيذ/تأكيد/idempotency | الحقول والجداول والفهارس المطلوبة | CC |
| اختبارات المرحلة المبينة في G | Regression وتدفق CC | تغطية غير كافية | تغطية POS baseline وDraft والدفع والتنفيذ | كلاهما |

### كود الخدمات

الكود الكامل المعتمد موجود في:

- `app/Services/Order/OrderConfirmationService.php`
- `app/Services/Invoice/InvoicePaymentService.php`
- `app/Services/CallCenter/CallCenterOrderExecutionService.php`
- `app/Services/Support/IdempotencyService.php`

لم تُنسخ الملفات هنا لتجنب وجود نسخة توثيقية قابلة للتقادم؛ هذه المسارات هي المصدر التنفيذي الكامل.

## C. حماية الكاشير

| الميزة | الاختبار | النتيجة |
|---|---|---|
| `is_takeaway` إنشاءً | `CashierBaselineRegressionTest::test_pos_store_defaults_source_and_persists_is_takeaway` | ناجح |
| printed items والتحديث دون حذف/تكرار | `test_updating_printed_item_changes_takeaway_without_deleting_or_duplicating_it` | ناجح |
| `is_printed_direct` ومنع ticket item مكرر | `test_pos_confirmation_marks_items_printed_and_does_not_duplicate_ticket_items` | ناجح |
| table transfer وحالة الطاولتين | `test_table_transfer_preserves_cashier_table_state_rules` | ناجح |
| POS confirm وProduction Tickets | الاختبار الثالث أعلاه و`InvoicePaymentExtractionRegressionTest` | ناجح |
| POS invoice والإطلاق التلقائي | `InvoiceControllerCallCenterDraftTest::test_pos_order_without_tickets_is_released_then_invoiced` | ناجح |
| partial payment | `InvoicePaymentExtractionRegressionTest::test_pos_partial_and_full_payment...` | ناجح |
| full payment | الاختبار نفسه | ناجح |
| journal entry عند الاكتمال فقط | الاختبار نفسه | ناجح |
| طرق الدفع القديمة | `test_all_legacy_pos_payment_method_strings_are_accepted` | ناجح |
| printing flows | بقيت دوال ومسارات الطباعة في Controller دون حذف؛ الاختبار يغطي علامة الطباعة ومنع التكرار، ولا يشغل أجهزة طباعة فعلية | محفوظة برمجيًا |

## D. دورة الكول سنتر

| الخطوة | حالات الطلب |
|---|---|
| Create order | `source=call_center`, `orders.status=pending` |
| Create Draft invoice | `orders.status=pending`, `kitchen_release_status=held`, ولا Production Tickets |
| Await payment | `payment_policy=manual_confirmation` و`payment_status=awaiting_confirmation`، أو تجهيز `instant_debit` |
| Financial Commit | دفع كامل فقط؛ `invoice.status=paid`, remaining `<= 0.001`, `payment_status=paid`, وسجل idempotency=`financial_committed`; يبقى order pending وkitchen held حتى الإطلاق |
| Kitchen Release | تستدعى `OrderConfirmationService::release()` بعد الـcommit المالي |
| Confirmed | `orders.status=confirmed`, `kitchen_release_status=released`, `kitchen_released_at/by` محفوظان، وProduction Tickets موجودة |

## E. Atomicity وIdempotency

- المعاملة المالية تضم إنشاء/قفل سجل idempotency، مقارنة `request_hash`، قفل الفاتورة والحساب/الكيان، التحقق بعد القفل، الخصم أو التأكيد، إنشاء Payment والقيد، تحديث `payment_status`، ثم تحديث idempotency إلى `financial_committed` قبل commit نفسه.
- إطلاق المطبخ يبدأ بعد الـcommit المالي في مرحلة مستقلة. إذا فشل، تبقى الدفعة والقيد محفوظين وتصبح `kitchen_release_status=release_failed`.
- إعادة نفس المفتاح ونفس payload تتجاوز المرحلة المالية بالكامل وتعيد محاولة الإطلاق فقط؛ لا Payment/Confirmation/خصم/قيد إضافي.
- اختلاف المبلغ أو المرجع أو payment method أو entity type/id يغير `request_hash` ويولد Idempotency Conflict.
- فريدة مرجع التحويل مركبة على `payment_method_id + normalized_reference_number`.

## F. نتائج الاختبارات

### الحزمة الكاملة

الأمر: `php artisan test --compact`

```text
Tests: 6 failed, 3 risky, 91 passed (318 assertions)
Duration: 5.13s
```

الإخفاقات الستة الظاهرة خارج ملفات المرحلة ومساراتها:

1. اختبار `DiscountEngine`: expected 100, got 150.
2. اختبارا `CrmCustomerDirectoryTest`: استجابة 403 بدل 200/201 بسبب الصلاحيات.
3. اختباران في `DiscountAccountingTest`: `production_tickets.department_id` فارغ ويخالف NOT NULL.
4. اختبار `DiscountAccountingTest` آخر: FK لـ`discounts.created_by`.

كما ظهر تحذير عدم القدرة على كتابة `.phpunit.result.cache` بسبب صلاحية المسار، و3 اختبارات risky بلا assertions. لذلك لا يتم الادعاء بأن الحزمة الكاملة ناجحة.

### الاختبارات المستهدفة

الأمر:

```text
php artisan test tests\Feature\CashierBaselineRegressionTest.php tests\Feature\InvoiceControllerCallCenterDraftTest.php tests\Feature\InvoicePaymentExtractionRegressionTest.php tests\Feature\CallCenterOrderExecutionServiceTest.php tests\Feature\CallCenterOrderTest.php tests\Feature\CallCenterPaymentExecutionSchemaTest.php tests\Feature\CallCenterPaymentPlanTest.php
```

النتيجة:

```text
31 passed (144 assertions)
Duration: 5.42s
```

## G. الملفات المتأثرة

### ملفات أعيد تنفيذ/تعديل منطق المرحلة فيها

1. `app/Http/Controllers/Api/OrderController.php`
2. `app/Http/Controllers/Api/InvoiceController.php`
3. `app/Http/Requests/Api/AddPaymentRequest.php`
4. `app/Http/Requests/Api/StoreOrderRequest.php`
5. `app/Http/Requests/Api/UpdateOrderRequest.php`
6. `app/Models/IdempotencyRecord.php`
7. `app/Models/Order.php`
8. `app/Models/PaymentConfirmation.php`
9. `app/Services/Support/IdempotencyService.php`
10. `app/Services/CallCenter/CallCenterOrderExecutionService.php`
11. `app/Services/Invoice/InvoicePaymentService.php`
12. `app/Services/Order/OrderConfirmationService.php`
13. `database/migrations/2026_08_02_000001_add_call_center_payment_execution_fields_to_orders_table.php`
14. `database/migrations/2026_08_02_000002_create_payment_confirmations_table.php`
15. `database/migrations/2026_08_02_000003_create_idempotency_records_table.php`
16. `tests/Feature/CallCenterOrderExecutionServiceTest.php`
17. `tests/Feature/CallCenterPaymentExecutionSchemaTest.php`
18. `tests/Feature/CallCenterOrderTest.php`
19. `tests/Feature/CashierBaselineRegressionTest.php`
20. `tests/Feature/InvoiceControllerCallCenterDraftTest.php`
21. `tests/Feature/InvoicePaymentExtractionRegressionTest.php`
22. `PHASE_2B_CLAUDE_HANDOFF.md`

توجد تغييرات Working Tree أخرى سابقة (مثل employee breaks وCRM وcomposer و`routes/api.php`) لم تُنشأ ولم تُنسب إلى إعادة تنفيذ 2ب هذه. لم يُعدّل Route أو Frontend في هذه المهمة، ولم يُنشأ Endpoint.

## H. نقاط تحتاج مراجعة Claude

1. Cashier Baseline كان نسخة Working Tree مستبدلة فوق `e59f9a9` وليس commit نظيفًا منفردًا؛ يلزم تثبيت commit لها قبل الدمج النهائي لتحسين traceability.
2. الحزمة الكاملة فيها 6 إخفاقات خارج المرحلة موثقة في F؛ يجب إصلاحها أو إثبات baseline سابق لها قبل اعتماد CI كليًا.
3. اختبارات الطباعة تحمي flags ومنع التكرار وتبقي control flow، لكنها لا تتصل بطابعة فعلية؛ يلزم smoke test على بيئة الأجهزة.
4. `saveOrderAwaitingBankConfirmation(array $paymentMethodData)` يحفظ حالات الخطة المعتمدة فقط؛ لا يوجد حقل schema عام لحفظ كامل payload، لذلك لم يُنشأ تخزين زائد غير معتمد.

## تأكيد النطاق

- لم تُنشأ Routes أو Endpoints.
- لم يُعدّل أي Frontend.
- لا يوجد COD أو `cash_on_delivery` في التنفيذ الجديد.
- تم التوقف عند المرحلة 2ب، ولم تبدأ 2ج.

## إغلاق مراجعة 2ب — الإصلاحات الإلزامية

- تم ربط خصم `customer/employee/supplier` بالـID الموجود فعليًا على الطلب، مع رفض 422 قبل إنشاء Idempotency أو أي أثر مالي.
- أصبحت مرحلة الإطلاق Transaction مستقلة كاملة: قفل الطلب، التذاكر، metadata والحالة. الفشل يعمل rollback للتذاكر والحالة ثم يسجل `release_failed` في Transaction لاحقة، مع Log سياقي كامل.
- أصبحت الأصناف بلا `department_id` مرفوضة 422 قبل إنشاء أي تذكرة.
- أصبح جلب وقفل الطلب والأصناف داخل Transaction، وأضيف unique index على `production_ticket_items.order_item_id` في migration لاحقة لجميع migrations المنشئة للجدول.
- حُصرت العمليات العامة الثلاث بطلبات `call_center`، ومنعت الحالات النهائية من الرجوع للانتظار، مع السماح بإعادة محاولة `release_failed` بالمفتاح نفسه.
- وُحّد `IdempotencyService::execute()` مع canonical recursive hash و`ConflictHttpException` ودورة `pending/completed` وحقول resource؛ لا يعاد سجل pending كنتيجة ناجحة. البحث أثبت عدم وجود مستدعٍ حاليًا لـ`execute()` خارج تعريفه، ولذلك تم تحصينه دون حذفه.
- استثناء مبلغ `InvoicePaymentService` أصبح `UnprocessableEntityHttpException` بدل `RuntimeException`.

### نتيجة الإغلاق المستهدفة

```text
43 passed (184 assertions)
Duration: 2.91s
```

### نتيجة الحزمة الكاملة بعد الإغلاق

```text
6 failed, 3 risky, 103 passed (358 assertions)
Duration: 4.66s
```

فشل `production_tickets.department_id` كان موجودًا قبل هذه الإصلاحات وما زال يظهر في اختبارين قديمين من `DiscountAccountingTest` لأن الاختبارين ينشئان `ProductionTicket` مباشرة بقيمة `department_id=null` ولا يمران عبر `OrderConfirmationService`. أما مسار الخدمة الجديد فيرفض الصنف بلا قسم قبل إنشاء التذكرة، وقد ثبت ذلك في `test_order_with_item_without_department_is_not_confirmed`.

### ملفات إغلاق المراجعة

1. `app/Services/CallCenter/CallCenterOrderExecutionService.php`
2. `app/Services/Order/OrderConfirmationService.php`
3. `app/Services/Invoice/InvoicePaymentService.php`
4. `app/Services/Support/IdempotencyService.php`
5. `database/migrations/2027_01_01_000001_add_unique_order_item_to_production_ticket_items.php`
6. `tests/Feature/CallCenterOrderExecutionServiceTest.php`
7. `tests/Feature/CallCenterPaymentExecutionSchemaTest.php`
8. `tests/Feature/IdempotencyServiceConsistencyTest.php`
9. `PHASE_2B_CLAUDE_HANDOFF.md`

لم تُلمس Routes أو ملفات Frontend في إغلاق المراجعة، ولم تبدأ المرحلة 2ج.
