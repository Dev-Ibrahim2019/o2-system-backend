# ADR EA-02 — Canonical Status Dictionary

## Status

**[x] Approved — 2026-08-09**

Approved: **Option A — Domain-Specific Canonical Status Dictionaries**.

This ADR approves semantic vocabulary only. It does not approve enums, constraints, migrations, APIs, TypeScript types, UI changes, or Phase F implementation.

## Context

Cloud, Laravel, Sync Agent, Portal, and Admin need consistent machine values without pretending unrelated lifecycle facts share one universal status.

## Current-System Evidence

- Laravel `orders.status` began with lowercase `pending`, `confirmed`, `in_progress`, `ready`, `served`, `paid`, `cancelled`; later constraints add `pending_confirmation`, lowercase/uppercase `pending_payment`, and uppercase `PREPARATION`, `ASSEMBLING`, `READY_FOR_DELIVERY`, `OUT_FOR_DELIVERY`, `CANCELLATION_REQUESTED`, `DELIVERED`, `FAILED_DELIVERY`, `CANCELLED` in the same column.
- Requests/services also reference `pending_payment`; Call Center compatibility code accepts both `cancelled` and misspelled-US `canceled`. Case and spelling therefore alter behavior.
- `confirmed` is used for the operational Order while kitchen release is separately represented by `kitchen_release_status`; some execution paths set both, demonstrating why acceptance and release cannot share meaning.
- Order payment state already has distinct values: `unpaid`, `awaiting_confirmation`, `processing`, `paid`, `failed`, `refunded`. `processing` is also used to represent partial collection in current execution logic, which is not a clear canonical meaning.
- Kitchen release uses `held`, `releasing`, `released`, `release_failed`; Production Tickets use `pending`, `preparing`, `ready`, `served`, `cancelled`.
- Invoices use `draft`, `awaiting_approval`, `awaiting_payment`, `partial`, `paid`, `cancelled`; Payment Confirmations use `confirmed`/`rejected`. These reuse words with different domain meanings.
- Customer status uses lowercase `active/inactive/blocked`, while branches, departments, employees, devices, tables, and zones use uppercase values such as `ACTIVE`, `SUSPENDED`, `AVAILABLE`, and `PENDING_CONFIRMATION`.
- React contains hard-coded status unions, comparisons, colors, and Arabic labels for multiple variants, including uppercase delivery values and lowercase operational values. The current Next.js site has no durable Website Order tracking/status contract.
- Approved E-03/E-04/E-07/E-08 define message, Notification, transport, and command-outcome facts that are intentionally not one aggregate status.

## Core Problem

A generic value such as `pending`, `confirmed`, `active`, or `completed` is meaningless without its domain. Current mixed casing and overloaded Order values can falsely equate submission, acceptance, payment, release, production, delivery, authentication, or synchronization.

## Options

### Option A — Domain-Specific Canonical Dictionaries

Each authority/domain owns an explicit typed vocabulary with mappings at boundaries.

### Option B — One Global Status Enum

Reuse generic `pending/active/completed/cancelled` values everywhere.

### Option C — Current Values + Frontend Ad Hoc Mapping

Leave semantic normalization to each client.

| Criterion | Domain Dictionaries | Global Enum | Frontend Mapping |
| --- | --- | --- | --- |
| Authority clarity | Strong | High Risk | Weak |
| Cross-system consistency | Strong | Weak | High Risk |
| Payment/production safety | Strong | High Risk | Weak |
| Legacy migration control | Strong | Weak | Weak |
| API clarity | Strong | Weak | High Risk |
| Implementation simplicity | Acceptable | Strong | Strong |

## Recommended Model

Use separate, typed dictionaries. Not every dictionary requires a database column: some values are projections, transport states, or command outcomes. Aggregate state records current business condition; events and timestamps preserve what happened and when.

## Canonical Domains

| Domain | Proposed conceptual machine values | Notes |
| --- | --- | --- |
| Cloud Staged Website Order | `draft`, `submitted`, `awaiting_review`, `awaiting_payment_evidence`, `acceptance_pending`, `accepted`, `rejected`, `cancelled_before_acceptance`, `expired`, `needs_review` | Cloud-only pre-acceptance authority; keep only states required by E-05–E-08. |
| Laravel Operational Order | `pending`, `accepted`, `preparing`, `ready`, `completed`, `cancelled` | Source-neutral operational summary; delivery detail remains separate. Exact transition mapping remains Phase F. |
| Payment Proof / Verification | `no_proof`, `under_review`, `verified`, `rejected`, `needs_finance_review` | Proof uploaded/received ≠ verified. |
| Settlement Clearance | `not_cleared`, `partially_cleared`, `cleared` | Authoritative financial permission for the Order to proceed; it may result from fully verified Payment or authorized Account/Credit settlement under EA-05. Payment Verification remains separate. |
| Production Release / Work | `not_released`, `releasing`, `released`, `preparing`, `ready`, `release_failed`, `cancelled` | Explicit release is not Order acceptance. Phase F may split release and work into two typed subdomains. |
| Delivery | `not_assigned`, `assigned`, `out_for_delivery`, `delivered`, `delivery_exception`, `cancelled` | Never represents kitchen preparation. |
| Identity Link | `unlinked`, `limited`, `linked`, `unlink_pending` | Security Suspension and business restriction are separate. |
| Identity Review | `pending_review`, `in_review`, `resolved`, `needs_review` | Review workflow, not authentication. |
| Portal Account Security | `active`, `security_suspended`, `recovery_restricted` | Conceptual; EA-01 authority. |
| Portal Session | `active`, `expired`, `revoked` | Separate from account/business state. |
| Authentication Challenge | `pending`, `succeeded`, `failed`, `expired`, `consumed` | Purpose-bound challenge state. |
| Conversation / Message | Separate conversation workflow, sync acceptance, customer release, and read facts | Preserve E-03; no single Message status. |
| Notification | Separate logical lifecycle, channel attempt, display/read/action, and correction facts | Preserve E-04. |
| Sync / Retry | `pending`, `in_flight`, `retry_scheduled`, `completed`, `blocked`, `needs_review`, `quarantined` | Transport state ≠ business state. |
| Command / Conflict Outcome | `applied`, `stale_revision`, `conflict`, `no_longer_allowed`, `already_completed`, `superseded`, `needs_refresh`, `needs_review`, `needs_finance_review`, `blocked` | Normally response/outcome, not aggregate status; durable review state only where domain requires it. |

## Legacy Mapping

| Existing Value | Current Domain | Canonical Meaning | Action |
| --- | --- | --- | --- |
| `pending` | Order | Pending operational work | Keep/map after source-specific review |
| `pending_confirmation` | Table/sub-order Order | Awaiting staff action | Map to domain-specific pending/review; do not share table casing |
| `confirmed` | Order | Accepted and/or sent to production | Split: `accepted`; derive production release separately |
| `in_progress` / `PREPARATION` | Order | Production/preparation in progress | Map to production `preparing`; Order projection may be `preparing` |
| `ASSEMBLING` | Order/delivery workflow | Fulfillment assembly | Map explicitly during Phase F; do not alias blindly to kitchen preparation |
| `ready` / `READY_FOR_DELIVERY` | Order | Ready for service/delivery | Map using order type plus production/delivery domain |
| `served` / `completed` / `DELIVERED` | Order/reporting | Different completion facts | Map `DELIVERED` to delivery; `served` to dine-in completion; define Order projection intentionally |
| `paid` | Order | Payment/closure overloaded | Map through authoritative financial facts to Settlement Clearance; do not use as operational Order state automatically |
| `pending_payment` / `PENDING_PAYMENT` | Order | Settlement not cleared | Map to Settlement Clearance `not_cleared`; retire case duplicates |
| `cancelled` / `CANCELLED` / `canceled` | Multiple | Cancelled | Canonical `cancelled`; compatibility mapping then retire aliases |
| `OUT_FOR_DELIVERY` | Order | Courier en route | Map to Delivery `out_for_delivery` |
| `FAILED_DELIVERY` | Order | Delivery exception | Map to Delivery `delivery_exception` |
| `awaiting_confirmation` | Order payment | Verification pending | Map to Payment Verification `under_review` where evidence exists |
| `processing` | Order payment | Current partial/processing ambiguity | Resolve evidence; map deliberately to verification or partial clearance |
| `held` / `released` / `release_failed` | Kitchen release | Release gate state | Retain in typed production-release dictionary with `not_released` mapping for `held` |
| uppercase `ACTIVE` vs lowercase `active` | Organizational/customer domains | Domain-specific active state | Use lowercase machine values per domain; do not merge domains |

This table is migration planning, not permission to rewrite deployed data without runtime inventory and compatibility analysis.

## Naming Convention

Canonical machine values use lowercase `snake_case`. Arabic/English labels, colors, icons, and explanatory metadata are presentation. APIs expose typed machine state plus optional display metadata; stored business identity never depends on Arabic labels.

## State vs Event vs Timestamp

```text
State: preparing
Event: order_preparation_started
Timestamp: preparation_started_at
```

An event records occurrence; a state describes current condition; a timestamp is evidence. `payment_verified_at` does not turn Order into `confirmed`, and `delivered_at` does not replace a defined Delivery state.

## API / UI Rules

- Every status field is domain-qualified in schema/type/API context (`payment_verification_status`, not ambiguous `status`).
- Clients do not infer Payment, production, or delivery from Order status.
- Unknown values render safely and trigger telemetry/compatibility handling rather than silently mapping to success.
- Frontends translate canonical machine values; they do not create an independent source of truth.

## Core Invariants

```text
Order Status ≠ Payment Status.
Payment Verification ≠ Settlement Clearance.
Settlement Clearance ≠ Production State.
Production State ≠ Delivery State.
Production Status ≠ Delivery Status.
Authentication State ≠ Business Restriction.
Transport State ≠ Business State.
Command Outcome ≠ Aggregate Status.
Canonical machine value ≠ Arabic display label.
Event ≠ State.
Timestamp ≠ State.
```

## Impact on EA-03 through EA-06

| Decision | EA-02 Impact |
| --- | --- |
| EA-03 Idempotency | Replays return the same domain outcome; idempotency state remains separate from business state. |
| EA-04 Catalog/Pricing | Catalog availability/price revision states use their own vocabulary. |
| EA-05 Financial Posting | Owns clearance/posting/settlement semantics and mapping from verified evidence. |
| EA-06 Privacy/Retention | Owns status/event/timestamp history retention and customer-safe labels. |

## Risks

- Over-normalization may erase valid source/order-type differences; mitigate with explicit domain/source mappings.
- Silent rename may break APIs, reports, constraints, and clients; mitigate with inventory, compatibility versioning, telemetry, and staged migration.
- Projection status may drift from authoritative subdomains; derive/reconcile with documented precedence, never ad hoc frontend logic.
- Too many durable states can mimic events; require a business invariant and owner for every persisted state.
- Unknown legacy values may be misclassified; quarantine/review rather than guess.

## Phase F Implications

Phase F must inventory deployed values/constraints, finalize transition tables and ownership, decide persisted versus derived states, implement compatibility mappings/versioned APIs, align Laravel constraints and TypeScript types, migrate data safely, update translations, test illegal transitions, and reconcile projections. No implementation begins here.

## Architecture Review Decisions

### Decision 1

**Decision:** Approved — use domain-specific canonical status dictionaries, never one universal system-wide `status` enum.
**Rationale:** A status has meaning only inside its owning authority and lifecycle.

### Decision 2

**Decision:** Approved — Cloud Staged Website Order State and Laravel Operational Order State remain separate because authority changes at acceptance.
**Rationale:** Staged customer intent is not an operational Laravel Order.

### Decision 3

**Decision:** Approved — Order State, Payment Verification, Settlement Clearance, Production, and Delivery remain separate domains.
**Rationale:** Verification is evidence review; Settlement Clearance is authoritative financial permission and may arise from verified Payment or authorized Account/Credit settlement; it never implies production or delivery.

### Decision 4

**Decision:** Approved — canonical machine values use lowercase `snake_case`; Arabic strings are presentation labels only.
**Rationale:** Stable contracts cannot depend on translated UI text.

### Decision 5

**Decision:** Approved — legacy values are mapped explicitly during Phase F rather than silently changing database semantics.
**Rationale:** Values including `PREPARATION`, `preparing`, `confirmed`, `cancelled`, `canceled`, `OUT_FOR_DELIVERY`, and `processing` need compatibility and migration analysis.

### Decision 6

**Decision:** Approved — `stale_revision`, `conflict`, `needs_refresh`, `already_completed`, and `no_longer_allowed` are command outcomes unless a domain genuinely requires durable aggregate state.
**Rationale:** Persisting every action result as business state creates false lifecycle semantics.

### Decision 7

**Decision:** Approved — State, Event, Timestamp, and Transport/Retry State remain separate concepts; Authentication State also remains separate from Business Restriction.
**Rationale:** Evidence and delivery mechanics must not impersonate current authoritative business state.

### Decision 8

**Decision:** Approved — exact database enums/checks, compatibility, TypeScript types, APIs, migration strategy, and rollout remain Phase F work.
**Rationale:** Architecture fixes semantics; implementation must first inventory deployed values and preserve compatibility.

## Traceability

- E-01 through E-10 and EA-01 remain approved and unchanged.
- E-05/E-06 preserve Order, Payment Verification, Clearance, and Production separation.
- E-07/E-08 preserve transport state and command outcome separation.
- EA-03 through EA-06 were approved with this consolidated package on 2026-08-09. Phase F remains not started.
