# ADR E-02 — Synchronization Mode

## Status

**[R] Proposed — Ready for Architecture Review**

This ADR proposes the cross-system synchronization transport shape. It does not implement a Sync Agent, queues, Inbox/Outbox, WebSocket/SSE, schemas, jobs, or APIs. It does not approve E-03 through E-10 or EA-01 through EA-06, including final retry, conflict, external-reference, event-versioning, or idempotency rules.

## Context

E-01 approved an independently deployed Cloud Integration API and an outbound-only local Sync Agent. E-02 must select how durable work and authoritative outcomes move between that cloud boundary and local Laravel while a branch may disconnect or restart.

The decision must distinguish three transport levels:

1. **Cross-System Synchronization:** Cloud Integration API ↔ outbound Sync Agent. This is the primary E-02 subject.
2. **Local Application Invocation:** Sync Agent → local Laravel through a narrowly authorized local API/process boundary.
3. **User Interface Freshness:** Cloud API → Website UI and Laravel → Admin UI. UI refresh transport does not provide cross-system delivery guarantees.

The selected mode must meet an initial normal synchronization-lag target under one minute, expose warning state over five minutes and critical state over fifteen minutes, support up to ten branches and Range B traffic, and recover without inferring that a timeout means Laravel did not commit.

## Approved E-01 Constraints

- The Cloud Integration API is independently deployed.
- Laravel remains local and is the final operational and financial authority.
- No inbound internet connection, public IP, or port forwarding is required for Laravel.
- Every branch/cloud connection is initiated by the local Sync Agent.
- Cloud commands remain durably staged during branch disconnection.
- Local Laravel continues operating during cloud/internet outages.
- Local authoritative events remain durable until the Cloud API acknowledges them.
- Next.js owns no durable retry, permanent Inbox/Outbox, reconciliation, or synchronization state.
- Admin access is Laravel-mediated in MVP.
- `last_confirmed_at`, `sync_status`, and stale/degraded state must be visible.
- Initial planning Range B is 10 branches, 5,000 website orders/day, 25,000 messages/day, and 2,500 proof files/day.

## Current-System Evidence

### Local Laravel

- Laravel 12 uses the database queue connection by default and has `jobs`, `job_batches`, and `failed_jobs` support. The development command runs a queue listener with one try, but no production retry policy is approved.
- The inspected code contains domain events/listeners and scheduled/local work, but no approved cross-system polling endpoint, durable Outbox/Inbox, synchronization cursor, or reconciliation worker.
- No approved Reverb/Pusher/WebSocket infrastructure or broadcasting dependency establishes a durable integration channel.
- Laravel uses transactions in order, invoice, payment, settlement, and accounting services. A timeout after invocation can therefore occur after a local commit, making blind retries unsafe.
- Call-center execution has partial idempotency records and payment-reference uniqueness. These are local safeguards, not an approved cross-system exactly-once or replay contract.
- Sanctum and POS-network middleware protect existing operational APIs. The Sync Agent needs a separate least-privilege local boundary; public cloud access to these routes remains prohibited.

### Admin React

- The Admin application uses shared Axios clients against Laravel and has manual refresh patterns plus component-specific timers, but no coherent cross-system polling/freshness framework.
- No React Query dependency or approved cache/revalidation layer was found in the manifest.
- No operational WebSocket, SSE, Laravel Echo, Pusher, Reverb, or Socket.IO dependency was found.
- Existing CRM pages expose loading/error/retry UI and manual refresh, but do not consistently model `last_confirmed_at`, `sync_status`, or stale/degraded projections.
- Because Admin is Laravel-mediated, Laravel polling/broadcasting can be chosen later for UI freshness without changing Cloud↔Agent delivery.

### Next.js website

- The website uses Next.js 16 App Router and Server Actions for current ratings, with no implemented cloud order-tracking or notification-center transport.
- No WebSocket/SSE client/server dependency or durable background synchronization runtime was found.
- Existing `setInterval` use is presentation behavior (slides/stats), not business synchronization.
- The hosting/runtime remains a website deployment and must not carry durable integration guarantees. Future active-order UI may poll, revalidate, or use SSE/WebSocket against the Cloud API independently from the Sync Agent protocol.

## Decision Drivers

- Preserve outbound-only connectivity and common firewall/proxy compatibility.
- Ensure commands/events survive connection loss and process restarts.
- Keep delivery state independent of a live connection.
- Meet sub-minute normal lag without making WebSocket availability mandatory.
- Recover safely after ambiguous timeouts and lost acknowledgements.
- Support batching, cursor-based catch-up, priority isolation, and reconciliation.
- Scale beyond Range B without rewriting core cursor/acknowledgement contracts.
- Make lag, backlog, agent health, and errors observable.
- Avoid any claim of exactly-once transport; enable later exactly-once business effects through EA-03 and Laravel invariants.

## Options Considered

### Option A — Fixed/Adaptive Polling

The Sync Agent periodically pulls cloud command batches by cursor, pushes Local Outbox batches, sends heartbeats, and varies cadence between active and idle modes. There is no persistent notification connection.

Polling is simple, outbound-friendly, easy to restart, and naturally compatible with durable cursors. Its trade-offs are detection latency during idle intervals and unnecessary empty requests. Aggressive polling reduces latency but increases connections and rate/API cost as branches grow.

### Option B — Persistent WebSocket

The Sync Agent opens a persistent outbound connection. Cloud commands and possibly local events travel immediately over that channel, with heartbeats and reconnect state.

WebSocket alone does **not** prove durable delivery, exactly-once effects, commit acknowledgement, cursor recovery, reconciliation, persistent ordering, or idempotency. To be safe it still needs durable command/event stores, resumable cursors, acknowledgements independent of the socket, replay, HTTP or equivalent catch-up, reconciliation, and later E-07/EA-03 rules. It therefore adds connection-state complexity without eliminating the durable protocol.

### Option C — Hybrid Durable Synchronization

Cloud-to-local uses durable authenticated HTTP pull/long polling with cursors and acknowledgements. Local-to-cloud uses immediate or batched HTTP push from a durable Local Outbox. An optional WebSocket/SSE signal may say only “work may be available—pull now.” Adaptive polling and scheduled reconciliation remain mandatory fallbacks.

The durable command/event records—not the live hint—are the source of truth. Losing a hint increases lag but loses no work. Cursor and acknowledgement state survive connection and process restarts.

## Detailed Comparison

| Criterion | Polling | WebSocket | Hybrid |
| --- | --- | --- | --- |
| Outbound-only compatibility | Strong | Strong when proxies permit upgrades | Strong |
| Durable delivery | Strong with durable store/cursor | High Risk alone | Strong by design boundary |
| Recovery after agent restart | Strong with persisted cursor/receipt | Weak without separate replay state | Strong |
| Recovery after cloud restart | Strong with durable command store | Weak without durable backplane | Strong |
| Command latency | Acceptable; interval-dependent | Strong while connected | Strong/Acceptable; long pull or hint accelerates |
| Local event latency | Acceptable; immediate push possible | Strong while connected | Strong; immediate push, batch under load |
| Idle connection cost | Weak due empty requests | Acceptable; persistent connections/heartbeats | Acceptable; long-poll plus adaptive fallback |
| Network instability | Strong, stateless requests recover simply | Weak; reconnect storms/state churn | Strong; live hint optional |
| Cursor support | Strong | Acceptable only when added separately | Strong and mandatory |
| Batch transfer | Strong | Acceptable; framing/backpressure required | Strong |
| Acknowledgement model | Strong and explicit | Weak if tied to socket delivery | Strong and independent of connection |
| Reconciliation support | Strong | Weak unless separately built | Strong and mandatory |
| Scaling to 10 branches | Strong | Strong | Strong |
| Scaling beyond Range B | Acceptable; tune cadence/batches | Acceptable; connection infrastructure grows | Strong; tune pull/push/hints independently |
| Firewall/proxy compatibility | Strong | Acceptable/Weak across restrictive proxies | Strong; HTTP is authoritative path |
| Implementation complexity | Strong | Weak | Acceptable |
| Operational complexity | Strong | Weak; connections plus durable layer | Acceptable; explicit lanes and metrics |
| Observability | Strong via request/batch/cursor metrics | Acceptable; connection metrics insufficient | Strong across durable lanes and hint health |
| Website/UI freshness | Independent; Acceptable | Independent; Strong for UI only | Independent; Strong choice per UI |
| Risk of silent loss | Weak only if polling lacks durable records; otherwise Strong | High Risk if socket message is treated as truth | Strong; hints never carry truth |
| Risk of duplicate delivery | Acceptable; expected and controlled later | High Risk after reconnect without replay contract | Acceptable; redelivery is explicit, effects depend on EA-03 |

## Recommended Synchronization Topology

```text
Cloud command store
  → outbound long poll/durable HTTP pull by Sync Agent
  → durable local receipt
  → narrow local Laravel invocation
  → durable per-command outcome acknowledgement

Laravel transaction
  → Local Outbox
  → immediate/batched HTTP push by Sync Agent
  → Cloud Inbox commit
  → durable acknowledgement

Optional WebSocket/SSE
  → wake-up hint only
  → Agent performs durable pull

Adaptive fallback polling
  + scheduled reconciliation
```

Cross-system delivery uses the durable HTTP/cursor/acknowledgement lanes. UI freshness remains independent:

- Website active-order UI may poll/revalidate or later use Cloud SSE/WebSocket.
- Admin obtains projections through Laravel and may poll Laravel or later use Laravel broadcasting.
- Both UIs must retain a five-minute hard fallback refresh and show freshness/stale state.

## Cloud-to-Local Command Lane

1. The Sync Agent opens an authenticated long-poll or adaptive pull request with agent/branch capability, last durable cursor, and bounded batch capacity.
2. The Cloud API returns after work is available or the request times out normally.
3. The response contains a bounded command batch. Single-command batches are valid for critical/large work; batching is preferred for throughput.
4. Before invoking Laravel, the Agent durably records the received delivery reference/cursor and command identities.
5. The Agent invokes Laravel through a local, least-privilege boundary and records the local outcome.
6. The Agent posts per-command outcomes and a durable acknowledgement. A batch is not complete merely because HTTP delivery succeeded.

The cursor is a monotonic/resumable position within an explicit logical partition, not necessarily a global sequence. Its exact format and retention remain E-10/E-07 matters.

If the connection closes after the Cloud returns a batch, the Cloud may redeliver any unacknowledged command. If Laravel commits but the result upload fails, the Agent must query/recover the recorded local outcome or re-invoke through later EA-03 idempotency rather than assuming failure. Redelivery occurs after acknowledgement lease/visibility rules to be decided by E-07. Capability/health data lets the Agent advertise supported version, branch scope, current load, local availability, and maximum batch capacity without finalizing a protocol schema.

## Local-to-Cloud Event Lane

1. Laravel commits authoritative state and its corresponding local event atomically to a Local Outbox boundary.
2. The Sync Agent reads unsent events in bounded priority/partition-aware batches.
3. It pushes immediately under ordinary load; it coalesces bounded batches under burst/backlog pressure.
4. The Cloud Inbox commits accepted events before returning acknowledgement.
5. Only after a durable Cloud acknowledgement does the Agent mark local delivery state.

If the Cloud commits but acknowledgement is lost, the Agent resends. Cloud Inbox deduplication and business-effect idempotency are required later; E-02 deliberately defines at-least-once redelivery, not exactly-once transport. After Agent restart, locally durable Outbox delivery state and the last acknowledged position drive resume. No event disappears because an in-memory push was interrupted.

## Optional Wake-up Lane

An outbound WebSocket or SSE channel may be added as an optimization. Its payload is a lightweight, non-sensitive hint:

```text
Work may be available — pull the durable command lane now.
```

It carries neither final order/payment truth nor the only copy of a command. It is not an acknowledgement channel. If it fails, expires, or is blocked by a proxy, adaptive polling continues and the effect is increased lag only. Whether this optimization is MVP or Post-MVP is an architecture-review question; no provider or certificate infrastructure is selected here.

## Fallback and Reconciliation

Mandatory fallback and recovery mechanisms include:

- Periodic pull even while the wake-up lane appears healthy.
- Five-minute hard fallback check independent of hints.
- Scheduled catch-up from last acknowledged cursors after downtime.
- Cursor-gap detection and safe pause/re-fetch for affected partitions.
- Missing-outcome queries for ambiguous Laravel invocation timeouts.
- Agent/cloud health inventory and backlog comparison.
- Projection source-version/freshness comparison.
- Controlled reconciliation sweeps, intensified after recovery or detected gaps.

E-07 will decide retry windows, leases, backoff, dead-letter/review behavior, and sweep timing. E-08 will decide conflict resolution and projection correction. E-10 and EA-03 will decide reference and idempotency semantics.

## Adaptive Polling Model

Initial ranges are proposals for review, not approved protocol constants:

| Mode | Proposed behavior |
| --- | --- |
| Active | Pull/long-poll immediately; next check in seconds when work/backlog exists |
| Idle | Long poll or check every tens of seconds with bounded jitter |
| Degraded | Exponential/controlled backoff while preserving a five-minute health/fallback attempt where reachable |
| Recovery | Controlled fast catch-up using bounded parallelism/batches, then return to Active/Idle |

Cadence must consider branch count, peak traffic, API/rate cost, proxy idle timeouts, batch size, backlog priority, and the under-one-minute normal-lag target. Battery is irrelevant because the Agent is a local service. Jitter prevents ten branches from reconnecting or polling simultaneously. Rate limiting must protect the cloud without starving critical work.

## Ordering and Partitioning

**No global ordering is proposed.** A global sequence would unnecessarily serialize branches and domains and reduce recovery/scaling flexibility.

Ordering is preserved only inside an explicit partition or aggregate when business semantics require it:

- Branch partition for branch-scoped catalog/operational projections.
- External Order aggregate for command/outcome lifecycle.
- Conversation aggregate for message order.
- Customer/Portal Account mapping aggregate for identity transitions.
- Payment/reference aggregate for verification outcomes.

Different aggregates may progress independently. Customer-wide ordering should be used only when a cross-aggregate invariant proves it necessary. Exact partition keys, versions, gap policy, and authoritative conflict rules remain E-08/E-10 matters.

## Priority Lanes

Logical priority isolation is proposed so low-value bulk work cannot delay active or financial operations:

1. Critical financial/order outcomes.
2. Active website-order commands and outcomes.
3. Customer identity reviews.
4. Messages.
5. Notifications.
6. Catalog projections.
7. Analytics/background projections.

These may use logically separate lanes, independent rate budgets, and different batch sizes without approving queue names or schemas. Critical/small commands favor small batches and low latency; messages use moderate batches; catalog/analytics use larger bounded batches and yield to critical backlog. Fairness prevents permanent starvation of lower priorities.

## Agent Health and Observability

Conceptual health data (not a schema) includes:

```text
agent_id
branch_id
agent_version
last_seen_at
last_successful_pull_at
last_successful_push_at
last_acknowledged_cursor
local_outbox_depth
cloud_command_backlog
sync_lag
connection_mode
health_status
last_error_category
```

Proposed states:

| State | Meaning |
| --- | --- |
| Healthy | Pull/push and acknowledgements are current; normal lag under one minute |
| Delayed | Work progresses but lag exceeds normal target; warning after five minutes |
| Degraded | Repeated channel/service errors or partial lane availability |
| Offline | Agent heartbeat/requests absent beyond approved threshold |
| Recovering | Agent is catching up after outage/restart with backlog decreasing |
| Blocked | Manual/configuration/security/business intervention prevents progress |

- Management sees branch status, last confirmation, lag band, backlog summary, and customer/operational impact.
- Supervisors see affected queues, owners, stale records, and safe escalation actions.
- Technical teams see versions, cursors, lane depths, rates, errors, reconnects, acknowledgement latency, and correlation details with PII redacted.
- Customers see only simplified “last updated,” delayed/stale wording, and safe next action—never internal topology or sensitive errors.

The E-01 targets apply: normal lag under one minute, warning over five minutes, critical over fifteen minutes. `last_confirmed_at`, `sync_status`, and stale/degraded indicators must derive from durable confirmation, not socket-connected state.

## UI Freshness

### Website

- Active-order pages may use bounded polling/revalidation for MVP.
- Cloud SSE/WebSocket may later improve immediacy, but is UI delivery only and cannot confirm Laravel effects.
- Every active view shows last confirmed update and stale/degraded status.
- A mandatory five-minute fallback refresh remains even with realtime UI hints.
- Background/inactive pages may use slower cadence to limit cost.

### Admin

- Admin remains `Admin Frontend → Laravel`; it does not connect directly to Cloud in MVP.
- Laravel returns locally authoritative state plus cloud projection freshness where relevant.
- Admin may poll Laravel or later use Laravel broadcasting independently of the Cloud↔Agent protocol.
- Dashboards and active-order views may have different UI cadences, but all retain five-minute fallback.
- Loading, error, stale, delayed, offline, and recovering are distinct states; a spinner must not hide stale data indefinitely.

## Security Boundaries

| Channel | Initiator | Authentication/rotation | Required controls |
| --- | --- | --- | --- |
| Agent durable pull/long poll | Sync Agent | Rotating machine credential; mTLS or signed requests remain options | TLS, replay dependency on E-10/EA-03, per-agent/branch rate limits, bounded response size, audit/correlation, redacted logs, proxy-aware timeout |
| Agent event push | Sync Agent | Same class with least-privilege lane scope | TLS, bounded compressed batches, request integrity, rate limits, durable commit-before-ack, correlation, safe retry |
| Agent outcome/ack | Sync Agent | Same authenticated session/request class | TLS, command/delivery reference, audit, replay protection dependency, strict size/timeouts |
| Optional wake-up | Sync Agent opens outbound connection | Rotating credential; connection reauthorization | TLS, heartbeat, rate/connection limits, minimal non-sensitive hints, no final payload, reconnect backoff |
| Agent → local Laravel | Sync Agent | Local service identity with rotation/revocation | Private boundary, least privilege, local rate/size limits, correlation/audit, strict timeouts, no broad staff token |
| Website UI freshness | Browser/server to Cloud API | Portal/session mechanism Pending EA-01 | TLS, authorization, rate limits, minimal projection data, cache rules, stale timestamp |
| Admin UI freshness | Admin browser to Laravel | Staff authentication/permissions | Local authority, branch scope, audit, rate limits, no Cloud credentials in browser |

All channels require credential expiry/rotation, explicit connection and request timeouts, payload limits, correlation IDs, audit proportional to sensitivity, and PII/secret redaction. E-02 does not approve a provider, certificate infrastructure, signature algorithm, or final replay window.

## Failure Analysis

| Failure | Expected behavior |
| --- | --- |
| Branch internet outage | Cloud commands remain staged; Laravel continues; Local Outbox grows durably; UI shows stale/degraded |
| Cloud API unavailable | Agent backs off without dropping Outbox; local work continues; website cannot fabricate local confirmation |
| Agent restart after batch receipt | Resume from durable receipt/cursor; unacknowledged commands may redeliver |
| Agent restart before local receipt | Cloud redelivers after visibility/lease rules; no socket memory is trusted |
| Connection drops after batch response | Batch remains unacknowledged and is eligible for redelivery |
| Laravel commits, outcome upload fails | Query/recover local outcome by reference; later idempotency protects any re-invocation |
| Cloud Inbox commits, push acknowledgement is lost | Agent resends; Inbox later deduplicates under EA-03 |
| Wake-up channel fails | Periodic/long polling continues; lag may rise but work is not lost |
| Proxy terminates long poll/socket | Reconnect with jitter; authoritative short/adaptive pull remains available |
| Out-of-order event or cursor gap | Pause affected partition, detect gap, catch up/reconcile; do not globally stop unrelated partitions |
| Low-priority backlog spike | Independent priority/rate budgets preserve active order and financial outcomes |
| Credential expires/revokes | Agent becomes Blocked/Degraded, alerts technical owner, retains durable local work |

No failure path claims exactly-once delivery. At-least-once transfer plus future idempotent business effects is the safe model.

## Capacity Analysis

At Range B, daily averages are modest, but restaurant traffic is bursty. Ten branches and 5,000 orders/day do not justify making persistent sockets the only command path. Long polling or adaptive HTTP produces manageable connection volume and supports batching. Twenty-five thousand messages/day favors moderate message batches and optional UI realtime, while critical order/payment outcomes favor immediate small transfers.

Scaling beyond Range B is achieved through partitioned cursors, bounded batches, per-lane rate budgets, horizontal Cloud API/workers, jittered Agent cadence, and optional wake-up infrastructure. External identifiers, cursor/acknowledgement meaning, event versions, idempotency keys, and partition contracts must remain stable as capacity changes. Batch size and polling intervals are operational configuration, not hard-coded architecture limits.

## Impact on Other Decisions

| Decision | E-02 Impact |
| --- | --- |
| E-03 Conversation Storage | Transport supports ordered conversation partitions and batches; authoritative storage remains open |
| E-04 Notification Storage | Notification events use lower-priority durable transfer; storage/delivery state remains open |
| E-05 Pre-Approval Order Model | Cloud-staged commands move through the critical command lane; lifecycle remains open |
| E-06 Payment Verification | Proof metadata/outcomes use private, high-priority durable references; verification policy remains open |
| E-07 Offline and Retry | Must define leases, retry/backoff, redelivery, reconciliation cadence, and terminal review states for this topology |
| E-08 Conflict Resolution | Must define behavior for cursor gaps, out-of-order versions, concurrent updates, and projection repair |
| E-09 Unified Customer Identity | Identity mapping commands/events receive ordered aggregate partitions; matching/merge rules remain open |
| E-10 External References | Must define command/event/correlation/cursor reference scope and persistence |
| EA-01 Portal Authentication | UI/session transport remains separate; Agent credentials are not portal credentials |
| EA-02 Status Dictionary | Projections carry versioned mapped status; canonical states remain open |
| EA-03 Idempotency | Must make redelivery safe and define duplicate-result semantics; E-02 makes no exactly-once claim |
| EA-04 Catalog Authority | Catalog projections use lower-priority bulk lane; source authority remains open |
| EA-05 Financial Timing | High-priority outcome transfer does not decide when postings occur |
| EA-06 Privacy and Retention | Must define retention for commands, events, acknowledgements, health logs, and reconciliation evidence |

## Recommended Option

**Choose Option C — Hybrid Durable Synchronization.**

- **Cloud-to-local:** outbound long polling or durable HTTP pull by the Sync Agent, with bounded batches, resumable cursors, durable local receipt, and per-command acknowledgements.
- **Local-to-cloud:** immediate HTTP push from Local Outbox under normal load, switching to bounded batches under burst/backlog.
- **Optional realtime:** outbound WebSocket/SSE wake-up hint only; it carries no durable truth and may be deferred.
- **Fallback:** adaptive periodic polling plus mandatory scheduled catch-up and reconciliation.

This choice fits the current absence of WebSocket infrastructure, preserves ordinary HTTP compatibility, meets E-01 outbound-only constraints, and makes connection loss a latency event rather than a data-loss event. A durable store precedes any instantaneous signal. Every transfer may redeliver. Cursors and acknowledgements are independent of the connection. Exactly-once transport is not claimed; future exactly-once business effects depend on EA-03 and Laravel invariants.

## Consequences

### Positive

- Durable delivery/recovery does not depend on WebSocket health.
- Standard outbound HTTP works through common branch firewalls and proxies.
- Long polling/hints can achieve low latency while adaptive polling bounds cost.
- Agent/cloud restarts resume from durable cursors and acknowledgements.
- Priority and batching can scale independently by workload.
- UI realtime can evolve without changing system-sync guarantees.

### Negative

- Multiple explicit lanes and durable states are more complex than naive polling.
- At-least-once delivery requires robust later idempotency and reconciliation design.
- Long-poll timeouts, proxy behavior, cursor gaps, and backlog recovery need operational testing.
- Optional WebSocket/SSE adds another monitored component if adopted.
- Health and stale-state semantics must be implemented consistently across systems.

## Risks

- A team may incorrectly treat HTTP 200 or socket delivery as business completion.
- Cursor/acknowledgement bugs could skip or repeatedly deliver work.
- Fast recovery could overload Laravel or the Cloud API.
- Priority isolation could starve background projections.
- Ambiguous local timeouts could duplicate financial/production effects without EA-03.
- Wake-up hints could accidentally grow into an undocumented authoritative channel.
- UI connected state could be misrepresented as synchronized state.

## Mitigations

- Define commit-before-ack and durable-receipt invariants in Phase F contracts.
- Require gap detection, replay, scheduled reconciliation, and observable backlogs.
- Use bounded batches, concurrency, rate budgets, backoff, jitter, and recovery throttles.
- Add fairness/age promotion across priority lanes.
- Complete E-07, E-08, E-10, and EA-03 before implementation of sensitive effects.
- Keep wake-up payload minimal and force every hint handler to perform a durable pull.
- Derive freshness from confirmed cursors/outcomes, never connection status.
- Exercise disconnection, lost acknowledgement, restart, timeout-after-commit, ordering, and backlog scenarios in E-01-approved Staging.

## Rejected Alternatives

### Option A — Polling only

Not preferred as the final architecture because fixed polling either increases active-order latency or wastes idle requests. Adaptive polling remains the mandatory durable fallback and may be sufficient for MVP, but the architecture should allow a long-poll/hint optimization without changing cursor/acknowledgement contracts.

### Option B — Persistent WebSocket as the synchronization channel

Rejected because a socket provides immediacy, not durable delivery. Safe operation would still require durable stores, cursors, acknowledgements, redelivery, catch-up, reconciliation, and idempotency. Making the socket authoritative adds proxy/reconnect/connection-state risk and creates a silent-loss hazard when a message is mistaken for committed work.

## Implementation Implications for Phase F

After E-02 approval and all required dependent decisions, Phase F should design—not implement in this ADR task:

- Versioned pull/push/outcome/health contracts and capability negotiation.
- Durable cloud command receipt and local Outbox/Agent resume boundaries.
- Cursor/partition, batch, acknowledgement, and correlation semantics.
- Priority/rate/backpressure policies and bounded recovery.
- Optional hint interface that cannot carry authoritative state.
- Stale/freshness projection fields and UI-independent delivery metrics.
- Staging tests for every failure mode in this ADR.
- Runbooks and alerts for Healthy, Delayed, Degraded, Offline, Recovering, and Blocked states.

No API route, process, table, queue, class, provider, polling constant, WebSocket server, schema, or migration is approved or created here.

## Traceability

- Approved deployment boundary: [ADR-E-01-CLOUD-INTEGRATION-LOCATION.md](ADR-E-01-CLOUD-INTEGRATION-LOCATION.md)
- Current evidence: [CURRENT_STATE_AUDIT.md](../CURRENT_STATE_AUDIT.md)
- Requirements: [CUSTOMER_PORTAL_REQUIREMENTS.md](../requirements/CUSTOMER_PORTAL_REQUIREMENTS.md), [ADMIN_CRM_REQUIREMENTS.md](../requirements/ADMIN_CRM_REQUIREMENTS.md)
- Business decisions: [CUSTOMER_PORTAL_BUSINESS_DECISIONS.md](../requirements/CUSTOMER_PORTAL_BUSINESS_DECISIONS.md), [ADMIN_CRM_BUSINESS_DECISIONS.md](../requirements/ADMIN_CRM_BUSINESS_DECISIONS.md)
- Governance: [PROGRAM_CHARTER.md](../program/PROGRAM_CHARTER.md), [DECISION_LOG.md](../program/DECISION_LOG.md), [MASTER_BACKLOG.md](../program/MASTER_BACKLOG.md), [PROGRAM_STATUS.md](../program/PROGRAM_STATUS.md), [RISK_REGISTER.md](../program/RISK_REGISTER.md)

## Architecture Review Questions

1. **Do we approve Option C — Hybrid Durable Synchronization?**  
   **Recommendation:** Approve Hybrid; durable HTTP/cursor/acknowledgement lanes are authoritative and any realtime signal is optional.
2. **Should the MVP command lane use long polling or short adaptive polling?**  
   **Recommendation:** Prefer long polling where hosting/proxies support it, with short adaptive polling as a fully compatible fallback using the same contract.
3. **Is a WebSocket/SSE wake-up hint required in MVP or deferred to Post-MVP?**  
   **Recommendation:** Defer to Post-MVP unless staging measurements show polling/long polling cannot meet the active-order target economically.
4. **What target should apply from cloud command availability to Agent receipt for an active website order?**  
   **Recommendation:** Target p95 under 10 seconds while healthy, without weakening the approved under-one-minute overall normal synchronization-lag target.
5. **What initial Active, Idle, and hard-fallback polling ranges should operations approve?**  
   **Recommendation:** Active checks in seconds, Idle in tens of seconds with jitter, and a mandatory five-minute hard fallback; tune from staging evidence.
6. **What initial batch strategy and bounds should apply?**  
   **Recommendation:** Small/immediate batches for financial and active-order work, moderate message batches, larger bounded catalog/analytics batches; approve byte/count/time limits after load tests.
7. **What ordering scope is approved?**  
   **Recommendation:** No global ordering; preserve order only within explicit branch or domain aggregate partitions such as External Order, Conversation, identity mapping, and payment reference.
8. **What logical priority isolation is required for MVP?**  
   **Recommendation:** Isolate critical financial/order, active orders, identity review, messages, notifications, catalog, and analytics with independent batch/rate budgets and fairness.
9. **Who may see Agent Health and who receives alerts?**  
   **Recommendation:** Management sees summarized branch impact, supervisors see actionable queues, technical operations see detailed metrics/errors, and customers see simplified freshness only; Integration Operations receives technical alerts.
10. **When should reconciliation sweeps run?**  
   **Recommendation:** Run a scheduled baseline sweep plus immediate targeted sweeps after recovery, cursor gaps, ambiguous outcomes, or critical-lag alerts; defer exact cadence to E-07.
