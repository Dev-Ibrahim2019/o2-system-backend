# ADR E-07 — Offline and Retry Policy

## Status

**[x] Approved — 2026-08-08**

Approved decision: **Option A — Durable Adaptive Retry + Targeted Reconciliation**, using exponential backoff with jitter, priority-aware scheduling, idempotent replay, and governed review for unresolved ambiguity.

This ADR approves behavioral failure, retry, recovery, acknowledgement, cursor, backlog, and review semantics only. It does not approve schemas, queue technology, APIs, jobs, schedulers, monitoring infrastructure, final status names, exact idempotency keys, or Phase F application work.

## Context

E-02 approves at-least-once durable synchronization between the Cloud Integration Service and an outbound-only Local Sync Agent. At-least-once transport necessarily permits timeout, duplicate delivery, lost acknowledgement, delayed work, restart, backlog, and replay. E-07 defines how those conditions remain safe without claiming that a failed HTTP exchange proves a business operation failed.

The recommended model is:

```text
Durable Adaptive Retry
+ Exponential Backoff with Jitter
+ Priority-Aware Scheduling
+ Idempotent Replay
+ Targeted Reconciliation
+ Needs Review for unresolved ambiguity
```

## Approved E-01 through E-06 Constraints

- E-01: independent Cloud Integration Service, outbound-only Local Sync Agent, Laravel operational/financial authority, and explicit operational ownership.
- E-02: Cloud→Local durable HTTP pull/long polling; Local→Cloud Outbox HTTP push; adaptive polling plus reconciliation; WebSocket/SSE is Post-MVP hint-only; at-least-once transfer.
- E-02 initial tunable targets remain: active receipt p95 at most 10 seconds; normal lag under 1 minute; warning over 5 minutes; critical over 15 minutes; active polling around 3–5 seconds; idle/long poll around 20–30 seconds; hard fallback around 5 minutes; degraded exponential backoff; bounded fast catch-up.
- E-02 priority order remains: critical financial/order outcomes, active website orders, customer identity reviews, messages, notifications, catalog projections, analytics/background.
- E-03: staff public replies may be Pending Sync and are not Sent before Cloud acceptance; per-Conversation sequence is authoritative.
- E-04: Cloud owns Customer Notifications; projections and delivery facts must not be invented during outage.
- E-05: one staged Order revision produces at most one Laravel Order; unknown acceptance outcomes require correlation/reconciliation.
- E-06: one logical Payment verification produces one financial outcome; unknown financial outcomes require reconciliation then Finance Review.
- E-08 through E-10 and EA-01 through EA-06 remain pending. Phase F remains not started.

## Current-System Evidence

### Laravel backend

- Laravel has standard `jobs`, `job_batches`, and `failed_jobs` migrations and queue configuration for sync/database/Beanstalkd/SQS/Redis with framework `retry_after` settings. No inspected application class implements `ShouldQueue`, and no inspected cross-system integration Job, worker, Inbox, Outbox, cursor, lease, priority lane, poison quarantine, or reconciliation service exists.
- No inspected application schedule establishes integration polling, backlog recovery, or baseline reconciliation. Framework queue availability must not be mistaken for an approved cross-system delivery policy.
- `IdempotencyService` stores `(scope,key)` uniquely, hashes canonical payloads, locks records, rejects key reuse with a different payload, and can replay completed responses. A `pending` record currently produces a conflict rather than a complete unknown-outcome recovery workflow.
- Call Center financial execution uses durable idempotency identity, row locks, a transaction, unique payment-reference/confirmation controls, and `financial_committed`. An identical retry does not recreate the financial phase. Kitchen release is separate: release failure records `release_failed`, preserves the Payment, and may be retried.
- Order confirmation and several financial/customer services use database transactions and `lockForUpdate`; Production Ticket item uniqueness and payment/reference constraints supply local duplicate defenses. These do not form a general Cloud↔Local retry contract, and deployed migration state remains unverified.
- External PBX HTTP calls specify 10–30 second timeouts but no inspected general exponential retry/reconciliation policy. A timeout is handled as an ordinary request failure, not durable integration evidence.
- Redis client configuration includes decorrelated-jitter reconnect settings. That is client connectivity configuration, not the E-07 business retry policy, and E-07 does not require Redis.

### Admin React application

- CRM screens commonly expose manual “retry” buttons. Some local views poll/tick with intervals, and one customer profile path attempts a small client-side incremental delay. No inspected durable browser queue, cross-system cursor, Inbox/Outbox, priority scheduler, or reconciliation workflow exists.
- The Call Center checkout understands that when financial execution has committed but kitchen release failed, the subsequent action retries the release phase rather than collecting another Payment. This is valuable current evidence for separating business phases.
- Browser manual retries and polling are usability mechanisms only; they cannot own durable commands after tab closure or prove cross-system outcomes.

### Next.js website

- The website has no inspected integration submission/retry layer. Checkout still opens WhatsApp, while intervals/timeouts are presentation behaviors such as slides and transient feedback.
- No durable staged-Order submission, Payment Proof retry, command identity, offline queue, cursor, or reconciliation behavior exists. E-07 defines target architecture rather than approving existing website retry code.

### Evidence qualification

Findings are repository-based. Actual queue workers, scheduler deployment, failed-job contents, database migrations, network topology, and production configuration remain unverified runtime concerns.

## Decision Drivers

- Prevent duplicate Orders, Payments, refunds, messages, and production effects.
- Retain durable work through hours-long branch or dependency outage.
- Recover unknown outcomes without blind re-execution.
- Isolate broken/background lanes from critical financial/order work.
- Bound retry storms and recovery pressure on Cloud, Laravel, databases, and networks.
- Detect gaps, stale versions, poison work, and silent drift.
- Provide truthful staff/customer states and explicit operational ownership.
- Preserve authority boundaries during reconciliation.

## Core Invariants

```text
Transport failure ≠ Business failure
Timeout ≠ Operation failed
Lost ACK ≠ Operation did not commit
Unknown result ≠ permission to execute again
Retry Scheduled ≠ business operation failed
Needs Review ≠ transport offline
Completed = required durable outcome is known
```

Retry transport aggressively where safe. Retry a business effect only when stable identity and receiver behavior make replay duplicate-safe; otherwise query/reconcile first.

## Options Considered

### Option A — Durable adaptive retry plus targeted reconciliation

Durable work, operation-class policies, exponential backoff with jitter, isolated priority lanes, idempotent replay, unknown-outcome reconciliation, quarantine, and governed review.

### Option B — Fixed retry count plus dead-letter after N attempts

Every operation receives the same fixed attempt budget, such as three tries, regardless of outage duration, safety, or error classification.

### Option C — Continuous blind retry until success

All failures retry indefinitely without distinguishing transient transport from permanent invalidity or unknown side-effect outcome.

## Detailed Comparison

| Criterion | Adaptive Durable Retry | Fixed N Retries | Blind Infinite Retry |
| --- | --- | --- | --- |
| Network outage recovery | Strong | Weak | Acceptable |
| Lost ACK safety | Strong | High Risk | High Risk |
| Duplicate Order protection | Strong | Weak | High Risk |
| Duplicate Payment protection | Strong | Weak | High Risk |
| Permanent validation failure | Strong | Acceptable | High Risk |
| Poison event handling | Strong | Acceptable | High Risk |
| Backpressure | Strong | Weak | High Risk |
| Priority support | Strong | Weak | Weak |
| Financial ambiguity | Strong | High Risk | High Risk |
| Reconciliation | Strong | Weak | Weak |
| Long branch outage | Strong | High Risk | Acceptable |
| Operational visibility | Strong | Acceptable | Weak |
| Scalability | Strong | Weak | High Risk |
| Recovery storms | Strong | Acceptable | High Risk |
| Authority safety | Strong | Weak | High Risk |

Fixed N cannot distinguish a five-hour outage from permanent validation failure and may abandon valid work. Blind retry can hammer dependencies or repeat indeterminate financial/order effects. Option A has greater implementation complexity but is the only option that addresses both durable recovery and business safety.

## Recommended Retry Model

Approved: **Option A — Durable Adaptive Retry + Targeted Reconciliation**.

```text
Durable Work Item
→ attempt under lease
→ known success: complete
→ known transient failure: backoff + retry
→ unknown business outcome: query/reconcile
→ permanent invalid/conflict: Blocked / Needs Review
→ unresolved financial ambiguity: Needs Finance Review
```

Conceptual work states include Pending, In Flight/Leased, Awaiting Outcome, Retry Scheduled, Delayed, Needs Review, Needs Finance Review, Completed, and Permanently Failed/Blocked. EA-02 owns final names.

## Operation Classes

| Class | Examples | Automatic behavior |
| --- | --- | --- |
| A — Safe projection/event replay | Conversation/Notification/Catalog/tracking/read-model/analytics projections | Long-lived automatic retry when application is version-aware and idempotent; stale versions do not overwrite newer state. |
| B — Idempotent business command | Order acceptance, Payment verification, complaint conversion, staff public reply, cancellation request | Retry only with stable command identity and receiver-guaranteed duplicate-safe outcome. |
| C — Unknown side-effect outcome | Order, Payment, refund, or release may have committed before response loss | Do not blindly re-execute; query/reconcile durable correlation, recover prior outcome, then review if unresolved. |
| D — Permanent/poison work | Invalid schema/version, unsupported command, corrupt payload, missing permanent mapping, authorization denial | Stop normal retry; quarantine/block with reason, audit, alert, and governed remediation. |

EA-03 finalizes exact idempotency. An item may move between operational handling classes when evidence changes—for example, a transport timeout for Class B becomes Class C until outcome is known.

## Transport vs Business Retry

An HTTP exchange can be retried at the transport layer only within the business safety contract. Connection failure before any possible receipt may be retried; response timeout after possible receipt creates an unknown outcome for side-effecting commands.

```text
POST accept-order
→ Laravel commits Order
→ response lost
→ caller observes HTTP failure
→ query stable command/staged-revision correlation
→ return existing outcome or Needs Review
```

The same rule applies to Payment Verification. HTTP failure never authorizes creation of another Order or Payment.

## Retry Ownership

The durable sender/outbox owns work until it receives the required durable terminal outcome or explicitly transfers it into governed review. Laravel Local Outbox owns Local→Cloud publication; the Cloud durable command queue owns Cloud→Local delivery. Agent memory does not become the authority.

Sending bytes, receiving HTTP 2xx from a transport proxy, or closing a socket does not delete ownership. Completion requires the acknowledgement level appropriate to the operation.

## Acknowledgement Semantics

| Acknowledgement | Meaning | Does it complete a business command? |
| --- | --- | --- |
| Transport Receipt | Receiver/network endpoint obtained bytes | No |
| Durable Intake Ack | Receiver durably stored identity/payload | No, unless intake is the entire required effect |
| Business Outcome Ack | Authoritative domain outcome committed or recovered | Yes for command execution, subject to publication confirmation |
| Projection Confirmation | Destination applied/recognized authoritative version | Completes projection delivery evidence |

Side-effecting command senders retain Awaiting Outcome until the authoritative result is known. A lost outcome acknowledgement triggers correlation query/reconciliation, not blind execution.

## Retry Backoff

Use exponential backoff with bounded jitter and operation-specific maximum cadence. Early transient failures may retry quickly; sustained outage moves to a degraded cadence. Proven dependency recovery reduces/resets backoff and activates bounded catch-up.

- Critical lanes receive more aggressive but bounded budgets.
- Background lanes back off more strongly.
- Rate-limit hints such as `Retry-After` are honored within safety limits.
- Connectivity flapping must not continuously reset all work into simultaneous immediate retries.
- One failing lane does not increase healthy-lane backoff.
- Exact curves, caps, and concurrency are Phase F configuration validated by staging/load testing.

## Retry Termination

There is no universal global retry count. Terminal handling depends on operation class, elapsed age, error class, idempotency confidence, dependency health, business deadline, and review need.

- Temporary outage: keep durable work and retry for hours under controlled cadence.
- Permanent validation/schema/authorization failure: stop normal retry immediately after classification.
- Unknown side effect: stop execution retry, reconcile now, then review if unresolved.
- Expired business intent: do not silently drop; record a governed terminal outcome according to authority/policy.

## Error Classification

| Error Class | Automatic Retry | Reconcile | Review | Alert/Action |
| --- | --- | --- | --- | --- |
| Network timeout before known receipt | Yes, bounded | If receipt ambiguity exists | Later by age/impact | Threshold-based |
| Dependency unavailable | Yes with backoff | On recovery/critical age | Not initially | Warning/critical by E-02 lag |
| 429/rate limited | Yes, honor hint + jitter | If prolonged | No initially | Surface sustained throttling |
| Retryable 5xx | Yes, bounded/adaptive | On repeated/unknown outcome | By class | Alert by rate/age |
| Permanent validation failure | No | No blind replay | Needs Review/Failed | Explicit reason/alert |
| 401/403 authorization | No blind retry | No | Needs Review/Blocked | Security/operations alert; credential repair |
| Schema/version incompatibility | No normal retry | Contract recovery/backfill | Needs Review | Integration-owner alert |
| Business conflict | No generic retry | Authority-aware query | E-08 review | Domain owner alert |
| Lost business ACK | No blind re-execution | Mandatory | If unresolved | High-priority alert |
| Duplicate command | Return prior outcome | Verify correlation | Only if conflicting | Low/no alert when expected |
| Poison/corrupt payload | No normal retry | No | Needs Review/Blocked | Quarantine and alert |

## Lease / Claim Model

Durable workers use bounded claims:

```text
Pending → Claimed/In Flight → Completed/Acked
```

Worker death permits the lease to expire and makes the item eligible for recovery. Lease expiration does not prove a business effect failed. Side-effecting work still uses stable identity and reconciliation. Long operations may renew claims; fencing/generation evidence should prevent stale workers from finalizing ownership after a newer claimant. Exact durations and clock-skew tolerance remain configurable.

## Inbox / Outbox Durability

- Persist identity, class/lane, authority/correlation, payload/version, attempt evidence, next eligibility, lease/owner, outcome state, and safe failure metadata conceptually.
- Do not delete unresolved items.
- Completed evidence remains long enough for replay detection, reconciliation, audit, and incident investigation.
- Agent restart reloads durable state; it never treats in-memory loss as completion.
- Exact schema, retention, cleanup, and deduplication windows remain EA-03/EA-06/Phase F.

## Pending Sync

```text
Staff public reply during outage
→ durable local command
→ Pending Sync
→ class-aware retry
→ Cloud durable acceptance
→ authoritative projection confirmation
→ Sent
```

Permanent failure becomes Failed Sync/Needs Review conceptually, with the original reply retained. The UI must never claim Sent merely because the Agent attempted HTTP delivery.

## Order Acceptance Retry

One staged reference/revision/command identity may produce at most one Laravel Order. After timeout or lost acknowledgement, query the durable correlation and return the committed Order outcome. If correlation cannot establish whether an Order exists, enter Needs Review. Never issue an uncorrelated create retry.

## Payment Verification Retry

One logical verification produces one financial result. After timeout/lost acknowledgement, query Payment/verification/idempotency correlation and republish the recovered result. If financially indeterminate, enter Needs Finance Review. Never execute another Payment to “make sure.” Kitchen release failure after committed Payment retries/reconciles the separate release operation only.

## Cursor Semantics

Cloud→Local pull uses a durable opaque cursor/checkpoint whose exact format remains E-10/Phase F. The cursor advances only after the boundary necessary to recover all work before it: normally durable intake of each item plus preservation of per-item application/outcome state, not mere byte receipt.

Partial batches record a contiguous durable boundary. Do not advance beyond an unhandled gap when that could make recovery ambiguous. A cursor need not mean every business effect completed if each durably received item remains independently tracked, but it must never make unresolved work undiscoverable.

## Cursor Gaps

When expected sequence/version 105 is followed by 107, detect the gap, pause the affected aggregate/partition if ordering matters, request targeted backfill/reconciliation, then resume after continuity or authoritative snapshot repair. Do not reset to latest and do not globally freeze unrelated scopes. A Conversation gap cannot block Payment synchronization.

## Out-of-Order Delivery

Do not use timestamps alone or let an older version overwrite a newer authoritative projection. Depending on the aggregate contract: buffer while filling a gap, ignore an already-applied stale version with audit, request backfill, or reconcile against authority. E-08 owns business conflict winners; E-07 owns delivery recovery.

## Targeted Reconciliation

Mandatory after Agent recovery, cursor gap, lost acknowledgement, unknown business outcome, critical lag, out-of-order event, projection-version mismatch, manual incident recovery, and duplicate/correlation conflict. Compare stable references, versions, hashes, and authoritative outcomes rather than blindly replaying all history.

Authority during reconciliation:

| Domain fact | Authority |
| --- | --- |
| Conversation/Public Message | Cloud |
| Customer Notification | Cloud |
| Staged Website Order before acceptance | Cloud |
| Accepted Order/production | Laravel |
| Payment Verification/Payment/Invoice/Accounting | Laravel |
| Payment Proof binary | Cloud |

## Baseline Reconciliation

Periodic baseline reconciliation detects silent drift, missed events, stale projections, cursor anomalies, and missing critical correlated outcomes. It is distinct from ordinary pull fallback: E-02’s approximately five-minute hard fallback may trigger/guide an initial operational check, while deeper reconciliation cadence and scope remain configurable based on cost and risk.

## Agent Recovery

```text
Agent starts
→ validate local durable state
→ establish Cloud and Laravel connectivity
→ load cursor/Inbox/Outbox/lease state
→ enter Recovering
→ critical backlog first
→ targeted reconciliation
→ bounded catch-up
→ Healthy
```

Recovery never clears queues, resets cursor to latest, or marks pending items complete. Agent health states remain conceptual: Healthy, Delayed, Degraded, Offline, Recovering, and Blocked; EA-02 finalizes names.

## Recovery Backpressure

After a long outage, cap concurrency/rate by lane and dependency, reserve capacity for live critical work, batch where safe, and adapt to 429/5xx/latency. Catalog/analytics catch-up cannot overwhelm Laravel, Cloud, databases, or current Order/Payment work. Bounded fast catch-up is faster than degraded cadence but never unbounded.

## Priority Lanes

| Priority | Lane | Retry behavior |
| --- | --- | --- |
| 1 | Critical financial/order outcomes | Reserved capacity, aggressive bounded retry/reconciliation, Finance ownership for ambiguity |
| 2 | Active website orders | Low-latency budget, protected from background flood |
| 3 | Customer identity reviews | Isolated budget; identity ambiguity remains E-09 |
| 4 | Messages | Preserve per-Conversation order; cannot block financial lanes |
| 5 | Notifications | Deduplicate/version; protect truthful critical notices |
| 6 | Catalog projections | Batch/back off more strongly; authority remains EA-04 |
| 7 | Analytics/background | Lowest urgency, strongest backpressure, still durable if required |

Each lane has independent concurrency, rate, backoff, and operational budget. A poison analytics event cannot block Payment outcomes.

## Fairness and Age Promotion

Priority is not starvation. Reserve a small share for lower lanes, schedule weighted/fair work within dependency limits, and promote sufficiently old eligible work without letting it exceed business-safety constraints. Poison work is removed from normal scheduling into quarantine so a partition can progress where ordering permits. Exact weights and age thresholds are tunable.

## Poison / Permanent Failure

Invalid schema, corrupted payload, unsupported command, permanent validation error, and exhausted safe contract recovery stop normal retries. Preserve the item and safe diagnostic evidence, quarantine/block the affected scope, alert its owner, and expose Needs Review. Do not log sensitive payloads or let one poison item globally block unrelated lanes.

## Contract-Version Failure

Unknown required version is not transient. Stop normal application, preserve raw durable evidence securely, advertise supported versions/capability, request compatible backfill/upgrade, and alert Integration Operations. Optional unknown fields may follow forward-compatible rules only when the contract explicitly permits them.

## Authentication / Authorization Failure

401/403 is not a generic transient 5xx. Stop blind command retry, validate credentials/clock/scope, alert Security/Integration Operations, and retain work. After governed credential repair, resume or reconcile. A domain permission denial may be a permanent business outcome/Needs Review; never change actors silently.

## Rate Limiting

Honor server retry hints, apply jitter, reduce affected lane concurrency, and keep critical lanes isolated. Persistent throttling changes health/backpressure state and alerts the dependency owner. Do not bypass limits with uncontrolled parallel clients.

## Cloud Outage

Local POS/Laravel operations that do not require Cloud may continue locally. Local Outbox remains durable; Cloud-authoritative Portal submission, proof upload, Conversation intake, and Notification inbox are unavailable. Staff replies remain Pending Sync. No Cloud acknowledgement or customer projection is invented.

## Laravel Outage

Cloud continues durable website submission, Conversation, Notification inbox, and Payment Proof intake. Laravel-authoritative Order acceptance, Payment Verification, Payment Clearance, and production release are unavailable/queued. Customer state remains Received/Under Review or stale, never falsely committed.

## Agent Outage

Cloud and Laravel may each continue their own authoritative local capabilities, but cross-system transfer pauses. Cloud command queue and Laravel Outbox retain ownership. Agent health becomes Offline/Delayed and recovery follows durable state plus reconciliation.

## Network Flapping

Use jittered backoff with stability evidence before fully resetting cadence, retain leases safely, avoid duplicating in-flight work, and probe dependencies with bounded requests. Repeated flap transitions to Degraded and preserves critical capacity without retry storms.

## Needs Review

Needs Review is a governed business/contract ambiguity requiring an owner and reason; it is not a synonym for ordinary offline delay. Examples include unresolved Order acceptance, conflicting correlation, permanent mapping, or authorization/business conflict. CRM/Call Center owns order/customer ambiguity with escalation according to domain.

## Needs Finance Review

Unknown Payment/refund/posting outcome, duplicate financial correlation, or Invoice/Payment inconsistency stops financial execution retry and routes to Finance. The durable item remains correlated and auditable until resolution; transport recovery alone does not auto-resolve financial ambiguity.

## Alerting and Ownership

Signals include last successful Cloud and Laravel contact, last durable acknowledgement, oldest pending age, backlog counts, cursor progress/gaps, retry/failure rates, poison presence, reconciliation state, and unknown outcomes.

| Evidence | Conceptual health/action | Owner |
| --- | --- | --- |
| Contacts/acks current, cursors progressing, bounded backlog | Healthy | Integration Operations observes |
| Lag approaches/exceeds normal target or backlog age grows | Delayed | Integration Operations |
| Sustained failures/backoff, warning lag over ~5 min | Degraded | Integration Operations + dependency owner |
| No viable Cloud/Laravel path, critical lag over ~15 min | Offline/Critical | Integration Operations incident; domain owners informed |
| Connectivity restored while backlog/reconciliation active | Recovering | Integration Operations |
| Poison/auth/version/manual ambiguity prevents scope progress | Blocked | Integration Operations plus CRM/Call Center or Finance |

These thresholds align with E-02 starting targets and remain tunable. Customer/staff views show honest delayed/stale/pending/review states, not raw sensitive errors.

## Offline Capability Matrix

| Capability | Cloud Offline | Laravel Offline | Agent Offline | Branch Internet Offline |
| --- | --- | --- | --- | --- |
| Existing local POS operations | Local only/available if Laravel local | Unavailable | Local only | Local only |
| Website order submission | Unavailable | Cloud only/queued | Cloud only/queued | Cloud only/queued |
| Website order acceptance | Unavailable/queued | Unavailable/queued | Queued | Queued |
| Conversation submission | Unavailable | Cloud only available | Cloud only; local projection stale | Cloud only |
| Staff public reply | Pending Sync/queued | Local entry may queue if available | Pending Sync | Pending Sync |
| Notification inbox | Unavailable/stale client cache | Cloud available; Laravel projection stale | Cloud available; local projection stale | Cloud available externally; branch local stale |
| Payment Proof upload | Unavailable | Cloud available/Under Review | Cloud available; review transfer queued | Cloud available externally; branch review queued |
| Payment Verification | Unavailable projection/queued | Unavailable | Queued | Queued |
| Kitchen release | Local only if Laravel/payment state available | Unavailable | Local Laravel capability only | Local only |
| Customer tracking | Cloud unavailable/stale | Cloud stale projection | Cloud stale projection | Cloud available externally; local stale |

“Available” never overrides domain authority. Exact degraded UI behavior remains Phase F.

## Failure Scenario Matrix

| Scenario | Retry/Backoff | Reconcile | Manual review | Owner | Visible state |
| --- | --- | --- | --- | --- | --- |
| Cloud request timeout | Adaptive+jitter by class | If receipt/outcome unknown | By age/ambiguity | Sender/Integration Ops | Pending/Delayed |
| Laravel request timeout | No blind side-effect retry | Mandatory for commands | Needs Review/Finance if unresolved | Cloud queue + domain owner | Awaiting Outcome |
| Agent crash mid-delivery | Lease expiry then class-safe retry | On restart | If unknown effect | Integration Ops | Delayed/Recovering |
| Agent restarts | Bounded catch-up | Mandatory targeted | Only anomalies | Integration Ops | Recovering |
| Lost durable acknowledgement | No blind business re-execution | Mandatory | If unresolved | Sender + authority owner | Awaiting Outcome |
| Cloud 5xx | Adaptive backoff | Repeated/critical | Later if permanent | Integration Ops/Cloud | Delayed |
| Laravel 5xx | Adaptive for safe work | Commands if outcome possible | Domain/Finance if unresolved | Integration Ops/Laravel | Delayed/Awaiting Outcome |
| 401/403 | Stop blind retry | Credential/scope validation | Blocked/Needs Review | Security/Integration Ops | Blocked |
| 429 | Honor hint+jitter | If prolonged drift | No initially | Sender/Cloud or Laravel owner | Delayed |
| Validation failure | Stop | No blind replay | Needs Review/Failed | Producer/domain owner | Action required |
| Cursor gap | Pause affected scope | Targeted backfill mandatory | If irreparable | Integration Ops | Delayed/stale affected scope |
| Out-of-order event | Buffer/ignore stale safely | Targeted | If conflict | Integration Ops/domain | Stale/unchanged projection |
| Duplicate event | Idempotent apply/prior outcome | Verify version/correlation | Only conflicting duplicate | Receiver | No duplicate effect |
| Duplicate command | Return prior outcome | Query durable identity | If payload conflict | Authority owner | Stable prior outcome |
| Poison payload | Quarantine; no normal retry | Contract remediation only | Needs Review | Integration Ops/producer | Blocked affected scope |
| Large backlog | Lane-limited bounded catch-up | Sample/target critical drift | No initially | Integration Ops | Recovering/Delayed |
| Repeated network flap | Backoff with stability threshold | On stable recovery | By critical age | Integration Ops/network | Degraded |
| Order acceptance outcome unknown | Do not recreate Order | Query staged/revision/command correlation | Needs Review if unresolved | CRM/Call Center | Awaiting Outcome/Needs Review |
| Payment verification outcome unknown | Do not create Payment | Query verification/Payment correlation | Needs Finance Review | Finance | Under Review/Finance Review |
| Kitchen release failed after payment | Retry separate release safely | Query Order/ticket/release state | Operations if persistent | CRM/operations | Paid; release failed/pending |

## Metrics and Observability

Measure Agent availability, Cloud/Laravel contact age, sync lag, Inbox/Outbox counts, oldest pending age by lane, attempts/retry rate, success/failure classifications, recovery time/success, reconciliation count/duration, cursor gaps, poison count, unknown outcomes, Needs Review/Finance Review count and age, critical command latency, lease expiry, and backlog drain rate. Do not select a monitoring vendor.

## Security

- Authenticate every Agent/endpoint and authorize operation/branch scope.
- Do not mutate actor identity on retry.
- Protect idempotency/correlation identifiers from guessing and cross-tenant reuse.
- Minimize payload/error logs; encrypt sensitive durable work and credentials.
- Audit quarantine release, manual replay, cursor repair, review resolution, and credential changes.
- Prevent a compromised Agent from resetting cursors, acknowledging unseen work, or escalating priority without authorization.

## Retention

Unresolved durable work is never deleted. Completed delivery/outcome evidence remains sufficiently long for duplicate detection, reconciliation, audit, and incident response. Exact retention, redaction, deletion, legal hold, and idempotency-window durations remain EA-03/EA-06.

## Impact on Other Decisions

| Decision | E-07 Impact |
| --- | --- |
| E-08 Conflict Resolution | Defines who wins business conflicts; E-07 only fetches/replays authority safely. |
| E-09 Unified Customer Identity | Defines identity-related retry/review resolution; no retry may silently merge. |
| E-10 External References | Defines durable command, aggregate, cursor, proof, Order, and Payment correlation. |
| EA-01 Portal Authentication | Defines uploader/customer authentication and session/replay behavior. |
| EA-02 Status Dictionary | Finalizes retry, delayed, blocked, review, and health vocabulary. |
| EA-03 Idempotency | Defines keys, scope, request hashing, replay outcome, and retention windows. |
| EA-04 Catalog/Pricing | Defines catalog authority/version used during projection recovery and Order revalidation. |
| EA-05 Financial Posting | Defines financially atomic/recoverable outcome queried during reconciliation. |
| EA-06 Privacy/Retention | Defines Inbox/Outbox/audit/payload retention and deletion controls. |

E-07 does not resolve any of these decisions.

## Recommended Option

Approved: **Option A — Durable Adaptive Retry + Targeted Reconciliation**. It treats connectivity failure as durable scheduling evidence, not proof of business failure; reserves replay for duplicate-safe operations; and makes targeted authority-aware reconciliation the first response to unknown side effects.

## Consequences

- Longer outages retain work instead of exhausting a universal attempt count.
- Permanent invalidity becomes visible quickly instead of consuming infinite retries.
- Operational implementation is more complex: durable state, classification, leases, lanes, reconciliation, health, and review ownership are required.
- Customer/staff state becomes more truthful but may remain Pending/Awaiting Outcome while authority is unavailable.
- Recovery prioritizes live critical outcomes without abandoning aged lower-priority work.
- Exact numerical tuning remains operational configuration, allowing safe load-test adjustment without changing the ADR.
- Duplicate/loss risk is substantially reduced at the cost of increased operational complexity and mandatory durable Agent state.

## Risks

| Risk | Conceptual mitigation |
| --- | --- |
| Duplicate Laravel Order/Payment | Stable identity, receiver idempotency, correlation query, uniqueness and review. |
| Lost command/acknowledgement | Durable sender ownership and multi-level acknowledgements. |
| Retry/recovery storm | Exponential backoff, jitter, bounded catch-up and dependency budgets. |
| Backlog starvation | Lane isolation, reserved fairness and age promotion. |
| Poison blocking partition | Quarantine affected scope; targeted repair without global freeze. |
| Infinite retry of permanent failure | Error classification and terminal Blocked/Needs Review. |
| Premature cursor advancement/skipped event | Contiguous durable boundary, gap detection and backfill. |
| Out-of-order overwrite/stale projection | Version authority; ignore/buffer/reconcile, never timestamp-only overwrite. |
| Unbounded queue growth | Backpressure, observability, capacity alerting; no silent deletion. |
| Agent recovery overload | Critical-first bounded catch-up and live-work reservation. |
| Authorization retry loop | Stop blind retry and alert Security/Integration Operations. |
| Clock-skew lease issue | Server/durable clock evidence, bounded leases, renewal and fencing concept. |
| Unknown financial outcome | Stop execution retry; Finance reconciliation and review. |
| Manual review backlog | Ownership, age/SLA metrics, escalation and capacity planning. |

## Mitigations

The durable ownership rule, class-aware retry, acknowledgement separation, operation-specific jittered backoff, leases, priority isolation, fairness, quarantine, cursor safety, authority-aware reconciliation, review routing, and observable health transitions are mandatory Phase F constraints if this proposal is approved.

## Rejected Alternatives

- **Universal fixed retry count:** three attempts cannot represent both a five-hour branch outage and a permanent validation failure.
- **Blind infinite retry:** it can hammer dependencies and repeat an indeterminate Order/Payment/refund effect.
- **Clearing queues on recovery:** silently loses work and destroys audit/reconciliation evidence.
- **Resetting cursor to latest:** skips durable work and conceals gaps.
- **Global FIFO without priority:** background floods can delay critical Order/Payment outcomes and poison items can block all work.
- **Timestamp-based last-write wins:** violates authority/version ordering and belongs to neither safe retry nor E-08 conflict policy.

## Implementation Implications for Phase F

Phase F will need durable Cloud command and Laravel Outbox/Inbox mechanisms, stable references, versioned contracts, bounded claims, per-lane scheduling/backpressure, error classification, targeted/baseline reconciliation, health/metrics, quarantine/replay controls, secure audit, and authority-aware status projections. Technology, schemas, jobs, APIs, retry curves, lease durations, weights, and retention are deliberately unapproved here.

## Architecture Review Decisions

### Decision 1

**Question:** Do we approve Durable Adaptive Retry + Exponential Backoff with Jitter + Targeted Reconciliation instead of a universal fixed retry count?

**Decision:** Approved. No universal fixed retry count and no blind infinite retry.

**Rationale:** A long network outage and a permanent validation failure require different safe terminal behavior; unknown side effects require reconciliation rather than another execution.

**Follow-up Decision:** Exact seconds, caps, cadence, concurrency, lane weights, and age thresholds remain Phase F configuration validated through staging/load testing.

### Decision 2

**Question:** Should retry policy be classified by operation type and business safety rather than one global rule?

**Decision:** Approved using conceptual Classes A/B/C/D: safe projection replay, idempotent business commands, unknown side-effect outcomes, and permanent/poison failures.

**Rationale:** Automatic replay safety depends on business effect, stable identity, error class, and whether the outcome is known.

**Follow-up Decision:** EA-03 defines exact duplicate-safe identity and replay contracts; EA-02 finalizes state names.

### Decision 3

**Question:** When Order Acceptance or Payment Verification has an unknown side-effect outcome, must durable correlation be queried/reconciled before re-execution?

**Decision:** Approved. No blind Order recreation or repeated Payment execution.

**Rationale:** Timeout or lost acknowledgement may occur after Laravel committed the authoritative result.

**Follow-up Decision:** E-10 defines stable references; EA-03 defines idempotency; EA-05 defines financially recoverable outcome boundaries.

### Decision 4

**Question:** Should unresolved business ambiguity become Needs Review and unresolved financial ambiguity become Needs Finance Review?

**Decision:** Approved; neither ambiguity retries indefinitely or becomes guessed success/failure.

**Rationale:** Automated execution must stop when it cannot safely determine the authoritative outcome.

**Follow-up Decision:** EA-02 defines final vocabulary; E-08 defines business conflict winners and resolution paths.

### Decision 5

**Question:** Should permanent validation, schema, authorization, and poison failures stop normal retry?

**Decision:** Approved. Preserve and quarantine/block the durable item, route to Needs Review, record an explicit reason, and alert the responsible owner. Do not delete the work.

**Rationale:** Repeated attempts waste capacity, can create security pressure, and may block unrelated work without changing a permanent condition.

**Follow-up Decision:** Phase F defines quarantine tooling; EA-06 defines payload/audit retention and redaction.

### Decision 6

**Question:** Should durable workers use bounded leases/claims so crashes can recover work?

**Decision:** Approved, including conceptual fencing/generation protection. Lease expiry does not prove the business operation failed.

**Rationale:** Claims coordinate workers, while stable identity/reconciliation protects against effects committed before a lease expired or a stale worker returned.

**Follow-up Decision:** Exact lease duration, renewal, clock-skew tolerance, and fencing mechanism remain Phase F configuration/design.

### Decision 7

**Question:** Should cursor gaps and out-of-order delivery trigger targeted affected-scope recovery?

**Decision:** Approved. Do not skip gaps, use reset-to-latest as a generic shortcut, overwrite a newer authoritative version, or globally block unrelated lanes where isolation is safe.

**Rationale:** Contiguous durable progress and scope isolation prevent silent loss while protecting critical/unrelated work.

**Follow-up Decision:** E-10 defines cursor/reference structure; E-08 defines conflict winners; Phase F defines snapshot/backfill mechanics.

### Decision 8

**Question:** Should Agent recovery process critical lanes first with bounded fast catch-up, independent budgets, fairness, and age promotion?

**Decision:** Approved, with no starvation and without allowing promotion to bypass business safety.

**Rationale:** Recovery must protect live Orders/Payments and dependencies without abandoning aged lower-priority durable work.

**Follow-up Decision:** Exact lane budgets, weights, age thresholds, batches, and concurrency remain Phase F/load-test configuration.

### Decision 9

**Question:** Should completed delivery/outcome evidence be retained for duplicate detection, reconciliation, audit, incident investigation, and replay protection?

**Decision:** Approved; do not immediately delete evidence after HTTP success.

**Rationale:** At-least-once recovery and lost-acknowledgement investigation depend on durable prior outcomes.

**Follow-up Decision:** Exact retention/idempotency window belongs to EA-03; privacy, redaction, deletion, and holds belong to EA-06.

### Decision 10

**Question:** Should exact idempotency design, status names, retry tuning, and conflict winner rules remain deferred while E-07 approves behavioral policy?

**Decision:** Approved.

**Rationale:** Retry/recovery invariants can be fixed without prematurely selecting identifiers, enums, magic numbers, or concurrent business winners.

**Follow-up Decision:** Idempotency design → EA-03; final status vocabulary → EA-02; retry numeric tuning → Phase F/staging/load testing; conflict winner rules → E-08.

## Traceability

- E-01/E-02: topology, outbound-only Agent, durable at-least-once pull/push, reconciliation, health and priority targets.
- E-03/E-04: Pending Sync, Cloud sequence/Notification authority, truthful delivery/customer projection.
- E-05: stable staged revision and at-most-one Laravel Order outcome.
- E-06: stable Payment verification outcome, Finance Review, and separate release retry.
- D1/D2: durable Portal intake, safe staff actions, transparent degraded states, authorization and audit.
- Current code: Laravel queue/failed-job framework configuration, partial idempotency records, row locks/transactions/uniqueness, Call Center financial/release phase separation, PBX timeouts, manual Admin retries/polling, and no inspected cross-system Sync Agent/Inbox/Outbox/cursor/reconciliation implementation.
- Approved on 2026-08-08: all ten Architecture Review Decisions above.
- Deferred: E-08 through E-10 and EA-01 through EA-06 remain pending; Phase F remains not started.
