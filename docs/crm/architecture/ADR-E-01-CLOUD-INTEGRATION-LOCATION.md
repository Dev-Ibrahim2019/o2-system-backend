# ADR E-01 — Cloud Integration API Location

## Status

**[R] Proposed — Ready for Architecture Review**

This ADR proposes a deployment boundary. It does not approve implementation, a technology stack, schemas, domains, providers, or any E-02 through E-10 or EA-01 through EA-06 decision.

## Context

O2 operates three distinct systems:

- A local Laravel 12 backend that owns operational and financial records.
- A React/Vite Admin application used by staff against the Laravel API.
- A public Next.js 16 website with an independent PostgreSQL database currently used for restaurant ratings.

The target program adds portal accounts, website-order staging, messages, notifications, private payment proof, complaints, ratings, read projections, and reliable synchronization with local operations. The integration boundary must tolerate branch internet outages without exposing the local database or turning the public website deployment into the durable integration runtime.

## Business Constraints

1. Local Laravel remains the final operational and financial authority.
2. The website must never connect directly to local MySQL.
3. Local Laravel must not be exposed to the internet without an approved integration/security layer.
4. Branch-to-cloud communication must support outbound initiation from the branch.
5. Website order, payment, status, and loyalty data are not final until Laravel confirms them.
6. A pre-approval website order is not a final local Order.
7. Interruption, retry, deduplication, reconciliation, and visible stale state are mandatory.
8. Duplicate Orders, Invoices, Payments, and Production Tickets must be prevented.
9. Payment proof is private evidence, never a public object URL.
10. E-02 through E-10 and EA-01 through EA-06 remain Pending.

## Current-System Evidence

### Local Laravel backend

- `routes/api.php` exposes broad operational APIs. Staff routes use Sanctum, and core POS/order/invoice/payment/production mutations are additionally grouped behind a POS-network middleware. This is an internal operational surface, not a purpose-built public integration boundary.
- `Order`, `Invoice`, `Payment`, and `ProductionTicket` models and services implement the only complete operational/financial chain. Invoice creation, settlement, accounting posting, and production actions must remain local.
- Laravel queues default to the database driver and record failed jobs, but the inspected code has no approved cross-system Outbox/Inbox contract.
- A call-center flow has partial idempotency records and payment-reference uniqueness. These controls are valuable local safeguards but are not a complete external integration protocol.
- Storage supports private local files and S3 configuration, while some legacy uploads are public. Payment proof therefore requires a new explicitly private contract, not reuse of public upload behavior.
- Auditable models and action logs exist in parts of the domain, but audit coverage is not yet a unified integration audit.

### Public website

- `package.json` identifies Next.js 16.0.10, React 19.2, Drizzle, and PostgreSQL.
- The App Router and current database schema support restaurant ratings. The audit found no durable portal-account, website-order, payment, or synchronization store.
- Current checkout builds a WhatsApp handoff rather than creating a Laravel Order. Menu, prices, branch information, and delivery values are duplicated locally in website code/data.
- Website deployment is configured as a web application deployment. Coupling durable retries, reconciliation workers, private-file lifecycle, and integration credentials to website releases would expand its failure and security blast radius.

### Admin application

- The shared Axios clients use `VITE_API_URL` (or `/api`) and the CRM client calls Laravel `/crm/*` endpoints.
- The Admin already reads operational customer, order, payment, finance, and CRM truth from Laravel. Direct browser access to a second cloud authority would complicate authorization, CORS, tokens, audit correlation, and stale/conflicting views.
- The default target should therefore keep Admin operational access through Laravel. A narrowly scoped cloud-read API may be considered later for cloud-only pending/review queues, but only after authentication, ownership, and projection decisions are approved.

## Decision Drivers

- Protect local operational and financial systems from public ingress.
- Preserve durable cloud intake during branch disconnection.
- Isolate customer-facing website releases from integration processing.
- Support background retry, reconciliation, private files, and independent scaling.
- Provide a stable contract for future mobile clients and additional branches.
- Minimize irreversible coupling while Phase E decisions remain open.
- Make availability, credentials, audit, and ownership independently operable.

## Options Considered

### Option A — Independent Cloud Integration Service

An independently deployed API and worker boundary, with its own database, private object storage, secrets, monitoring, and release lifecycle. A logical hostname such as `cloud-api.o2company.com` illustrates the boundary only; no domain is approved here.

### Option B — Integration API inside Next.js

API Routes and/or Server Actions in the website deployment own portal and durable integration responsibilities, using the website runtime, database, secrets, and release lifecycle.

### Option C — Logically separated module inside Next.js, extracted later

Integration code is initially modularized within Next.js, with an intention to migrate its data, sessions, files, contracts, references, and workers to an independent service later.

## Detailed Comparison

| Criterion | Independent Service | Inside Next.js | Extract Later |
| --- | --- | --- | --- |
| Security boundary | Strong — separate public API, worker, data, and secrets boundary | Weak — website compromise/release shares integration trust | Acceptable initially; High Risk during extraction |
| Deployment independence | Strong | Weak | Weak initially; Acceptable after extraction |
| Availability | Strong — can remain available through website releases | Weak — coupled to website health and deploys | Acceptable initially; migration introduces dual-running risk |
| Operational complexity | Acceptable — one additional operated service | Strong initially | Weak — initial simplicity followed by migration complexity |
| Initial development cost | Acceptable — higher setup cost | Strong — lowest boundary setup | Strong initially |
| Long-term maintenance | Strong — clear ownership and contracts | Weak — mixed web/integration concerns | Weak — migration plus compatibility burden |
| Retry and reconciliation | Strong — dedicated workers and schedules | High Risk — runtime/timeout/background-job constraints vary by host | Weak — durable semantics must later move safely |
| File storage | Strong — private object boundary and lifecycle | Acceptable only with external private storage | Weak — object ownership and references must migrate |
| Portal authentication | Strong — stable API independent of website UI | Acceptable — convenient but deployment-coupled | Weak — session/token migration is security-sensitive |
| Website-order staging | Strong — durable system boundary | Acceptable at small scale; operationally coupled | Weak — live staged orders must migrate |
| Message storage | Strong — durable ordering/workers can be dedicated | Weak for long-lived integration responsibilities | Weak — histories and delivery cursors must migrate |
| Notification storage | Strong | Acceptable for simple in-app MVP only | Weak — delivery state and preferences must migrate |
| Observability | Strong — independent SLOs, logs, queues, and alerts | Weak — web and integration telemetry compete | Acceptable initially; fragmented during migration |
| Scaling | Strong — API, workers, and storage scale independently | Weak — tied to web scaling shape | Acceptable after extraction |
| Vendor portability | Strong — contract can move as a unit | Weak — tied to Next.js hosting/runtime assumptions | Weak — extraction is itself a portability project |
| Failure isolation | Strong | High Risk — website and integration share blast radius | Weak until extraction; High Risk during cutover |
| Support for future mobile app | Strong — stable client-neutral boundary | Acceptable but website-owned | Acceptable after extraction |
| Risk of rework | Strong — lowest foreseeable boundary rework | High Risk | High Risk — rework is explicitly deferred |

The independent service has a higher initial operational cost, but it is the only option rated Strong for durable retry/reconciliation, failure isolation, deployment independence, and long-term client neutrality. The two Next.js options optimize the first implementation step while transferring material security and migration risk into later phases.

## Security Boundaries

| Connection | Initiator | Preliminary authentication | Data | Exposure and controls | Replay behavior |
| --- | --- | --- | --- | --- | --- |
| Customer Browser → Next.js Website | Browser | Anonymous session or portal session; final mechanism Pending EA-01 | Pages, public catalog projection, customer input | Public TLS; WAF/rate limits; CSP and input validation | Safe reads repeat; mutations require CSRF/replay protection and idempotency where applicable |
| Customer Browser → Cloud Integration API | Browser only for approved public portal endpoints | Short-lived portal token/session, Pending EA-01 | Portal profile, staged orders, messages, proof-upload initiation | Public TLS; strict CORS, rate limits, authorization, abuse controls, audit | Duplicate mutations return the prior logical result or a deterministic conflict |
| Next.js Website → Cloud Integration API | Website server | Rotating service credential or signed requests | Public projections, staged-order commands, portal orchestration | Public endpoint over TLS; least privilege, rate limits, request signing considered | Idempotency key and correlation ID required for effects |
| Cloud Integration API → Private Object Storage | Cloud service/worker | Workload identity or rotating storage credentials | Encrypted proof/message attachments and metadata | Private service boundary; no public bucket; short-lived scoped access | Re-upload deduplicated by object/reference policy; metadata remains audited |
| Local Sync Agent → Cloud Integration API | Sync Agent (outbound) | Rotating machine credential; mTLS and/or signed requests to be reviewed | Pull work, acknowledge results, push outbox events, cursors, health | Public cloud endpoint, outbound local connection; IP allowlists are supplementary only | Cursor plus idempotency/reference contract makes repeats safe |
| Cloud API response → Local Sync Agent | Response on agent-initiated connection | Same authenticated channel | Work batches, acknowledgements, retry instructions | No unsolicited inbound local connection | Agent records receipt before/with controlled application; unconfirmed batches may be redelivered |
| Local Sync Agent → Local Laravel | Sync Agent | Local service identity and narrowly scoped authorization | Validated commands, projection requests, acknowledgements | Private local network/host; rate limits and full audit | Laravel enforces local idempotency and business invariants |
| Local Laravel → Local Outbox | Laravel transaction | Internal database transaction | Authoritative domain events and references | Local database boundary | Unique event identity; repeated publisher reads do not repeat effects |
| Local Sync Agent → Cloud Integration API (outbox push) | Sync Agent | Same rotating machine credential | Confirmed Laravel events, outcomes, projections | Outbound TLS, audit, batch/rate controls | Cloud Inbox deduplicates and acknowledges event identities |
| Admin Frontend → Local Laravel | Staff browser | Existing/future hardened staff session | Operational CRM, finance, orders, review actions | Local/private operational API; Backend authorization authoritative | Commands use business references/idempotency where required |
| Admin Frontend → Cloud Integration API | Not the default; only if separately approved | Federated/short-lived staff token | Cloud-only pending queues or diagnostics | Public TLS, strict staff authorization, rate limits, audit; no shared browser secret | Read repetition safe; commands require idempotency and cross-system correlation |

Trust is not transitive: a valid website session is not a Sync Agent credential; a Sync Agent cannot gain broad Laravel staff authority; object-storage access does not grant database access; and Admin authorization must be enforced by the API serving the data.

## Communication Direction

Target direction:

```text
Customer → Website and approved public Cloud API endpoints
Website server → Cloud Integration API
Local Sync Agent → Cloud Integration API
Cloud API response → Local Sync Agent (on the outbound-initiated channel)
Local Sync Agent → Local Laravel
Local Laravel transaction → Local Outbox
Local Sync Agent → Cloud Integration API
```

- The Cloud Integration API does **not** connect directly to local Laravel.
- Local Laravel needs no public IP, inbound firewall opening, or port forwarding.
- Outbound-only branch connectivity is feasible through polling, long polling, or another E-02-approved transport initiated by the Sync Agent.
- During internet outage, cloud requests remain durably staged. They are not represented as final local Orders.
- Laravel continues local operations. Its local outbox retains unpublished authoritative events until connectivity returns.
- The website and Admin projections expose `last_confirmed_at`, synchronization state, and an explicit stale/degraded indicator. Exact transport and freshness thresholds remain E-02/E-07 matters.
- Reconnection replays unacknowledged work using E-10 references and EA-03 idempotency; unresolved conflicts follow E-08 rather than silent overwrite.

## Data Ownership Boundaries

### Cloud Integration API may own or durably stage

- Portal Accounts and customer-link review state, subject to E-09 and EA-01.
- Verification challenges or provider references, not unnecessary secrets.
- Pre-approval website orders and their customer-approved snapshots.
- External references, correlation IDs, Integration Inbox/Outbox, cursors, idempotency records, retry state, and reconciliation state.
- Conversation messages and assignment/delivery metadata, subject to E-03.
- In-app notifications and delivery state, subject to E-04.
- Payment-proof metadata and private object-storage references, subject to E-06 and EA-06.
- Cloud-originated complaints, ratings, and safe read projections.
- Confirmed snapshots from Laravel clearly labeled with source, version/freshness, and last confirmation.

### Cloud Integration API must not become final authority for

- Final accounting entries, balances, invoices, payments, or settlement.
- Final operational Order, Production Tickets, inventory, fulfillment, or delivery truth.
- Final loyalty balance or ledger.
- Final local order state without Laravel confirmation.
- Branch-local permissions or financial authorization decisions.

No final schema or physical table ownership is approved by this ADR.

## Operational Ownership

| Responsibility | Accountable role |
| --- | --- |
| Service architecture and contracts | Integration Technical Lead |
| Cloud deployment and rollback | Platform/Cloud Operations |
| Service application ownership | Integration Service Owner |
| Availability, queues, logs, and alerts | Site Reliability/Operations |
| Sync Agent installation and health | Branch/Local Systems Operations |
| Sync Agent credentials and rotation | Security/Platform Operations |
| Failed Sync triage | Integration Operations |
| Needs Review business resolution | CRM/Call Center Operations; Finance when financial evidence is involved |
| Database backups and restore tests | Database/Platform Operations |
| Private object storage and lifecycle | Platform Operations with Security/Data Owner |
| Credential rotation policy | Security Owner |
| Correlation-ID standards and audit access | Integration Technical Lead and Security/Audit |

Runbooks must distinguish transport failure, validation rejection, duplicate replay, conflict, private-file failure, and an indeterminate local financial outcome.

## Failure and Availability Analysis

| Failure | Required behavior |
| --- | --- |
| Branch internet unavailable | Cloud accepts and retains staged work; local operations continue; no final confirmation is fabricated; stale state is visible |
| Cloud API unavailable | Website fails safely with a traceable retry option; Sync Agent retains local outbox; no direct Laravel fallback from the internet |
| Website deployment fails | Cloud API, staged orders, workers, and Sync Agent synchronization remain independently available |
| Sync Agent restarts | Resume from durable cursors/inbox/outbox state; redelivery is safe |
| Laravel rejects a command | Cloud records a terminal/reviewable rejection with customer-safe state; it does not force local mutation |
| Timeout after local commit | Reconcile by external/correlation reference before retry; never assume failure means no commit |
| Duplicate delivery | Inbox/idempotency contract returns the prior outcome and produces no duplicate financial/production effects |
| Out-of-order event | Detect version/order gap, pause unsafe projection change, fetch/reconcile according to E-08 |
| Object storage unavailable | Do not mark proof received/verified until durable private storage succeeds; allow safe resumable retry |
| Database degradation | Reject or queue writes according to proven durability; never acknowledge data that was not committed |

Availability must be defined as separate SLOs for the public API, workers, database, object storage, and synchronization lag. Multi-region operation is not implied by this proposal.

## Impact on E-02 through E-10

| Decision | Impact of E-01 |
| --- | --- |
| E-02 Synchronization Mode | Transport connects an outbound Sync Agent to an independent API; polling/WebSocket/hybrid remains open |
| E-03 Conversation Storage | Independent service is the candidate cloud boundary, but authoritative store and ordering remain undecided |
| E-04 Notification Storage | Independent service can host durable in-app delivery state; schema/channels remain undecided |
| E-05 Pre-Approval Order Model | Staged intent belongs on the cloud side until Laravel accepts it; exact lifecycle remains undecided |
| E-06 Payment Verification | Private proof and verification workflow sit across cloud storage and Laravel confirmation; verifier contract remains undecided |
| E-07 Offline and Retry | Dedicated workers/inbox/outbox are now feasible; retry limits and reconciliation policy remain undecided |
| E-08 Conflict Resolution | Independent boundary requires explicit versions, authorities, and review states; policy remains undecided |
| E-09 Unified Customer Identity | Cloud Portal Account maps to Laravel Customer through an explicit contract; identity rules remain undecided |
| E-10 External Order Identifier | Stable service boundary requires durable external/correlation references; format and uniqueness scope remain undecided |
| EA-01 Portal Authentication | Authentication can be service-owned and UI-independent; provider/session mechanics remain undecided |
| EA-02 Status Dictionary | Cloud projections map Laravel-confirmed states; canonical dictionary remains undecided |
| EA-03 Idempotency | Both cloud Inbox and Laravel command boundary need coordinated idempotency; contract remains undecided |
| EA-04 Catalog Authority | Cloud may distribute projections but does not decide menu/price authority |
| EA-05 Financial Timing | Cloud never becomes final financial authority; posting points remain undecided |
| EA-06 Privacy and Retention | Separate cloud database/object storage require explicit classification, retention, deletion, and access policy |

## Recommended Option

**Choose Option A: an independently deployed Cloud Integration API.**

Keep Laravel local as operational and financial authority. Use a local Sync Agent that initiates outbound communication. Do not place durable integration responsibility inside Next.js. Keep Admin operational reads/actions through Laravel by default; consider only narrowly scoped direct cloud access in a later approved contract.

This recommendation best matches the existing systems: the website is a customer experience with a limited database and coupled release cycle, while Laravel contains sensitive local operational/financial APIs that should not become internet-facing. An independent boundary gives durable cloud intake, private storage, workers, failure isolation, and stable future-client contracts without changing the authority model.

## Consequences

### Positive

- No inbound internet path, public IP, or port forwarding to local Laravel.
- Website deployments do not stop synchronization or durable order intake.
- API, workers, database, and private files receive explicit security and operational ownership.
- Mobile and future channel clients can use a stable non-UI-owned API.
- Retry, reconciliation, and observability can scale independently.

### Negative

- A new service, datastore, deployment pipeline, monitoring surface, backup plan, and on-call responsibility are required.
- Cross-system contracts and eventual-consistency UX must be designed explicitly.
- Authentication, service credentials, private storage, and data lifecycle create additional operational cost.
- Phase F cannot begin integration implementation until dependent E/EA contracts are approved.

## Risks

- Underfunding or under-owning the new service creates an unreliable critical boundary.
- Weak service credentials could expose branch synchronization.
- Incorrect acknowledgements could lose or duplicate business effects.
- Cloud projections could be mistaken for current Laravel truth.
- Private files could leak through misconfigured storage or overly broad signed access.
- Direct Admin-to-cloud access could produce split authorization and inconsistent views.
- A single cloud region/provider may become a new availability dependency.

## Mitigations

- Assign explicit service, platform, security, database, and operational owners before Phase F.
- Use short-lived/rotated least-privilege credentials; evaluate mTLS plus signed requests for Sync Agents.
- Require durable Inbox/Outbox, acknowledgements, correlation IDs, idempotency, and reconciliation tests.
- Label every projection with authority, version, freshness, and last confirmation.
- Use private object storage, encryption, malware/content validation, scoped short-lived access, and access audit.
- Keep Admin through Laravel by default and require a separate architecture approval for direct cloud access.
- Define SLOs, backups, restore tests, staging, alerting, dead-letter/Needs Review runbooks, and capacity expectations.

## Rejected Alternatives

### Option B — Integration API inside Next.js

Rejected because it couples durable business intake, secrets, workers, and recovery to the customer website's deployment and runtime constraints. It also expands the blast radius of a public-web compromise or routine release. API Routes may remain a presentation/BFF layer, but must not own durable integration truth.

### Option C — Logically separated module inside Next.js, extracted later

Rejected because the planned extraction would migrate live portal sessions, staged orders, payment-proof objects, messages, delivery state, identifiers, and idempotency history. Dual operation and cutover would add precisely the duplicate and orphan risks the program is designed to prevent. Logical modularity is still required inside the independent service, but it is not a substitute for the deployment boundary.

## Implementation Implications for Phase F

If approved, Phase F should design—not yet implement until authorized—the following:

- An independent API/worker deployment, database, private object storage, secrets, observability, backup, and staging boundaries.
- Versioned contracts between Website↔Cloud API, Sync Agent↔Cloud API, and Sync Agent↔Laravel.
- A narrowly privileged local Sync Agent with durable state and outbound-only connectivity.
- Local Laravel Outbox and command Inbox boundaries around existing transactional services.
- End-to-end correlation, idempotency, acknowledgements, reconciliation, stale-state semantics, and Needs Review workflows.
- A website migration plan for ratings and duplicated menu/order inputs only after their related E/EA decisions approve ownership.
- An Admin access design that prefers Laravel projections and avoids embedding cloud service secrets in the browser.

No repository, service, route, schema, migration, provider, domain, or credential is created or approved by this ADR.

## Traceability

- Program constraints: [PROGRAM_CHARTER.md](../program/PROGRAM_CHARTER.md)
- Current evidence: [CURRENT_STATE_AUDIT.md](../CURRENT_STATE_AUDIT.md)
- D1 requirements and decisions: [CUSTOMER_PORTAL_REQUIREMENTS.md](../requirements/CUSTOMER_PORTAL_REQUIREMENTS.md), [CUSTOMER_PORTAL_BUSINESS_DECISIONS.md](../requirements/CUSTOMER_PORTAL_BUSINESS_DECISIONS.md)
- D2 requirements and decisions: [ADMIN_CRM_REQUIREMENTS.md](../requirements/ADMIN_CRM_REQUIREMENTS.md), [ADMIN_CRM_BUSINESS_DECISIONS.md](../requirements/ADMIN_CRM_BUSINESS_DECISIONS.md)
- Architecture catalog and risks: [DECISION_LOG.md](../program/DECISION_LOG.md), [RISK_REGISTER.md](../program/RISK_REGISTER.md)
- Backlog/status: [MASTER_BACKLOG.md](../program/MASTER_BACKLOG.md), [PROGRAM_STATUS.md](../program/PROGRAM_STATUS.md)

## Architecture Review Questions

1. **Do we approve an independently deployed Cloud Integration API as the E-01 boundary?**  
   **Recommendation:** Approve Option A and prohibit durable integration ownership inside Next.js.
2. **Which organizational role owns the service application, hosting, deployment, and on-call escalation?**  
   **Recommendation:** Assign an Integration Service Owner with Platform Operations and Security responsibilities explicitly documented.
3. **What availability and recovery objectives are required for API, workers, database, object storage, and maximum synchronization lag?**  
   **Recommendation:** Approve measurable component SLOs and RPO/RTO before selecting infrastructure.
4. **Is a separate staging environment required for contract and disconnection testing?**  
   **Recommendation:** Require staging with isolated data, credentials, storage, and a representative Sync Agent.
5. **What operating budget and support coverage are approved for the independent service?**  
   **Recommendation:** Fund managed database/object storage, monitoring, backups, and alert response before Phase F.
6. **Should Admin ever connect directly to the Cloud Integration API?**  
   **Recommendation:** Default to Laravel-mediated access; approve direct access only for demonstrably cloud-only queues with federated staff authorization and full audit.
7. **What are the expected initial and three-year branch, portal-user, order, message, and proof-file volumes?**  
   **Recommendation:** Approve planning ranges and peak factors before capacity and availability design.
8. **How long may staged orders, failed-sync evidence, messages, and private payment-proof files remain in cloud storage?**  
   **Recommendation:** Defer exact periods to EA-06, but require explicit legal/business retention classes before implementation.
9. **Who reviews Failed Sync and Needs Review queues, including after-hours and financially indeterminate outcomes?**  
   **Recommendation:** Assign Integration Operations for transport failures and CRM/Call Center or Finance for business/financial outcomes with documented escalation.
