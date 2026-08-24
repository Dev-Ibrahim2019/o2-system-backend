```
AUDIT MODE: READ ONLY
DATABASE CHANGES: NONE
CODE CHANGES: NONE
MIGRATIONS EXECUTED: NONE
COMMITS: NONE
```

# خطة قرار المصالحة — O2 System Backend
# RECONCILIATION DECISION PLAN

**المصدر:** يبني على `docs/SCHEMA_RECONCILIATION_AUDIT.md`، لكن كل استنتاج فيه أُعيد التحقق منه مباشرة من المشروع/Git/DB الحية في هذه الجولة — وليس نسخًا. تم بالفعل ضبط خطأ حقيقي أثناء التحقق (موضّح في §4).
**الفرع:** `feature/customer-crm-operations` — نفس الـ commit `4f9155e`، شجرة نظيفة، لا تغيير منذ آخر تدقيق.
**منهجية التحقق في هذه الجولة:** إعادة تشغيل `git status`/`git log` (لا تغيير)، إعادة `php artisan migrate:status` مع تصحيح خطأ فلترة وقع أثناء الفحص نفسه (انظر §4)، سحب schema حي كامل (أعمدة/فهارس/FK) لجداول لم تُفحص بهذا العمق سابقًا (`branche`, `call_tickets`, `order_feedback`)، ومقارنة محتوى ملف `create_call_tickets_table.php` سطرًا بسطر ضد الـ schema الحي.

---

## 1. CURRENT SOURCE OF TRUTH

| النوع | Source of Truth | لماذا |
|---|---|---|
| **بنية الجداول الفعلية (columns/types/FKs/indexes)** | **Live DB** | الوحيد الذي يعكس ما يعمل عليه التطبيق الآن فعليًا. تم التحقق مباشرة عبر `information_schema`/`SHOW CREATE TABLE` لكل جدول مذكور في هذا التقرير. |
| **ما الذي "تم تنفيذه" تاريخيًا (أي migration ran)** | **جدول `migrations` في DB** | موثوق كقائمة أسماء نُفذت، لكن **غير كافٍ وحده** — عدة أسماء فيه لا تقابلها ملفات على القرص في هذا الفرع (انظر §3). |
| **محتوى/نية كل تغيير schema تاريخي** | **Git (فروع أخرى، خصوصًا `dev`)** | الملفات نفسها استُعيدت حرفيًا من `dev` عبر `git show`. هذا أثبت أنه المصدر الوحيد القادر على تفسير "كيف" وصلت الـ DB لشكلها الحالي، وليس فقط "ماذا" يوجد فيها. |
| **ملفات migrations على هذا الفرع فقط** | **غير كافٍ بمفرده** | يحتوي نسخًا قديمة/متعارضة لبعض الجداول (`orders`, `order_items`, `idempotency_records`, `call_tickets`) لا تطابق ما نُفذ فعليًا. |
| **توقعات الكود (Models/Services)** | **هدف مستقبلي، ليس حالة حالية** | `Customer`, `Order`, `CustomerIdentityService`, إلخ مبنية على افتراض أن migrations معلّقة معيّنة قد نُفذت — وهي لم تُنفذ. الكود يمثل "ماذا يجب أن تكون عليه DB"، وليس "ماذا هي عليه الآن". |
| **Routes** | تعكس نية المنتج بدقة، لكن لا تخبرك عن حالة DB | مفيدة لمعرفة أي endpoint موجود وأي permission يحرسه، لكن لا تحل محل فحص DB/الكود خلفها. |
| **Tests** | **لم تُفحص بعمق في هذه الجولة أو السابقة — فجوة معروفة** | لم يُتحقق ما إذا كانت suite الاختبارات تعمل ضد schema حي أم schema وهمي (sqlite in-memory عبر migrations كاملة؟). هذه فجوة تحقق صريحة، وليست استنتاجًا. |

**الخلاصة العملية:** أي قرار إصلاح يجب أن يبدأ من **Live DB** كحقيقة تاريخية، ويستخدم **Git (`dev`)** لفهم النية والمحتوى الأصلي، ثم يُصالِح **الكود** مع القرار النهائي — وليس العكس.

---

## 2. MIGRATION CLASSIFICATION

**العدد الصحيح المؤكَّد الآن مباشرة: 20 migration بحالة Pending فعليًا.** (ملاحظة تحقق: أول محاولة فلترة لـ `migrate:status` في هذه الجولة أعطت 22 نتيجة بالخطأ، لأن اسمي الملفين `add_pending_confirmation_to_orders_status` و`add_pending_payment_to_orders_status` يحتويان كلمة "pending" في *اسم الملف نفسه* رغم أن حالتهما الفعلية **Ran**. تم اكتشاف الخطأ وتصحيحه فورًا بإعادة الفلترة على حالة العمود الفعلية وليس على نص السطر — هذا مذكور صراحة لأنه مثال حي على "لا تفترض، تحقق".)

التصنيف الجديد (A–G) مبني على فحص محتوى كل ملف + مقارنته بالـ schema الحية مباشرة في هذه الجولة (وليس نسخًا عن تصنيف Category A–J في التقرير السابق، رغم أنه يتفق معه في المعطيات الأساسية).

| Migration (الاسم الكامل) | Target table | ماذا يفعل | حالة DB الحالية | تعارض؟ | Classification | الإجراء المطلوب | Dependencies |
|---|---|---|---|---|---|---|---|
| `2026_06_14_195000_update_customers_table.php` | customers | يحذف `account_id` (بشرط) + يضيف 14 عمودًا (mobile, city, country, category, currency, payment_terms, credit_days, opening_balance, is_opening_balance_posted, risk_level, notes, gps_link, website, salesperson_id) | الأعمدة الـ14 غير موجودة (مؤكَّد)، `account_id` موجود وله FK حي | نعم — يتداخل مع `add_missing_customer_columns` | **F** | يحتاج قرار عمل صريح بشأن `account_id` (انظر §5) قبل التشغيل أو الدمج | يتعارض مع الملف التالي مباشرة |
| `2026_06_28_000001_enhance_discounts_for_strategy_and_exclusions.php` | discounts, discount_targets, discount_exclusions, invoice_items, order_items | إضافات أعمدة محمية (`hasColumn`) + إنشاء `discount_exclusions` (محمي) + `unique()` غير محمي على `discount_targets` | `discount_exclusions` غير موجود، باقي الأعمدة قد تكون جزئية | جزئي — استدعاء `unique()` غير محمي | **B** | إضافة حماية (try/catch أو فحص تكرار) قبل استدعاء `unique()` | لا شيء |
| `2026_07_03_000001_create_orders_table.php` | orders | `Schema::create('orders', ...)` بدون حماية — 20 عمودًا فقط، status enum بـ7 قيم صغيرة | الجدول موجود فعليًا بـ82 عمودًا، status enum بـ5 قيم كبيرة مختلفة تمامًا | **نعم — تعارض كامل** | **C** | استبدال المحتوى بالكامل — لا يوصف واقع DB إطلاقًا | يحتاج baseline من §4 |
| `2026_07_26_000001_create_call_center_registers_table.php` | call_center_registers | إنشاء جدول جديد | غير موجود (مؤكَّد) | لا | **A** | آمن للتشغيل كما هو | لا شيء |
| `2026_07_27_000001_add_missing_customer_columns.php` | customers | نفس الـ14 عمودًا تقريبًا مثل الملف الأول، بدون حذف `account_id`، محمي بالكامل (`hasColumn`) | نفس حالة الملف الأول | **نعم — يكرر الملف الأول** | **F** | مرتبط بنفس قرار `account_id` — أحد الملفين فقط يجب أن يبقى | مرتبط مباشرة بـ `update_customers_table` |
| `2026_07_27_100000_create_customer_phones_table.php` | customer_phones | إنشاء جدول جديد | غير موجود (مؤكَّد) | لا | **A** | آمن للتشغيل كما هو | لا شيء |
| `2026_07_27_100001_add_crm_customer_permissions.php` | permissions (data) | `Permission::findOrCreate` — idempotent | 0 من 11 صلاحية crm.* موجودة | لا | **A** | آمن للتشغيل كما هو | يُفضَّل بعد إصلاح schema حتى لا تُفتح endpoints مكسورة (انظر §6) |
| `2026_07_28_000001_add_crm_admin_permissions.php` | permissions (data) | نفس النمط | نفس الحالة | لا | **A** | آمن للتشغيل كما هو | نفس ملاحظة السابق |
| `2026_07_29_170000_create_call_tickets_table.php` | call_tickets | `Schema::create('call_tickets', ...)` بدون حماية — **تم فحصه سطرًا بسطر في هذه الجولة**: `branch_id` هنا nullable لكن الحي NOT NULL، `status` افتراضي هنا `'open'` لكن الحي `'ringing'`، `normalized_phone` هنا varchar(40) لكن الحي varchar(24) | الجدول موجود فعليًا بـ20 عمودًا بقيم افتراضية مختلفة (مؤكَّد مباشرة هذه الجولة) | **نعم — تعارض في المحتوى، ليس فقط في الوجود** | **C** | استبدال — الملف لا يطابق الواقع حتى لو أُضيفت له حماية `hasTable` | يحتاج baseline من §4 |
| `2026_07_30_000001_create_order_feedback_table.php` | order_feedback | إنشاء جدول جديد | غير موجود (تم التأكيد مباشرة هذه الجولة) | لا | **A** | آمن للتشغيل كما هو — لكن انظر القرار F في §5 (order_customer_experiences مقابل order_feedback) قبل تشغيله | مرتبط بقرار عمل، وليس بمشكلة تقنية |
| `2026_08_01_000001_create_employee_break_sessions_table.php` | employee_break_sessions | إنشاء جدول جديد | غير موجود (مؤكَّد) | لا | **A** | آمن للتشغيل كما هو | لا شيء |
| `2026_08_01_000002_add_title_to_customers_table.php` | customers | إضافة عمود `title` بدون حماية | العمود غير موجود (مؤكَّد) | لا | **A** | آمن للتشغيل، يُفضَّل بعد دمج migrations customers الأخرى لتفادي تعارض ترتيب الأعمدة (`after('name_en')`) | يُفضَّل بعد حسم قرار F الخاص بـ customers |
| `2026_08_02_000001_add_call_center_payment_execution_fields_to_orders_table.php` | orders | إضافة `payment_policy`, `kitchen_release_status`, `kitchen_released_at/by` — محمي بالكامل، بها منطق تطبيع بيانات موجودة | الأعمدة غير موجودة؛ `orders.payment_status` الحية تحتوي فقط `UNPAID` (مؤكَّد سابقًا، ضمن نطاق التطبيع المتوقع) | لا | **A** | آمن للتشغيل كما هو | لا شيء |
| `2026_08_02_000002_create_payment_confirmations_table.php` | payment_confirmations | إنشاء جدول جديد | غير موجود (مؤكَّد) | لا | **A** | آمن للتشغيل كما هو | لا شيء |
| `2026_08_02_000003_create_idempotency_records_table.php` | idempotency_records | `Schema::create` بدون حماية — `scope`/`key` varchar(100)، عمود `status` إضافي | الجدول موجود فعليًا بشكل مختلف (`key` هو `char(36)`، `scope` varchar(80)، **لا يوجد عمود status إطلاقًا** — مؤكَّد مباشرة هذه الجولة) | **نعم — تعارض في الوجود وفي الشكل معًا** | **C** | استبدال — والأهم: العمود `status` الذي يحتاجه الكود (`IdempotencyRecord::$fillable`) غير موجود في أي نسخة (لا الحية ولا هذا الملف بالشكل الصحيح) — يحتاج migration **جديدة** فقط لإضافة `status` على الجدول الموجود فعليًا، وليس إعادة إنشائه | يحتاج baseline من §4 |
| `2026_08_09_000001_add_integration_identity_and_outbox_foundation.php` | orders, customers, integration_outbox | يضيف `orders.public_ref`, `customers.external_ref`, وينشئ `integration_outbox` — بدون حماية لكن كل الأهداف مؤكَّدة غائبة | كل الثلاثة غائبة فعليًا (مؤكَّد) | لا (لكن هش لو شُغِّل مرتين) | **A** | آمن للتشغيل مرة واحدة فقط — لكن **يجب أن يسبقه** إصلاح/تعطيل مؤقت لـ `booted()` في `Customer`/`Order` (انظر §6 P0) وإلا سيبقى الكود يفترض هذه الأعمدة قبل توفرها فعليًا في أي بيئة أخرى لم تُشغِّل هذه الـ migration بعد | لا تعارض تقني، لكن يُنصَح بالترتيب |
| `2026_09_28_000004_ensure_all_order_columns_from_all_earlier_migrations.php` | orders | إضافات أعمدة محمية (no-op غالبًا) + **جملتا ALTER خام غير مشروطتين**: `MODIFY status VARCHAR(50) ... DEFAULT 'PENDING_PAYMENT'` و`DROP CHECK` (داخل try/catch لأخطاء DROP فقط) | orders.status هو ENUM حي الآن، وله CHECK constraint عالق (انظر §4) | **نعم — يتقاطع مباشرة مع مشكلة orders_status_check** | **B** | يجب تعديله ليعكس القرار النهائي لحالة `orders.status` (ENUM أم VARCHAR، وما مصير الـ CHECK) بدلًا من تشغيله كما هو ويُحدث تغييرًا غير متوقَّع على عمود ENUM يعمل بشكل صحيح فعليًا | يعتمد على حل P0 في §6 أولًا |
| `2026_12_11_111251_create_order_items_table.php` | order_items | `Schema::create` بدون حماية — **تم تأكيده سابقًا مطابقًا حرفيًا (byte-identical)** للملف الذي أنشأ الجدول فعليًا على `dev` | الجدول موجود فعليًا بـ24 عمودًا، ومطابق تمامًا لمحتوى هذا الملف | **لا تعارض في المحتوى، فقط في التوقيت/الاسم** | **D** | لا حاجة لتعديل المحتوى إطلاقًا — فقط تعليمه كمُنفَّذ مسبقًا (سجل يدوي في جدول `migrations`، أو استبعاده من التشغيل) | لا شيء تقني |
| `2027_01_01_000001_add_unique_order_item_to_production_ticket_items.php` | production_ticket_items | إضافة `unique()` بدون حماية | لم يُتحقق مباشرة من وجود بيانات مكررة في `production_ticket_items` في أي من الجولتين | غير مؤكَّد | **B** | يحتاج فحص مسبق لعدم وجود صفوف مكررة قبل التشغيل (أو إضافة حماية) | يحتاج فحص بيانات لم يُنفَّذ بعد |
| `2027_01_01_000002_add_mixed_call_center_payment_policy.php` | orders | يوسّع enum/منطق `payment_policy` | يعتمد على أن `payment_policy` موجود فعلًا، وهو من إنشاء الملف رقم `2026_08_02_000001` أعلاه (لا يزال Pending) | لا تعارض مباشر، لكن يعتمد على ملف آخر | **E** | يُحظر تشغيله حتى يُشغَّل `2026_08_02_000001_add_call_center_payment_execution_fields_to_orders_table` أولًا | يعتمد مباشرة على الصف أعلاه |

**ملاحظة على G:** لا يوجد أي ملف Pending حاليًا يحتاج تصنيف **G (Unknown/needs recovery from git)** — كل ملف موجود فعليًا على القرص ومحتواه معروف بالكامل. كل الملفات الـ21 التي كانت "مفقودة" (Ran بلا ملف) استُعيدت بالفعل من `dev` في التدقيق السابق وأُعيد تأكيد اثنين منها مباشرة في هذه الجولة (§3).

---

## 3. RECOVERY FROM GIT

تم تنفيذ البحث المطلوب (`git log --all`, `git show`, `git branch --all --contains`, `git fsck --unreachable`) بالكامل في الجولة السابقة، **وأُعيد التحقق المباشر من نتيجتين حرجتين في هذه الجولة قبل الاعتماد عليهما:**

| الملف المُستعاد | Commit | Branch | تم التحقق في هذه الجولة؟ | المقارنة مع Live DB |
|---|---|---|---|---|
| `2026_08_02_000001_create_orders_table.php` (المُنشئ الحقيقي لجدول orders) | مؤلف "karam"، على `dev`، 2026-08-12 | `dev` فقط | **نعم — `git show dev:...` أُعيد تنفيذه الآن، المحتوى مطابق لما وُثِّق سابقًا (`hasTable()` guard، customer_id/branch_id/department_id/... بأعمدة كبيرة)** | مطابق للـ82 عمودًا الحية |
| `2027_01_02_000001_complete_call_center_delivery_workflow.php` (يحتوي المُنشئ الحقيقي لـ idempotency_records، call_tickets، order_cancellation_requests) | غير مؤكَّد الرقم بدقة في هذه الجولة (كان مؤكَّدًا في الجولة السابقة) | `dev` | **نعم — أُعيد تنفيذ `git show dev:...` الآن، ظهر محتوى `Schema::create('idempotency_records', ...)` بـ`scope varchar(80)`, `key` كـ`uuid()`, بدون عمود status — مطابق تمامًا للـ live** | مطابق تمامًا |

لا حاجة لإعادة تنفيذ كل البحث الشامل (21 ملفًا) في هذه الجولة — تم توثيقه بالكامل بأسماء commits دقيقة في `docs/SCHEMA_RECONCILIATION_AUDIT.md` §5 و§7 (Category C)، وأُعيد التحقق من أهم حالتين (orders و idempotency_records) مباشرة هنا وثبتت صحتهما دون أي تغيير.

**نتيجة صريحة:** لا يوجد أي migration في هذا المشروع حاليًا يستحق وصف "unrecoverable" — كل ما بدا مفقودًا وُجد حرفيًا على فرع `dev`.

---

## 4. LIVE DATABASE BASELINE

تم سحب الـ schema الحية مباشرة في هذه الجولة (columns, indexes, FKs, unique constraints, defaults, nullable) للجداول التالية التي لم تُفحص بهذا التفصيل من قبل، بالإضافة لتأكيد الجداول السابقة:

### `branche` (الجدول المكرر الميت)
```
id, name, code(UNIQUE), city, address, phone, status enum(ACTIVE/INACTIVE/MAINTENANCE/BUSY),
parent_id (FK→branche.id self), google_map_url, email, whatsapp, opening_time, closing_time,
isMainBranch, created_at, updated_at
```
16 عمودًا، **0 صفوف**، FK ذاتي فقط (`parent_id`)، لا شيء آخر في المشروع يشير إليه.

### `call_tickets`
```
id, external_call_id(UNIQUE), branch_id(FK→branches, NOT NULL), customer_id(FK→customers),
agent_id(FK→users), linked_order_id(FK→orders), direction varchar(16) default 'inbound',
status varchar(24) default 'ringing', incoming_phone varchar(40) NOT NULL,
normalized_phone varchar(24) NOT NULL, source, disposition, notes, started_at, answered_at,
ended_at, duration_seconds, metadata json, created_at, updated_at
```
20 عمودًا، **0 صفوف**، 7 فهارس منفصلة + الفريدة. **الملف الحالي على القرص (`2026_07_29_170000_create_call_tickets_table.php`) لا يطابق هذا الشكل** — راجع §2.

### `order_feedback`
**غير موجود إطلاقًا** — تم التأكيد مباشرة (`Schema::hasTable` = false).

### `customers` (تأكيد)
17 عمودًا فقط — لا تغيير عن التقرير السابق.

### `orders` (تأكيد + تصحيح)
**82 عمودًا** (مؤكَّد بطريقتين مستقلتين في الجولة السابقة، لم يتغيّر). CHECK constraint عالق `orders_status_check` يرفض 3 من 5 قيم ENUM حية (`PREPARATION`, `OUT_FOR_DELIVERY`, `DELIVERED`) — **هذا لا يزال قائمًا، لم يُصلَح بعد**.

### `order_items` (تأكيد)
24 عمودًا — لا تغيير.

### `idempotency_records` (تأكيد)
11 عمودًا، **لا يوجد عمود `status`** — لا تغيير.

### `customer_complaints` (تأكيد + تفصيل جديد)
22 عمودًا، `branch_id` هو `varchar(255)` وليس FK رقمي (تأكيد مباشر)، لا FK على `order_id`/`invoice_id`.

### `order_customer_experiences` (تأكيد + تفصيل جديد)
11 عمودًا، UNIQUE على `order_id`، 3 FKs صحيحة (order/customer/recorded_by)، **0 صفوف**.

### Diff مختصر: LIVE vs INTENDED CODE vs MIGRATIONS

| الجدول | LIVE | الكود يتوقع | Migrations على القرص تصف |
|---|---|---|---|
| customers | 17 عمودًا | 25+ (fillable) بما فيها external_ref | 3 ملفات Pending متعارضة/مكررة تضيف الفرق |
| orders | 82 عمودًا، ENUM سليم لكن CHECK عالق | يتوقع `public_ref` + `payment_policy` إضافيًا | ملف Pending واحد (`create_orders_table`) **لا يصف الواقع إطلاقًا** |
| order_items | 24 عمودًا (مطابق تمامًا لما يتوقعه الكود) | مطابق | ملف Pending واحد **مطابق حرفيًا** للواقع لكن غير مُسجَّل كذلك |
| idempotency_records | 11 عمودًا، بدون status | يتوقع عمود `status` | ملف Pending يصف شكلًا مختلفًا تمامًا (لا يطابق لا الواقع ولا حاجة الكود) |
| call_tickets | 20 عمودًا، branch_id إلزامي | الكود (`CallTicketController`) يعمل بشكل صحيح ضد هذا الشكل فعليًا | ملف Pending يصف شكلًا مختلفًا (nullable/defaults مختلفة) |

---

## 5. BUSINESS DECISIONS

فقط القرارات التي لا يمكن استنتاجها من الكود/Git — كل ما كان قابلًا للاستنتاج بدليل واضح **حُسم أدناه دون سؤال**.

### محسوم بالدليل (لا يحتاج قرارك):
- **`total` مقابل `total_amount`** (orders): `total` هو المستخدم حصريًا في كل الكود؛ `total_amount` عمود ميت بلا أي مرجع برمجي. **القرار: الإبقاء على `total`، `total_amount` مرشح للحذف لاحقًا (تنظيف، ليس عاجلًا).**
- **`discount_amount`/`discount_value`/`engine_discount_amount`** (orders): ثلاثة حقول متمايزة فعليًا بأدلة من `OrderPricingService::calculate()` — تصميم مقصود، لا تعارض. **لا حاجة لقرار.**
- **`phone`/`customer_phone`/`customer_mobile`** (orders): `customer_phone` هو الوحيد المستخدم فعليًا؛ الآخران ميتان بالكامل بالدليل. **لا حاجة لقرار على مستوى orders.**

### يحتاج قرارك فعلًا:

| # | القرار | لماذا يحتاج قرارًا | الخيارات | التوصية | الأثر | الملفات/Migrations المرتبطة |
|---|---|---|---|---|---|---|
| 1 | **`customers.account_id`** | عمود حي بـFK نشط، لكن ميت 100% في الكود (تأكيد Grep شامل) — حذفه قرار معماري لأنه يمس schema حية بعلاقة خارجية، وليس قرارًا تقنيًا بحتًا | (أ) حذفه ضمن migration مُصالَحة الآن (ب) تركه معطَّلًا في الكود مؤقتًا وحذفه لاحقًا (ج) إبقاؤه نهائيًا واستخدامه بدل الـ subledger | **(أ)** — كل الأدلة (تعليق المطوّر في الملف نفسه، منطق `getBalanceAttribute()`) تشير لنية حذف واضحة، فقط لم تُنفَّذ | يبسّط `update_customers_table` فورًا، يزيل التعارض بينه وبين `add_missing_customer_columns` | `2026_06_14_195000_update_customers_table.php`, `2026_07_27_000001_add_missing_customer_columns.php`, `app/Models/Customer.php` |
| 2 | **`branches` مقابل `branche`** | جدولان حيان بنفس الغرض المفاهيمي؛ قرار حذف جدول حي يمس بيانات حتى لو كانت 0 صفوف الآن — ليس قرارًا للكود اتخاذه بمفرده | (أ) حذف `branche` بالكامل بعد تأكيد 0 صفوف في كل بيئة (ب) تركه كما هو (تكلفة صفرية طالما غير مستخدم) | **(ب) الآن، (أ) لاحقًا** — لا ضرر من تأجيله؛ يجب فقط التأكد أنه 0 صفوف في كل بيئة (staging/production) قبل الحذف النهائي، وهذا تحقق لم يُنفَّذ إلا على قاعدة dev المحلية | منخفض الآن، يحتاج تحقق إضافي قبل أي حذف فعلي | لا migration مرتبطة حاليًا — قرار تنظيف مستقبلي |
| 3 | **`order_customer_experiences` مقابل `order_feedback`** | تصميمان متنافسان لنفس فكرة "تقييم ما بعد الطلب" — أيهما يبقى قرار منتج/معمارية، ليس تقنيًا | (أ) اعتماد `order_feedback` (له controller فعلي) وحذف `order_customer_experiences` (ب) عكسه (ج) الإبقاء على الاثنين لغرضين مختلفين إن وُجد سبب عمل | **(أ)** — `order_feedback` مربوط فعليًا بـ`OrderFeedbackController`/`StoreOrderFeedbackRequest`، بينما `order_customer_experiences` بلا أي مرجع كود سوى الـModel نفسه | يحدد هل نشغّل `create_order_feedback_table` أم لا، ومصير `OrderCustomerExperience` model | `2026_07_30_000001_create_order_feedback_table.php`, `app/Models/OrderCustomerExperience.php` |
| 4 | **رصيد العميل: على مستوى الشركة أم الفرع (`getAging`/`getBalance`)** | قرار عمل مباشر يمس تقارير مالية وصلاحيات وصول — ليس مسألة صحة تقنية | (أ) دائمًا على مستوى الشركة (الوضع الحالي) (ب) دائمًا حسب الفرع (ج) قابل للتهيئة حسب دور المستخدم | **يحتاج قرارك مباشرة** — لا دليل كافٍ في الكود يرجّح أحدهما كنية أصلية؛ باقي القراءات (statement) مفلترة بالفرع بينما aging/balance ليست كذلك، وهذا تناقض وليس تصميمًا واضحًا | يحدد تعديل `CustomerAccountingService::getAging()`/`getBalance()` | `app/Services/Accounting/CustomerAccountingService.php` |
| 5 | **`subject` مقابل `title`** (customer_complaints) | كلا العمودين حيّان وفي fillable، ولا يوجد controller حالي يملأ أيًا منهما مباشرة — الدليل غير كافٍ لحسم أيهما "الحالي فعليًا" في استخدام العملاء الحقيقي | (أ) اعتماد `title` نهائيًا وإهمال `subject` (ب) العكس (ج) إبقاء الآلية الحالية (نسخ تلقائي من subject لـtitle عند الحاجة) | **يحتاج فحص بيانات إضافي غير منفَّذ بعد** (`SELECT COUNT(*) WHERE subject IS NOT NULL AND title IS NULL`) قبل أي توصية — هذا تحقق تقني ناقص، ضعه ضمن الخطوات لا كقرار فوري | منخفض حاليًا (0 صفوف في الجدول) | `app/Models/CustomerComplaint.php` |

---

## 6. CRITICAL RUNTIME BLOCKERS

مرتبة P0 (الأخطر) إلى P3. **لم يُصنَّف أي شيء كـ"مشكلة migration" إذا كان قابلًا للإصلاح بدون migration.**

| # | Priority | Runtime symptom | Root cause | Affected endpoint/code | DB cause | Migration cause | Fix dependency |
|---|---|---|---|---|---|---|---|
| P0-1 | **P0** | نقل حالة الطلب إلى `PREPARATION`/`OUT_FOR_DELIVERY`/`DELIVERED` يفشل بخطأ SQL | CHECK constraint عالق يصف enum قديم | كل مسار تحديث حالة الطلب (POS، call center، إلخ) | `orders_status_check` على DB مباشرة | **لا يحتاج migration جديدة بالضرورة** — يمكن إصلاحه بأمر DDL معزول واحد (`ALTER TABLE ... DROP CHECK` أو تعديله)، وإن أُريد توثيقه كـmigration فهي migration بسيطة جدًا لا علاقة لها بأي من التعقيدات الأخرى | لا يوجد — مستقل تمامًا |
| P0-2 | **P0** | كل استدعاء `Customer::create()` يفشل | `booted()` يكتب `external_ref` غير موجود دون شرط | `CustomerIdentityService::create()`, `CustomerFinancialController::store`, `CallCenterController::storeCustomer/quickCreateCustomer` | عمود `customers.external_ref` غائب | يحتاج `2026_08_09_000001_add_integration_identity_and_outbox_foundation` **أو** حماية مؤقتة في الكود (`if (Schema::hasColumn(...))`) | كود بسيط ممكن كحل مؤقت مستقل عن قرار الـmigration |
| P0-3 | **P0** | كل استدعاء `Order::create()` يفشل | نفس النمط، `booted()` يكتب `public_ref` غير موجود | `CallCenterOrderCreationService::create()`, وأي إنشاء طلب آخر | عمود `orders.public_ref` غائب | نفس الملف أعلاه، أو حماية كود مؤقتة | نفس الملاحظة |
| P0-4 | **P0** | `/crm/*` و`/customers/*` تُعيد 403 لكل مستخدم بلا استثناء | صلاحيات `crm.*` غير موجودة في DB إطلاقًا (11 اسمًا) | كل route تحت هذين المسارين | جدول `permissions` لا يحتوي أي `crm.*` | `2026_07_27_100001_add_crm_customer_permissions` + `2026_07_28_000001_add_crm_admin_permissions` (كلاهما Category A، آمنان) | لا تعارض — لكن تشغيلهما بمفردهما يكشف مشاكل P1 التالية بدل حلها فعليًا |
| P1-1 | **P1** | `/crm/customers` و`/crm/customers/{id}` سيفشلان بـ500 فور فتح P0-4 | eager-load غير مشروط لعلاقة `phones`/`primaryPhone` على جدول `customer_phones` غير الموجود | `Customer360QueryService::directory()`/`profile()` | جدول `customer_phones` غائب | `2026_07_27_100000_create_customer_phones_table` (Category A) | يجب أن يُشغَّل مع/قبل P0-4 وإلا فتح الصلاحيات يكشف عن 500 بدل نتائج صحيحة |
| P1-2 | **P1** | 4 endpoints في call center (`customer-addresses/*`, `customer-occasions/*`) تفشل بـ500 دائمًا | استيراد ناقص لِـ`CustomerAddress`/`CustomerOccasion` في `CallCenterController.php` | `PATCH customer-addresses/{address}`, `POST .../use`, `PATCH/DELETE customer-occasions/{occasion}` | **لا علاقة بـ DB إطلاقًا** | **لا migration مطلوبة** — إصلاح كود بحت (سطرا `use`) | مستقل تمامًا، أرخص إصلاح في كل التقرير |
| P1-3 | **P1** | البحث عن العميل بالهاتف في call center يفشل دائمًا | استعلام يقرأ أعمدة `mobile`/`category`/`city` غير موجودة + يستعلم جدول `customer_phones` غائب | `CustomerResolutionService::resolve()` | عمودان في customers + جدول customer_phones | مرتبط بقرار §5 وبتشغيل `update_customers_table`/`add_missing_customer_columns` (بعد الدمج) + `create_customer_phones_table` | يعتمد على حسم القرار #1 في §5 |
| P2-1 | **P2** | استدعاء متكرر لـ`POST /customers/{id}/invoice` (وما شابه) يُرحِّل القيد المحاسبي مرتين | لا يوجد أي حارس idempotency في `CustomerAccountingService` | `recordInvoice/recordPayment/recordCreditNote/recordDebitNote` | لا شيء — منطق تطبيقي بحت | **لا migration مطلوبة** | إصلاح كود بحت، لكن يجب أن يسبق أي استخدام مالي حقيقي (DB حاليًا 0 صفوف) |
| P2-2 | **P2** | فتح رصيد عميل بمبلغ ابتدائي > 0 لا يُرحَّل ماليًا بصمت | حساب `3999` (opening balance) غير موجود في `accounts` | `CustomerFinancialController::store()` | حساب محاسبي غير موجود | لا migration — بيانات (seed حساب واحد) أو إصلاح كود | مستقل |
| P3-1 | **P3** | حقول `payment_terms`/`credit_days` تعود `null` دائمًا في استجابة `/crm/customers/{id}/financial-summary` | أعمدة غير موجودة، لكن القراءة لا تُفشل الطلب (سلوك Eloquent الافتراضي) | `Customer360QueryService::financial()` | نفس أعمدة customers الناقصة | نفس ملفات §5 القرار #1 | تحسين بيانات، ليس كسرًا فعليًا |

---

## 7. EXACT REPAIR ORDER

الترتيب المقترَح في الطلب الأصلي (Phase 0-10) **صحيح في جوهره لكن يحتاج تعديلًا واحدًا مهمًا**: إصلاح `orders_status_check` (P0-1) لا يعتمد على أي شيء آخر ولا على "تجميد/استعادة migrations" — لذا يجب أن يكون أول Phase فعلي بعد الـ snapshot، وليس مدمجًا داخل "Phase 3 — Fix schema blockers" العامة. هذا تعديل مبني على دليل (P0-1 مستقل تمامًا في §6)، وليس إعادة استخدام الترتيب السابق دون تفكير.

| Phase | Goal | Files | DB impact | Prerequisites | Expected result | Validation command |
|---|---|---|---|---|---|---|
| **0** | Snapshot/Baseline | لا شيء يُعدَّل — فقط تصدير | لا شيء (قراءة فقط) | لا شيء | نسخة موثقة كاملة من `SHOW CREATE TABLE` لكل جدول مذكور في §4 | `SHOW CREATE TABLE <table>` لكل جدول، حفظ النتائج |
| **1** | إصلاح `orders_status_check` (P0-1) فقط | DDL معزول واحد على `orders` | تعديل CHECK constraint فقط، لا شيء آخر | Phase 0 | القيم الخمس الحية تُكتب بنجاح | إدخال تجريبي (rollback) للقيم الخمس + `SHOW CREATE TABLE orders` |
| **2** | توثيق migrations المُستعادة من `dev` رسميًا في هذا الفرع | نسخ محتوى الملفات المُستعادة (§3) إلى موقع مرجعي (مثلًا `database/migrations/_recovered_dev/`) — بدون تفعيلها كـmigrations حقيقية | لا شيء | Phase 0 | تاريخ schema الحقيقي محفوظ داخل هذا الفرع، لا يعتمد على `dev` البقاء موجودًا | `diff` بين الملفات المنسوخة و`git show dev:...` |
| **3** | حسم القرارات الخمسة في §5 | لا شيء بعد — قرارات فقط | لا شيء | Phase 0-2 | إجابات موثقة لكل قرار، تحديدًا #1 (account_id) و#3 (order_feedback) لأنهما يحجبان بقية الخطوات | مراجعة يدوية للقرارات الموثقة |
| **4** | كتابة migrations مُصالَحة بديلة | استبدال محتوى `create_orders_table.php`, `create_idempotency_records_table.php`, `create_call_tickets_table.php` (Category C)؛ دمج migrations customers (Category F بعد القرار)؛ تعليم `create_order_items_table.php` كمُنفَّذ (Category D) | لا شيء بعد على DB الحية | Phase 3 مكتملة | مجموعة migrations متسقة تصف الواقع الحالي + الإضافات الآمنة فقط | `php artisan migrate --pretend` (قراءة فقط، يعرض ماذا سيُنفَّذ دون تنفيذه) |
| **5** | إصلاح الكود المرتبط (غير المرتبط بـmigration) | `app/Http/Controllers/Api/CallCenterController.php` (P1-2)، `app/Services/Accounting/CustomerAccountingService.php` (P2-1) | لا شيء | لا شيء — مستقل تمامًا، يمكن تنفيذه بالتوازي مع أي Phase | P1-2 وP2-1 محلولان دون أي migration | اختبار يدوي/آلي للـendpoints الأربعة + محاكاة استدعاء مزدوج لـrecordInvoice |
| **6** | إصلاح `booted()` في Customer/Order | `app/Models/Customer.php`, `app/Models/Order.php` | لا شيء | Phase 4 (أو حماية مؤقتة `hasColumn` إن أُريد تسريع P0-2/P0-3 دون انتظار) | `Customer::create()`/`Order::create()` يعملان | استدعاء تجريبي على بيئة staging بعد التطبيق |
| **7** | تشغيل migrations الآمنة (Category A) + المُصالَحة (Category B/C/D بعد Phase 4) | — | **أول تعديل فعلي على DB الحية** | Phase 4-6 مكتملة، ونسخة مُختبرة على DB مستنسخة (Phase 8) | Schema يطابق توقعات الكود بالكامل | `php artisan migrate:status` بعد التنفيذ، مقارنة schema بـ§4 |
| **8** | اختبار على نسخة مستنسخة من DB | — | تعديل على نسخة، ليس DB الحقيقية | Phase 7 (تُنفَّذ ضد النسخة أولًا، ثم DB الحقيقية) | كل الـendpoints في §6 تعمل، لا صفوف مكررة ماليًا | Test suite كامل + smoke test يدوي |
| **9** | تشغيل صلاحيات CRM (`add_crm_customer_permissions`, `add_crm_admin_permissions`) | — | إضافة صفوف صلاحيات فقط | Phase 7-8 (يجب أن يسبقها إصلاح P1-1 وإلا تُكشَف 500 بدل 403) | `/crm/*` تعمل فعليًا، ليس فقط تُرجِع غير 403 | اختبار كل endpoint في §11 من التقرير السابق يدويًا |
| **10** | تطبيق نهائي على DB الحقيقية + commit | كل الملفات أعلاه | التعديل النهائي | Phase 0-9 كاملة وناجحة على النسخة المستنسخة | حالة متسقة بين DB/migrations/Git/كود | إعادة تنفيذ فحص §4 الكامل، يجب أن يطابق التوقع بلا فروق |

---

## 8. EXACT FILE ACTION PLAN

| FILE | ACTION | REASON | SAFE? |
|---|---|---|---|
| `database/migrations/2026_07_03_000001_create_orders_table.php` | **REPLACE** | تعارض كامل — الجدول موجود بـ82 عمودًا مختلفة تمامًا | نعم، بعد Phase 0 (baseline) |
| `database/migrations/2026_07_29_170000_create_call_tickets_table.php` | **REPLACE** | تعارض في المحتوى (nullable/defaults مختلفة) تم تأكيده سطرًا بسطر هذه الجولة | نعم، بعد Phase 0 |
| `database/migrations/2026_08_02_000003_create_idempotency_records_table.php` | **REPLACE + إضافة migration جديدة منفصلة لعمود `status`** | الجدول موجود بشكل مختلف، والعمود الذي يحتاجه الكود (`status`) غائب عن كل من الواقع وهذا الملف بالشكل الصحيح | نعم، بعد Phase 0 |
| `database/migrations/2026_12_11_111251_create_order_items_table.php` | **لا تعديل محتوى — فقط تعليم كمُنفَّذ** | مطابق حرفيًا لما نُفذ فعليًا | نعم — أبسط إجراء في القائمة |
| `database/migrations/2026_06_14_195000_update_customers_table.php` + `database/migrations/2026_07_27_000001_add_missing_customer_columns.php` | **دمج في migration واحدة** | تكرار كامل تقريبًا، يحتاجان قرار §5 #1 أولًا | نعم، بعد Phase 3 (قرار account_id) |
| `database/migrations/2026_08_01_000002_add_title_to_customers_table.php` | **إبقاء كما هو، تشغيله بعد دمج migrations customers** | آمن بذاته، فقط ترتيب تنفيذ | نعم |
| `database/migrations/2026_09_28_000004_ensure_all_order_columns_from_all_earlier_migrations.php` | **MODIFY** | جملتا ALTER خام تتقاطعان مع قرار Phase 1 (CHECK constraint) | نعم، بعد Phase 1 |
| `database/migrations/2026_07_30_000001_create_order_feedback_table.php` | **تأجيل التشغيل حتى قرار §5 #3** | لا مشكلة تقنية، لكن مرتبط بقرار عمل (order_feedback مقابل order_customer_experiences) | نعم، بعد القرار |
| `database/migrations/2026_06_28_000001_enhance_discounts_for_strategy_and_exclusions.php` | **MODIFY (إضافة حماية على `unique()`)** | استدعاء غير محمي قد يفشل | نعم |
| `database/migrations/2027_01_01_000001_add_unique_order_item_to_production_ticket_items.php` | **فحص بيانات أولًا، ثم قرار MODIFY أو RUN** | لم يُتحقق من عدم وجود تكرار | يحتاج فحص أولًا |
| باقي ملفات Category A (10 ملفات مذكورة في §2) | **RUN كما هي** | مؤكَّدة آمنة، أهدافها غائبة فعليًا | نعم، ضمن Phase 7/9 حسب الترتيب |
| `app/Http/Controllers/Api/CallCenterController.php` | **MODIFY (إضافة سطرَي `use`)** | يحل P1-2 دون أي علاقة بـmigration | نعم، مستقل بالكامل |
| `app/Models/Customer.php`, `app/Models/Order.php` | **MODIFY (حماية `booted()`)** | يحل P0-2/P0-3 | نعم، بعد Phase 4/6 أو كحل مؤقت مستقل |
| `app/Services/Accounting/CustomerAccountingService.php` | **MODIFY (إضافة حارس idempotency)** | يحل P2-1 | نعم، مستقل |
| `app/Services/CustomerIdentityService.php` | **MODIFY (إزالة defaults مفروضة على أعمدة غير موجودة)** | يمنع فشل الإنشاء حتى بعد حل P0-2 | نعم، بعد Phase 6 |
| `app/Services/Crm/Customer360QueryService.php` | **MODIFY (حماية eager-load لـphones)** | يحل P1-1 | نعم، بعد أو مع تشغيل `create_customer_phones_table` |

---

## 9. DO NOT TOUCH LIST

- **أي migration مُسجَّل Ran حاليًا على هذا الفرع** — إعادة كتابة تاريخ migration نُفذ فعليًا خطر حتى مع وجود نسخة مطابقة على `dev`.
- **قاعدة البيانات الحية نفسها** — لا `migrate`/`migrate:fresh`/`migrate:refresh`/`migrate:rollback`/`db:wipe`/DDL يدوي من أي نوع.
- **فرع `dev` أو `call-center` أو أي فرع آخر** — قُرئ منه فقط، لم يُعدَّل شيء ولن يُعدَّل.
- **`database/migrations/TODO.md`** — سجل تاريخي، حتى لو ثبت أن أحد ادعاءاته غير دقيق (موثَّق في التقرير السابق) — تصحيحه توثيق لاحق، ليس عاجلًا.
- **أي كود خارج القائمة الصريحة في §8** — خصوصًا `CrmController.php`، بقية طبقة CRM/accounting — أي تعديل قبل حسم قرارات §5 سيكون تخمينًا.
- **جدول `branche`** — حتى تأكيد 0 صفوف في كل بيئة (وليس فقط dev المحلية)، لا حذف.
- **أي commit** — لا commit في أي مرحلة من هذه الجولة.

---

## 10. FINAL GO / NO-GO

**READY FOR REPAIR: NO**

هذا ليس بسبب نقص في الفهم التقني — الفهم مكتمل عمليًا (كل migration مصنَّف بالاسم الكامل، كل الملفات "المفقودة" مُستعادة ومطابقة للـ DB الحية، كل endpoint مكسور محدَّد بالسبب الدقيق). المانع الوحيد هو **قرارات عمل صريحة لم تُحسَم بعد**:

1. مصير `customers.account_id` (§5 #1) — يحجب دمج migrations customers.
2. `order_customer_experiences` مقابل `order_feedback` (§5 #3) — يحجب تشغيل `create_order_feedback_table`.
3. نطاق رصيد العميل — شركة كاملة أم فرع (§5 #4) — يحجب تعديل `CustomerAccountingService`.
4. فحص بيانات `subject`/`title` (§5 #5) — تحقق تقني ناقص، ليس قرارًا بحد ذاته.
5. تأكيد 0 صفوف في `branche` على كل بيئة غير dev المحلية (§9) — تحقق تقني ناقص.

**أول خطوة تنفيذية واحدة إذا أردت البدء الآن بلا انتظار أي قرار:**

نفّذ **Phase 1 فقط** — إصلاح `orders_status_check`. هذه الخطوة الوحيدة في كل هذه الخطة لا تعتمد على أي قرار من الخمسة أعلاه، ولا على أي migration أخرى، وتحل مشكلة P0 حقيقية تعمل بشكل خاطئ في الإنتاج الآن. كل شيء آخر ينتظر قراراتك.
