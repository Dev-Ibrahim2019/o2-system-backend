# Phase F Implementation Plan

## Status

**Planning complete — Ready for implementation.** Architecture Gate E-01 through E-10 and EA-01 through EA-06 is binding. No further architecture gate precedes F-01 unless implementation reveals a genuine blocking conflict.

## Current-Code Baseline

### Laravel — `o2-system-backend`

- `app/Models/Order.php`, `OrderItem.php`, `ProductionTicket.php`, `Invoice.php`, `Payment.php`, `PaymentConfirmation.php`, `Customer.php`, `CustomerPhone.php`, `CustomerComplaint.php`, `CallTicket.php`, `Item.php`, and `Branch.php` are the operational foundation.
- `app/Services/CallCenter/CallCenterOrderCreationService.php` creates local Call Center Orders transactionally; `CallCenterOrderExecutionService.php` already separates financial commit from kitchen release and uses row locks.
- `app/Services/Support/IdempotencyService.php` and `app/Models/IdempotencyRecord.php` already implement scope/key uniqueness, canonical payload hashing, replay of completed outcomes, and different-payload conflict. In-flight recovery, external command semantics, retention, and broader endpoint coverage are gaps.
- Reuse `app/Services/Invoice/InvoiceFromOrderService.php`, `InvoicePaymentService.php`, `app/Services/Accounting/TransactionPostingService.php`, `SubledgerService.php`, `CustomerAccountingService.php`, and `app/Services/Order/OrderConfirmationService.php`. Do not create a second accounting or production subsystem.
- `Item::branches()` and `Branch::items()` use `branch_item.price` and `branch_item.is_active`; this directly supports the core branch-price/availability projection. External refs, versions/change feed, modifiers/options evidence, and projection publishing are gaps.
- `routes/api.php` exposes Sanctum staff/POS/CRM APIs and a small public table portal. It has no purpose-built Sync Agent boundary. `app/Jobs`, `app/Events`, and `app/Listeners` do not currently exist.
- Order statuses and constraints mix lowercase and uppercase legacy values. `payment_status=processing` represents an ambiguous partial/processing condition. Existing numeric IDs and `order_number` are widely consumed.

### Admin React — `o2-company-front`

- Reuse `src/features/crm/Customer360Page.tsx`, `CustomersPage.tsx`, `tabs/OrdersTab.tsx`, `src/components/call-center/CallCenterWorkspace.tsx`, `ActiveOrdersPage.tsx`, `CustomerProfileDrawer.tsx`, `ComplaintsManagement.tsx`, and existing Call Center order/payment workflows.
- Extend `src/features/crm/api.ts`, `src/features/crm/types.ts`, `src/components/call-center/services/callCenterService.ts`, `src/services/callCenterOrderWorkflow.ts`, route definitions in `src/App.tsx`, and permissions in `src/auth/permissions.ts`.
- Numeric `customer_id`, local Order IDs, `order_number`, payment references, and hard-coded legacy status comparisons are widespread. Add opaque refs and canonical domain fields additively; preserve current operational keys during compatibility.

### Next.js — `O2-Gaza-Project`

- `lib/menu-data.ts` and `lib/branch-context.tsx` hard-code Catalog/branches; `lib/cart-context.tsx` is client-only and not authoritative.
- `app/category/[id]/page.tsx`, `app/categories/page.tsx`, and `app/reservation/[branch]/page.tsx` hand off via WhatsApp. This is not staged-order architecture.
- `lib/db/schema.ts` contains only `restaurant_ratings`; `app/actions/ratings.ts` is the only durable customer mutation found. There are no Portal Account/session/order/message/notification routes.
- Next.js remains presentation. New account pages call the independent Cloud API using server-side/BFF helpers where cookie and CSRF handling benefits; it does not own durable queues, staged-order authority, or Cloud database access.

## New Component Boundaries

### Independent Cloud Integration Service

Create a new sibling repository before cloud implementation: **`Dev-Ibrahim2019/o2-cloud-integration`**. Do not put it in Next.js or Laravel. Proposed verified-at-creation areas are `src/`, `database/`, `tests/`, `config/`, `deploy/`, and `docs/contracts/`; exact framework files are created by the selected F-01 scaffold, not assumed here.

It owns the Cloud PostgreSQL database, Portal Accounts/sessions/challenges, staged Orders/revisions, durable Cloud command queue/Inbox, Catalog and customer-safe projections, conversations, Notifications, proof metadata, command outcomes, cursors, and reconciliation evidence. It owns private encrypted object storage for Payment Proof and approved attachments. Next.js communicates over public browser/customer APIs; the Sync Agent uses separate machine-authenticated pull/ack/push/health APIs.

### Local Sync Agent

Create a separately runnable top-level **new proposed area** `sync-agent/` in `o2-system-backend` for initial co-versioning with the local Laravel contract. It is deployed beside Laravel on the branch network, has a distinct machine identity/configuration, and initiates all Cloud communication outbound.

The Agent pulls prioritized Cloud commands, durably records receipt/cursor/checkpoints, delivers commands through a narrow local Laravel integration API, sends Laravel Outbox batches to Cloud, acknowledges durable boundaries, applies retry/backoff, performs targeted reconciliation, and reports health. Its durable local store contains only agent identity, cursors, leases/attempts needed across restart, and reconciliation checkpoints; business authority remains Cloud or Laravel. A small embedded database is acceptable for agent transport state, subject to F-01 implementation validation.

## Workstreams and Dependencies

| Workstream | Priority | Purpose | Depends on |
| --- | --- | --- | --- |
| F-01 — Integration Foundation | P0 | Stable refs, Cloud/Agent foundations, databases, Laravel Outbox/command intake, mappings, statuses, idempotency, health/logging. | Architecture Gate |
| F-02 — Portal Identity & Authentication | P0/P1 | Portal Account, OTP/password/session/device/recovery, Customer Link, Limited Account and Identity Review. | F-01 contracts/security base |
| F-03 — Catalog / Pricing Synchronization | P1 | Laravel-authoritative branch Catalog/pricing/availability projection, versions, freshness and snapshots. | F-01 |
| F-04 — Website Orders | P1 | Cloud staged revisions, acceptance fence/handoff, one Laravel Order, reconciliation and tracking. | F-01, F-02 identity, F-03 commercial refs |
| F-05 — Payment & Settlement | P1/P2 | Private proof, verification, Payment/AR, Invoice timing, Settlement Clearance, refund/reversal and production gate. | F-01, F-04; uses Laravel finance |
| F-06 — Customer Portal | P1/P2 | Usable Next.js account, Catalog, order, rewards, message, Notification, support and settings journeys. | F-02, F-03, F-04; F-05 for payment path |
| F-07 — Admin CRM / Call Center | P1/P2 | Extend existing CRM for Website Order, payment/identity review, portal context, support, audit and health queues. | F-02, F-04, F-05 |
| F-08 — Reliability / Reconciliation | P0/P2 | Retry classes, replay, gaps, recovery, quarantine, conflicts and review queues. | F-01 foundation; completed across F-02–F-05 |
| F-09 — Security / Observability | P0–P2 | Permissions, sensitive access, abuse/CSRF/CORS/secrets, logs, metrics, lag alerts and health. | Starts F-01; hardens all workstreams |
| F-10 — Migration / Staging / Rollout | P2 | Backfill, compatibility, staging, validation, pilot, rollback, cutover and cleanup. | All functional/security/reliability slices |

Dependency flow: `F-01 → {F-02, F-03, F-04 foundation, F-08 foundation}`; `{F-02,F-03,F-04} → F-06`; `{F-04,F-05} → full Order flow`; `{F-02,F-04,F-05} → F-07`; all streams receive F-09 controls and converge on F-10.

## Milestones and Vertical Slices

1. **M1 — Integration Spine:** one safe probe/projection command traverses Cloud → Agent → Laravel and its durable result traverses Laravel Outbox → Agent → Cloud with stable refs, replay protection, acknowledgement, health, and tests.
2. **M2 — Portal Identity Spine:** register, verify phone, login, HttpOnly session, Customer Link/limited state, revoke/logout-all and Security Suspension without a full portal.
3. **M3 — Catalog Projection:** Laravel Catalog/branch price/availability → Outbox → Agent → Cloud → Website with version/freshness evidence.
4. **M4 — Website Order Happy Path:** authenticate → synchronized menu → staged immutable revision → Call Center exact-revision approval → one Laravel Order → Cloud tracking projection. Payment is not required for acceptance.
5. **M5 — Financial / Production Path:** private proof → Laravel verification → Payment or authorized AR → Settlement Clearance → explicit Kitchen Release → Production/tracking.
6. **M6 — Customer Experience:** profile, addresses, history/tracking, loyalty, messages, Notifications, complaints, ratings, and settings.
7. **M7 — Reliability & Rollout:** failure injection, offline/recovery, reconciliation, conflicts, monitoring, migration, staging, pilot, rollback and production rollout.

## File-Level Work Map

| Workstream | Repository | Existing files | New files/areas | DB changes | API changes | UI changes | Dependencies |
| --- | --- | --- | --- | --- | --- | --- | --- |
| F-01 | Backend + new Cloud + `sync-agent/` | `Order.php`, `Customer.php`, `IdempotencyService.php`, `routes/api.php`, `config/queue.php`, current tests | New Laravel Integration models/services/controllers/resources; new `sync-agent/`; new Cloud repo | Laravel public/external refs, mappings, Outbox, command receipts; Cloud command/inbox/cursors | Machine-auth pull/ack/push, narrow Agent→Laravel commands | Admin health later only | — |
| F-02 | Cloud + Backend + Website + Admin | `Customer.php`, `CustomerPhone.php`, `CustomerIdentityService.php`, CRM types/API, `app/layout.tsx` | Cloud identity/session modules; new Next.js `app/account/*`; Laravel link/review models/services; Admin review UI | Cloud accounts/challenges/sessions/devices; Laravel links/reviews/projection | Browser→Cloud auth; Agent→Laravel link evaluation; Admin→Laravel review | Login/register/recovery/account; identity queue | F-01 |
| F-03 | Backend + Cloud + Website | `Item.php`, `Branch.php`, `branch_item`, `ItemResource.php`, `lib/menu-data.ts`, menu/category pages | Catalog publisher/projection modules; Website Cloud client | Laravel catalog refs/version/outbox; Cloud catalog projection | Laravel event feed via Agent; Browser→Cloud Catalog reads | Replace hard-coded menu incrementally; freshness state | F-01 |
| F-04 | Cloud + Backend + Website + Admin | Call Center Order services/controllers/resources; Website cart/category pages; Call Center workspace | Staged-order/revision/handoff modules; acceptance service/controller; review/tracking UI | Cloud Orders/revisions/outcomes; Laravel mapping/public refs/receipt | Browser staged-order APIs; Agent acceptance/reconcile; Admin review via Laravel | Checkout/tracking and existing Call Center extension | F-01–F-03 |
| F-05 | Cloud + Backend + Website + Admin | `Payment.php`, `PaymentConfirmation.php`, Invoice services, accounting services, `OrderConfirmationService.php`, payment UI | Proof/verification/clearance/review modules | Cloud proof revisions/private refs; Laravel settlement/audit/allocation additions | Proof upload; verification, reconciliation, refund/reversal contracts | Customer proof/status; Admin finance review | F-04 |
| F-06 | Website + Cloud | Current layout/navbar/rating/menu/cart | New proposed `app/account/*`, Cloud API client/session helpers | Primarily Cloud-owned data from F-02–F-05 | Browser/customer Cloud contracts | Complete account workflows, minimal decorative work | F-02–F-05 |
| F-07 | Admin + Backend | CRM/Call Center components, `src/features/crm/*`, `App.tsx`, permissions; CRM/Call Center controllers | Review queue services/resources/components/routes | Laravel projections/audit/assignment fields as proven necessary | Admin Laravel endpoints only | Integrated Website/payment/identity/review views | F-02,F-04,F-05 |
| F-08 | All service repos | `IdempotencyService.php`, idempotency tests | Reconciliation jobs/services, Agent recovery, Cloud quarantine/replay | attempts, checkpoints, gaps, review/quarantine outcomes | query/reconcile/replay controls | Admin review/recovery visibility | F-01 onward |
| F-09 | All | Sanctum, policies, permission seeders, Axios clients, logging config | machine-auth middleware, audit/metrics/security modules | credentials/audit evidence where required | rate limits, CSRF/CORS, scoped proof access, health/metrics | permission gates and safe error states | all |
| F-10 | All | legacy migrations/status consumers/deployment config | backfill/verification commands, staging/pilot/runbooks | additive backfills, compatibility then cleanup | version compatibility/cutover | compatibility cleanup after verified rollout | all |

## Data and Schema Families

- **Laravel:** opaque public/external Order and Customer refs; typed mappings; Integration Outbox; inbound command receipts/outcomes; canonical domain status fields added alongside legacy state; Customer Link/review authority; Catalog versions; settlement/AR/audit additions proven by F-05.
- **Cloud PostgreSQL:** Portal Accounts, credentials metadata, sessions/devices, OTP challenges, staged Orders/revisions/commercial snapshots, Inbox/command queue/outcomes, Catalog projection, Customer/link projection, conversations/messages, Notifications, proof metadata/revisions, sync cursors/checkpoints, review/reconciliation and policy evidence.
- **Sync Agent:** only durable transport cursors, leases/attempts, agent identity, delivery checkpoints, and reconciliation checkpoints required to survive restart. No Customer, Order, Payment, Catalog, or accounting authority.

## Contract Map

| Boundary | Planned contracts |
| --- | --- |
| Browser → Cloud | Portal register/verify/login/logout/session/recovery; Catalog reads; staged Order draft/revision/submit/cancel/status; proof upload initiation/completion; account/profile/address/order/rewards/message/Notification/support APIs. |
| Sync Agent → Cloud | machine authenticate/rotate; prioritized command pull; durable-intake and outcome ACK; Laravel Outbox batch push; cursor/backfill/reconcile; agent health. |
| Sync Agent → Laravel | narrow local integration health; command intake/query by Command/Handoff/Aggregate refs; exact-revision Order acceptance; identity evaluation/link; Payment verification; release/cancel/refund/reconcile; Outbox lease/read/ack. |
| Admin React → Laravel | Website Order/revision review; Payment/Finance Review; Identity Review; Customer360 portal context; message/complaint/loyalty projections; sync/review/health visibility. |
| Laravel internal | acceptance → pricing validation → Order mapping/outbox; financial commitment → Invoice/Payment or AR → settlement evaluation; clearance + eligibility → explicit release; all under existing accounting/order services and idempotent transaction boundaries. |

`Browser → Local Laravel` is prohibited. Cloud never calls local Laravel inbound.

## Data Ownership Matrix

| Domain | Authority | Projection |
| --- | --- | --- |
| Portal Account | Cloud | Minimal Laravel/Admin context |
| Customer | Laravel | Minimal Cloud portal-safe projection |
| Identity Link | Laravel/CRM | Cloud link/limited outcome |
| Catalog | Laravel | Cloud/Website read model |
| Pricing | Laravel | Cloud branch-price projection |
| Staged Website Order | Cloud before acceptance | Laravel review projection |
| Accepted Order | Laravel | Cloud customer tracking projection |
| Payment Proof | Cloud private evidence store | Laravel authorized metadata/access reference |
| Payment | Laravel | Cloud customer-safe status |
| Invoice | Laravel | Cloud customer-safe summary |
| AR | Laravel | Minimal authorized portal/Admin summary |
| Production | Laravel | Cloud tracking projection |
| Conversation | Cloud public history | Laravel operational projection; Internal Notes remain Laravel-only |
| Notification | Cloud customer store | Minimal Laravel operational projection |

## Compatibility and Migration Strategy

Use additive schema and response fields first. Existing POS, Call Center, accounting, production, delivery, and CRM continue using numeric IDs, `order_number`, and legacy statuses while opaque refs/domain states are populated. Introduce compatibility readers/mappers, backfill in bounded batches, verify counts/uniqueness/reconciliation, switch individual consumers, then stop legacy writes and remove aliases only after telemetry and rollback windows pass. Dual-write is allowed only at a single authoritative transaction boundary; never dual-authority.

### Status migration

- Inventory deployed constraints/data before changes. `confirmed` is dangerous because it may mean accepted and released; split using tickets and `kitchen_release_status` evidence.
- `PREPARATION` and `preparing` require source/order-type analysis before mapping to production `preparing`; do not case-normalize blindly.
- `cancelled`, `CANCELLED`, and `canceled` map to canonical `cancelled` only after consumers and constraints accept it.
- `OUT_FOR_DELIVERY` maps to Delivery `out_for_delivery`, never generic Order progress.
- `processing` is dangerous because current Call Center logic uses it for partial financial collection; derive Settlement Clearance from Invoice/Payment facts rather than rename it.

### Identifier migration

Backfill immutable Public Order Refs for existing Laravel Orders with a unique indexed nullable-first column, then make generation mandatory for new POS/Call Center Orders. Website creates its Public Order Ref at Cloud origin and Laravel preserves it at handoff. Add Customer External Ref only where cross-system projection requires it; Cloud owns Portal Account Ref, while Laravel owns Identity Link Ref. PKs remain internal and `order_number` remains display-only.

### Idempotency rollout

First cover Website Order acceptance, then Payment Verification, Customer create/link, Kitchen Release, cancellation, and refund/reversal. Reuse and harden `IdempotencyService`/`idempotency_records`: expand resource identity beyond integer-only assumptions, define in-flight/unknown durable outcomes, and preserve scope/key/hash conflict semantics. Reuse Payment reference uniqueness and transaction/row-lock safeguards; do not replace them with transport keys.

### Catalog and finance

Current Item/Branch/`branch_item` supports item activation, branch availability, and branch price. Gaps are stable external refs, version/change evidence, modifiers/options if required, delivery pricing projection, and Cloud read model. Reuse the existing Invoice, Payment, `InvoiceFromOrderService`, `InvoicePaymentService`, `TransactionPostingService`, `SubledgerService`, `CustomerAccountingService`, and `OrderConfirmationService`. Refactor automatic invoice/payment/release coupling only at tested boundaries; duplicating journals, Payments, AR, or production tickets is prohibited.

## Test Strategy

- **Unit:** reference generation/parsing, canonical payload hash, status mapping, price snapshot, settlement evaluation, retry classification.
- **Integration:** Laravel acceptance transaction/mapping/outbox; existing finance services; Cloud queues/sessions/revisions; Agent durable cursor/ACK.
- **Contract:** versioned Browser↔Cloud, Agent↔Cloud, Agent↔Laravel, Admin↔Laravel payloads and unknown-value compatibility.
- **End-to-end:** M1 spine, identity, Catalog, Website Order, financial/production, and customer/Admin projections.
- **Failure/security/migration:** duplicate acceptance and Payment verification; lost ACK after commit; same key changed payload; wrong revision; price change before versus after submission; revoked Link during session; Security Suspension; Laravel/Cloud offline; Agent recovery; partial Payment; Account/Credit settlement; cancel after Payment/release; cursor gaps/poison messages; CSRF/CORS/rate limits/authorization/private proof; backfill uniqueness, rollback, and old-client compatibility.

## First Implementation Run

### F-01A — Stable Laravel Integration Identity and Outbox Foundation

- **Repository:** `o2-system-backend` only.
- **Existing files affected:** `app/Models/Order.php`, `app/Models/Customer.php`, `app/Providers/AppServiceProvider.php` only if generation hooks require registration; existing factories/tests as needed. Do not change `routes/api.php` in F-01A.
- **New proposed files:** one additive migration for nullable unique `public_ref` on Orders, nullable unique `external_ref` on Customers, and an `integration_outbox` table; `app/Models/IntegrationOutbox.php`; typed reference generator/value support under new `app/Support/Integration/`; an Outbox writer service under new `app/Services/Integration/`; focused unit/feature tests.
- **Migrations:** additive nullable columns first; unique indexes; Outbox identity/type/aggregate ref/payload/version/availability/publication-attempt timestamps. Backfill and non-null enforcement are explicitly deferred to F-10.
- **Tests:** opaque stable format; uniqueness/retry behavior; new Order/Customer ref generation without changing `order_number`/PK; Outbox event created in the same DB transaction and rolled back with it; existing Order/Call Center/Customer tests remain green.
- **Dependencies:** approved E-10/EA-03 semantics, current Laravel database support, existing model creation paths, and a verified migration baseline. No Cloud repository is required for this first slice.
- **Compatibility/rollback:** nullable additive fields, no legacy API removal, no status changes; migration down removes only new unused structures before rollout.
- **Explicit non-goals:** no Cloud service scaffold, Sync Agent, endpoint, public ingress, status migration/backfill, Portal auth, Catalog sync, staged Order acceptance, Payment change, UI change, or production deployment.

F-01A is intentionally small. F-01B will scaffold the independent Cloud service contract and F-01C the Local Sync Agent/secure Laravel command boundary after the identity/outbox transaction tests are real.
