# ADR E-08 — Conflict Resolution Policy

## Status

**[x] Approved — 2026-08-08**

Approved decision: **Option A — Authority-Aware Conditional Conflict Resolution + Exact Version/Revision Preconditions + Single Valid Authoritative Commit + Explicit Conflict Outcome**.

This ADR approves business conflict behavior, authority handoff, conditional transition, loser outcome, and audit semantics only. It does not approve schemas, version fields, locks, APIs, acceptance-token mechanisms, queues, financial/production implementation, UI, or Phase F application work.

## Context

Offline, asynchronous work allows individually valid-looking actions to compete: customer cancel versus staff approval, V2 versus approval of V1, Payment versus cancellation, and production release versus cancellation. E-07 safely delivers/reconciles work but explicitly does not choose business winners. E-08 defines how authority, exact input, preconditions, and one authoritative commit produce one auditable result.

Approved model:

```text
Authority-Aware Conditional Conflict Resolution
+ Exact Version/Revision Preconditions
+ Single Valid Authoritative Commit
+ Explicit Conflict Outcome
```

## Approved E-01 through E-07 Constraints

- E-01/E-02: independent Cloud integration boundary, outbound-only Agent, Laravel operational/financial authority, Cloud domain authorities, at-least-once synchronization and reconciliation.
- E-03: Cloud-authoritative Conversations/Public Messages with Cloud sequence; Laravel projections and Internal Notes cannot overwrite Cloud public history.
- E-04: Cloud-authoritative Customer Notifications; local projections are stale-aware copies.
- E-05: Cloud owns immutable staged Website Order revisions before acceptance; approval names one exact revision; Laravel owns the accepted Order after its committed handoff.
- E-06: Laravel owns Payment Verification, Payment/Invoice/accounting, Payment Clearance and production eligibility; Payment and release are separate; paid cancellation requires financial disposition.
- E-07: unknown outcomes reconcile before replay; deterministic business conflicts are not transport retry problems; unresolved ambiguity enters Needs Review/Finance Review.
- E-09/E-10 and EA-01 through EA-06 remain pending. Phase F remains not started.

## Current-System Evidence

### Laravel backend

- `OrderConfirmationService::release` uses a database transaction, reloads and `lockForUpdate`s the Order/items/tickets/table, revalidates Call Center full payment and blocked statuses, and uses `firstOrCreate` plus a unique Order-item ticket constraint to reduce duplicate production effects.
- `CallCenterOrderExecutionService` locks the Order/Invoice inside transactions, uses durable idempotency identity and reference uniqueness, separates the committed financial phase from kitchen release, and revalidates full payment before release.
- `IdempotencyService` distinguishes the same `(scope,key)`/payload from key reuse with a different hash and raises a conflict. This is partial duplicate-versus-conflict evidence, not a complete aggregate concurrency contract.
- Payment confirmations uniquely constrain normalized reference per method, Payment references have an intended global unique migration, call tickets have unique external IDs, Order numbers are unique, and Production Ticket items have an intended unique Order-item constraint. Actual production migration state remains unverified.
- `OrderController::cancel` checks status before entering its transaction and updates the route-bound model without `lockForUpdate`; `OrderController::update` also checks state without an expected aggregate version. Concurrent cancel/update/release behavior is therefore not uniformly serialized by current code.
- Order update blocks only `paid`/`cancelled` at entry and may synchronize production tickets. Release blocks cancelled/paid/served and locks authoritative state. Cancellation currently permits pending/pending_confirmation/confirmed and cancels tickets/items, but its precondition and mutation are not performed on a freshly locked Order.
- No inspected `expected_version`/compare-and-swap contract, staged Website Order revision/fence, generic conflict record, optimistic version column, or governed cross-system acceptance claim exists. `updated_at` is exposed in resources but is not a safe conflict arbiter.
- Transactions sometimes use retry count `3` for database/deadlock behavior; that is not business conflict resolution. No inspected universal governed `force=true` override exists, while several unrelated “force” concepts are not a cross-domain override contract.

### Admin React application

- Order/customer/payment screens hold client state, call update/confirm/cancel/payment endpoints, expose refresh/manual retry, and frequently use `updated_at` for display/sorting. Commands do not consistently send expected aggregate versions.
- Call Center payment attempt state reuses idempotency keys within the open browser session and correctly distinguishes retrying failed release from recollecting Payment, but browser memory is not conflict authority.
- No inspected Website staged-revision review/acceptance queue, acceptance fence, stale-revision conflict UI, proof-version verification UI, or generic authoritative conflict response contract exists.
- Client-side validation/caches may prevent obvious actions but cannot decide cancellation/payment/release races; server revalidation is required.

### Next.js website

- The website has client cart/checkout state and WhatsApp handoff, not a durable staged Website Order, revision/cancel contract, expected-version command, or conflict UI.
- E-08 is target architecture, not approval of an existing customer edit/cancel implementation.

### Evidence qualification

The repository shows useful local locks, transactions, state checks, idempotency and uniqueness, but not a uniform conflict contract or cross-system acceptance handoff. Runtime database constraints and deployed behavior remain unverified.

## Decision Drivers

- Preserve customer consent for one exact staged revision.
- Prevent split-brain Cancelled-in-Cloud/Accepted-in-Laravel outcomes.
- Prevent Payment or production effects after an earlier valid cancellation.
- Prevent ordinary cancellation/modification after irreversible production milestones.
- Repair projections from authority without clock comparison.
- Give every losing action an explicit, safe, auditable result.
- Distinguish deterministic denial from unresolved ambiguity and from duplicate replay.
- Remain safe during outage, delayed projection, multiple staff actors, and clock skew.

## Authority Model

| Domain fact | Authority |
| --- | --- |
| Conversation/Public Message | Cloud |
| Customer Notification | Cloud |
| Staged Website Order before acceptance | Cloud |
| Payment Proof binary/evidence | Cloud |
| Accepted Order/operational state | Laravel |
| Payment Verification/Payment/Invoice/Accounting/Clearance | Laravel |
| Production/Delivery | Laravel |
| Complaint operations/final Loyalty ledger | Laravel |

Authority wins over projection. A projection is repaired; it never defeats its source because its timestamp appears newer.

## Core Conflict Invariants

```text
Authority wins over projection.
Newer valid revision defeats stale review input.
Stale commands never silently apply to newer state.
Irreversible authoritative milestones change later action legality.
One valid authoritative transition wins.
Every loser receives an explicit outcome.
Conflict ≠ silent overwrite.
Conflict ≠ duplicate execution.
Conflict ≠ timestamp-only Last Write Wins.
Conflict ≠ retry problem.
Client state is never conflict authority.
```

## Options Considered

### Option A — Authority + preconditions + version/fencing + single-winner commit

Identify the authority, require exact revision/version/state, validate preconditions, conditionally commit one authoritative transition, then return an explicit conflict/superseded/review result to losers.

### Option B — Timestamp Last Write Wins

Whichever action appears newest overwrites the other, despite different clocks, offline queues, and authority boundaries.

### Option C — Dual apply and reconcile later

Cloud and Laravel apply competing actions independently, then attempt a later merge/repair even when both effects may be irreversible.

## Detailed Comparison

| Criterion | Authority + Preconditions | LWW | Dual Apply |
| --- | --- | --- | --- |
| Customer consent safety | Strong | High Risk | High Risk |
| Order acceptance race | Strong | High Risk | High Risk |
| Payment safety | Strong | High Risk | High Risk |
| Production safety | Strong | High Risk | High Risk |
| Offline behavior | Strong | Weak | High Risk |
| Auditability | Strong | Weak | Weak |
| Clock skew safety | Strong | High Risk | Weak |
| Duplicate protection | Strong | Weak | High Risk |
| Stale revision protection | Strong | High Risk | High Risk |
| Projection drift recovery | Strong | Weak | Acceptable |
| Financial correctness | Strong | High Risk | High Risk |
| Operational clarity | Strong | Weak | High Risk |
| Scalability | Acceptable | Strong | Weak |
| Implementation complexity | Acceptable | Strong | Weak |
| Authority consistency | Strong | High Risk | High Risk |

Option A costs explicit versions, conditional contracts, handoff evidence, richer errors, and audit, but it prevents incompatible effects instead of repairing damage. LWW is simple but unsafe across clocks and authorities. Dual apply is unacceptable for Order, Payment, production and refund effects.

## Approved Conflict Model

Approved: **Option A — Authority-Aware Conditional Conflict Resolution**.

```text
Identify authority
→ validate exact version/revision
→ validate expected state and business preconditions
→ conditionally commit one valid authoritative transition
→ competing action revalidates
→ explicit conflict or governed alternative if no longer legal
```

No timestamp or client click order is the source of truth.

## Conflict Detection

Commands that can race conceptually carry stable command identity, target aggregate/reference, expected revision/version/state, exact evidence/commercial input, actor/scope, and operation-specific preconditions. Authority compares expected and actual state at the commit boundary. Mismatch yields a deterministic conflict unless authority/outcome cannot be proven.

## Expected Revision / Version

- Staff reviewed Staged Order V4; current is V5: Approve(V4) is stale and must not mean Approve(V5).
- Verifier reviewed Proof V1; current eligible proof policy requires V2: Verify(V1) conflicts, not Verify(V2).
- UI displayed active/unpaid Order; Laravel is cancelled/paid/released: server rejects or routes the now-governed alternative.
- Exact formats/field names belong to E-10/Phase F; timestamps alone are not versions.

## First Valid Authoritative Commit

For mutually exclusive transitions within one authority, the first **valid authoritative commit after locking/version/state/precondition validation** changes the aggregate and therefore the legality of the later command. This is not generic arrival-time or browser-click precedence.

```text
Both actions reach Laravel
→ Laravel serializes/reloads/revalidates
→ first valid transition commits
→ later action revalidates new authoritative state
→ succeeds only if still legal; otherwise explicit conflict/alternative
```

## Cross-System Handoff Conflicts

Before acceptance, Cloud alone decides whether the exact staged revision remains eligible. After Laravel atomically commits the accepted Order and durable correlation, Laravel owns the Order. The handoff must not leave Cloud able to cancel/revise an accepted transition as if it were still ordinary pre-acceptance intent.

## Acceptance Fence / Conditional Claim

Conceptually require an authority-issued conditional acceptance fence/claim for the exact staged revision:

```text
Staff approves V3
→ Cloud conditionally validates V3 still current/eligible/not cancelled
→ Cloud establishes one acceptance transition/fence
→ Laravel conditionally creates/correlates one Order
→ Laravel outcome returns durably
→ Cloud finalizes handoff/projection
```

If cancel/revision committed first, the claim fails and no Laravel Order is created. If the claim committed first, later ordinary pre-acceptance mutation receives a truthful transition/conflict result rather than silently invalidating acceptance. Exact token, lease, storage and API mechanics remain Phase F/E-10/EA-03.

If Laravel is unavailable after the claim, E-07 reconciles: prove no Order committed before safely releasing/recovering eligibility; unknown outcome becomes Needs Review. While indeterminate, do not allow a second acceptance or incompatible staged mutation.

## Customer Edit vs Approval

```text
Customer V2 commits before Approve(V1)
→ Cloud current revision is V2
→ Approve(V1) returns Stale Revision / Refresh Required
→ reviewer makes a new decision on V2
```

Never mutate V1, automatically approve V2, or treat an old consent snapshot as current.

## Customer Cancel vs Approval

Before acceptance Cloud is authority:

- Cancellation commits before the conditional fence: cancellation wins, approval fails, no Laravel Order.
- Fence for exact eligible revision commits first: ordinary pre-acceptance cancellation cannot silently win; it receives Pending Transition/Conflict and later follows the final handoff result.
- Fence outcome or Laravel commit unknown: block incompatible actions and reconcile; do not retry both.

This produces one winner without timestamp LWW.

## Cloud Unreachable During Acceptance

Recommend **no final Website Order acceptance when current Cloud eligibility cannot be proven**. Laravel may record a local approval intent as Pending/Awaiting Cloud validation, but it must not create an accepted Order from a stale projection. Local-native POS/Call Center Orders are unaffected because Cloud is not their pre-acceptance authority.

## Post-Acceptance Cancellation

After Laravel Order commit, a Cloud staged cancellation cannot overwrite the accepted Order. Customer cancellation becomes a request/action against Laravel, which evaluates current payment, production, Order, permission and conflict preconditions. Cloud projects the authoritative result.

## Payment vs Cancellation

- Cancellation validly commits first: ordinary Payment Verification/payment execution revalidates cancelled state and stops unless a distinct exceptional financial operation applies.
- Payment validly commits first: cancellation revalidates Paid state and becomes controlled paid cancellation with refund/void/reversal/credit disposition; original Payment remains.
- Unknown commit: E-07 correlation reconciliation, then Needs Finance Review if unresolved.

## Payment Proof Replacement vs Verification

Verification names the exact Proof version. It never silently substitutes a replacement. If V1 is superseded/ineligible, Verify(V1) returns stale evidence/conflict. V1 may remain independently eligible only if explicit later evidence-cardinality policy permits; exact rules remain Phase F/EA-05. Evidence lineage remains immutable under E-06.

## Payment Reference Conflict

Same logical command/reference retry returns the prior result under EA-03. The same reference claimed by different Order/customer/method/amount is a conflict, not a duplicate: do not reassign or apply latest; stop financial execution and route Needs Finance Review.

## Kitchen Release vs Cancellation

- Cancellation locks/revalidates and commits first: release sees cancelled state, is blocked, and creates no new Production Tickets.
- Release locks/revalidates and commits first: Production Released is an irreversible ordinary boundary; later ordinary cancellation is blocked/escalated into manager/service-recovery/exception workflow.
- Current cancellation code does not uniformly lock/reload like release; Phase F must implement one authoritative conditional contract rather than rely on present route timing.

## Order Modification vs Production

- Modification commits first: release reloads and uses the new authoritative Order snapshot.
- Production release commits first: ordinary commercial modification is blocked; a distinct exceptional correction/workflow is required.
- Never silently mutate the commercial/production snapshot already released.

## Order Modification vs Payment

Material quantity/price/discount/fee modification must invalidate and re-evaluate the applicable expected amount and Payment Clearance. Example: paid/cleared 100 followed by total 140 cannot remain silently Cleared. Exact Invoice/Payment/refund/accounting consequences remain EA-05; pricing authority/version remains EA-04.

## Projection vs Authority

```text
Cloud Order projection Preparing vs Laravel Cancelled
→ Laravel wins; Cloud projection repaired

Laravel Conversation projection vs Cloud Conversation
→ Cloud wins; Laravel projection repaired

Laravel Notification projection vs Cloud Notification
→ Cloud wins; Laravel projection repaired
```

`updated_at` ordering does not change authority.

## Duplicate vs Conflict

| Input | Meaning | Outcome |
| --- | --- | --- |
| Same stable command identity + same payload | Duplicate/replay | Return prior outcome; no new effect |
| Same identity + different payload | Idempotency conflict | Reject/conflict/review; do not overwrite prior command |
| Different incompatible commands against same state | Business conflict | Authoritative conditional transitions produce one winner |

EA-03 finalizes identity/hash/replay behavior.

## Concurrent Staff Actions

Approve vs reject, verify vs reject proof, and cancel vs release all carry expected authoritative state/version and real actor identity. Backend serializes/revalidates; first valid commit changes state; later action gets explicit conflict if preconditions fail. Audit both attempts where meaningful. Never silently refresh then execute the old decision against changed state.

## Manual Overrides

Reject a generic `force=true` overwrite. Exceptional cancellation, supervisor correction, financial reversal, production exception, and identity resolution are separate domain operations with specific permission, reason, actor, audit and authoritative validation. Supervisor status does not erase domain invariants.

## Conflict vs Retry

A deterministic conflict is terminal for that command version and must not be retried unchanged. Approve(V1) against current V2 requires reload/new approval. E-07 transport retry applies only until the conflict outcome is durably known.

## Conflict vs Needs Review

- Deterministic: actual state is known and action is no longer allowed/stale/already complete → explicit business conflict/denial/superseded outcome.
- Ambiguous: authority, winner, correlation, or financial effect cannot be proven → Needs Review or Needs Finance Review.
- Needs Review is not the default for every normal stale-state denial.

## Conflict Categories

Conceptual outcomes: Applied, Stale Revision, Conflict, No Longer Allowed, Already Completed, Superseded, Needs Refresh, Needs Review, Needs Finance Review and Blocked. EA-02 owns final names.

## Order Conflict Matrix

| Conflict | First valid authoritative outcome | Later action |
| --- | --- | --- |
| Customer Edit V2 vs Approve V1 | Cloud V2 | Approve V1 stale; reload V2 |
| Pre-acceptance Cancel vs fence | Cloud conditional transition | Loser gets explicit cancel/transition conflict |
| Post-acceptance Cloud cancel vs Laravel Order | Laravel Order authority | Submit Laravel cancellation request |
| Cancel vs Payment | First valid Laravel commit | Payment blocked, or paid-cancellation workflow |
| Cancel vs Kitchen Release | First valid Laravel commit | Release blocked, or cancellation escalated |
| Modify vs Release | First valid Laravel commit | Release new snapshot, or ordinary edit blocked |
| Staff Approve vs Reject | Conditional authority commit | Later command conflicts/reviews current state |

## Payment Conflict Matrix

| Conflict | Required behavior |
| --- | --- |
| Proof V2 vs Verify V1 | Exact version check; no substitution; conflict if V1 ineligible |
| REF-123 on Order A vs Order B | No reassignment/LWW; Needs Finance Review |
| Payment commit vs cancellation | Paid cancellation requires governed disposition |
| Repricing after clearance | Recompute payment requirement; never silently retain clearance |
| Verify vs proof rejection by another staff actor | First valid conditional decision; later stale action conflicts |
| Unknown Payment winner | Reconcile Laravel; Needs Finance Review if unresolved |

## Conversation / Notification Boundaries

Cloud sequence/version resolves public Conversation and Notification projection conflicts. Laravel-only Internal Notes remain separate and are not merged into public history. Staff action from stale projection is revalidated by the owning service; neither client timestamp nor Laravel projection overwrites Cloud authority.

## Identity / Pricing Deferrals

E-09 defines merge/link/unlink and same-person identity conflicts; E-08 forbids silent merge as conflict repair. EA-04 defines catalog/pricing authority/version; E-08 requires exact authoritative commercial inputs and re-consent/revalidation but does not choose prices.

## Offline Conflict Behavior

| Scenario | Authority | Automatic Winner? | Required Behavior |
| --- | --- | --- | --- |
| Customer edits while branch offline | Cloud | Latest valid Cloud revision | Local stale approval rejected |
| Customer cancels before local approval arrives | Cloud | Cancellation if committed before fence | No Laravel Order |
| Acceptance fence exists, Laravel temporarily offline | Handoff pending | No second winner | Block incompatible mutation; reconcile/recover |
| Laravel Order committed, Cloud confirmation delayed | Laravel after handoff | Laravel Order | Cloud eventually repairs/links projection |
| Local accepted Order cancelled while Agent offline | Laravel | Laravel | Cloud projection later updated |
| Payment commits while Cloud offline | Laravel | Laravel | Cloud projection later updated |
| Conversation updated while branch offline | Cloud | Cloud | Local projection later repaired |

Cloud unreachability before a fence means acceptance waits. Agent outage cannot transfer authority. Delayed projections do not permit incompatible effects.

## Cross-System Atomicity

No distributed database transaction is assumed. The acceptance fence plus stable command/revision correlation, conditional Laravel transaction, durable outcome, E-07 reconciliation and explicit indeterminate state form a recoverable handoff. If Laravel commit is unknown, neither Cloud nor Laravel may invent a second winner.

## Conflict Audit

Meaningful conflict evidence conceptually records aggregate/reference, expected and actual revision/state, command/action, actor, authority, category, winning authoritative outcome, losing/rejected action, safe reason, correlation, time, and review/escalation. Minimize sensitive payloads; EA-06 owns retention.

## Customer/Staff Presentation

Customer-safe examples: “Your order changed while under review,” “Cancellation could not complete because preparation started,” or “Payment is under financial review.” Do not expose lock exceptions, versions, indexes, or internal banking details.

Staff may see Order changed/refresh required, Customer cancelled, Payment already processed, Production released, Needs Review, or Needs Finance Review with authorized diagnostic context. A materially changed state requires a new explicit decision.

## Impact on Other Decisions

| Decision | E-08 Impact |
| --- | --- |
| E-09 Unified Customer Identity | Defines merge/link/unlink/same-person conflict winners and review. |
| E-10 External References | Defines stable aggregate/revision/command/fence/handoff references. |
| EA-01 Portal Authentication | Binds customer actor/session to edit/cancel/consent commands. |
| EA-02 Status Dictionary | Finalizes conflict, stale, superseded, review and blocked names. |
| EA-03 Idempotency | Defines duplicate versus conflicting payload and replay outcome semantics. |
| EA-04 Catalog/Pricing | Defines authoritative commercial versions and recalculation inputs. |
| EA-05 Financial Posting | Defines Payment/refund/repricing/cancellation financial consequences. |
| EA-06 Privacy/Retention | Defines conflict/fence/audit/payload retention and redaction. |

E-08 does not resolve these decisions.

## Approved Option

Approved: **Option A — Authority-Aware Conditional Conflict Resolution**. Authority first, exact revision/version and expected state second, one conditional authoritative commit third, then explicit conflict or governed alternative. Timestamp LWW and dual irreversible effects are rejected.

## Consequences

- Cross-authority Website acceptance waits when Cloud eligibility cannot be proven.
- Stale reviews/actions fail visibly and require a new decision.
- Laravel transactions must re-read/serialize/revalidate all racing operational/financial transitions.
- An acceptance fence introduces handoff complexity and temporary Pending Transition states but prevents Cancelled/Accepted split brain.
- Projections become repairable caches rather than competing truth.
- Exceptional operations become explicit governed commands, not force overwrites.
- Ambiguous cases can create review workload; deterministic conflicts avoid unnecessary manual review.

## Risks

| Risk | Conceptual mitigation |
| --- | --- |
| Customer cancellation ignored during acceptance | Cloud conditional fence on exact revision; cancel-before-fence wins. |
| Stale revision approved | Expected revision and explicit stale outcome; no substitution. |
| Dual Cloud/Laravel accepted/cancelled truth | Recoverable fence/correlation handoff and blocked ambiguity. |
| Payment posted after cancellation | Locked/conditional Laravel revalidation; first valid commit governs later action. |
| Duplicate Payment during conflict | EA-03 identity, uniqueness, correlation and Finance Review. |
| Kitchen release after cancellation | Serialize/reload Order; cancellation-first blocks ticket creation. |
| Ordinary cancel after release | Irreversible milestone routes to exception/manager workflow. |
| Clearance stale after repricing | Material changes invalidate/recompute payment requirement. |
| Old projection overwrites authority | Authority/version repair; projection never wins. |
| Clock-skew LWW | Never use timestamps alone for business order. |
| Generic force bypass | Separate permissioned exceptional commands; no universal force. |
| Acceptance stuck in transition | E-07 reconciliation, fencing lease recovery, Needs Review if unknown. |
| Manual review overload | Deterministic conflicts return explicit denial; review only ambiguity. |
| Conflict audit gaps | Preserve expected/actual/winner/loser/actor/correlation evidence. |

## Mitigations

Authority mapping, expected versions, conditional state transitions, exact revision/evidence binding, single-winner authoritative commits, acceptance fencing, projection repair, explicit loser outcomes, review only for ambiguity, governed exceptions, and conflict audit are mandatory Phase F constraints if approved.

## Rejected Alternatives

- **Global Last Write Wins:** timestamp order is not business authority; clocks, queues and network delay make it unsafe.
- **Client Last Write Wins:** browser/cache state cannot arbitrate business truth.
- **Dual apply then reconcile:** unacceptable for acceptance/cancellation, Payment, production and refund because both effects can be irreversible.
- **Generic force overwrite:** bypasses invariants and destroys meaningful audit/alternative workflow semantics.
- **Retry both operations:** repeats conflict rather than selecting one authoritative valid transition.

## Implementation Implications for Phase F

Phase F will require stable references, exact revision/version/state preconditions, authoritative conditional APIs/transactions, Cloud acceptance fence and recovery, Laravel locking/CAS as appropriate, domain conflict responses, audit, projection repair, and staff/customer conflict presentation. Exact columns, token/lease mechanism, status codes/names, endpoints and UI remain unapproved.

## Architecture Review Decisions

### Decision 1

**Question:** Do we approve Authority-Aware Conditional Conflict Resolution instead of global Last Write Wins?

**Decision:** Approved. Global/timestamp/client Last Write Wins is rejected.

**Rationale:** Business authority, exact versions and valid authoritative commits—not `updated_at`, clock, request arrival or browser click order—must determine the winner.

**Follow-up Decision:** Exact version/reference representation remains E-10/Phase F.

### Decision 2

**Question:** Should every race-prone state-changing command use exact expected revision/version/state preconditions?

**Decision:** Approved. Commands bind target reference, expected revision/version/state, exact business input, actor/scope, stable identity where applicable and operation-specific preconditions.

**Rationale:** A stale decision must fail visibly rather than silently apply to changed state.

**Follow-up Decision:** Exact fields/protocol belong to E-10/Phase F; duplicate semantics belong to EA-03.

### Decision 3

**Question:** Does Website Order acceptance require a conceptual Cloud conditional acceptance fence/claim for the exact staged revision before Laravel creates the Order?

**Decision:** Approved; the fence proves the revision is current, eligible, not cancelled/superseded and not in an incompatible transition.

**Rationale:** Cloud remains pre-acceptance authority, so a stale local projection cannot safely authorize the handoff.

**Follow-up Decision:** Exact fence/token/lease/API mechanism remains E-10/Phase F and EA-03 where idempotency applies.

### Decision 4

**Question:** If Cloud eligibility cannot be proven because Cloud is unavailable, must final Website Order acceptance wait?

**Decision:** Approved. Laravel may retain an approval intent but cannot create the authoritative Website Order from stale projection. Local-native POS/Call Center Orders are unaffected.

**Rationale:** Availability cannot override the system that still owns staged eligibility.

**Follow-up Decision:** Phase F defines the Pending/Awaiting Cloud Validation workflow; EA-02 finalizes its name.

### Decision 5

**Question:** For Laravel-authoritative competing transitions, does the first valid authoritative commit after locking/version/state/precondition validation govern later actions?

**Decision:** Approved for Cancellation vs Payment Verification, Cancellation vs Kitchen Release, Modification vs Release and other mutually exclusive transitions.

**Rationale:** The first valid commit changes authoritative state; the later action must revalidate and follow the new legal path rather than rely on click/arrival time.

**Follow-up Decision:** EA-05 defines paid-cancellation/financial consequences; Phase F defines locking/CAS transactions.

### Decision 6

**Question:** Must stale customer, Order and Payment-Proof revisions return explicit conflict and require a new valid decision?

**Decision:** Approved. No automatic latest-revision or latest-proof substitution.

**Rationale:** Approve(V1) cannot mean Approve(latest), and Verify Proof V1 cannot mean Verify(latest proof); consent and reviewed evidence must remain exact.

**Follow-up Decision:** E-10 defines revision references; EA-05/Phase F defines evidence eligibility/cardinality.

### Decision 7

**Question:** Should projection-versus-authority conflicts always be repaired from domain authority rather than newest timestamp?

**Decision:** Approved. Domain authority wins and the projection is repaired.

**Rationale:** Projections are caches/views, not competing truth, and clocks/network delay cannot transfer authority.

**Follow-up Decision:** E-07 governs repair delivery; EA-02 defines stale/conflict projection statuses.

### Decision 8

**Question:** Should deterministic conflicts return explicit denial/stale/refresh while only ambiguous conflicts enter review?

**Decision:** Approved. Deterministic conflict gets an explicit business outcome; unprovable business ambiguity enters Needs Review; financial ambiguity enters Needs Finance Review.

**Rationale:** Known stale or illegal actions do not require manual investigation, while unknown authority/effects must never be guessed.

**Follow-up Decision:** EA-02 finalizes vocabulary; E-07 governs reconciliation; EA-05 governs financial resolution.

### Decision 9

**Question:** Should generic supervisor/super-admin force overwrite be prohibited?

**Decision:** Approved. No generic `force=true`; exceptional correction is a separate permissioned, reasoned, audited domain operation that revalidates current authority.

**Rationale:** Administrative privilege does not permit erasing Payment, production, consent, accepted-revision or other historical truth.

**Follow-up Decision:** Phase F and later security/business policy define exact exceptional commands and permissions.

### Decision 10

**Question:** Should exact formats, idempotency, identity rules, financial consequences and final status vocabulary remain deferred?

**Decision:** Approved.

**Rationale:** E-08 fixes conflict behavior without prematurely selecting fields, tokens, merge policy, posting mechanics or enums.

**Follow-up Decision:** Version/reference/fence formats → E-10/Phase F; idempotency → EA-03; identity conflicts → E-09; financial consequences → EA-05; final conflict/status vocabulary → EA-02.

## Traceability

- E-01–E-04: fixed Cloud/Laravel authorities and projection boundaries.
- E-05: exact immutable staged revision and Cloud→Laravel authority handoff.
- E-06: Laravel financial authority, exact proof lineage, clearance/release separation and paid cancellation.
- E-07: unknown-outcome reconciliation, conflict-not-retry, targeted recovery and review routing.
- D1/D2: customer consent, staff authorization, audit, truthful states and no silent business effects.
- Current code: partial Laravel transactions/locks/state checks/idempotency/uniqueness, locked release versus unlocked cancellation gap, client caches/actions without expected versions, and no current staged acceptance fence.
- Approved on 2026-08-08: all ten Architecture Review Decisions above.
- Deferred: E-09/E-10 and EA-01 through EA-06 remain pending; Phase F remains not started.
