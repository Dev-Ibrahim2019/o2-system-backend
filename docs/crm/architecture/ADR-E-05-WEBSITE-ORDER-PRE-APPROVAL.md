# ADR E-05 — Website Order Pre-Approval Model

## Status

**[R] Proposed — Ready for Architecture Review**

This ADR proposes authority, lifecycle and handoff semantics only. It does not approve schemas, tables/classes, identifiers, enums, APIs, queues/jobs, payment verification, financial posting, inventory reservation, UI or Phase F application work.

## Context

A public website submission must survive branch outages without polluting Laravel's operational Order history or triggering financial/production effects. E-05 decides what the submission is before authorized staff approval, which system owns it, and the exact conceptual boundary at which a committed Laravel `Order` becomes authoritative.

Core invariants:

```text
Website Submission ≠ Laravel Order
Website Submission ≠ Invoice
Website Submission ≠ Payment
Website Submission ≠ Production Ticket
Website Submission ≠ Inventory commitment
Website Submission ≠ Kitchen production
```

## Approved E-01 through E-04 Constraints

- E-01 approves the independent Cloud Integration Service, outbound-only Local Sync Agent, Laravel operational/financial authority and Laravel-mediated Admin.
- E-02 approves durable pull/push, adaptive reconciliation and at-least-once transfer; WebSocket/SSE is hint-only and Post-MVP.
- E-03 approves Cloud-authoritative Conversations/Public Messages and governed references rather than copied history.
- E-04 approves the Cloud customer Notification inbox while Laravel retains Order/Payment domain truth.
- E-06 through E-10 and EA-01 through EA-06 remain pending.

## Business Constraints

1. Website submission creates a durable customer order intent, not a local operational Order.
2. Only authorized Laravel validation/approval may create the accepted Laravel Order.
3. Approval references one exact submitted/revisioned commercial snapshot.
4. Material price, availability, branch, fulfillment or delivery changes require explicit customer re-consent; no silent repricing/substitution/rerouting.
5. Duplicate submit, synchronization and approval retries must not create duplicate Laravel Orders.
6. Staged state cannot create Invoice, Payment, Accounting, Production Ticket or hard inventory commitment.
7. Order approval, payment verification and kitchen release are distinct gates.
8. Customer edit/cancel and staff review races must be detected, never silently overwritten.
9. Unsafe ambiguity enters Needs Review rather than being retried into business effects.
10. Final identity, references, idempotency, pricing, status and retention policies remain dependent decisions.

## Current-System Evidence

### Laravel backend

- `Order` is already the operational aggregate. Its model describes the current sequence: pending Order/items, confirmation creates Production Tickets, Invoice is an official payment artifact, and Payments attach to the Invoice.
- `OrderController::store` creates a real local Order in a database transaction with branch, source, customer and items, initially `pending`; this is not a non-operational website-intent abstraction.
- `OrderConfirmationService::release` locks the Order, validates eligibility, creates `ProductionTicket`/items by department and changes the Order to confirmed. Confirmation is therefore a production boundary, not website acceptance.
- `CallCenterOrderCreationService` transactionally resolves/creates a Customer/address and creates a real `source=call_center`, `status=pending` Order/items using local item/branch prices. It proves local draft/payment-waiting behavior but is already inside Laravel's Order lifecycle.
- Call Center payment execution requires an existing draft Invoice, uses idempotency records/payment-reference uniqueness, records payment, and releases to the kitchen only when fully paid. `OrderConfirmationService` also blocks unpaid Call Center Orders.
- Invoice, Payment and Production operations are separate routes/services. Local Order creation alone does not necessarily create all downstream effects, but exposing it to raw website submit would still create operational records and reporting ambiguity.
- Existing idempotency covers selected Call Center financial phases, not a complete website submission/acceptance contract. No inspected Staged Website Order, website/external-order lifecycle, immutable revision model or Cloud review projection exists.
- Existing `source` and operational statuses are useful after acceptance but must not be reused as Cloud pre-approval authority or final cross-system status names.

### Admin React application

- Call Center UI can build and save a real Laravel Order “awaiting payment,” then execute payment routes and release the kitchen when paid. This is a local staff-originated flow, not a website review queue.
- Existing screens show active/pending Orders, branch/customer details, payment status and kitchen release failure. They provide reusable operational display/payment concepts.
- No inspected dedicated website-intent queue, exact revision approval, repricing/customer re-consent, pre-acceptance cancellation, Needs Review/Financial Review workflow or website rejection flow exists.
- No inspected `REJECT_WEB_ORDERS` permission exists; E-05 therefore requires an appropriate authorized rejection capability without inventing its final permission name.

### Next.js website

- Cart is client React state, cleared on branch change; it holds displayed item/variant pricing and quantities but has no durable order reference/revision or cross-device recovery.
- Branch menus, prices, availability flags, delivery areas/fees and WhatsApp destinations are duplicated in website code/data.
- Checkout collects name, phone, address, area, notes, branch, cart totals and delivery fee, formats them into WhatsApp text, opens `wa.me`, and clears local cart state.
- No Cloud order submission, Portal identity binding, authoritative product identifiers, server repricing, payment workflow, idempotency/correlation reference or durable acceptance acknowledgement exists.
- This gap confirms that WhatsApp handoff cannot be treated as a Staged Website Order or accepted Laravel Order.

### Invoice and production boundary summary

Current code creates a Laravel Order before payment, requires a draft Invoice before Call Center payment execution, records Payment separately, and creates Production Tickets on explicit release/confirmation. The target website flow should therefore create the Laravel Order at authorized order approval, while payment verification remains a separate E-06 gate and production stays blocked until the applicable payment/release policy is satisfied.

## Decision Drivers

- Durable intake during branch/Laravel outage.
- Clean Laravel operational/sales reporting.
- One clear pre-approval authority and a deterministic authority handoff.
- Exact customer commercial consent and stale-revision protection.
- Prevention of duplicate Orders, financial records and production effects.
- Recoverable ambiguity after timeouts and at-least-once delivery.
- Compatibility with current Laravel domain services without equating staged and local pending states.

## Terminology

**Staged Website Order** (or Website Order Intent) means the Cloud-authoritative pre-approval aggregate. It must not be called simply “Order” where confusion with Laravel is possible.

**Accepted Laravel Order** means the operational Order created by a committed, authorized Laravel acceptance transaction. Exact class/table/status/reference names remain unapproved.

## Options Considered

### Option A — Cloud-authoritative Staged Website Order until Laravel acceptance

Cloud owns submitted intent and revisions. Laravel receives an authorized review projection and creates one operational Order only upon acceptance of an exact eligible revision.

### Option B — Immediate Laravel Order on website submission

Every public submission creates a local pending/draft Order, even before staff approval. This couples public availability to branch synchronization and fills operational storage/reporting with unaccepted intent.

### Option C — Dual Cloud/Laravel draft authority

Both systems maintain editable authoritative pre-approval records. Concurrent customer/staff edits, cancellation and outages create divergent commercial snapshots.

## Detailed Comparison

| Criterion | Cloud Staging | Immediate Laravel Order | Dual Authority |
| --- | --- | --- | --- |
| Branch outage intake | Strong | Weak | Acceptable |
| Customer submission durability | Strong | Weak | Acceptable |
| Laravel data cleanliness | Strong | Weak | High Risk |
| Premature order creation risk | Strong | High Risk | High Risk |
| Premature invoice/payment risk | Strong | Weak | High Risk |
| Production safety | Strong | Acceptable with gates | High Risk |
| Repricing workflow | Strong | Weak | High Risk |
| Customer re-consent | Strong | Weak | High Risk |
| Duplicate submission safety | Strong | Acceptable | High Risk |
| Staff approval audit | Strong | Acceptable | Weak |
| Rejection handling | Strong | Weak | High Risk |
| Cancellation before approval | Strong | Weak | High Risk |
| Synchronization complexity | Acceptable | Acceptable | High Risk |
| Conflict risk | Strong | Acceptable | High Risk |
| Recovery after timeout | Strong | Acceptable | High Risk |
| Reporting clarity | Strong | Weak | High Risk |
| Future mobile support | Strong | Weak | Acceptable |
| Scalability | Strong | Acceptable | Weak |
| Authority clarity | Strong | Acceptable | High Risk |
| Risk of orphan local records | Strong | High Risk | High Risk |

Immediate Laravel creation can reuse current APIs but makes an unapproved public intent indistinguishable from real local Orders and weakens outage intake. Dual authority adds the worst reconciliation burden. Cloud staging adds an explicit acceptance contract but provides the required safety and clarity.

## Recommended Authority Model

Choose **Option A — Cloud-authoritative Staged Website Order until authorized Laravel acceptance**.

```text
Before Acceptance: Cloud Staged Website Order = authority
After Acceptance:  Laravel operational Order = authority
```

After handoff, Cloud retains immutable intake/revision/consent/audit evidence and a customer-facing linked Order projection, but it no longer owns operational Order state.

## Pre-Approval Lifecycle

Conceptually analyze Draft → Submitted → Under Review → Needs Customer Confirmation → Ready for Approval → Accepted, plus Rejected, Cancelled by Customer, Expired and Needs Review. These are not final enums.

Accepted means the exact eligible revision produced a committed Laravel Order and durable correlation outcome. A staff click, dispatched command or timeout is not acceptance.

## Authority Handoff

Before acceptance, Cloud owns the stable staged reference, customer/Portal and cart snapshots, requested branch/fulfillment/delivery, customer-approved commercial snapshot, notes, submission time, pre-approval workflow, revisions/consents and staging audit.

At acceptance Laravel receives a specific staged reference/revision, locks or otherwise validates the acceptance key, revalidates identity/branch/items/pricing/eligibility, authorizes the real staff actor and scope, creates one Order/items through a local domain service, records correlation, and commits the acceptance result atomically. Laravel Outbox then returns the authoritative outcome to Cloud.

After acceptance Laravel owns operational status, final branch, production/delivery/cancellation eligibility, Invoice/Payment relationships, accounting, inventory and production effects. Cloud owns original staged evidence and synchronized customer-facing projection only.

## Draft vs Submitted

Recommend **client-only cart/draft for MVP; Cloud stores only submitted order intent**. Cross-device autosaved carts add privacy, abandoned-cart retention and storage scope without being required for safe submission. A later explicit cart feature may adopt Cloud drafts under EA-06.

A draft/cart is editable and has no System Receipt as submitted intent. Submit must durably commit the staged snapshot before the UI claims receipt. Client retry targets the same logical intent under EA-03.

## Submitted Commercial Snapshot

Preserve conceptually Portal/customer reference; item references, quantities and options/modifiers; displayed item prices/subtotal; discount/coupon representation; delivery area/fee; displayed total; requested branch; fulfillment type; address reference/snapshot; notes; submission time; and commercial/catalog snapshot version.

This preserves what the customer saw and submitted but does not make website prices authoritative. Exact fields and catalog reference contract remain EA-04/E-10.

## Revision Model

Never mutate a submitted commercial snapshot in place. Customer or staff-proposed changes create V2, V3, etc.; approval always names one exact revision. Prior revisions remain audit evidence subject to EA-06. If Cloud has advanced beyond the reviewed revision, stale approval fails or enters review under E-08.

## Pricing and Repricing

Website-displayed price is not automatically the final price. Laravel revalidates the candidate against authoritative catalog/pricing rules defined by EA-04. No material difference permits approval to continue; a material difference produces a proposed revision and Needs Customer Confirmation, never silent overwrite.

## Customer Consent

Staff cannot approve an unconsented commercial revision. Re-consent evidence conceptually identifies the staged reference, exact revision, commercial totals, time, Portal identity and consent action. Customer V2 consent does not retroactively approve V1 or any later change. Exact identity/reference/idempotency contracts remain E-09/E-10/EA-03.

## Availability and Branch Revalidation

Laravel revalidates item/branch activation, modifiers, delivery area/fee, promotion and branch eligibility at acceptance. Allowed outcomes are rejection, customer decision or an explicit revised snapshot. Do not silently replace/remove items or reroute branches.

The requested branch is intent; the final branch is Laravel authority. A material branch change requires item, pricing and delivery revalidation plus customer re-consent where expectations change.

## Customer Edit and Cancellation

Before acceptance, customers may edit through a new revision and may cancel in Cloud subject to policy. Cancellation makes the staged intent ineligible and eventually updates Laravel's review projection. Edit/cancel racing with approval must have only one valid outcome under E-08; neither may be silently ignored.

After Laravel Order commit, Cloud cancellation is no longer authoritative. The customer submits a request/action against Laravel's accepted Order lifecycle. Pre-acceptance cancellation and post-acceptance Order cancellation are distinct.

## Staff Review and Authorization

Laravel authenticates/authorizes reviewer permission, branch scope and actual actor. Every attempt records staged reference/revision, actor, time and applicable reason/context. Cloud cannot promote intent into an operational Order without Laravel validation.

Existing Admin/Call Center UI lacks this website review/repricing contract; Phase F must introduce it rather than mapping Cloud staging onto the existing “save Order awaiting payment” action.

## Rejection and Needs Review

Authorized rejection records exact revision, internal reason, customer-safe explanation, actor and time in the governed workflow. No verified final permission name exists, so E-05 requires an appropriate permission without naming it. A rejected intent creates no Laravel Order/Invoice/Payment/Production Ticket.

Ambiguous timeout, customer identity, pricing, branch/item mapping, external reference, payment state or conflicting revision enters **Needs Review**, not silent retry into effects. Ownership follows E-01 failure routing and E-07/E-08/E-09/E-10.

## Laravel Acceptance Boundary

The conceptual local transaction is:

```text
Authorize approval
→ lock/validate staged reference + exact revision
→ validate customer, branch, items and commercial contract
→ create Laravel Order + items through domain service
→ record unique staged/revision correlation and actor outcome
→ commit
→ publish committed acceptance through Local Outbox
```

All local Order creation and correlation facts must commit together. Cloud must not mark Accepted before evidence of the committed Laravel result.

## Timeout and Duplicate Recovery

Double-click submit, website retry, source redelivery, repeated customer confirmation, approval retry and duplicate command must converge. A timeout after Laravel commit is an unknown outcome, not permission to create another Order. Retry/reconciliation queries the durable correlation and returns the prior result or moves to Needs Review. EA-03/E-10 define exact keys/references.

## Order Approval vs Payment Verification

Recommend creating the real Laravel Order **at authorized order approval**, not waiting for payment verification. This matches the current local model, which creates a pending Order before Invoice/payment and separately gates production.

```text
Order Approval ≠ Payment Verification
Order Exists ≠ Payment Verified
Payment Verified ≠ Kitchen Release unless release rules also succeed
```

E-06 defines Payment Verification and EA-05 defines Invoice/Payment/accounting timing. A payment-dependent business policy may hold the accepted Order from production without moving the authority handoff.

## Financial Safety

Cloud staging never creates Invoice, Payment, accounting entry, receivable, refund, compensation or loyalty ledger effect. Payment Proof upload is evidence governed by E-06, not verification. Laravel acceptance also must not accidentally post financial effects unless EA-05 explicitly includes them in a later approved transaction contract.

## Production Safety

No Staged Website Order produces a Production Ticket, KDS entry, print job or kitchen release. Current code creates tickets during explicit Order confirmation/release; the target must preserve that gate. Customer/staff-facing language must not say “preparing” before authoritative Laravel production state.

## Inventory Semantics

Recommend **no hard inventory reservation from Cloud staging in MVP**. Current restaurant Order flow revalidates local item/department availability and does not establish a safe cross-system reservation/expiry contract. Cloud availability may be stale; Laravel revalidates at acceptance. Any later reservation requires an explicit authority, quantity, expiration/release and reconciliation decision.

## Customer-Facing Continuity

Cloud may show the Staged Website Order and pre-approval state during branch outage. It must label receipt/review honestly and never claim Laravel acceptance, payment verification, production or delivery prematurely. E-04 may later notify intent received, confirmation required, accepted, rejected, cancelled or customer action required only from the corresponding fact.

## Post-Acceptance Projection

After handoff, Cloud links the staged reference to an opaque Laravel Order reference and displays a stale-aware synchronized projection of authorized Order tracking. Laravel remains authority. A Conversation may reference the staged/accepted Order without copying E-03 history.

## Data Ownership Matrix

| Data | Pre-Approval Authority | Post-Acceptance Authority | Projection |
| --- | --- | --- | --- |
| Staged Order reference | Cloud | Cloud historical identity | Laravel review/correlation |
| Customer-submitted snapshot | Cloud | Cloud audit evidence | Authorized Laravel review |
| Revision history | Cloud | Cloud historical evidence | Relevant reviewed revision |
| Customer consent | Cloud evidence; identity pending E-09 | Cloud audit/correlation | Laravel acceptance evidence |
| Requested branch | Cloud intent | Cloud historical | Laravel review |
| Final branch | Not final | Laravel | Cloud Order tracking |
| Displayed pricing snapshot | Cloud evidence | Cloud historical | Laravel validation input |
| Final accepted commercial result | Candidate in Cloud | Laravel Order | Cloud linked projection |
| Approval decision | Laravel-authorized command; pending until commit | Laravel outcome | Cloud staged outcome |
| Rejection | Cloud staged lifecycle after authorized decision | Cloud history | Laravel review audit as needed |
| Laravel Order | None | Laravel | Cloud customer projection |
| Operational Order status | None | Laravel | Cloud authorized tracking |
| Invoice | None | Laravel | Cloud safe projection if allowed |
| Payment | None | Laravel | Cloud safe projection under E-06 |
| Production | None | Laravel | Cloud tracking projection |
| Delivery | Requested intent only | Laravel | Cloud tracking projection |
| Customer-facing tracking | Cloud staged state | Laravel-sourced facts in Cloud | Portal display |

The matrix is conceptual and approves no schema.

## Offline and Failure Behavior

| Scenario | Cloud Staged Order | Laravel | Customer State | Required Behavior |
| --- | --- | --- | --- | --- |
| Branch internet offline | Accepts submission | Unchanged | Received/review delayed | Durable intake; no local Order |
| Sync Agent offline | Remains queued | Projection stale | Review delayed | Show delay; reconcile later |
| Laravel offline | Remains authoritative pre-approval | No acceptance | Not accepted | No false acceptance |
| Cloud API unavailable before submit | No durable submit | Unchanged | Draft/not submitted | Safe retry; no receipt claim |
| Submit request retries | Same logical intent | Unchanged | One submission | Deduplicate under EA-03 |
| Staff reviewing stale revision | Newer revision authoritative | Must reject stale attempt | Awaiting review | Refresh/review exact revision |
| Customer edits during review | Creates new revision | Old projection stale | Latest revision pending | No in-place mutation |
| Customer cancels during review | Cancellation competes | Approval must detect race | One outcome | Resolve under E-08 |
| Approval sent twice | Same staged/revision key | At most one Order | One accepted outcome | Return prior result |
| Laravel commits but ack lost | Still appears pending temporarily | Order/correlation committed | Unknown, not rejected | Reconcile; never recreate |
| Price changes during review | Old snapshot preserved | Revalidates | Confirmation required | Proposed revision + consent |
| Item becomes unavailable | Intent preserved | Rejects/revises | Action required | No silent substitution |
| Order accepted but Cloud update delayed | Staged outcome stale | Laravel authoritative | Confirmation delayed | Outbox replay/reconciliation |

## Audit and Reporting

Audit submission, revision, customer re-consent, staff review/approval/rejection, cancellation, branch/repricing/availability change, Laravel Order creation, acceptance outcome and reconciliation with actual actors and correlation. Never silently overwrite the original snapshot.

Cloud reporting distinguishes Submitted Intents, Accepted Orders, Rejected, Cancelled Before Acceptance, Expired, Needs Review, conversion/approval time, repricing and reconfirmation. Operational sales reporting uses Laravel accepted Orders. Submission counts must never be reported as accepted sales.

## Impact on Other Decisions

| Decision | E-05 Impact |
| --- | --- |
| E-06 Payment Verification | Defines proof, review and verified/rejected payment truth after/beside Order approval. |
| E-07 Offline and Retry | Defines retry/lease timing, terminal failure and Needs Review handling. |
| E-08 Conflict Resolution | Defines concurrent edit/cancel/approve and stale revision outcomes. |
| E-09 Unified Customer Identity | Defines Portal Account to Laravel Customer resolution; phone alone cannot merge ambiguity. |
| E-10 External Order Identifier | Defines stable opaque staged, revision, Laravel Order and correlation references. |
| EA-01 Portal Authentication | Authenticates submission, edit, cancellation and customer consent. |
| EA-02 Status Dictionary | Defines exact separate staged and operational lifecycle names. |
| EA-03 Idempotency | Defines submission, consent and acceptance retry keys/outcomes. |
| EA-04 Catalog and Pricing Authority | Defines authoritative items, options, prices, discounts, delivery fees and versions. |
| EA-05 Financial Posting Timing | Defines Invoice, Payment and accounting posting relative to acceptance/verification. |
| EA-06 Privacy and Retention | Defines draft/staged snapshot, revision, consent, rejection and audit retention/deletion. |

E-05 does not resolve these decisions.

## Recommended Option

Choose Option A: Cloud owns the Staged Website Order until an authorized Laravel transaction accepts one exact revision and creates/correlates the operational Order. Laravel then owns the Order; its Outbox confirms the handoff to Cloud. Reject dual authority and immediate local creation on public submit.

## Consequences

- Website intake remains durable through branch outage and Laravel holds only accepted operational Orders.
- A new Cloud staging/revision/consent lifecycle and Laravel review projection/acceptance contract are required later.
- Staff cannot reuse the current Call Center “save real Order” action as website approval without a dedicated acceptance boundary.
- Customer-visible state must distinguish intent receipt, approval, payment and production.
- Reporting gains a clear conversion funnel but must correlate two authority eras.

## Risks

- Duplicate Laravel Order from submit/approval replay.
- Approval of a stale customer revision or silent repricing.
- Customer cancellation/edit races with staff approval.
- Price/availability drift or wrong-branch acceptance.
- Orphan financial record or premature Production Ticket.
- Stale Cloud accepted-state projection and ambiguous timeout after Laravel commit.
- Customer identity mismatch or ambiguous phone mapping.
- Staged-order/revision retention and privacy growth.

## Mitigations

- Stable staged/revision correlation, unique acceptance outcome and EA-03 idempotency.
- Immutable revisions, exact approval version and explicit re-consent.
- E-08 concurrency rules with locks/version checks and one winning outcome.
- Laravel revalidation of customer, branch, items, price, delivery and eligibility.
- Transactional Order/correlation creation; separate E-06/EA-05 financial and production gates.
- Durable Local Outbox, reconciliation and Needs Review for unknown outcomes.
- E-09 identity resolution and no silent phone-based merge.
- EA-06 minimization, retention/deletion and holds before production.

## Rejected Alternatives

- **Immediate Laravel Order on website submit** is rejected: public input would pollute operational Orders/reporting, create orphan pending records, depend on branch availability and increase downstream financial/production risk despite existing gates.
- **Dual Cloud/Laravel authority** is rejected: Cloud revisions and Laravel drafts can diverge during outages, making price consent, cancellation, approval and recovery ambiguous.
- Mapping Staged state onto the existing Call Center `pending` Order is rejected because that record is already a real Laravel Order and participates in local Invoice/payment/production services.
- Waiting until payment verification to create any Laravel Order is rejected as the default because current code and business constraints separate Order acceptance from Payment Verification; production can remain held without delaying authority handoff.

## Implementation Implications for Phase F

After approval and dependent decisions, Phase F must define versioned staged/revision contracts, Portal authorization, Laravel review projection, transactional acceptance/correlation, Outbox outcome, duplicate recovery, conflict handling, safe tracking projection, audit and tests for every failure scenario. It must reuse local domain services without exposing them directly to public submit. Phase F has not started and no schema is implied.

## Architecture Review Questions

1. **Do we approve a Cloud-authoritative Staged Website Order until Laravel acceptance?** Recommendation: Yes; it preserves outage intake and keeps unaccepted intent out of operational Orders.
2. **When should a real Laravel Order be created: authorized order approval or only after payment verification?** Recommendation: At authorized order approval; Payment Verification remains a separate E-06 gate and production remains held by Laravel policy.
3. **Should approval always reference an exact immutable/revisioned customer snapshot?** Recommendation: Yes; stale/unknown revisions cannot be silently approved.
4. **Do material pricing, branch, delivery, availability or commercial changes require explicit customer re-consent?** Recommendation: Yes; propose a new revision and record consent.
5. **May the customer edit/cancel before Laravel acceptance using revisions rather than silent mutation?** Recommendation: Yes, subject to E-08 race resolution.
6. **Should rejected/cancelled/expired intents remain historical Cloud records without Laravel Orders?** Recommendation: Yes, subject to EA-06 retention/deletion.
7. **May a Cloud Staged Order directly create financial or production effects?** Recommendation: No; those require separate Laravel-authoritative gates.
8. **Should Laravel acceptance atomically correlate the staged reference/revision, created Order and acceptance outcome?** Recommendation: Yes; exact implementation follows E-10/EA-03/Phase F.
9. **Should MVP staging avoid hard inventory reservation and revalidate availability at acceptance?** Recommendation: Yes; inspected code has no safe cross-system reservation/expiry contract.
10. **Should unsafe ambiguity enter Needs Review instead of silent retry/overwrite?** Recommendation: Yes; ownership/timing follow E-07/E-08 and E-01 failure routing.

## Traceability

- E-01 through E-04: independent Cloud staging, durable synchronization, link-based Conversation history and truthful customer Notifications.
- D1: durable website intake, branch/price/customer-visible consent and pre-approval safety.
- D2: staff authorization, separate payment/order approval, repricing consent, rejection and safe retry/reconciliation.
- Current code: Laravel `Order`, `OrderController`, Call Center creation/payment execution, `OrderConfirmationService`, Invoice/Payment/Production routes, Admin Call Center draft/payment UI, and Next.js WhatsApp checkout/cart/menu data.
- Dependencies left open: E-06 through E-10 and EA-01 through EA-06 as detailed above.
