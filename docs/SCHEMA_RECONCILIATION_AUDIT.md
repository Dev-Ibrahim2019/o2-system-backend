```
AUDIT MODE: READ ONLY
DATABASE CHANGES: NONE
CODE CHANGES: NONE
MIGRATIONS EXECUTED: NONE
```

# O2 System Backend — Schema Reconciliation Audit

**Repository:** o2-system-backend
**Branch:** `feature/customer-crm-operations` (working tree clean at time of audit)
**Stack:** Laravel 12, PHP 8.4, MySQL 8.0.46 (InnoDB / utf8mb4_unicode_ci), Sanctum, Spatie Laravel Permission
**Database audited:** `o2-restaurant-backend` (local dev, read-only queries only)
**Method:** Independent parallel re-verification by four separate investigators (live database introspection, migration file content, git history archaeology, and code tracing), each instructed not to trust a prior audit's conclusions and to re-derive every claim from primary sources. Findings are tagged **CONFIRMED** (directly observed — a query result, a file's actual content, a traced code path), **INFERRED** (a reasonable deduction from confirmed facts, not directly observed), or **UNKNOWN** (not yet determined; what evidence would resolve it is stated explicitly).

This document supersedes an earlier read-only audit on several specific numeric and causal claims — the corrections are called out explicitly wherever they occur, because understanding *why* the first pass was wrong is itself part of the reconciliation.

---

## 1. Executive Summary

Ten findings, most urgent first.

| # | Finding | Severity | Confidence |
|---|---|---|---|
| 1 | **`orders.status` is broken in production right now**, independent of any migration question. The live column is a native `ENUM('PENDING_PAYMENT','PREPARATION','OUT_FOR_DELIVERY','DELIVERED','CANCELLED')`, but a stale `CHECK` constraint (`orders_status_check`) left over from an earlier lowercase-snake_case status scheme only allows `('pending','pending_confirmation','confirmed','in_progress','ready','served','paid','cancelled','pending_payment')`. Writing `PREPARATION`, `OUT_FOR_DELIVERY`, or `DELIVERED` throws `SQLSTATE[HY000]: 3819`. Verified with a direct read-only probe against every enum value. | **CRITICAL** | CONFIRMED |
| 2 | Both `Customer::booted()` and `Order::booted()` unconditionally write an integration-reference column on every create (`external_ref`, `public_ref` respectively). **Neither column exists live.** Every `Customer::create()` and every `Order::create()` call in the entire application throws. The `Order::create()` half of this was missed by the prior audit and is the larger blast radius — orders are created far more often than customers (POS, call center, everywhere). | **CRITICAL** | CONFIRMED |
| 3 | The "20 deleted migrations" from the prior audit **were never deleted**. They exist verbatim on the unmerged `dev` branch. The shared dev database had them run while `dev` was checked out; this branch's own migration files diverged through a different, unmerged lineage. This is recoverable history, not lost history — it changes the repair strategy materially (see §5, §18). | **CRITICAL** (as root cause) | CONFIRMED |
| 4 | Two Pending "create table" migrations (`2026_07_03_000001_create_orders_table`, `2026_12_11_111251_create_order_items_table`) target tables that already exist live with real data (21 and 45 rows respectively) and have no existence guard — `php artisan migrate` would abort with "table already exists" on the first one it reaches. Two more Pending creates (`create_call_tickets_table`, `create_idempotency_records_table`) have the identical defect. | **CRITICAL** | CONFIRMED |
| 5 | Every `crm.*` permission referenced by `/crm/*` and permission-gated `/customers/*` routes is absent from the live `permissions` table (11 distinct names, all created only by two Pending migrations). Traced the exact Spatie/Laravel Gate resolution path line-by-line: this degrades to a clean, uniform **403 for every user including super-admin** — not a 500, not intermittent. | **CRITICAL** | CONFIRMED (full source trace, not inferred) |
| 6 | `customers` is missing 16 columns the model/services assume exist (`mobile, city, country, category, currency, risk_level, payment_terms, credit_days, opening_balance, is_opening_balance_posted, notes, gps_link, salesperson_id, title, external_ref`, plus `customer_phones` table entirely absent). Customer creation, update, call-center customer search/creation, and CRM 360-profile all fail independent of the permission issue in #5. | **CRITICAL** | CONFIRMED |
| 7 | `CallCenterController.php` never imports `CustomerAddress`/`CustomerOccasion`. Four endpoints (`PATCH customer-addresses/{address}`, `POST .../use`, `PATCH/DELETE customer-occasions/{occasion}`) throw an uncaught `ReflectionException` during Laravel's own dependency-injection step, before the controller body runs — a pure PHP bug, unrelated to schema or permissions. New finding, verified via reflection against the live app container. | **HIGH** | CONFIRMED |
| 8 | Customer accounting has four independent posting paths to the same AR account (code `1120`). Three are adequately guarded (POS invoice, B2B SalesInvoice with a caveat, call-center order execution — the last is genuinely idempotent via a real DB unique constraint). **The fourth — `CustomerAccountingService`'s manual `recordInvoice/recordPayment/recordCreditNote/recordDebitNote` endpoints — has zero duplicate-posting protection of any kind.** Nothing anywhere cross-checks whether the same real-world event was already posted through a different path. Currently latent: live `transactions`/`entries`/`customers` tables all have 0 rows, so no data has actually been corrupted yet. | **HIGH** | CONFIRMED |
| 9 | Live counts, re-verified twice independently (`Schema::getColumnListing` and a direct `information_schema.COLUMNS` count, in this pass): `orders` has **82 columns** — the original audit's "85" was close; a corrected figure of "62" briefly appeared in an intermediate pass of this reconciliation and was itself wrong, caught and fixed here. `order_items` has **24 columns**, confirmed correct twice now (the original audit's "16" was wrong). `order_items.weight_grams` is a live column with zero migration or code reference anywhere in the repository — added out-of-band, undocumented. | **MEDIUM** (numeric correction) | CONFIRMED (re-verified twice) |
| 10 | Two duplicate live tables with no code path: `branches` (used everywhere, 18+ FK references) vs. `branche` (0 rows, zero code references, a same-week typo/duplicate never cleaned up). `order_customer_experiences` (schema-complete, live, zero controller references) vs. `order_feedback` (fully wired to a controller, but its table doesn't exist live) — two competing implementations of "post-order feedback," each broken in the opposite way. | **MEDIUM** | CONFIRMED |

**What changed since the prior audit, and why it matters:** the previous read-only pass concluded that a documented cleanup had permanently deleted ~20 migrations and that their content was largely unrecoverable. Deep git archaeology in this pass (branch-wide search, not just this branch's history) found all of them intact on `dev`. This is materially better news — it means the "true" historical schema-building migrations can be recovered exactly rather than reconstructed by inference, which changes the repair plan from "reverse-engineer what probably happened" to "pull the real files and reconcile them deliberately." See §5 and §18.

---

## 2. Repository Inventory

Confirmed via `Glob`/`Grep` across the repository.

| Area | Path | Notes |
|---|---|---|
| Migrations | `database/migrations/` | 105 `.php` files + `TODO.md` (planning doc, not code) + 1 extensionless file (`2026_07_06_131304`, invisible to Artisan's migrator) |
| Models | `app/Models/` | ~55 models; CRM-relevant: `Customer`, `CustomerPhone`, `CustomerAddress`, `CustomerComplaint`, `CustomerNote`, `CustomerOccasion`, `ComplaintFollowup`, `OrderCustomerExperience`, `OrderFeedback`, `Order`, `OrderItem`, `Branch` (no `Branche` model exists), `IdempotencyRecord`, `IntegrationOutbox`, `Entry`, `Transaction`, `Account`, `Invoice`, `Payment`, `CallTicket` |
| Services | `app/Services/` | `CustomerIdentityService`, `Crm/Customer360QueryService`, `Crm/CrmCustomerAccessService`, `Accounting/CustomerAccountingService`, `AccountingService`, `SalesInvoice/SalesInvoiceJournalService`, `Accounting/Subledgerservice`, `CallCenter/CallCenterOrderCreationService`, `CallCenter/CallCenterOrderExecutionService`, `CallCenter/CustomerResolutionService`, `Support/IdempotencyService`, `Integration/IntegrationOutboxWriter` |
| Controllers | `app/Http/Controllers/Api/` | `Crm/CrmController`, `CustomerFinancialController`, `CustomerResolutionController`, `CallCenterController`, `CallCenterPaymentController`, `CallTicketController`, `OrderFeedbackController` |
| Requests | `app/Http/Requests/Api/` | `StoreCustomerRequest`, `UpdateCustomerRequest`, `StoreOrderFeedbackRequest`, and others |
| Routes | `routes/api.php` | 563 lines; three customer-facing route families (`/customers/*`, `/crm/*`, `/call-center/*`) |
| Seeders | `database/seeders/` | `PaymentMethodSeeder` (confirms AR account mapping), permission/role seeders |
| Integration/outbox | `app/Support/Integration/IntegrationReference.php`, `app/Services/Integration/IntegrationOutboxWriter.php`, `app/Models/IntegrationOutbox.php` | Fully coded against a schema (`integration_outbox` table, `orders.public_ref`, `customers.external_ref`) that does not exist live |
| Accounting/subledger | `app/Services/Accounting/`, `app/Models/{Entry,Transaction,Account}.php` | Confirmed consistent, single AR account code (`1120`) used everywhere |
| Permissions | Spatie tables + two Pending migrations | `vendor/spatie/laravel-permission/` source read directly to confirm runtime behavior |
| Docs | `database/migrations/TODO.md` | A developer's own record of a migration-squash effort — see §5, §18 |

**Dependency map (CRM-critical path):**

```
routes/api.php
  ├─ /customers/*  → CustomerFinancialController → CustomerIdentityService → Customer (BROKEN) → customers table
  ├─ /crm/*        → CrmController → CrmCustomerAccessService, Customer360QueryService → Customer, CustomerPhone (BROKEN)
  └─ /call-center/* → CallCenterController (missing imports, BROKEN) → CallCenterService → CustomerResolutionService (BROKEN)
                    → CallCenterOrderCreationService → Order (BROKEN), CustomerIdentityService (BROKEN), IntegrationOutboxWriter (BROKEN)
                    → CallCenterOrderExecutionService → IdempotencyService (BROKEN at the write layer, but its DB unique constraint still protects concurrency) → AccountingService
```

---

## 3. Migration Inventory

**Totals, independently reconciled (corrected from the prior audit's approximate figures):**

| Metric | Count | Note |
|---|---|---|
| Items in `database/migrations/` | 107 | 105 `.php` + `TODO.md` + 1 extensionless draft (`2026_07_06_131304`) |
| Rows in live `migrations` table | 106 | All batch 1 |
| Rows matching a file on disk exactly | **85** | Corrected from prior audit's "~86" — verified via exact `comm` diff after stripping `\r` (a Windows/PHP_EOL artifact that produced a false zero-match on the first attempt — noted as a methodology trap for future audits) |
| Ran, file **not** on disk ("Category C") | **21** | One more than the prior audit's 20 — `2027_01_02_000001_complete_call_center_delivery_workflow` was missed previously |
| Pending (file on disk, no DB row) | **20** | |
| **All 21 "missing" files recovered exactly from the `dev` branch** | 21/21 | See §5 |

Full per-migration classification (Category A–J, per the scheme required for this audit) is in §7. The narrative reconstruction for the tables that matter most is in §8.

---

## 4. Migration Status Reconciliation

`php artisan migrate:status` **cannot be trusted as a signal of what schema is actually missing** — multiple tables it lists as "Pending" (`orders`, `order_items`, `customers`-column-adds, `call_tickets`, `idempotency_records`, `discounts`-family) already exist live, because they were built by the Category-C migrations whose files are gone from this branch but whose DB-table effects persist. Every claim in this document about "does X exist" was verified by direct `information_schema`/`Schema::hasTable`/`Schema::hasColumn` queries against the live database, never by reading `migrate:status` alone.

---

## 5. Git History Findings

This is the single most consequential correction in this audit.

**CONFIRMED:** all 21 orphaned migrations (Ran in the live `migrations` table, no file on this branch) exist **verbatim** at the tip of the local `dev` branch (several also survive on `call-center`). They were never deleted in any git sense. `git log --all --full-history --diff-filter=A` found every one added on some branch; `git branch --all --contains <add-commit>` and `git cat-file -e dev:database/migrations/<name>.php` confirmed their presence today. Recovered exact content via `git show dev:database/migrations/<name>.php` for all 21.

**Mechanism:** the dev database (`o2-restaurant-backend`) is shared across branches. Migrations were run while `dev` (and briefly `call-center`) was checked out. This branch (`feature/customer-crm-operations`) never merged that lineage, so its own `database/migrations/` directory has no record of those files — while the shared database still carries their effects and the `migrations` tracking table still remembers their names.

**`orders`/`order_items` rename question — answered, with opposite results for each table:**
- **`order_items`: YES, a rename-in-disguise, CONFIRMED byte-for-byte.** The currently-Pending `2026_12_11_111251_create_order_items_table.php` is byte-identical (`diff` returns nothing) to the recovered `2026_08_02_111251_create_order_items_table.php` that actually ran. This is the single easiest reconciliation in the whole audit: the file that's "wrong" already has the right content, it just needs to stop being treated as new work.
- **`orders`: NO — genuinely different files.** `git log --follow` traces `2026_07_03_000001_create_orders_table.php`'s lineage through renames on a `feature/employee-accounting`-descended chain, landing on a 7-value lowercase `status` enum, no `customer_id`/`department_id`/`user_id`/`barcode`/payment/audit columns, and no `hasTable()` guard. The migration that actually ran (`2026_08_02_000001_create_orders_table.php`, `dev` branch, author "karam") is a **different, larger, uppercase-status-enum schema with a `hasTable()` guard**. These are not the same migration under two names — they are two independent implementations that happened to target the same table name, from two different unmerged lineages.

**`TODO.md` verification — mostly accurate, demonstrably false on one specific point.** Spot-checked 8 of its claims against actual surviving file content: 7 confirmed accurate (departments, branches, users, dining_tables, sales_invoices, invoices, suppliers, payments). **One confirmed false:** TODO.md claims the `orders` "pricing context" columns (`customer_id, employee_id, supplier_id, engine_discount_amount`) were merged into the create file and that status uses `VARCHAR + CHECK`. Neither is true — the actual merge commit only added `dining_table_id` to the create file, and live `orders.status` is a native `ENUM`, not `VARCHAR`. This is a documentation/reality mismatch independent of the branch-divergence issue.

**Other git-recovered facts, refuting specific prior-audit claims:**
- `order_customer_experiences` — prior audit said "zero git history hit, unrecoverable." **Refuted.** `git log --all -S"order_customer_experiences"` finds it inside the recovered `2026_07_15_160000_add_quality_and_urgency_to_orders.php` (not a standalone create file, which is why a filename-based search missed it). Recovered schema is a **perfect column-for-column match** against the live table.
- `idempotency_records` — prior audit said "zero history, created out-of-band." **Partially refuted.** The true source is a `Schema::create('idempotency_records', ...)` embedded inside the recovered `2027_01_02_000001_complete_call_center_delivery_workflow.php` — not the on-disk Pending file of a similar name (which has a different, incompatible column shape). Confirmed via exact column match (`key` is `char(36)` matching a `$table->uuid('key')` call, not the Pending file's `string(100)`).
- `customer_complaints.subject`/`title` rewrite-in-place — **CONFIRMED with exact commit hashes**: added `008d060` (2026-07-27), edited `a00e1c5` (2026-07-28) to demote FKs; the sibling nullable-subject migration was repurposed entirely from a `subject` column to a `title` column across commits `2f3c497` → `e4d88ed` → `008d060` → `a00e1c5` → `ea1c3d9`, same filename throughout.
- `branches` vs `branche` — **CONFIRMED dead duplicate.** `branche` was added one day after `branches`, same author, same commit (`b49b45e`) that also added the real `Branch` model/controller. Zero code references to `branche` anywhere in `app/`.

**Branch topology (CONFIRMED via `git branch --all`):** local branches `account_statement, call-center, dev, develop-new, feature/call-center-professional-workspace, feature/cashier, feature/customer-crm-operations (current), feature/employee-accounting, main, posandhos, test`. The commit that added the real `orders`/`order_items` creators (`447018f`, author "karam", 2026-08-12) exists **only on `dev`** — refining the prior audit's looser "posandhos/call-center lineage" claim to a specific, single branch. (This specific branch attribution is the one place this pass would flag as **INFERRED rather than fully proven** — intermediate commit parentage wasn't traced exhaustively.)

`git fsck --unreachable --no-reflogs` and `git reflog` were checked and found nothing further — the dangling objects present are unrelated `git stash` artifacts from 2026-04-19.

---

## 6. Live Database Inventory

CONFIRMED via `information_schema`, `SHOW CREATE TABLE`, `SHOW INDEX`, engine/collation from `information_schema.TABLES`. All audited tables: InnoDB, utf8mb4_unicode_ci.

### Tables confirmed absent (0 rows in `information_schema.TABLES`)
`customer_phones`, `integration_outbox`, `order_feedback`, `payment_confirmations`, `employee_break_sessions`, `call_center_registers`.

### `customers` — 17 columns (CONFIRMED)
```
id, name, name_en, code, tax_number, phone, email, address,
status enum('active','inactive','blocked') default 'active',
credit_limit decimal(15,3) default 0.000,
loyalty_points int unsigned default 0,
account_id (FK→accounts.id, ON DELETE SET NULL),
branch_id (FK→branches.id, ON DELETE SET NULL),
meta json, deleted_at, created_at, updated_at
```
Missing: `title, mobile, website, city, country, category, currency, risk_level, payment_terms, credit_days, opening_balance, is_opening_balance_posted, notes, gps_link, salesperson_id, external_ref`. Indexes: PK, UNIQUE `code`. No CHECK constraints. 0 rows live.

### `orders` — **82 columns (re-verified twice via independent methods in this pass; the prior audit's "85" was close, an intermediate "62" was a transcription error and is wrong)**
```
id, customer_id, branch_id, department_id, cashier_id, user_id, barcode, order_type, source,
status, payment_status, transaction_id, table_number, customer_count, seated_at, sub_status,
order_number, reference_number, customer_name, phone, customer_phone, customer_mobile,
subtotal, discount_value, discount_type, tax_amount, discount_amount, total, total_amount,
paid_amount, change_amount, balance_amount, note, needs_attention, customer_service_flag,
customer_notes, delivery_notes, call_notes, ordered_at, completed_at, printed_at, cancelled_at,
cancellation_reason, cancelled_by, created_by, updated_by, deleted_at, created_at, updated_at,
call_center_agent_id, paid_at, assembled_at, assembled_by, assembly_duration_seconds,
delivery_started_at, delivered_at, delivery_employee_name, delivery_duration_seconds,
assembly_started_at, assembler_id, delivery_assigned_by, is_urgent, priority, expedited_at,
expedited_by, delivery_zone_id, dining_table_id, customer_address_id, delivery_address_snapshot,
shift_id, opened_by, closed_by, printed_by, employee_id, supplier_id, engine_discount_amount,
delivery_fee, driver_id, manual_adjustment, adjustment_reason, adjusted_by, adjusted_at
```
- `status`: native `ENUM('PENDING_PAYMENT','PREPARATION','OUT_FOR_DELIVERY','DELIVERED','CANCELLED')` default `PENDING_PAYMENT`.
- **`orders_status_check` CHECK constraint present**: `CHECK (status IN ('pending','pending_confirmation','confirmed','in_progress','ready','served','paid','cancelled','pending_payment'))` — see Finding #1. utf8mb4_unicode_ci is case-insensitive, so `PENDING_PAYMENT`/`CANCELLED` coincidentally pass; `PREPARATION`, `OUT_FOR_DELIVERY`, `DELIVERED` do not appear under any casing and **fail**.
- No `public_ref`, no `payment_policy`. 27 foreign keys (mostly `ON DELETE SET NULL`), 29 indexes. **21 rows live**, all `source = 'call_center'`, AUTO_INCREMENT at 23.

### `order_items` — **24 columns (CORRECTED — prior audit said 16)**
```
id, order_id, item_id, department_id, item_name, item_name_ar, quantity, price,
original_price, final_price, discount_amount, discount_percent, discount_id,
discount_apply_strategy, tax_rate, tax_amount, total, status, notes,
sent_to_kitchen_at, is_printed_direct, is_takeaway, created_by, weight_grams
```
`weight_grams` — live column, zero migration or code reference anywhere in the repository (Category I, see §7). 45 rows live. FKs: `order_id→orders` (cascade), `discount_id→discounts` (set null), `created_by→users` (set null).

### `idempotency_records` — 11 columns (CONFIRMED, matches prior audit's figures exactly)
```
id, user_id, scope varchar(80), key char(36), request_hash char(64),
resource_type, resource_id, response json, response_status smallint default 200,
created_at, updated_at
```
No `status` column. **UNIQUE `(scope, key)`** — this constraint is what makes `IdempotencyService::lockOrCreate()` genuinely safe under concurrency (see §14) even though the write itself fails (`status` doesn't exist). FK `user_id→users` (set null). Its only candidate migration on disk (`2026_08_02_000003_create_idempotency_records_table.php`) is Pending and defines an incompatible shape — the real source, per §5, is a different, git-recovered file.

### `branches` (13 cols, used everywhere) vs `branche` (16 cols, self-referencing `parent_id`, 0 rows, zero code references) — both live, confirmed genuinely distinct duplicate tables. See §16.

### Remaining audited tables (confirmed existing, column/index/FK detail on file with the investigating agent, summarized)

| Table | Cols | Notable |
|---|---|---|
| customer_addresses | 22 | FK customer_id (cascade) |
| customer_notes | 11 | FK customer_id (cascade), created_by→users (set null) |
| customer_occasions | 13 | FK customer_id (cascade), created_by→users (set null) |
| customer_complaints | 22 | `branch_id` is `varchar(255)`, not a bigint FK (type mismatch vs. `customers.branch_id`); `type/priority/status/severity` are plain varchar, not enum; `order_id`/`invoice_id` have no DB-level FK despite being logically related |
| complaint_followups | 11 | FK complaint_id (cascade), user_id→users (set null) |
| order_customer_experiences | 11 | UNIQUE order_id; FK order_id (cascade), customer_id, recorded_by (set null) |
| invoices | 37 | `payment_method` enum(9 values incl. cash/card/account/wallet/mixed) |
| invoice_items | 24 | FK invoice_id (cascade) |
| accounts | 21 | UNIQUE code; `type`/`normal_balance`/`entity_type` enums |
| entries | 12 | FK account_id is **RESTRICT** (not set-null) — intentional, prevents deleting an account with postings |
| transactions | 21 | UNIQUE transaction_number; `type` enum (9 values), `status` enum (draft/posted/cancelled); `source_type/source_id` index is **non-unique** (relevant to §14 cross-chain risk) |
| call_tickets | 20 | UNIQUE external_call_id; `branch_id` FK is RESTRICT |
| payments | 17 | UNIQUE number |
| payment_methods | 10 | `account_id` FK has no explicit ON DELETE clause (defaults to RESTRICT) |
| sales_invoices | 46 | UNIQUE number, UNIQUE uuid; `status` enum(draft/awaiting_approval/awaiting_payment/partial/paid/cancelled) |

No CHECK constraints exist on any live table except `orders.orders_status_check` — verified across all 35 tables via `information_schema.CHECK_CONSTRAINTS`.

**Live data volumes (context for severity throughout this document):** `customers` 0 rows, `transactions` 0 rows, `entries` 0 rows, `orders` 22 rows (all call-center sourced), `order_items` 45 rows, `accounts` 30 rows. **The financial and CRM risks documented in this audit are structural/latent, not yet realized as data corruption** — there is a genuine window to reconcile safely before real transaction volume flows through these paths.

---

## 7. Database vs Migration Matrix (Category Classification)

Every migration classified per the scheme required for this audit. Table-existence checks were run for the full set; column-level diffs were done exhaustively for the tables in §6 and at lighter depth (existence + Ran-status only) for tables outside that list — flagged where relevant.

### Category A — Ran + file exists + schema matches
~78 of the 85 matched migrations, spanning accounts, employees, users, invoices, permissions, customer_addresses/notes/occasions/complaints/followups, call_tickets, order_customer_experiences (embedded, see §5), discounts family, dining_tables/zones, printers, quotes/vouchers, shifts, fiscal_years, audit_logs, production_tickets, purchase_bills. **No confirmed Category B member was found** — the closest candidate (`2026_10_06_130938_create_accounts_table.php`) matches live exactly on `up()`; its defect is in `down()` only (Category J, below).

### Category C — Ran, file not on disk, now recovered from `dev` (21 items — full list and recovered content per §5)
| Migration | Target | Recovery |
|---|---|---|
| 2026_07_01_000001_add_partial_status_to_sales_invoices_table | sales_invoices | RECOVERED exact |
| 2026_07_01_000002_add_missing_columns_to_invoices_table | invoices | RECOVERED exact |
| 2026_07_06_140954_add_type_column_to_invoices_table | invoices | RECOVERED exact |
| 2026_07_06_141258_add_missing_columns_to_invoice_items_table | invoice_items | RECOVERED exact |
| 2026_07_06_143008_expand_invoice_status_enum | invoices | RECOVERED exact |
| 2026_07_10_235000_add_delivery_workflow_timestamps_to_orders | orders | RECOVERED exact |
| 2026_07_11_000001_add_operations_fields_to_employees_table | employees | RECOVERED exact |
| 2026_07_12_160000_add_hr_operations_setup_fields | employees | RECOVERED exact |
| 2026_07_12_170000_add_order_execution_tracking | orders | RECOVERED exact |
| 2026_07_15_160000_add_quality_and_urgency_to_orders | orders **+ creates order_customer_experiences** | RECOVERED exact, live-schema-matched |
| 2026_07_20_103603_add_delivery_zone_id_to_orders_table | orders | RECOVERED exact |
| 2026_07_22_000001_add_dining_table_id_to_orders_table | orders | RECOVERED exact |
| 2026_07_22_000002_add_remaining_missing_columns_to_orders_table | orders | RECOVERED exact |
| 2026_07_22_000003_fix_orders_status_check_constraint | orders | RECOVERED exact — **prime suspect for the stale CHECK constraint, Finding #1** |
| 2026_07_22_000004_convert_orders_status_to_varchar | orders | RECOVERED exact |
| **2026_08_02_000001_create_orders_table** | orders | **RECOVERED exact** — the true origin of the live 82-column table (author "karam", `dev` branch, has a `hasTable()` guard) |
| **2026_08_02_111251_create_order_items_table** | order_items | **RECOVERED exact** — byte-identical to the on-disk Pending `2026_12_11_111251` file |
| 2026_08_12_000001_add_indexes_to_order_items_table | order_items | RECOVERED exact |
| 2027_01_01_000001_create_delivery_management_tables | (delivery infra) | RECOVERED exact |
| 2027_01_01_000001_enforce_order_lifecycle | orders | RECOVERED exact — finalized the live 5-value uppercase status enum |
| **2027_01_02_000001_complete_call_center_delivery_workflow** | orders, **idempotency_records**, call_tickets | RECOVERED exact — missed entirely by the prior audit's orphan list; **true source of the live `idempotency_records` schema** |

### Category D — Pending, target genuinely absent, safe to run today (10 items)
`2026_06_14_195000_update_customers_table` (all 14 target columns confirmed absent; `hasColumn()`-guarded; drops `account_id`, also guarded), `2026_07_26_000001_create_call_center_registers_table`, `2026_07_27_100000_create_customer_phones_table`, `2026_07_27_100001_add_crm_customer_permissions` (idempotent data seed), `2026_07_28_000001_add_crm_admin_permissions` (idempotent data seed), `2026_07_30_000001_create_order_feedback_table`, `2026_08_01_000001_create_employee_break_sessions_table`, `2026_08_01_000002_add_title_to_customers_table`, `2026_08_02_000001_add_call_center_payment_execution_fields_to_orders_table` (guarded; live `orders.payment_status` values checked directly — only `UNPAID` present, which its `legacyMap` covers, so it would not throw), `2026_08_09_000001_add_integration_identity_and_outbox_foundation` (unguarded but all three targets confirmed absent — safe once, fragile if ever run twice), `2026_09_28_000004_ensure_all_order_columns_from_all_earlier_migrations` (defensive catch-up), `2027_01_01_000002_add_mixed_call_center_payment_policy` (depends on the payment-policy migration above it, correct in filename order).

### Category E/H — Pending, target already exists, would fail outright (4 items, CONFIRMED)
| Migration | Failure |
|---|---|
| `2026_07_03_000001_create_orders_table` | Unguarded `Schema::create('orders', ...)` against a live table with 21 rows → "table already exists." Column shape also materially wrong even hypothetically (7-value lowercase status vs. live's 5-value uppercase). |
| `2026_07_29_170000_create_call_tickets_table` | Same failure mode, `call_tickets` live |
| `2026_08_02_000003_create_idempotency_records_table` | Same failure mode; column shape also diverges from live even if the guard were added |
| `2026_12_11_111251_create_order_items_table` | Same failure mode, `order_items` live with 45 rows — despite being byte-identical to the file that actually created it (§5) |

### Category F — Pending, partially safe (2 items)
`2026_06_28_000001_enhance_discounts_for_strategy_and_exclusions` (most operations guarded; one `Schema::table('discount_targets', ...)->unique(...)` call has **no guard** — INFERRED risk, not independently row-checked), `2027_01_01_000001_add_unique_order_item_to_production_ticket_items` (unguarded unique add, data state not independently verified).

### Category G — Pending, duplicate of another migration (1 item)
`2026_07_27_000001_add_missing_customer_columns` duplicates `2026_06_14_195000_update_customers_table` almost exactly (same 14 target columns; the June file additionally drops `account_id`). Both Pending simultaneously.

### Category I — Live element with no migration explanation anywhere (1 item)
`order_items.weight_grams` — confirmed via repository-wide grep (not just migrations) returning zero hits. Created out-of-band.

### Category J — Migration's own claims contradicted by evidence (2 items)
`2026_10_06_130938_create_accounts_table.php` — filename says "create," body is three `Schema::table()` (alter) calls, no `Schema::create` at all. `TODO.md` — claims the `orders` pricing-context columns were merged and status uses VARCHAR+CHECK; neither is true (§5).

---

## 8. Reconstructed Table Histories

Format: LIVE CREATION → MIGRATION CHANGES → GIT HISTORY → CURRENT LIVE STRUCTURE → CURRENT APPLICATION EXPECTATION, with CONFIRMED/INFERRED/UNKNOWN throughout.

### `orders`
- **LIVE CREATION:** CONFIRMED — `2026_08_02_000001_create_orders_table.php`, on `dev` branch (author "karam", 2026-08-12), recovered exact. Guarded with `hasTable()`. Original shape: `customer_id, branch_id, department_id, cashier_id, user_id, dining_table_id, order_number, reference_number, barcode, order_type` enum, **uppercase** `status` enum (`PENDING_PAYMENT, PREPARATION, READY, SERVED, CANCELLED` — note: `READY`/`SERVED`, not yet `OUT_FOR_DELIVERY`/`DELIVERED`), `sub_status`, `payment_status`, `transaction_id`, audit columns.
- **MIGRATION CHANGES:** CONFIRMED — 15 further Category-C migrations (§7) layered on delivery workflow timestamps, execution tracking, quality/urgency fields, delivery zone, dining table FK, remaining columns, and two CHECK-constraint rewrites, culminating in `2027_01_01_000001_enforce_order_lifecycle` (CONFIRMED, recovered) which finalized `status` to the current live 5-value set (`PENDING_PAYMENT, PREPARATION, OUT_FOR_DELIVERY, DELIVERED, CANCELLED`) — but did **not** update the CHECK constraint left by an earlier migration (`2026_07_22_000003_fix_orders_status_check_constraint`, also recovered), producing the live Finding #1 defect.
- **GIT HISTORY:** CONFIRMED — this entire chain lives on `dev`, never merged into `feature/customer-crm-operations`. This branch independently carries a different `create_orders_table` draft descended from `feature/employee-accounting` (renamed through commits `8600b0f` → `a00e1c5` → current `2026_07_03_000001`), which was never run and does not match live.
- **CURRENT LIVE STRUCTURE:** CONFIRMED — 82 columns, native ENUM status with a stale CHECK constraint, 27 FKs (§6).
- **CURRENT APPLICATION EXPECTATION:** CONFIRMED — `Order` model's `$fillable` includes `payment_policy, kitchen_release_status, kitchen_released_at, kitchen_released_by`, none of which are live (created by a still-Pending migration); `booted()` unconditionally writes `public_ref`, not live at all (Finding #2).

### `order_items`
- **LIVE CREATION:** CONFIRMED — `2026_08_02_111251_create_order_items_table.php` (`dev`), recovered exact, byte-identical to the currently-Pending `2026_12_11_111251_create_order_items_table.php` on this branch.
- **MIGRATION CHANGES:** CONFIRMED — `2026_08_03_000003_add_created_by_to_order_items_table` (Ran, file present) added `created_by`; `2026_08_12_000001_add_indexes_to_order_items_table` (Category C, recovered) added the live `order_items_item_id_index`/`order_items_department_id_index`.
- **GIT HISTORY:** CONFIRMED — same `dev` origin as `orders`.
- **CURRENT LIVE STRUCTURE:** CONFIRMED — 24 columns (§6), including the unexplained `weight_grams` (Category I).
- **CURRENT APPLICATION EXPECTATION:** CONFIRMED — `OrderItem::$fillable` matches live almost exactly; `weight_grams` is not fillable and not referenced anywhere (dead column, INFERRED reason: reserved for a weight-based pricing feature never wired up).

### `customers`
- **LIVE CREATION:** CONFIRMED — `2026_06_02_000004_create_customers_table.php`, present on disk, Ran, matches live exactly (17 columns, incl. `account_id` FK).
- **MIGRATION CHANGES:** CONFIRMED — only `2026_07_11_000002_add_loyalty_points_to_customers_table` (Ran, present) has actually applied since creation. Everything else the model expects (`title, mobile, website, city, country, category, currency, risk_level, payment_terms, credit_days, opening_balance, is_opening_balance_posted, notes, gps_link, salesperson_id, external_ref`) is defined only in **Pending** migrations (§7 Category D/G).
- **GIT HISTORY:** No divergence found — this is the one core table where the on-disk migration history genuinely matches what ran, cleanly.
- **CURRENT LIVE STRUCTURE:** CONFIRMED — 17 columns (§6), 0 rows.
- **CURRENT APPLICATION EXPECTATION:** CONFIRMED — `Customer::$fillable` lists 25 fields, 16 of which don't exist; `booted()` unconditionally writes `external_ref` (Finding #2); relations `phones()`/`primaryPhone()` target the nonexistent `customer_phones` table.

### `idempotency_records`
- **LIVE CREATION:** CONFIRMED — embedded inside `2027_01_02_000001_complete_call_center_delivery_workflow.php` (`dev`, recovered exact), **not** the similarly-named on-disk Pending `2026_08_02_000003_create_idempotency_records_table.php`, which defines an incompatible shape.
- **CURRENT LIVE STRUCTURE:** CONFIRMED — 11 columns, no `status`, `key char(36)`, UNIQUE `(scope,key)` (§6).
- **CURRENT APPLICATION EXPECTATION:** CONFIRMED — `IdempotencyRecord::$fillable` includes `status`; every write in `IdempotencyService` sets it → fails at the SQL layer. The DB-level unique constraint still protects concurrency even though the write fails (see §14).

### `order_customer_experiences`
- **LIVE CREATION:** CONFIRMED — embedded inside `2026_07_15_160000_add_quality_and_urgency_to_orders.php` (`dev`, recovered exact). Prior audit's "no migration, no git trace" claim is refuted.
- **CURRENT LIVE STRUCTURE:** CONFIRMED — 11 columns, perfect match to the recovered migration.
- **CURRENT APPLICATION EXPECTATION:** CONFIRMED — `OrderCustomerExperience` model exists, schema-correct, but **zero controllers/routes reference it**. See §16.

### `branches` / `branche`
- **LIVE CREATION:** CONFIRMED both — `2026_04_08_170300_create_branches_table.php` and `2026_04_09_153328_create_branche_table.php` (one day apart, same author, `branche` added in the same commit as the real `Branch` model/controller).
- **CURRENT LIVE STRUCTURE:** CONFIRMED — `branches` 13 columns, referenced by 18+ FKs; `branche` 16 columns, self-referencing `parent_id`, 0 rows, zero code references.
- **CURRENT APPLICATION EXPECTATION:** CONFIRMED — no `Branche` model exists at all; `branche` is pure dead schema.

---

## 9. Model vs Database Audit

| Model | Field/Relation | Expected | Actual DB | Status |
|---|---|---|---|---|
| Customer | 16 `$fillable` fields (title, mobile, website, city, country, category, currency, risk_level, payment_terms, credit_days, opening_balance, is_opening_balance_posted, notes, gps_link, salesperson_id) | Columns on customers | None exist | **BROKEN** |
| Customer | `booted()` sets `external_ref` unconditionally | external_ref column | Missing | **BROKEN** — blocks all creation |
| Customer | `account_id` | Not referenced by model at all | Column exists, FK live, 0 code references anywhere | **Orphaned** — dead in code, alive in DB, drop is an in-progress intentional deprecation (Pending migration comment confirms) |
| Customer | `phones()`, `primaryPhone()` | customer_phones table | Table does not exist | **BROKEN** |
| Customer | `scopeByRiskLevel()` | risk_level column | Missing | **BROKEN** |
| Customer | `getBalanceAttribute()` | SubledgerService, live entries columns | Correct | **SAFE** |
| Order | `booted()` sets `public_ref` unconditionally | public_ref column | Missing | **BROKEN** — blocks all order creation (new finding, larger blast radius than Customer's equivalent bug) |
| Order | `$fillable`: `payment_policy, kitchen_release_status, kitchen_released_at, kitchen_released_by` | Columns on orders | None exist (Pending migration) | **BROKEN / SCHEMA DEPENDENCY** |
| Order | `total_amount`, `balance_amount`, `change_amount`, `phone`, `customer_mobile`, `sub_status`, `barcode`, and 10+ others | Live columns | Exist, but zero references anywhere in `app/` | **Dead columns** |
| OrderItem | `$fillable` | order_items columns | Matches, except `weight_grams` unreferenced | **SAFE**, one dead column |
| CustomerPhone | Full model (fillable, SoftDeletes, is_primary) | customer_phones table | Does not exist | **BROKEN** — wired into 6+ call sites |
| CustomerAddress | All fillable/casts | customer_addresses table | Exact match | **SAFE** |
| CustomerComplaint | `resolution` fillable | resolution column | Does not exist (only `resolution_notes`/`resolution_result` do) | **CONDITIONALLY BROKEN** — currently dormant, no controller sets it |
| CustomerComplaint | `subject`, `title` both fillable | Both exist live | Match | **SAFE structurally**, see §16 for which is actually in use |
| CustomerComplaint | `branch_id` | bigint FK expected (matches customers.branch_id pattern) | `varchar(255)`, no FK | **Type mismatch** — foot-gun, not yet triggered |
| CustomerNote, CustomerOccasion, ComplaintFollowup | All fillable/casts | Respective tables | Exact match | **SAFE** |
| OrderCustomerExperience | Full model | order_customer_experiences table | Exact match, live | **Structurally SAFE, functionally dead** — no code references it |
| OrderFeedback | `$table = 'order_feedback'` | order_feedback table | Does not exist | **BROKEN** — and actively used by `OrderFeedbackController`, unlike OrderCustomerExperience |
| Branch | All fillable | branches table | Exact match | **SAFE**, one dead column (`static_ip`) |
| (no Branche model) | — | branche table | Exists, 0 rows, unused | **Orphaned table, no model** |
| IdempotencyRecord | `status` fillable | status column | Does not exist live | **BROKEN** |
| IntegrationOutbox | Full model | integration_outbox table | Does not exist | **BROKEN** |
| Invoice | `$fillable` | invoices table | Matches, one dead column (`payment_method_id`, used by `SalesInvoice` not `Invoice`) | **SAFE** |
| Payment, Entry, Transaction, Account, CallTicket | All fillable/casts | Respective tables | Exact match | **SAFE** |

---

## 10. Service Dependency Audit

Every method tagged per the required scheme: SAFE / BROKEN / CONDITIONALLY BROKEN / SILENTLY WRONG / DUPLICATED LOGIC / FINANCIAL RISK / SCHEMA DEPENDENCY.

| Service::method | Chain | Tags |
|---|---|---|
| `CustomerIdentityService::create()` | INPUT → force-defaults nonexistent columns → `Customer::create()` (external_ref hook) → `customer_phones` (missing) | **BROKEN** (three independent failure points stacked) |
| `CustomerIdentityService::update()` | Same path, narrower trigger | **CONDITIONALLY BROKEN** |
| `CustomerIdentityService::findByPhone()` | `whereHas('phones', ...)` | **BROKEN** unconditionally |
| `Customer360QueryService::directory()` | Unconditional `with(['primaryPhone'])` eager-load | **BROKEN** unconditionally |
| `Customer360QueryService::profile()` | Unconditional `load(['phones'])` | **BROKEN** unconditionally |
| `Customer360QueryService::financial()` | Reads `payment_terms`/`credit_days` off Customer | **SILENTLY WRONG** (returns null, no exception) + delegates balance/aging correctly (**SAFE** for that part) |
| `CrmCustomerAccessService::visibleCustomers()`/`authorize()` | Only touches `branch_id` | **SAFE** |
| `CustomerAccountingService::recordInvoice/recordPayment/recordCreditNote/recordDebitNote` | Live `entries`/`transactions` columns only | Schema-**SAFE**, but **FINANCIAL RISK** — zero duplicate-posting guard on any of the four |
| `CustomerAccountingService::getBalance/getAging/getStatement` | Live columns, delegates to SubledgerService | **SAFE**, minor **DUPLICATED LOGIC** overlap with `Customer::getBalanceAttribute()` (two independent call sites, no shared cache) |
| `AccountingService::createJournalEntryForInvoice()` | Schema-correct entries/transactions writes | Schema-**SAFE**, **FINANCIAL RISK** — the existing-transaction guard runs *before* `DB::transaction()` begins, a TOCTOU race under concurrency |
| `SalesInvoiceJournalService::postSalesInvoice()` | Schema-correct against live `sales_invoices` | Schema-**SAFE**, **FINANCIAL RISK** — no idempotency guard at all, and no `lockForUpdate()` on the invoice row (status-check-only guard has a real, if narrow, concurrent-double-submit window) |
| `SalesInvoiceJournalService::reverseTransaction()` | Creates offsetting entries, never mutates originals | **SAFE** design; guarded against re-reversal via status check |
| `SubledgerService` (all public methods) | Only live `entries`/`accounts`/`transactions` columns | **SAFE** across the board — cleanest service in the audit |
| `CallCenterOrderCreationService::create()` | `CustomerIdentityService::create()` → `Order::create()` (public_ref hook) → `IntegrationOutboxWriter::record()` | **BROKEN** — three independent unconditional failure points in one transaction, cannot complete a single successful call today |
| `CustomerResolutionService::resolve()` | Selects `mobile`/`category`/`city` (none exist), `orWhereHas('phones', ...)` (missing table) | **BROKEN** unconditionally — the call center's core phone-lookup-before-ticket flow |
| `IntegrationOutboxWriter::record()` | `IntegrationOutbox::create()` | **BROKEN** unconditionally — **SCHEMA DEPENDENCY** on the Pending integration-foundation migration |
| `IdempotencyService::lockOrCreate/execute/markFinancialCommitted` | Writes `status` (missing column) | **BROKEN** at the write layer, but the underlying **DB unique constraint on (scope,key) still protects concurrency** even while the ORM write itself fails — this nuance matters for §14 |
| `CallCenterOrderExecutionService::debitEntityAccountAndRelease()`/`confirmBankTransferAndRelease()` | `IdempotencyService` (broken write, but constraint-protected) + row locks on Order and the debited entity + a unique reference-number check on `PaymentConfirmation` | Best-guarded chain in the whole audit — see §14 |

---

## 11. CRM Audit

Full route inventory, permission requirements, and per-endpoint runtime outcome for `/customers/*` and `/crm/*`.

| Endpoint | Permission-layer outcome today | Underlying outcome if permission were fixed |
|---|---|---|
| `GET /customers` | 403 | 200 — schema-safe, uses a hand-rolled Entry/Account balance join (works, but see §14 duplicated-logic note) |
| `POST /customers` (create) | 403 | **500** — forced defaults on nonexistent columns, then the `external_ref` hook |
| `PUT /customers/{id}` | 403 | **500 if payload includes any of the 16 missing fields**, otherwise conditionally OK |
| `GET /customers/{id}` | 403 | 200 |
| `GET /customers/aging-report`, `/collection-report` | 403 | 200, but **silently company-wide** — no branch scoping applied at all |
| `POST /customers/{id}/invoice|receipt|credit-note|debit-note` | 403 | 200, schema-safe, but **zero idempotency guard** (§14) |
| `GET /crm/dashboard` | 403 | 200 — schema-safe |
| `GET /crm/customers`, `/crm/customers/{id}`, `/overview` | 403 (blocked earlier by the group-level `crm.access` gate) | **500** — unconditional `primaryPhone`/`phones` eager-load against the missing `customer_phones` table |
| `GET /crm/customers/{id}/orders`, `/addresses`, `/complaints`, `/notes`, `/occasions` | 403 | 200 — all underlying tables/columns live and correct |
| `GET /crm/customers/{id}/financial-summary` | 403 | 200, but returns `payment_terms`/`credit_days` as `null` (silently wrong, not a crash) |
| `GET /crm/customers/{id}/statement`, `/aging` | 403 | 200 — statement is branch-scoped for non-global users, **aging is not** |

**Permissions verified live:** the `permissions` table has 40 rows, **zero** starting with `crm.`. All 11 distinct `crm.*` strings referenced by routes/controllers are created only by two Pending migrations. `model_has_permissions` (direct grants) is empty — all access is role-derived, and no role holds any `crm.*` permission because none exist to grant.

**Runtime trace, not assumed:** `PermissionMiddleware::handle()` → `$user->canAny()` → Laravel `Gate::before` → Spatie's `checkPermissionTo()` catches `PermissionDoesNotExist` internally, returns `false` → Gate's own ability resolution has nothing registered for an unknown permission name, falls through to an empty closure → `Gate::inspect()` denies → `UnauthorizedException` (hardcoded HTTP 403 in its constructor). **Confirmed definitively: a clean, uniform 403 for every user, including super-admin, with no possibility of a 500 from this specific mechanism.**

**A `/crm/*`-adjacent bug independent of permissions entirely:** `CustomerController` (the older/legacy controller) is imported in `routes/api.php` but wired to zero routes — dead import.

---

## 12. Call Center Audit

`/call-center/*` is gated by a compound `role_or_permission:call-center|super-admin|accountant|branch-manager|access-call-center-interface|manage-call-center` middleware — **this gate is currently satisfiable** (the `call-center` role holds real, existing legacy permissions), so call-center endpoints are **not** blocked the way `/crm/*` is. This makes their independent breakage more consequential — they're reachable today.

| Endpoint | Outcome | Cause |
|---|---|---|
| `GET customers/resolve-by-phone` | **500, confirmed** | `CustomerResolutionService::resolve()` — selects `mobile`/`category`/`city` (missing) and queries the missing `customer_phones` table. This is the core "look up caller before opening a ticket" flow. |
| `POST customers` (storeCustomer), `POST customers/quick-create` | **500** | Funnels through `Customer::create()` — same missing-column and `external_ref` failures as everywhere else |
| `GET customers/directory` | **Conditional 500** — fails whenever a caller supplies the `q` search parameter (unconditionally reachable `orWhere('mobile', ...)`) or a `city` filter; base listing without those is schema-safe | Missing `mobile`/`city` columns |
| `PATCH customer-addresses/{address}`, `POST .../use` | **500, new finding, unrelated to schema or permissions** | `CallCenterController.php` never imports `App\Models\CustomerAddress` — the bare type-hint resolves to a nonexistent class in the controller's own namespace, and Laravel's `ResolvesRouteDependencies::transformDependency()` throws an uncaught `ReflectionException` while trying to inject it, before the controller body ever runs |
| `PATCH/DELETE customer-occasions/{occasion}` | **500, same cause** | Missing `App\Models\CustomerOccasion` import |
| `GET tickets`, `POST tickets/manual`, `.../accept`, `.../customer`, `.../order`, `.../notes`, `.../complete`, `.../workspace` | Schema-safe at the controller-import level (verified `CallTicketController.php` imports correctly, re-checked explicitly to rule out a false positive from the pattern above) | `call_tickets` table live and correct. Deeper `CallTicketService`/`CustomerWorkspaceBuilder` correctness **not fully read line-by-line — UNKNOWN** |
| `POST orders/{order}/confirm-transfer`, `/debit-entity` | Schema-safe, well-guarded (see §14) | Additionally gated by `Gate::authorize('execute-call-center-payment', $order)` — **whether this ability is actually registered in a Policy/Gate::define was not verified — UNKNOWN**; if unregistered, the same fall-through-to-deny mechanics from §11 would apply (403, not 500), by analogy but not independently traced for this specific ability |
| `GET customers/{id}/profile`, `/full-profile`, `/orders`, `/favorites`, `/alerts`, `/ordering-insights` | **Likely broken, not individually confirmed line-by-line — INFERRED risk** | Given the pervasive `city`/`mobile`/`category`/`customer_phones` dependency shown everywhere else in `CallCenterService`, several of these are plausible additional failures, but each was not traced method-by-method in this pass |

---

## 13. Permissions Audit

Covered in depth in §11. Summary table:

| Permission | Referenced in | Created by | Migration status | Live? |
|---|---|---|---|---|
| `crm.access` | Route group gate | add_crm_admin_permissions | Pending | No |
| `crm.dashboard.view` | CrmController::dashboard | add_crm_admin_permissions | Pending | No |
| `crm.view-customers`, `crm.create-customers`, `crm.edit-customers`, `crm.delete-customers` | Both route families | add_crm_customer_permissions | Pending | No |
| `crm.view-customer-financial`, `crm.view-customer-statement`, `crm.export-customer-statement`, `crm.manage-customer-credit` | Both route families | add_crm_customer_permissions | Pending | No |
| `crm.view-sensitive-notes` | Customer360QueryService, CrmController | add_crm_customer_permissions | Pending | No |
| `crm.customer-orders.view`, `crm.customer-addresses.view`, `crm.complaints.view`, `crm.notes.view`, `crm.occasions.view` | CrmController | add_crm_admin_permissions | Pending | No |

Live `permissions` table (40 rows, legacy/non-namespaced): `access-call-center, access-pos, access-pos-interface, add-payments, adjust-order-total, assign-delivery-drivers, close-invoices, complete-delivery-stops, create-customers, create-orders, manage-accounting, manage-branches, manage-call-center, manage-customers, manage-delivery-trips, manage-departments, manage-dining-zones, manage-employees, manage-hospitality-devices, manage-invoices, manage-items, manage-orders, manage-payments, manage-pos-registers, manage-settings, manage-suppliers, manage-users, post-journal, request-delivery-order-cancellation, review-order-cancellation, view-accounting, view-archive, view-audit-log, view-call-ticket-customer-profile, view-customers, view-menu, view-operations-dashboard, view-orders, view-reports, view-sensitive-customer-finance`. Roles: 9 users, one role each — `call-center` (×1), `super-admin` (×1), `branch-manager` (×2), `accountant` (×1), `cashier` (×2), `hospitality` (×1), `dept-staff` (×1).

---

## 14. Accounting Audit

**AR control account confirmed:** code `1120` ("ذمم العملاء المدينة / Accounts Receivable"), id 6, type asset, `meta.subledger=true, meta.entity_type=customer`. Used identically, hardcoded, across every posting path — no divergent account code found anywhere.

**Four posting chains, mapped BUSINESS EVENT → SERVICE → TRANSACTION → ENTRY → SUBLEDGER → ACCOUNT:**

| Chain | Path | Guard | Verdict |
|---|---|---|---|
| **A — POS/Order invoice** | `InvoicePaymentService` → `AccountingService::createJournalEntryForInvoice()` | `Transaction::forSource($invoice->order)->exists()` check, **before** `DB::transaction()` begins | **FINANCIAL RISK** — TOCTOU race; only guarded when `order_id` is set |
| **B — B2B SalesInvoice** | `SalesInvoiceService::approve()` → `SalesInvoiceJournalService::postSalesInvoice()` | Status-check only (`draft`/`awaiting_approval` → `awaiting_payment`), no row lock | **FINANCIAL RISK** — narrow concurrent-double-submit window, not reproduced |
| **C — Manual customer-financial endpoints** | `CustomerFinancialController` → `CustomerAccountingService::{recordInvoice,recordPayment,recordCreditNote,recordDebitNote}` | **None whatsoever** | **FINANCIAL RISK, confirmed unconditional** — an ordinary sequential duplicate API call (not even a race) posts twice |
| **D — Call-center order execution** | `CallCenterOrderExecutionService::debitEntityAccountAndRelease()`/`confirmBankTransferAndRelease()` | `IdempotencyService::lockOrCreate()` backed by a **real DB UNIQUE constraint on `idempotency_records(scope,key)`**, plus `lockForUpdate()` on both the Order and the debited entity, plus a uniqueness check on `(payment_method_id, normalized_reference_number)` for bank transfers | **Genuinely safe under concurrency** — the best-guarded chain in the system, despite the underlying `IdempotencyRecord::create()` write itself failing on the missing `status` column (the DB constraint still does its job before the ORM error would even matter for a duplicate) |

**Cross-chain double-counting — the audit's core question, answered directly:** confirmed as a structural, unguarded risk. All four chains tag postings identically (`subledger_type='customer'`, `subledger_id={customer.id}`, account `1120`), but `Transaction.source_type/source_id` differs by chain and is only a **plain, non-unique index** — nothing anywhere checks whether another chain already recorded the same real-world event. Example: a call-center agent closes an order via Chain D, and a finance user separately calls `POST /customers/{id}/invoice` (Chain C) believing it's unbilled — both post, both succeed, the customer's AR balance silently doubles. **Not yet realized in live data** — `transactions`/`entries`/`customers` all have 0 rows today.

**Reversal:** both reversal implementations (`TransactionPostingService::reverse()`, `SalesInvoiceJournalService::reverseTransaction()`) correctly create offsetting entries rather than mutating originals — audit-trail-safe. Double-reversal is guarded in both (an existence check on `reversal()` in one, a status check in the other).

**Branch scoping — confirmed inconsistent on both write and read sides.** Write: Chains A/B/D tag `branch_id` from the source document; Chain C only tags it if the caller explicitly supplies it (optional on every endpoint), defaulting to `NULL`. Read: `CustomerAccountingService::getStatement()` and `CrmController::statement()` honor branch scoping; **`getAging()`/`getBalance()` never accept or apply a branch filter anywhere in the codebase** — aging and balance are always company-wide, and the legacy `/customers/*` controller never applies `CrmCustomerAccessService`'s per-customer branch check at all, unlike `/crm/*`.

**Silent-failure note:** opening-balance posting (`CustomerFinancialController::store()`) targets account code `3999`, which **does not exist live** — the posting is silently skipped whenever a new customer has `opening_balance > 0` (a data-loss bug, not a crash).

**Accounting-period enforcement is currently inert:** `accounting_periods` has 0 live rows, so `TransactionPostingService::validatePeriod()`'s "block posting to a closed period" logic never fires for any chain today.

**Open verification gap:** `TransactionController::cancel` (the generic reversal entry point, if any route exists for it) was not read in this pass — **UNKNOWN** whether it reuses `reverse()` safely.

---

## 15. Integration / Outbox Audit

`2026_08_09_000001_add_integration_identity_and_outbox_foundation.php` (Pending) is the sole source of `orders.public_ref`, `customers.external_ref`, and the `integration_outbox` table — **confirmed all three absent live.** This single un-run migration is the root cause of three independent, unconditional breakages:

1. `Customer::booted()` → every `Customer::create()` fails
2. `Order::booted()` → every `Order::create()` fails (larger blast radius, missed by the prior audit)
3. `IntegrationOutboxWriter::record()` → always fails, terminal step of `CallCenterOrderCreationService::create()`

The migration itself, if run in isolation against the current live schema, would succeed cleanly (all three targets genuinely absent, no guard needed because nothing conflicts) — the danger is not in running it, it's in everything downstream that already assumes it has run. `idempotency_records` (a related but structurally separate piece of the same "integration foundation" effort) exists live already, but via the git-recovered `2027_01_02_000001_complete_call_center_delivery_workflow.php`, not via the on-disk Pending `2026_08_02_000003_create_idempotency_records_table.php` — which would fail outright if run (table already exists) even setting aside its incompatible column shape.

---

## 16. Duplicate Concept Report

| Concept cluster | Classification | Evidence |
|---|---|---|
| `phone`/`mobile`/`customer_phone`/`customer_mobile` (customers + orders) | **CONFLICT (mid-migration, broken transition)** | Codebase is actively transitioning from single string columns toward a normalized `customer_phones` table (`PhoneNormalizer`, `syncPhones()`), but the target table doesn't exist and the interim `mobile` column doesn't exist either — neither old nor new mechanism currently works. On orders, `customer_phone` is the sole actively-used field; `phone` and `customer_mobile` are dead columns (zero app-code references, confirmed by grep). |
| `total`/`total_amount` (orders) | **LEGACY (dead column)** | `total` is used exclusively by every writing service; `total_amount` has zero references as an Order attribute anywhere in `app/`. |
| `discount_amount`/`discount_value`/`engine_discount_amount` (orders) | **INTENTIONAL** — the strongest clean-design case in this audit | `OrderPricingService::calculate()`: `discount_value` is the raw manual-discount input, `discount_amount` is the computed normalized result, `engine_discount_amount` is a separately-computed automatic discount. All three genuinely distinct, all three live, all three fillable. |
| `branches`/`branche` | **DUPLICATE (orphaned)** | `branches` used everywhere (18+ FK references, real model); `branche` has 0 rows and zero code references anywhere in `app/`. |
| `order_customer_experiences`/`order_feedback` | **CONFLICT (mid-migration, broken in opposite directions)** | `order_customer_experiences`: schema-complete and live, but zero controller/route references it. `order_feedback`: fully wired to `OrderFeedbackController`, but its table doesn't exist live. INFERRED that `order_feedback` was built to replace the other, but the replacement's migration was never deployed. |
| `subject`/`title` (customer_complaints) | **INTENTIONAL, with thin evidence** | Both live; model's `fill()` auto-copies `subject`→`title` when `title` is empty, suggesting `title` is preferred and `subject` kept for backward compatibility. No controller currently mass-assigns either — **UNKNOWN which field live traffic actually populates**; would need to inspect frontend/API payloads or query `WHERE subject IS NOT NULL AND title IS NULL` to see if the fallback is firing. |
| `resolution`/`resolution_notes`/`resolution_result` (customer_complaints) | **LEGACY/BROKEN transition** | `resolution` is fillable but not a live column; the model's `fill()` override treats it as a legacy alias copied into `resolution_notes` — but unlike `subject`/`title`, the alias source was never actually a live column, or was dropped without the model being updated. Currently dormant (no caller sets it). |
| `account_id`/subledger (`subledger_type`/`subledger_id`) on customers | **LEGACY, confirmed in-flight deprecation** | `Customer::getBalanceAttribute()` exclusively uses the subledger path; the Pending `update_customers_table` migration explicitly comments `// Remove account_id - we use subledger only` and drops it. Unambiguous, confirmed intentional — just not yet executed. |

---

## 17. Fresh Database Reproducibility

Static trace only — `php artisan migrate` was **not** executed. Tracing all 105 `.php` files in filename order (the extensionless draft file is excluded — Laravel's migrator globs `*.php` only, so it's invisible to Artisan regardless) as if starting from an empty database.

**Points of failure/divergence found:**
1. `2026_07_03_000001_create_orders_table.php` would succeed on a truly empty DB (nothing exists yet to collide with) — but produces a materially wrong shape: 18 columns, 7-value lowercase status enum with no `pending_payment` value, `discount_type` as a rigid enum instead of live's free varchar.
2. The ~15 Category-C migrations that actually built the live 82-column `orders` table (§8) are not on disk on this branch and would **not** run in a fresh-migrate scenario, since Laravel only executes files it can see.
3. `order_items` is the one core table that **would** reproduce almost correctly on a fresh run — its Pending file is byte-identical to what actually ran (§5), differing only by the later-added `created_by` (which does have a surviving, present migration) and the unexplained `weight_grams` (Category I, orphaned everywhere).
4. Four Pending "create" migrations (`orders`, `order_items`, `call_tickets`, `idempotency_records`) only avoid a "table already exists" crash *in the fresh-DB scenario* because nothing has created those tables yet at that point in the sequence — against the **current, live** database, all four would crash immediately (§7 Category E/H).
5. Even a fully successful fresh run would still land on the exact live CHECK-constraint-vs-ENUM defect from Finding #1, because the migration chain that produced it (a fix that was itself incomplete) is present in the recovered Category-C set and would apply identically.
6. `accounts_table`'s `down()` is confirmed broken independent of any of this: it calls `dropUnique('accounts_entity_unique')`, an index name never created by `up()` (which creates a plain, differently-named composite index instead) — rolling back this migration throws.
7. `discount_usage_logs`'s create migration checks `Schema::hasTable('orders')` at migration time; in fresh-run filename order, `orders` doesn't exist yet when this file runs (2026-06-23, before `2026_07_03`), so its FK to `orders` is silently skipped rather than failing loudly — a soft forward-dependency workaround that produces yet another point of fresh-vs-live divergence (not independently column-diffed — **INFERRED**, flagged for follow-up).

### Can this repository currently reproduce the live database from a completely empty database via `php artisan migrate`?

**NO.**

`orders` would come out with the wrong `status` type and enum values and would be missing a substantial, currently-unenumerated subset of its 82 live columns, because the two migrations that actually built the live shape are absent from this branch's `database/migrations/` directory (recoverable from `dev`, per §5 — but not present here today). Four Pending migrations would also crash outright if pointed at the *current* live database rather than a fresh one. `order_items` and most non-`orders` tables would reproduce faithfully or near-faithfully; `orders` is the standout failure, not a repository-wide one.

---

## 18. Root Causes

**Root cause 1 — a documented but incomplete/unmerged migration-squash effort.** `database/migrations/TODO.md` records a real decision to merge incremental ALTER migrations into rewritten CREATE files and delete the ALTERs, and largely followed through — but the deletion happened on a lineage (`dev`) that never merged into `feature/customer-crm-operations`, while the *effects* of those migrations persist in the shared development database both branches point at. This branch's own migration directory was never updated to reflect what actually ran against the shared DB. **This is the single mechanical explanation for nearly every "Pending-but-actually-exists" anomaly in this audit**, and — critically, a correction from the prior pass — **it is a branch-divergence problem, not a data-loss problem.** The historical migrations are not gone; they are one `git show dev:<path>` away.

**Root cause 2 — application code was written against an intended future schema, not the schema that has actually landed on this branch's database.** `Customer`/`Order` models, `CustomerIdentityService`, `Customer360QueryService`, the CRM permission set, and `IntegrationOutboxWriter` were all built assuming several specific Pending migrations (`update_customers_table`/`add_missing_customer_columns`, `create_customer_phones_table`, `add_integration_identity_and_outbox_foundation`, the two CRM permission migrations) had already run. They have not. This is the inverse of the usual drift pattern (normally code lags a migration; here code raced ahead of migrations that were never applied).

**Root cause 3 — a live, standalone data-definition defect, unrelated to either of the above.** The `orders_status_check` CHECK constraint (Finding #1) was left behind by an incomplete fix migration (`2026_07_22_000003_fix_orders_status_check_constraint`) and never reconciled against the ENUM finalized by a later migration (`enforce_order_lifecycle`). This bug exists in the live database **right now**, independent of any git-history or Pending-migration question, and independent of which branch anything came from.

**Source-of-truth determination, evaluated explicitly per the required framework:**
- **Live database** — the most complete record of what actually happened, and now the only place several structural facts (the true `orders`/`order_items`/`idempotency_records` shapes, corrected column counts) could be verified with certainty. Treat as historical ground truth.
- **Migration history (the `migrations` table)** — reliable as a list of *names* that ran, but as shown, several of those names' effects are only reconstructable by looking outside this branch.
- **Migration files on this branch** — incomplete on their own; **now substantially completable** by pulling the recovered files from `dev` (§5), which was not true in the prior audit's assessment.
- **Git history (branch-wide, not just this branch)** — turned out to be the single most valuable source in this entire audit; recovers what the live database alone could tell you existed but not tell you *how* it was built.
- **Models/business logic** — describe an intended future schema for `customers`/`orders`, not the current one; useful as a target, not as evidence of current state.

**Recommendation:** the live database remains historical truth. But unlike the prior audit's conclusion, the path to a reconciled migration baseline should **start from the git-recovered `dev`-branch files**, not from writing a blind schema-dump-only baseline — the recovered files carry intent (guards, comments, structure) that a raw `SHOW CREATE TABLE` snapshot would lose. Application models must then be aligned to whichever schema decisions get made in §20's Phase 4-5 (particularly the `account_id` drop and the duplicate customer-column migrations), not the other way around.

---

## 19. Master Risk Matrix

| ID | Area | Object | Current DB State | Migration State | Code Expectation | Git Evidence | Problem | Severity | Confidence | Recommended Action | Before Migration? | Before CRM? | Before Prod? |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| R1 | Schema | orders.status | ENUM 5 uppercase values, live | CHECK constraint stale (fix migration incomplete) | App writes PREPARATION/OUT_FOR_DELIVERY/DELIVERED | Recovered, §8 | 3 of 5 legal enum values rejected by CHECK | **CRITICAL** | CONFIRMED | Align or drop the CHECK constraint | Y | Y | Y |
| R2 | Models | Order::booted() | public_ref missing | Created by Pending integration migration | Unconditional write on every create | Confirmed | Every Order::create() fails | **CRITICAL** | CONFIRMED | Guard the hook or run the migration first | Y | Y | Y |
| R3 | Models | Customer::booted() | external_ref missing | Same Pending migration | Unconditional write on every create | Confirmed | Every Customer::create() fails | **CRITICAL** | CONFIRMED | Same fix as R2 | Y | Y | Y |
| R4 | Migrations | create_orders_table (Pending) | orders exists, 21 rows | Different schema than what ran | N/A | Recovered, not a rename | Hard fail if migrated | **CRITICAL** | CONFIRMED | Replace with reconciled baseline using recovered dev-branch content | Y | — | Y |
| R5 | Migrations | create_order_items_table (Pending) | order_items exists, 45 rows | Byte-identical to what ran | N/A | Recovered, confirmed rename-in-disguise | Hard fail if migrated | **CRITICAL** | CONFIRMED | Mark as already-satisfied / retire, no content change needed | Y | — | Y |
| R6 | Migrations | create_idempotency_records_table (Pending) | idempotency_records exists | Wrong shape (status col, varchar sizes) vs. what ran | N/A | Recovered — true source is a different file | Hard fail if migrated | **CRITICAL** | CONFIRMED | Replace with reconciled baseline | Y | — | Y |
| R7 | Permissions | 11 crm.* permissions | Absent (0 of 40 live perms start with crm.) | 2 Pending migrations | Routes/controllers reference all 11 | N/A | Uniform 403 on /crm/* and /customers/* for everyone | **CRITICAL** | CONFIRMED (full Gate trace) | Run the two permission migrations after schema reconciliation | Y | Y | Y |
| R8 | Schema | customers 16 columns | Absent | 2 duplicate Pending migrations (1 also drops account_id) | Model fillable references all 16 | N/A | Customer create/update/search broken | **CRITICAL** | CONFIRMED | Consolidate the 2 migrations, decide account_id, run | Y | Y | Y |
| R9 | Schema | customer_phones table | Absent | 1 Pending migration | Customer::phones(), CustomerIdentityService, CrmController, CustomerResolutionService all depend on it | N/A | 4+ independent broken code paths | **CRITICAL** | CONFIRMED | Run the migration as part of the reconciled batch | Y | Y | Y |
| R10 | Code | CallCenterController imports | N/A | N/A | Missing `use App\Models\CustomerAddress` / `CustomerOccasion` | N/A | 4 endpoints throw ReflectionException before controller body runs | **HIGH** | CONFIRMED | Add the two missing imports | — | Y | Y |
| R11 | Accounting | CustomerAccountingService posting methods | 0 live transactions/entries | N/A | 4 methods with zero duplicate-posting guard | N/A | Sequential duplicate call double-posts AR | **HIGH** | CONFIRMED | Add idempotency guard before real transaction volume flows through | — | — | Y |
| R12 | Accounting | Cross-chain double-counting | 0 live transactions | N/A | 4 independent posting chains, no cross-check | N/A | Same event postable via 2+ chains | **HIGH** | CONFIRMED (structural), not yet realized in data | Design a cross-chain guard or a single canonical posting entrypoint | — | — | Y |
| R13 | Schema | idempotency_records.status | Missing live | Migration defines it, but wrong migration is Pending | IdempotencyService writes it on every call | Recovered, correct source identified | Every idempotent call-center execution write fails at ORM layer (constraint still protects concurrency) | **HIGH** | CONFIRMED | Reconcile schema so the model's expectation matches either the true recovered shape or a new migration | Y | — | Y |
| R14 | Duplicate schema | branches vs branche | Both live, branche 0 rows unused | Both Ran | Only Branch model used | Confirmed same-week duplicate | Dead orphan table | **MEDIUM** | CONFIRMED | Drop branche once confirmed 0 rows in every environment | — | — | N (cleanup) |
| R15 | Duplicate concept | order_customer_experiences vs order_feedback | Former live+dead-code, latter missing+wired | Recovered vs. Pending | OrderFeedbackController wired to the missing one | Recovered | Two competing, both currently non-functional in opposite ways | **MEDIUM** | CONFIRMED | Product decision needed on which is canonical | — | — | N |
| R16 | Type mismatch | customer_complaints.branch_id | varchar(255) live | N/A | Compared/used as if numeric elsewhere | N/A | Foot-gun, not yet triggered | **MEDIUM** | CONFIRMED | Convert to bigint FK in the reconciled batch | Y (if touching this table) | — | N |
| R17 | Silent bug | Opening balance account 3999 | Missing live | N/A | CustomerFinancialController::store() posts to it | N/A | Silent no-op, data loss for opening balances | **MEDIUM** | CONFIRMED | Create the account or fix the reference | — | — | Y |
| R18 | Column count | orders/order_items | 82 / 24 cols | N/A | Prior audit said 85 / 16; an intermediate pass of this reconciliation briefly said 62 for orders and was wrong | Re-verified twice via independent methods (`Schema::getColumnListing` + `information_schema.COLUMNS` count) | Documentation accuracy only | **INFO** | CONFIRMED | Use this document's figures going forward | — | — | — |

---

## 20. Proposed Repair Strategy

**Not implemented. Sequenced, with exact files, dependencies, risks, and validation per phase.**

### Phase 0 — Freeze
No new migrations merged into this branch. No `php artisan migrate*` against any shared database. No schema/model/permission edits. *(Already the state at the time of this audit — no drift occurred during the investigation.)*

### Phase 1 — Capture database baseline
Snapshot `SHOW CREATE TABLE` for all 35 tables audited in §6 (and the remainder at existence-level) into a version-controlled file. This is the historical-truth anchor everything else gets checked against. *Validation:* diff the captured baseline against a fresh `information_schema` pull before proceeding — must be identical (no live writes should occur between capture and use).

### Phase 2 — Formally pull the recovered migration history
For each of the 21 Category-C migrations (§7), commit the exact recovered content from `dev` (via `git show dev:database/migrations/<name>.php`) into a clearly-labeled historical reference location (e.g. `database/migrations/_recovered_dev/`) — **not** directly into the active migration path yet. This preserves the git-archaeology work as a durable artifact rather than something that has to be re-derived if this audit is revisited. *Risk:* none — this is a pure read/copy operation. *Validation:* `diff` each recovered file against a fresh `git show` re-pull to confirm no transcription error.

### Phase 3 — Classify and resolve the 20 Pending migrations individually
Using §7's categories:
- **Discard/replace** (Category E/H): `create_orders_table`, `create_order_items_table`, `create_idempotency_records_table`, `create_call_tickets_table` — none should ever run as-written against the live DB. `create_order_items_table` needs no content change (it's already correct, per §5) — just needs to be excluded from the run path or marked satisfied. `create_orders_table` and `create_idempotency_records_table` need their content replaced with the Phase-1 baseline or the Phase-2 recovered originals.
- **Consolidate** (Category G): merge `update_customers_table` and `add_missing_customer_columns` into one migration — **requires an explicit decision on `account_id`** (drop, per the confirmed-dead-in-code finding in §9, but this needs sign-off since it's a live FK-constrained column, not a unilateral call).
- **Keep as-is** (Category D): `create_customer_phones_table`, `create_call_center_registers_table`, `create_order_feedback_table`, `create_employee_break_sessions_table`, `add_title_to_customers_table`, `add_integration_identity_and_outbox_foundation`, both CRM permission migrations, `add_call_center_payment_execution_fields_to_orders_table`, `ensure_all_order_columns_from_all_earlier_migrations`, `add_mixed_call_center_payment_policy`.
- **Fix independently, outside migration reconciliation:** the `orders_status_check` CHECK constraint (Finding #1) — this can and should be addressed as its own tiny, low-risk migration once schema work resumes, since it doesn't depend on any of the above.
*Risk:* Medium — this phase involves judgment calls, not mechanical ones. *Validation:* every decision in this phase should be recorded with its rationale (this document is the starting point) before Phase 4 begins.

### Phase 4 — Create the reconciled migration baseline
Write new migration(s) reflecting the Phase 1 baseline + Phase 3 decisions, replacing the four broken Category E/H files and the Category G duplicate. *Dependencies:* Phases 1-3 complete. *Risk:* High if rushed — this is the step that actually changes what `php artisan migrate` would do. *Rollback:* keep the old (broken) files in a `_deprecated/` folder rather than deleting, so `down()` paths and history remain inspectable.

### Phase 5 — Repair schema/code mismatches
- `app/Models/Customer.php`, `app/Models/Order.php`: guard or sequence the `booted()` hooks so they don't fire until the integration migration has actually run (or make them conditional on `Schema::hasColumn()`).
- `app/Http/Controllers/Api/CallCenterController.php`: add the two missing `use` statements (R10) — this one is a pure bug fix, no schema dependency, could technically happen independently and earlier if the team wants a quick win.
- `app/Services/CustomerIdentityService.php`: remove forced defaults on columns that don't exist yet, or sequence against Phase 4's completion.
- `app/Services/Crm/Customer360QueryService.php`: guard the unconditional `phones`/`primaryPhone` eager-loads.
- `orders_status_check`: align the CHECK constraint to the live ENUM values, or drop it in favor of relying solely on the ENUM (simpler, since MySQL enums already enforce valid values).
*Dependencies:* Phase 4 for the schema-dependent items; R10/R1 are independent and can move first if desired.

### Phase 6 — Repair permissions
Run the two CRM permission migrations (idempotent, low risk) **after** Phase 4/5, so that once `/crm/*` and `/customers/*` become reachable, the endpoints behind them actually work rather than trading a 403 for a 500.

### Phase 7 — Repair CRM/call-center dependencies
Address the accounting idempotency gap (R11: add a guard to `CustomerAccountingService`'s four posting methods) and the cross-chain double-counting risk (R12: decide on a single canonical posting entrypoint or a cross-chain check) — this can be scheduled independently of the schema work, but must land before real transaction volume flows through Chain C.

### Phase 8 — Validate accounting
Confirm `account_id` drop has no remaining dependents anywhere (it doesn't, per §9, but re-verify post-Phase-4 since the codebase will have changed). Fix the missing account `3999` (R17). Decide branch-scoping requirements for `getAging()`/`getBalance()`.

### Phase 9 — Test on a cloned database
Run the full reconciled migration set against a copy of the live database, not the live one itself. Verify the Phase-1 baseline still matches post-migration. Run the application test suite, plus manual smoke tests for `Customer::create()`, `Order::create()`, every `/crm/*` endpoint, and the four call-center endpoints from R10.

### Phase 10 — Apply to the real database
Only after Phase 9 passes cleanly. Given live `customers`/`transactions`/`entries` currently have 0 rows (§6), the actual data-migration risk at this step is close to zero — the risk in this whole plan is almost entirely in getting the *schema* decisions right, not in protecting existing data.

---

## 21. Files That Would Eventually Need Changes

- `database/migrations/2026_07_03_000001_create_orders_table.php` — replace content
- `database/migrations/2026_12_11_111251_create_order_items_table.php` — retire/mark-satisfied, no content change needed
- `database/migrations/2026_08_02_000003_create_idempotency_records_table.php` — replace content
- `database/migrations/2026_06_14_195000_update_customers_table.php` + `2026_07_27_000001_add_missing_customer_columns.php` — consolidate into one, explicit account_id decision required
- `database/migrations/2026_07_22_000003_fix_orders_status_check_constraint.php`'s living equivalent — new small migration to reconcile the CHECK constraint (Finding #1)
- `app/Models/Customer.php`, `app/Models/Order.php` — guard/sequence `booted()` hooks
- `app/Http/Controllers/Api/CallCenterController.php` — add missing imports
- `app/Services/CustomerIdentityService.php` — remove forced defaults on not-yet-live columns
- `app/Services/Crm/Customer360QueryService.php` — guard unconditional phone eager-loads
- `app/Services/Accounting/CustomerAccountingService.php` — add duplicate-posting guards to four methods

## 22. Files That Must NOT Be Changed Yet

- Any migration file currently marked **Ran** on this branch — rewriting applied-migration history is dangerous regardless of the branch-divergence finding
- The live database itself — no `migrate`, `migrate:fresh`, `migrate:refresh`, `migrate:rollback`, `db:wipe`, or manual DDL
- `app/Models/Customer.php`, `app/Models/Order.php`, `CrmController.php`, and the rest of the CRM/accounting service layer beyond the specific fixes listed in §21 — broader changes before the Phase 3 decisions (especially `account_id`) are made would be guessing
- Any commit history on `dev`, `call-center`, or any other branch — this audit read from them but changed nothing
- `database/migrations/TODO.md` — leave as historical record even though one of its claims is now known to be inaccurate (§5); correcting it is documentation hygiene, not urgent

## 23. Exact Next Action

**1. What is the next thing we should do?**
Fix the `orders_status_check` CHECK constraint on the live database — either align it to the current 5-value uppercase ENUM or drop it in favor of relying solely on the ENUM's own validation.

**2. Why?**
It is the only finding in this entire audit that is a **live, currently-active production defect independent of every other question in this document** — it doesn't require the migration-reconciliation decision (§20 Phase 3), the `account_id` sign-off, or the CRM permission rollout to be resolved first. It is blocking real order-status transitions (`PREPARATION`, `OUT_FOR_DELIVERY`, `DELIVERED`) right now, and every other finding in this audit can be sequenced around it without conflict.

**3. What must NOT be done before it?**
Nothing in this audit needs to happen first — but this fix itself must **not** be bundled with anything else (no touching `orders.public_ref`, no running the customer-column or permission migrations, no editing `Customer`/`Order` models in the same change). Keep it a single, isolated, reversible DDL statement so it can be validated and rolled back independently of the larger reconciliation work in §20.

**4. What evidence will confirm it is complete?**
A read-only re-run of the same probe used in Finding #1 — attempting (in a transaction that is rolled back, or against a staging copy) to write each of the five live ENUM values to `orders.status` and confirming all five succeed against the CHECK constraint, plus a fresh `SHOW CREATE TABLE orders` showing the constraint's value list matches the ENUM's value list exactly.

---

## 24. الخلاصة النهائية — إجابات مباشرة (Final Reconciliation — Direct Answers)

هذا القسم نتيجة مراجعة ثانية مستقلة لكل استنتاجات هذا التقرير مقابل المشروع الفعلي (migrations، جدول migrations في قاعدة البيانات، الـ live schema، Git، الـ Models/Services/Controllers/Routes/Permissions) — وليس نسخًا عن الجولة الأولى. تم التحقق مباشرة أثناء هذه الجولة من: حالة الـ git (نظيفة، نفس الـ commit `4f9155e`)، عدد migrations الـ Pending/Ran (20/85، بدون تغيير)، الـ CHECK constraint على `orders.status`، عدد أعمدة `customers`/`orders`/`order_items`، وأعمدة `idempotency_records`. **تم اكتشاف وتصحيح خطأ حقيقي واحد** أثناء هذه المراجعة: عدد أعمدة `orders` كان مذكورًا خطأً بـ 62 في نسخة سابقة من هذا التقرير — العدد الصحيح المؤكَّد مرتين بطريقتين مستقلتين هو **82 عمودًا**. القائمة التفصيلية للأعمدة في §6/§8 كانت صحيحة أصلًا؛ الخطأ كان فقط في الرقم الإجمالي المُلخَّص، وقد تم تصحيحه في كل مكان بهذا التقرير.

### 1. الـ Root Cause الحقيقي

ثلاثة أسباب جذرية منفصلة، وليس سببًا واحدًا:

1. **انقسام Git لم يُدمج بعد.** الـ 21 migration التي بدت "محذوفة" لم تُحذف فعليًا — هي موجودة حرفيًا على فرع `dev` غير المدموج في `feature/customer-crm-operations`. قاعدة البيانات المشتركة (`o2-restaurant-backend`) استُخدمت من عدة فروع، فتراكمت فيها تعديلات لا يعرفها هذا الفرع. هذا هو التفسير الميكانيكي لكل حالات "Pending لكنه موجود فعلًا في DB".
2. **الكود كُتب لِـ schema مستقبلي لم يُطبَّق بعد.** Models وServices (خصوصًا `Customer`, `Order`, `CustomerIdentityService`, `Customer360QueryService`, صلاحيات CRM) بُنيت على افتراض أن migrations معيّنة (أعمدة customers، `customer_phones`، صلاحيات `crm.*`، `integration_identity_and_outbox_foundation`) قد طُبّقت — وهي لم تُطبَّق.
3. **عيب حي مستقل تمامًا في قاعدة البيانات الآن.** الـ CHECK constraint على `orders.status` (`orders_status_check`) متروك من إصلاح ناقص سابقًا، ويرفض حاليًا 3 من أصل 5 قيم صحيحة (`PREPARATION`, `OUT_FOR_DELIVERY`, `DELIVERED`) — هذا لا علاقة له بالـ migrations المعلّقة إطلاقًا، وهو يعمل بشكل خاطئ الآن بغض النظر عن أي قرار آخر.

### 2. قائمة المشاكل مرتبة Critical → Low

راجع الجدول الكامل في §19 (Master Risk Matrix، 18 بندًا). ملخص حسب الشدة:

- **CRITICAL (8):** R1 orders.status CHECK، R2 Order::booted() يكتب public_ref غير موجود، R3 Customer::booted() يكتب external_ref غير موجود، R4 create_orders_table (Pending) سيفشل لو شُغّل، R5 create_order_items_table (Pending) سيفشل، R6 create_idempotency_records_table (Pending) سيفشل، R7 صلاحيات crm.* غائبة كليًا (403 لكل المستخدمين)، R8/R9 أعمدة/جدول customers الناقصة (16 عمود + جدول customer_phones).
- **HIGH (4):** R10 استيراد ناقص في CallCenterController (4 endpoints تفشل 500)، R11 CustomerAccountingService بلا حماية من التكرار المالي، R12 خطر ترحيل مزدوج بين 4 مسارات محاسبية، R13 عمود status ناقص في idempotency_records.
- **MEDIUM (4):** R14 جدول branche مكرر وميت، R15 order_customer_experiences مقابل order_feedback (تصميمان متنافسان)، R16 customer_complaints.branch_id نوعه varchar بدل bigint، R17 حساب opening balance (3999) غير موجود فيسقط الترحيل بصمت.
- **INFO (1):** R18 تصحيح عدد الأعمدة (توثيقي فقط).

### 3. Migrations التي يجب تشغيلها (بعد استيفاء الشروط في §20)

`update_customers_table` + `add_missing_customer_columns` (بعد دمجهما — انظر بند 5)، `create_customer_phones_table`، `create_call_center_registers_table`، `create_order_feedback_table`، `create_employee_break_sessions_table`، `add_title_to_customers_table`، `add_integration_identity_and_outbox_foundation`، `add_crm_customer_permissions`، `add_crm_admin_permissions`، `add_call_center_payment_execution_fields_to_orders_table`، `ensure_all_order_columns_from_all_earlier_migrations`، `add_mixed_call_center_payment_policy`. كل هذه Category D في §7 — تم التحقق أن أهدافها غير موجودة فعليًا في DB الحية وأنها آمنة (guarded) أو محايدة عند التشغيل.

### 4. Migrations التي **لا يجب** تشغيلها كما هي الآن

`2026_07_03_000001_create_orders_table.php` (سيفشل — الجدول موجود بمحتوى مختلف تمامًا)، `2026_12_11_111251_create_order_items_table.php` (سيفشل — الجدول موجود، رغم أن محتواه مطابق حرفيًا لما نفّذ فعلًا)، `2026_08_02_000003_create_idempotency_records_table.php` (سيفشل — الجدول موجود بشكل مختلف)، `2026_07_29_170000_create_call_tickets_table.php` (سيفشل — الجدول موجود). هذه الأربعة Category E/H — لا يجب تشغيلها بصيغتها الحالية أبدًا على قاعدة البيانات الحية.

### 5. Migrations التي يجب دمجها أو استبدالها

- **دمج:** `2026_06_14_195000_update_customers_table.php` مع `2026_07_27_000001_add_missing_customer_columns.php` — كلاهما يضيف نفس الأعمدة الـ14 تقريبًا على customers، والأول فقط يحذف `account_id`. **يتطلب قرارًا صريحًا** بشأن حذف `account_id` قبل الدمج (مؤكَّد أنه ميت في الكود بالكامل، لكنه FK حي في DB).
- **استبدال:** `create_orders_table.php` بمحتوى مُصالَح يعكس الـ 82 عمودًا الحية الفعلية (النسخة الأصلية التي أنشأت الجدول فعليًا مُستعادة بالكامل من فرع `dev` — راجع §5/§8).
- **استبدال:** `create_idempotency_records_table.php` بمحتوى مطابق للشكل الحي الفعلي (المصدر الحقيقي مُستعاد أيضًا من `dev`، من داخل `2027_01_02_000001_complete_call_center_delivery_workflow.php`).
- **بلا تغيير في المحتوى، فقط تصنيف جديد:** `create_order_items_table.php` — محتواه مطابق حرفيًا لما نفّذ فعلًا؛ المطلوب فقط استبعاده من مسار التشغيل أو تعليمه كمُنفَّذ مسبقًا، وليس تعديل محتواه.

### 6. الملفات البرمجية التي تحتاج تعديل لاحقًا (وليس الآن)

`app/Models/Customer.php`، `app/Models/Order.php` (حماية/تأجيل `booted()`)، `app/Http/Controllers/Api/CallCenterController.php` (إضافة `use App\Models\CustomerAddress;` و`use App\Models\CustomerOccasion;`)، `app/Services/CustomerIdentityService.php` (إزالة القيم الافتراضية المفروضة على أعمدة غير موجودة بعد)، `app/Services/Crm/Customer360QueryService.php` (حماية eager-load لعلاقة `phones`/`primaryPhone`)، `app/Services/Accounting/CustomerAccountingService.php` (إضافة حماية من الترحيل المزدوج على أربع دوال).

### 7. الترتيب الصحيح للإصلاح

Freeze (بدون تغييرات جديدة) ← التقاط baseline من الـ DB الحية ← توثيق ملفات migrations المُستعادة من `dev` رسميًا ← تصنيف وحسم الـ 20 migration المعلّقة فردًا فردًا (بما فيها قرار `account_id`) ← كتابة migrations مُصالَحة جديدة تحل محل الأربعة الفاشلة ← إصلاح الكود المرتبط (Customer/Order/CallCenterController/CustomerIdentityService/Customer360QueryService) ← تشغيل migrations الصلاحيات ← إصلاح فجوة الحماية المحاسبية ← اختبار على نسخة مستنسخة من DB ← التطبيق على DB الحقيقية. التفاصيل الكاملة لكل مرحلة (ملفات، مخاطر، أوامر تحقق) في §20.

### 8. EXACT NEXT STEP — خطوة واحدة فقط

**إصلاح الـ CHECK constraint (`orders_status_check`) على عمود `orders.status`** — إما مطابقته لقيم الـ ENUM الحية الخمس، أو حذفه بالكامل والاعتماد على الـ ENUM وحده للتحقق.

**لماذا:** هذه هي المشكلة الوحيدة في كل هذا التقرير التي هي **عيب حي في الإنتاج الآن**، ومستقلة تمامًا عن كل قرارات الـ migrations الأخرى (لا تحتاج قرار `account_id`، ولا تشغيل صلاحيات CRM، ولا أي شيء آخر). هي تمنع فعليًا نقل الطلبات إلى حالات `PREPARATION`/`OUT_FOR_DELIVERY`/`DELIVERED` الآن.

**ما يجب عدم فعله قبلها:** لا شيء من بقية هذا التقرير يُشترط أولًا — لكن هذا الإصلاح نفسه يجب أن يبقى **معزولًا تمامًا**: لا تعديل على `orders.public_ref`، ولا تشغيل migrations الأعمدة أو الصلاحيات، ولا تعديل على Models في نفس التغيير.

**دليل الاكتمال:** إعادة تشغيل نفس الفحص المستخدم في Finding #1 (قراءة فقط) — التأكد أن كل القيم الخمس الحية للـ ENUM تمر عبر الـ CHECK constraint، ثم `SHOW CREATE TABLE orders` للتأكد أن قائمة قيم الـ CHECK تطابق قائمة قيم الـ ENUM تمامًا.

---

## RECONCILIATION STATUS

**NOT READY FOR REPAIR**

The migration/schema conflicts themselves are now sufficiently understood — arguably more thoroughly than is typical for an audit of this kind, given that all 21 previously "unrecoverable" migrations were successfully recovered from git history and every live schema claim in this document was independently verified against `information_schema`. What remains before Phase 4 (§20) can begin are not information gaps but **explicit open decisions and a handful of unresolved verifications**, listed exhaustively so they can be closed out quickly:

- **Decision required:** consolidate `update_customers_table` vs. `add_missing_customer_columns`, including explicit sign-off on dropping `customers.account_id` (confirmed dead in code, but it's a live FK-constrained column and the drop should not happen on a unilateral technical call).
- **Decision required:** `order_customer_experiences` vs. `order_feedback` — which is the canonical post-order-feedback design going forward.
- **Decision required:** whether `branche` (0 rows, unused) is safe to drop in every environment, not just the one audited here.
- **Verification gap:** `TransactionController::cancel` reversal wiring — not read in this pass.
- **Verification gap:** `execute-call-center-payment` Gate ability registration — not traced to a Policy/Gate::define.
- **Verification gap:** several deeper `CallCenterService` methods (favorites, alerts, full-profile, ordering-insights) — flagged as likely broken by pattern but not individually confirmed line-by-line.

None of these block starting §20 Phase 1 (capture baseline) or Phase 2 (formalize the git-recovered history) immediately — only Phase 4 (writing the reconciled baseline) and beyond should wait on the decisions above.
