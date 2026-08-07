# ADR E-04 — Notification Storage

## Status

**[x] Approved**

Approval Date: **2026-08-07**

Approved Decision: **Option A — Cloud-authoritative Customer Notification Store**

This ADR approves authority and conceptual semantics only. It does not approve schemas, table/class names, identifiers, enums, APIs, queues/workers, templates, providers, channel implementations, WebSocket/SSE, push/service workers, retention periods or Phase F application work.

## Context

The Portal requires a durable customer in-app inbox that remains available while a branch is disconnected. Notifications may communicate authoritative Laravel operational facts or Cloud Conversation/security facts, but notification text and interaction state must never become operational, financial or conversation truth. E-04 decides the authority for the logical customer Notification, inbox, read/dismiss state and channel-delivery evidence while retaining each source domain's authority.

## Approved E-01/E-02/E-03 Constraints

- E-01 approves an independent Cloud Integration Service, outbound-only local Sync Agent, Laravel operational/financial authority and Laravel-mediated Admin access.
- E-02 approves durable long polling/HTTP pull, Local Outbox HTTP push, adaptive fallback, reconciliation and at-least-once transfer. WebSocket/SSE is Post-MVP and hint-only; no exactly-once claim exists.
- E-03 makes Cloud authoritative for Conversations/Public Messages and their customer release/read facts, retains a Laravel operational projection and Laravel-only Internal Notes, and makes Conversation assignment/SLA Cloud-authoritative after Laravel authorization.
- Notification semantics must preserve the difference between System Receipt, Cloud Accepted, Customer Released and Customer Read.
- E-05 through E-10 and EA-01 through EA-06 remain pending.

## Business Constraints

1. MVP requires a customer-facing in-app inbox; Push, WhatsApp, SMS and Email are potential later channels, with no provider approved here.
2. Operational, security and marketing communication are separately governed. Portal registration does not imply marketing consent.
3. Notifications may report authoritative facts but never replace Order, Payment, Complaint, loyalty or Conversation state.
4. Critical security notices should not be suppressible by ordinary preferences; exact security channels remain EA-01/EA-06.
5. Customer interaction state must synchronize across devices and remain honest about availability, delivery, display, read and action.
6. At-least-once source-event transfer must not create duplicate logical Notifications.
7. Sensitive content must be minimized in inbox previews and future external-channel surfaces.
8. Internal staff alerts have different audiences and authority and need not share the customer inbox.
9. Final consent, retention, deletion and legal/business-hold policy remains EA-06.

## Current-System Evidence

### Laravel backend

- No inspected `Notification` class, customer notification model/migration, `notifications`/`database_notifications` table, `read_at`, unread counter, preference store, template store or notification API exists.
- `User` uses Laravel's `Notifiable` trait and Laravel includes the notification/mail framework, but no inspected application usage establishes a durable customer notification authority. Mail defaults to a configurable framework mailer and is not an approved customer-notification channel.
- The database queue/failed-job infrastructure exists, but no inspected notification Job/Event/Listener pipeline or cross-system notification Outbox contract exists.
- No FCM/Firebase, SMS, WhatsApp delivery provider or order/payment/loyalty customer-notification implementation was found. Branch contact metadata and preferred-contact fields do not constitute delivery integrations.
- `CallCenterService::getCustomerAlerts` computes staff-facing complaint alerts from Laravel Complaint facts, and `show_alert` controls complaint visibility. These are internal operational alerts, not a durable Portal inbox.
- `DepartmentResource` returns hard-coded sound/flash/push presentation settings. These are UI-oriented department defaults, not customer preferences or delivery evidence.
- Existing Customer, Order, Payment, Complaint, loyalty, authorization, audit and pagination mechanisms are reusable source-domain foundations. Laravel's built-in Notification framework must not be assumed to be the cross-system authority.

### Admin React application

- The application has a shared `ToastContainer`, component-local success/error alerts and sounds. These transient UI effects have no durable customer history, recipient ownership, read state or delivery evidence.
- KDS/operational screens use sounds, bells, warnings and polling/timers for local order activity. Customer 360/call-center surfaces show complaint alerts derived from Laravel.
- No inspected durable notification center, notification API client, customer unread counter, preferences, browser-push integration or customer notification projection contract exists.
- These patterns may inform staff UX, but a toast, sound or dashboard warning is not Notification storage.

### Next.js website

- No inspected Portal notification center/inbox, notification database entity, unread state, preferences/account settings, push permission, service worker, FCM client or deep-link notification contract exists.
- Existing Radix/Sonner dependencies and component-local cart/error Toasts are transient presentation only.
- The rating Server Action returns a success/error message but does not create a durable customer Notification.

### Reuse and separation

Reuse authoritative Laravel domain events, Local Outbox synchronization, Cloud Conversation facts, portal ownership/authentication once approved, opaque references, audit and server-side pagination conventions. Do not reuse Toasts, KDS sounds, computed complaint alerts, mail configuration or Laravel `Notifiable` as proof of a customer Notification authority.

## Decision Drivers

- Customer inbox availability through branch/Laravel outages.
- One governed, multi-device customer communication history.
- Protection of Laravel operational/financial truth and Cloud Conversation truth.
- Duplicate-safe creation under E-02 at-least-once delivery.
- Honest lifecycle, delivery and read semantics.
- Security/operational/marketing separation and sensitive-content minimization.
- Extensibility to later channels and mobile clients without five independent histories.
- Proportionate Laravel projection and operational complexity.

## Scope

E-04 covers logical customer Notifications, in-app inbox/order, per-item read and dismiss/archive state, safe released snapshots, source references, delivery-attempt evidence, conceptual preferences, expiration/presentation, corrections and future channel modeling. It analyzes operational, security, support, order, payment, loyalty and marketing categories.

It does not govern the underlying domain transition, internal staff alert authority, OTP delivery, provider choice, final consent/retention policy or implementation.

## Options Considered

### Option A — Cloud-authoritative Notification Store with domain-authoritative source facts

Cloud owns each logical customer Notification and inbox interaction/delivery state. Laravel and Cloud Conversation storage emit governed authoritative source facts.

### Option B — Laravel-authoritative Notification Store with Cloud Portal projection

Laravel creates the customer Notification history and Cloud mirrors it for the Portal. Customer availability and multi-device state depend on synchronization from an intermittently reachable branch authority.

### Option C — Dual notification stores / dual-write ownership

Laravel and Cloud both create or mutate authoritative customer Notifications. This makes duplicates, read/dismiss conflicts and correction history ambiguous under at-least-once transport.

## Detailed Comparison

| Criterion | Cloud Authority | Laravel Authority | Dual Authority |
| --- | --- | --- | --- |
| Customer availability during branch outage | Strong | Weak | Acceptable |
| In-app inbox availability | Strong | Weak | Acceptable |
| Single governed history | Strong | Strong | High Risk |
| Read state | Strong | Weak | High Risk |
| Dismiss state | Strong | Weak | High Risk |
| Multi-device behavior | Strong | Weak | High Risk |
| Operational event integrity | Strong with source validation | Strong | High Risk |
| Payment truth protection | Strong with Laravel source authority | Strong | High Risk |
| Conversation notification support | Strong | Weak | Acceptable |
| Marketing separation | Strong | Acceptable | Weak |
| Future push/SMS/email support | Strong | Acceptable | Weak |
| Deduplication | Strong | Acceptable | High Risk |
| Retention consistency | Strong | Acceptable | High Risk |
| Preference management | Strong | Weak | High Risk |
| Auditability | Strong | Acceptable | Weak |
| Offline behavior | Strong | Weak | High Risk |
| Scalability | Strong | Acceptable | Weak |
| Future mobile client | Strong | Weak | Acceptable |
| Synchronization complexity | Acceptable | Acceptable | High Risk |
| Divergence risk | Strong | Acceptable | High Risk |

Cloud Authority aligns the inbox with the customer-facing availability boundary and directly supports Cloud Conversation notifications. Its operational integrity depends on accepting only authenticated, versioned Laravel facts for Laravel-owned domains. Laravel Authority preserves local familiarity but cannot keep the authoritative inbox/read state available through branch outage. Dual Authority is incompatible with one governed history.

## Recommended Authority Model

Approved: **Option A — Cloud-authoritative Customer Notification Store**.

Cloud owns the logical customer Notification, in-app inbox, deterministic recipient ordering, per-item read state, dismiss/archive state, safe released snapshot, notification/source references, version/state, expiration presentation state and channel delivery-attempt evidence.

Laravel remains authority for Orders, accepted operational Customers, Invoices, Payments, Accounting, Inventory, Production, Delivery operational truth, Complaints and final loyalty ledger/balance. The Cloud Conversation Store remains authority for accepted/released Public Messages and public Conversation state. Notifications reference these facts and never replace them.

Internal staff alerts remain Laravel/operations-authoritative and outside the customer Cloud inbox in MVP.

## Source Fact vs Notification

```text
Authoritative source fact
→ governed source event/reference
→ notification eligibility and purpose
→ safe customer communication snapshot
```

For example, Laravel's `Order state = preparing` remains authoritative; “Your order is being prepared” is a customer communication artifact. `Notification ≠ Domain State`. Notification read/dismiss/action state is not Order acknowledgement, Payment confirmation, Complaint resolution or Conversation read/response state. A deep-link destination must load and re-authorize the latest projection instead of trusting possibly stale notification text.

## Data Ownership Matrix

| Data | Final Authority | Laravel Projection | Customer Visible | Notes |
| --- | --- | --- | --- | --- |
| Logical customer Notification | Cloud | Minimal when operationally needed | Yes | One governed history |
| Safe title/body snapshot | Cloud | Selective | Yes | Immutable after release |
| Inbox ordering | Cloud | No authority | Yes | Recipient-local deterministic order |
| Read state | Cloud | Optional summary | Own state | Per item |
| Dismiss/archive state | Cloud | No authority by default | Own state | Does not delete evidence |
| Channel attempts/evidence | Cloud | Important failures only | Policy-limited | Not domain truth |
| Notification preferences | Cloud/Portal policy boundary, pending EA-01/EA-06 | Operational constraints as needed | Own settings | Consent remains separate |
| Marketing consent evidence | Pending EA-06 | Relevant governed evidence | Own setting | Not implied by registration |
| Security event | Cloud or EA-01 authority by source | Minimal audit | Safe notice | Mandatory policy may apply |
| Order/Payment/Accounting fact | Laravel | Native authority | Latest authorized projection | Notification is not authority |
| Complaint and classification | Laravel | Native authority | Safe projection | Internal details excluded |
| Loyalty ledger/balance | Laravel | Native authority | Latest authorized projection | Counters never become balance |
| Public reply/Conversation state | Cloud Conversation Store | E-03 projection | Yes | Only accepted/released content |
| Internal staff alert | Laravel/operations | Native authority | No | Separate audience/store |

This is conceptual and approves no schema or final preference authority beyond the Notification boundary.

## Notification Model

A conceptual Notification includes an opaque reference; recipient Portal Account reference; source type/reference/version or event reference; category and priority; safe title/body/preview; opaque action/deep-link reference; created/released timestamps; optional expiration; per-item interaction state; dismiss/archive state; delivery state and channel summaries; template/version reference; correlation reference; and Notification version/state.

E-10 decides exact external references. No field, table, identifier or enum name is approved.

## Notification Categories

Conceptual categories include Security, Critical Operational, Active Order, Payment, Support/Conversation, Complaint, Loyalty, General Operational and Marketing. These are not final enums.

Security and operational communication remain distinct from Marketing. Marketing requires applicable consent. Ordinary preferences cannot suppress mandatory critical-security communication, and later policy may restrict opt-out for order-critical notices. Financial and complaint content is minimized.

## Notification Lifecycle

Preserve separate evidence for:

```text
Source Event Confirmed
Notification Created
Notification Released / Available in Inbox
Channel Delivery Attempted
Channel Delivered
Notification Displayed
Notification Read
Notification Dismissed / Archived
Notification Actioned
Notification Superseded / Withdrawn
```

Delivered is not Read; Displayed is not Read; Read is not a business action. For in-app delivery, Cloud storage proves “Available in Inbox,” not device-level delivery. No UI may claim a stronger state without evidence.

## Read and Dismiss State

Use authoritative **per-notification read state** because inbox items may be opened non-sequentially. A recipient last-read cursor alone would incorrectly mark skipped Notifications read. A hybrid cached unread count is allowed only as a rebuildable projection of per-item state.

Customers may conceptually Mark Read, Mark Unread, Dismiss or Archive subject to later policy. Dismiss/archive hides an item from the default view but does not erase the logical Notification, source correlation, delivery or audit evidence. Security or legally required notices may restrict dismissal. Ordinary hard deletion is not dismiss; retention remains EA-06.

## Immutability and Corrections

A released Notification is an immutable historical communication snapshot. Template edits or changed source facts do not silently rewrite it. Depending on authoritative evidence, the system may mark it Superseded/Withdrawn and/or release a traceable corrected/new Notification.

A payment-confirmed notice must never silently become payment-unconfirmed text. Financial correction requires authoritative Laravel evidence and remains subject to E-06/EA-05. Unreleased local/rendering work is not customer history.

## Duplicate Suppression

E-02 redelivery must converge on the same logical Notification. Candidate uniqueness inputs include recipient, source event/reference/version, Notification purpose/type and template/version, but the final key belongs to EA-03/E-10. A new logical Notification is allowed only for an intentional new communication event, not transport replay.

## Multi-Channel Model

Use one logical customer Notification with channel-specific delivery attempts/evidence:

```text
Logical Notification
├── In-App
├── Push
├── WhatsApp
├── SMS
└── Email
```

Channel-specific safe content adaptation is allowed, but channels do not create independent truths. MVP requires In-App only. Push, WhatsApp, SMS and Email are potential Post-MVP channels. EA-01 may require a separate recovery/security channel; OTP delivery remains distinct from general Notification delivery. No provider or channel implementation is selected.

## Notification Preferences

Conceptual preferences may cover optional operational topics, Marketing, later channels and quiet behavior where policy allows. Critical security notices cannot be disabled through ordinary preferences. Order-critical opt-out may be restricted later. Channel preference is not Marketing consent; Marketing consent must be explicit, withdrawable and evidenced under EA-06. Portal account/session security remains EA-01. No preference schema is approved.

## Sensitive Content

Minimize content because previews may later appear on lock screens, shared devices, email subjects, push banners or messaging channels. Avoid unnecessary payment references, balances, financial state, private Complaint details, Internal Notes, family/allergy-like data, full addresses, security tokens and OTPs. Prefer a safe generic update with authenticated navigation when detail is sensitive.

Deep links use opaque references, authenticate before protected destinations, re-authorize recipient ownership and staff scope, preserve only safe return destinations, and never encode sensitive content in URLs. Exact routes and identifiers remain E-10/implementation matters.

## Operational Notifications

```text
Laravel transaction and authoritative fact
→ Local Outbox
→ Sync Agent / E-02 transfer
→ Cloud inbox event
→ eligibility and duplicate suppression
→ customer Notification
```

Examples include order acceptance/preparation/delivery, Complaint updates and confirmed loyalty events. Cloud must not infer final Laravel state from time or local-looking evidence. A payment-proof upload cannot produce “payment received/verified” without the authoritative E-06/Laravel outcome.

## Conversation Notifications

Once Laravel staff authorization has been respected, a Cloud-accepted and Customer-Released public reply may create a customer Notification directly within the Cloud boundary without a Laravel round trip to prove the reply exists. Internal Notes never generate customer message notifications. Pending Sync replies do not notify customers. The Notification's read state remains distinct from the E-03 Conversation read cursor.

## Payment Notifications

E-04 does not decide Payment Proof received, under review, verified, rejected or reconciliation-required workflow. E-06 owns verification truth and EA-05 owns financial posting timing. Notification storage records communication only after the corresponding authoritative fact is established and minimizes financial content.

## Loyalty Notifications

Cloud may notify about confirmed points, available/expiring rewards or redemption only from governed Laravel-authoritative ledger/balance events or authorized projections. Notification/unread counters never become loyalty balance truth.

## Marketing Notifications

Campaign, occasion, reactivation and promotional Notifications require consent validation before creation/delivery, campaign/reference audit and opt-out enforcement. Marketing opt-out cannot suppress operational/security communication. Consent and operational registration must not be combined through dark patterns. EA-06 retains final consent and retention authority.

## Customer Inbox

The conceptual Portal experience includes a paginated inbox, derived unread count, per-item read/unread, archive/dismiss, category filtering, safe deep links, freshness/source-correction indication and safe errors. A dashboard may show a recent preview linked to a full inbox. All behavior remains unimplemented.

## Laravel Projection

Recommend a **minimal operational Notification projection**, not a full copy and not necessarily zero projection. Project only relevant opaque references, important delivery/failure state, communication audit and operationally important recent notices where a staff workflow demonstrates need. This minimizes duplication and privacy exposure while supporting investigations.

Unlike E-03 public Messages, staff do not need every customer Notification body for normal operations. Cloud remains authority; projected data is freshness-aware and cannot be directly edited. Specific workflows may justify additional authorized fields later without changing authority.

## Staff/Internal Alerts

Keep internal staff operational alerts Laravel/operations-authoritative during MVP: SLA breach, Failed Sync, Needs Review, Financial Review, active-order delay, Agent Offline and high-priority Complaint. They do not enter the customer Cloud inbox and do not inherit customer read/preferences semantics. A future common delivery service may distribute them, but their audience, content authorization and source authority remain separate.

## Offline and Failure Behavior

| Scenario | Customer Notification Behavior | Source Authority | UI State |
| --- | --- | --- | --- |
| Branch internet offline | Existing inbox and Cloud-source notices remain available; new Laravel facts wait | Cloud inbox; Laravel domain | Inbox available; operational freshness may lag |
| Sync Agent offline | Cloud-source notices continue; Laravel events remain local | Respective source | Explicit delayed operational updates |
| Laravel offline | Inbox and Cloud Conversation/security notices remain available | Cloud inbox/Conversation | Do not infer missing operational outcomes |
| Cloud API offline | No new inbox commit/read/dismiss confirmation | Last committed Cloud state | Degraded/retry; no false confirmation |
| Local domain event waiting in Outbox | No Notification until synchronized and accepted | Laravel source fact | Latest confirmed inbox only |
| Cloud conversation reply generated | Eligible after Cloud acceptance/release | Cloud Conversation Store | Available in Inbox when committed |
| Duplicate source event | Converges on the prior logical Notification | Original source + Cloud inbox | No duplicate item |
| Notification channel delivery fails | Logical inbox item remains; attempt is recorded | Cloud Notification | Channel failure/retry state, not unread truth |
| Notification already read on another device | Cloud read state is returned to all devices | Cloud Notification | Read after refresh/sync |
| Notification source later corrected | Original remains; governed supersession/correction follows | Source domain + Cloud history | Corrected/withdrawn state with traceability |

No operational truth is fabricated. Stale source data and unavailable confirmation are explicit.

## Multi-Device Behavior

Read, unread and dismiss/archive changes commit to Cloud and are reflected across authorized devices. Device-local caches are projections only and reconcile with Cloud. Push-device registration is out of scope.

## Security and Authorization

- Require EA-01 Portal authentication and recipient ownership checks for every inbox read/mutation and deep-link target.
- Apply least-privilege staff visibility and Laravel branch/team scope where operational investigation exposes Notification metadata.
- Apply rate limits, abuse/replay protection, CSRF where applicable, input/template sanitization and safe opaque deep links.
- Minimize/redact PII and financial/Complaint/security content in logs, exports and previews; never include secrets or OTPs.
- Audit creation, release, preference/consent use, read/dismiss where policy requires, correction/withdrawal, channel attempts, sensitive access and exports.
- Encrypt in transit/at rest and support retention classification and legal/business holds without choosing technology.

## Search, Pagination and Reporting

Use server-side pagination and recipient authorization. Within one inbox, order deterministically by release time plus a stable tie-breaker; there is no global cross-customer order. Support category filtering and rebuildable unread count. Authorized reporting may include source correlation, delivery/channel failures, Marketing delivery, security audit and aggregate read rates. Do not select an analytics vendor or search engine.

## Ordering and Expiration

Notifications do not require E-03's strict per-Conversation sequence. Source versions validate eligibility/correction; recipient inbox order uses `released_at` conceptually plus a stable opaque tie-breaker. Expiration may hide limited-time offers/reminders from active presentation but never silently destroys required audit evidence. Operational/security histories can have different retention classes; durations remain EA-06.

## Template Versioning

Maintain the conceptual chain `authoritative fact → Notification purpose → template/version → safe rendered snapshot`. Template/version is traceable and historical released content does not change when a template changes. Localization may vary; Arabic is primary, but identity and deduplication must not depend on rendered text. Template implementation is not approved.

## Impact on Other Decisions

| Decision | E-04 Impact |
| --- | --- |
| E-05 Pre-Approval Order Model | Must distinguish staged-order communication from accepted Laravel Order truth. |
| E-06 Payment Verification | Defines Payment notification truth and proof/review outcomes. |
| E-07 Offline and Retry | Defines event/channel retry, terminal failure and degraded-state behavior. |
| E-08 Conflict Resolution | Defines correction when source facts/projections conflict. |
| E-09 Unified Customer Identity | Defines recipient Portal-to-Customer mapping and ownership. |
| E-10 External References | Defines opaque Notification, source, action and correlation references. |
| EA-01 Portal Authentication | Defines sessions, recovery/security events and protected inbox access. |
| EA-02 Status Dictionary | Defines exact source/public lifecycle vocabulary. |
| EA-03 Idempotency | Defines duplicate Notification and channel-attempt prevention. |
| EA-04 Catalog Authority | Governs product/offer facts referenced by communication. |
| EA-05 Financial Timing | Defines when financial facts may be communicated. |
| EA-06 Privacy and Retention | Defines consent, Marketing, minimization, retention, deletion, holds and sensitive-content policy. |

E-04 does not resolve any of these decisions.

## Recommended Option

**Approved: Option A — Cloud-authoritative Customer Notification Store.** Cloud owns the logical customer Notification, In-App inbox and customer interaction state. Laravel and the Cloud Conversation Store retain their respective domain/source authorities. Laravel keeps only a minimal operational projection. Internal staff alerts remain operationally separate. In-App is the only approved MVP customer delivery channel; Push, WhatsApp, SMS and Email remain deferred.

## Consequences

- Customers have one multi-device inbox available independently of branch connectivity.
- Laravel and Cloud source domains must emit authenticated, versioned, duplicate-safe facts with clear eligibility rules.
- Portal read/dismiss state and later channel evidence are Cloud responsibilities.
- Staff investigations use a deliberately minimal Laravel projection rather than a second full Notification history.
- Security, operational and Marketing governance and content minimization become explicit release requirements.

## Risks

- Stale or invalid source events could communicate incorrect operational/financial facts.
- At-least-once delivery could duplicate customer communication.
- Sensitive preview or deep-link data could leak on shared devices.
- Preference/consent mistakes could suppress mandatory notices or send unauthorized Marketing.
- Cloud outage blocks new inbox commits and synchronized interaction state.
- Minimal Laravel projection may omit evidence needed by an unforeseen staff workflow.
- Future channel providers may report incompatible delivery semantics.
- Payment notification timing may be wrong until E-06 and EA-05 define the authoritative verification/posting points.

## Mitigations

- Require authoritative source references/versions and never infer final Laravel facts.
- Apply EA-03 idempotency, correlation and intentional-new-event semantics.
- Use safe snapshots, opaque links, destination re-authorization and PII minimization.
- Separate security/operational/Marketing policy; preserve auditable EA-06 consent evidence.
- Display honest degraded state and use E-07 retries/reconciliation without false delivery claims.
- Validate staff workflows before projection expansion; retain Cloud authority and least privilege.
- Normalize channel attempts under one logical Notification without claiming unsupported delivery evidence.
- Complete E-06 for payment truth, E-07 for retry/outage behavior, E-08 for correction conflicts, E-09 for recipient identity, E-10 for references, EA-01 for Portal/security channels, EA-03 for deduplication, EA-05 for financial timing and EA-06 for consent/privacy/retention. These risks remain open.

## Rejected Alternatives

- **Laravel-authoritative Notification Store** is rejected because authoritative inbox/read/dismiss state becomes unavailable or delayed during branch/Laravel outage and Cloud Conversation notifications require unnecessary round trips.
- **Dual Authority/dual write** is rejected because it creates divergent histories, duplicate Notifications and conflicting multi-device/read/correction state.
- A full Laravel mirror by default is rejected as unnecessary duplication/privacy exposure; no Laravel projection is also rejected as too rigid for delivery-failure audit and operational investigation.
- One independent Notification per channel is rejected because it fragments logical history, correction and preference semantics.
- Treating Toasts, sounds or internal complaint alerts as durable customer Notifications is rejected.

## Implementation Implications for Phase F

After approval and dependent decisions, Phase F must define versioned source-event and Notification contracts, opaque references, recipient authorization, duplicate handling, safe rendering/versioning, lifecycle evidence, minimal Laravel projection, pagination and reconciliation. Channel/provider implementation remains out of scope. Phase F has not started and no schema follows automatically from this ADR.

## Final EA-06 Deferral

EA-06 retains final authority for Marketing consent rules, retention periods, deletion/anonymization, legal/business holds, sensitive-content and channel-specific privacy policies, and Marketing preference schema. E-04 permanently establishes that Portal registration is not Marketing consent, mandatory critical-security notices cannot be suppressed through ordinary preferences, Dismiss is not Delete, previews are minimized, Internal Notes never become customer Notifications, OTP is not ordinary Notification content, and Notification never replaces Domain State.

## Architecture Review Decisions

1. **Do we approve a Cloud-authoritative Customer Notification Store?**

   **Decision:** Approved.

   **Rationale:** It provides one branch-independent customer inbox and authoritative multi-device interaction state without changing source-domain authority.

   **Follow-up Decision:** E-09/E-10 define recipient mapping and external references.

2. **Is In-App the required MVP channel while Push/WhatsApp/SMS/Email remain later?**

   **Decision:** Approved; In-App is the only E-04 MVP customer channel and all listed external channels are deferred.

   **Rationale:** It satisfies the approved D1 MVP while avoiding premature provider/channel architecture.

   **Follow-up Decision:** EA-01/EA-06 govern required security/recovery channels and privacy; later delivery decisions select providers.

3. **Should Laravel keep a full, minimal or no customer-Notification projection?**

   **Decision:** Minimal Operational Notification Projection approved.

   **Rationale:** Demonstrated staff/audit needs can be supported without duplicating the Cloud inbox or unnecessary Marketing/read/archive data.

   **Follow-up Decision:** Phase F validates exact authorized operational fields and freshness evidence.

4. **Do we approve one logical Notification with channel-specific delivery attempts?**

   **Decision:** Approved.

   **Rationale:** One communication history preserves correction, preference and audit semantics across future channels.

   **Follow-up Decision:** E-07/EA-03 define attempt retry and duplicate-safe effects; providers remain later decisions.

5. **Do we distinguish Source Event, Created, Released, Attempted, Delivered, Displayed, Read, Dismissed, Actioned and Corrected/Superseded?**

   **Decision:** Approved as separate evidence-backed lifecycle facts.

   **Rationale:** Available in Inbox does not prove device delivery, and delivery/display/read do not prove customer action or domain outcome.

   **Follow-up Decision:** EA-02 and later channel contracts define exact terms without collapsing these distinctions.

6. **Is per-notification Read State authoritative with unread count as a projection?**

   **Decision:** Approved.

   **Rationale:** Non-sequential inbox access must represent read/unread independently for every item.

   **Follow-up Decision:** EA-03 defines mutation idempotency and Phase F defines rebuild/reconciliation contracts.

7. **Should released Notifications remain immutable with governed correction/supersession?**

   **Decision:** Approved, including Corrected, Superseded, Withdrawn or a new corrective Notification as governed outcomes.

   **Rationale:** Historical customer communication cannot be silently rewritten, especially for financial facts.

   **Follow-up Decision:** E-06/E-08/EA-05/EA-06 define source correction, financial timing, presentation and retention rules.

8. **Do Security, Operational and Marketing remain separately governed, with critical Security not disabled by ordinary preferences?**

   **Decision:** Approved.

   **Rationale:** Their legal basis, urgency, consent, sensitivity and opt-out behavior differ; Portal registration is not Marketing consent.

   **Follow-up Decision:** EA-01/EA-06 define final security-channel, preference, consent and privacy policy.

9. **Do internal staff alerts remain Laravel/operations-authoritative and outside the customer Cloud inbox in MVP?**

   **Decision:** Approved.

   **Rationale:** Staff alerts have different audiences, permissions, escalation, read semantics, workflows and retention.

   **Follow-up Decision:** A later delivery-service decision may distribute them without changing authority.

10. **Do final Marketing consent, retention, deletion and sensitive-content rules remain deferred to EA-06?**

    **Decision:** Approved.

    **Rationale:** E-04 establishes permanent safety boundaries but does not have the policy/legal scope to set final periods or consent evidence.

    **Follow-up Decision:** EA-06 must resolve consent, withdrawal, retention, deletion/anonymization, holds and channel privacy before production.

## Traceability

- E-01: independent Cloud boundary, outbound-only Sync Agent, Laravel-mediated Admin and local operational/financial authority.
- E-02: durable pull/push, at-least-once delivery, reconciliation and hint-only realtime.
- E-03: Cloud Conversation/Public Message authority, release/read distinctions, Pending Sync exclusion and Laravel-only Internal Notes.
- D1: in-app MVP, operational/Marketing separation, consent, customer messages/complaints and secure Portal expectations.
- D2: actor/scope authorization, complaint/SLA visibility, operational notices and campaign governance.
- Current-system evidence: Laravel `Notifiable` without application Notification storage, computed complaint alerts, transient Admin Toasts/KDS sounds, and transient website Toasts/ratings responses.
- Dependencies left open: E-05 through E-10 and EA-01 through EA-06 as detailed above.
