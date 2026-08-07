# ADR E-03 — Conversation and Message Storage

## Status

**[x] Approved**

Approval Date: **2026-08-07**

Approved Decision: **Cloud-authoritative Conversation and Public Message Storage**

This approval selects an authority boundary and conceptual semantics only. It does not approve a schema, table or class names, retention periods, storage/scanning providers, attachment allowlists or limits, exact identifiers, exact statuses, queues/jobs, APIs, WebSocket/SSE, complaint implementation, or Phase F work.

## Context

The customer portal needs a durable conversation history while branch Laravel can be unreachable. Staff must continue to use Laravel-mediated administration, and the system must not create two editable versions of the same public history. E-03 therefore decides where Conversations, public Messages, ordering, read state, assignment evidence, SLA evidence, delivery/synchronization state, and operational links are authoritative.

## Approved E-01 and E-02 Constraints

- E-01 approves an independent Cloud Integration Service, an outbound-only local Sync Agent, Laravel as operational and financial authority, and Laravel-mediated Admin access.
- E-02 approves durable HTTP pull/long polling from cloud to local, Local Outbox HTTP push, adaptive polling and reconciliation, at-least-once transfer, and aggregate/partition ordering rather than global ordering.
- WebSocket/SSE is Post-MVP and may only be a wake-up hint. Correctness cannot depend on it.
- E-07, E-08, E-09, E-10 and EA-01 through EA-06 remain pending.

## Business Constraints

1. A customer may open a general conversation or one linked to an Order or Complaint and may see only their conversations.
2. Staff access is permission- and branch-scoped; supervisors may inspect and intervene in team conversations, with the real actor recorded.
3. Public customer/staff messages and internal notes must never share a customer-visible stream.
4. A new conversation must enter an assignable queue; assignment, reassignment and escalation need evidence.
5. Ordering must be reliable and duplicate delivery must not create another logical message.
6. System Receipt, Human Acknowledgement, read state and staff reply are distinct facts.
7. Conversation history must have one authority and must not diverge during outages.
8. MVP supports controlled multi-type attachments as purpose-classified evidence; arbitrary executable or active content is prohibited by default. Payment Proof is governed by E-06, not by generic message attachments.
9. Final retention, deletion and privacy periods are deferred to EA-06.

## Current-System Evidence

### Laravel backend

- No inspected `Conversation` or public `Message` model, migration, service, route, sequence, read cursor or thread endpoint exists.
- `CustomerComplaint` is an operational complaint record linked to Customer, optional Order and Invoice, assigned Employee, creator, branch, priority/status and resolution timestamps. It is soft-deleted but is not a public message thread.
- `ComplaintFollowup` records actor-attributed complaint actions/notes and old/new status. It has no soft delete and is useful complaint timeline evidence, but its mixed action/note semantics must not become the public Message model.
- `CustomerNote` is a soft-deleted local note linked to Customer and optional Order, with type, importance, creator and sensitive-note authorization. It is reusable as an internal-note concept, not as public content.
- Authenticated call-center routes expose complaint CRUD, follow-ups/timeline and customer notes. CRM read routes expose complaints and notes with explicit permissions. No public portal conversation route exists.
- Existing Customer, Order, Employee/User, branch scope, Sanctum/role/permission checks, audit facilities and server-side pagination are reusable local operational foundations. Audit coverage is not a complete cross-system conversation audit.
- No inspected polymorphic relation provides a current conversation abstraction; Order/Complaint links should remain explicit conceptual links until schema design.

### Admin application

- There is no conversation inbox/thread UI or public-message composer. Existing screens include complaint management, quick complaint creation and Customer 360 complaint/note views.
- The application uses Laravel Axios clients (`VITE_API_URL` or `/api`) and `/crm/*`/call-center endpoints. No direct Cloud client was found.
- Existing complaint controls cover status/follow-up behavior; customer notes and sensitive-note permissions exist. No conversation unread count, read cursor, assignment controls for conversations, synchronization freshness, or Pending Sync public-reply contract was found.
- Refresh behavior found in unrelated operational screens does not form a conversation auto-refresh/realtime contract.

### Public website

- No portal accounts, Messages pages, support-conversation form, notification center, realtime client, conversation pagination, unread pattern or message attachment flow was found.
- The inspected PostgreSQL schema contains `restaurant_ratings`; `app/actions/ratings.ts` writes ratings through a Server Action. This is feedback intake, not conversation storage, and must not be expanded into a second conversation authority.
- Current WhatsApp handoffs and UI uses of message icons are not an owned durable conversation history.

### Reuse and separation

Reuse Laravel identities, permissions, branch scope, Customer/Order/Complaint records, internal-note controls, audit patterns and pagination conventions. Keep complaint follow-ups, customer/internal notes, ratings, call tickets and public Messages as distinct concepts. Connect them with governed references rather than copied content.

## Decision Drivers

- Customer availability while a branch is offline.
- One governed, recoverable public history.
- Laravel-mediated staff authorization and operational access.
- Deterministic aggregate ordering and duplicate suppression under at-least-once delivery.
- Explicit assignment/SLA/read evidence and honest stale/pending states.
- Least-privilege separation of public content, internal notes and sensitive operational/financial records.
- A client-neutral foundation for a future mobile application.

## Options Considered

### Option A — Cloud-authoritative conversations with Laravel operational projection

Cloud commits the public aggregate and its workflow evidence. Laravel receives an authorized, stale-aware, read-only operational projection and originates authorized commands through its outbox.

### Option B — Laravel-authoritative conversations with cloud portal projection

Laravel commits the history and the cloud mirrors it for customers. Branch or Laravel outage prevents authoritative customer intake unless the cloud accepts non-authoritative drafts, which recreates a second reconciliation surface.

### Option C — Dual-authoritative or dual-write storage

Cloud and Laravel both accept authoritative writes or participate in a synchronous dual write. This creates split-brain history, ambiguous ordering and conflict resolution across an intermittently connected boundary.

## Detailed Comparison

| Criterion | Cloud Authority | Laravel Authority | Dual Authority |
| --- | --- | --- | --- |
| Customer availability during branch outage | Strong | Weak | Acceptable |
| Single governed history | Strong | Strong | High Risk |
| Admin operational access | Strong | Strong | Acceptable |
| Offline staff behavior | Acceptable | Strong | Weak |
| Message ordering | Strong | Strong | High Risk |
| Duplicate suppression | Strong | Acceptable | High Risk |
| Assignment and SLA evidence | Strong | Acceptable | High Risk |
| Internal/public separation | Strong | Acceptable | Weak |
| Privacy exposure | Acceptable | Strong | High Risk |
| Retention consistency | Strong | Strong | High Risk |
| Synchronization complexity | Acceptable | Acceptable | High Risk |
| Conflict risk | Strong | Acceptable | High Risk |
| Customer read/unread state | Strong | Weak | High Risk |
| Staff read/unread state | Acceptable | Strong | High Risk |
| Complaint conversion | Strong | Acceptable | Weak |
| Future mobile application | Strong | Weak | Acceptable |
| Search and reporting | Acceptable | Strong | Weak |
| Failure recovery | Strong | Acceptable | High Risk |
| Scalability | Strong | Acceptable | Weak |
| Risk of divergent history | Strong | Acceptable | High Risk |

Laravel Authority preserves familiar local staff operations, but it cannot provide authoritative customer intake during a branch outage. Dual Authority performs worst on the correctness properties that matter most. Cloud Authority adds projection and staff-command latency, but aligns with E-01/E-02 and keeps one durable customer-facing history.

## Recommended Authority Model

Use a **Cloud-authoritative Conversation Store**.

Cloud owns Conversation identity, the public Conversation/Message history, per-conversation sequence, customer-visible state, assignment snapshot/history, SLA and Human Acknowledgement evidence, per-participant read cursors, message correction/redaction history, customer delivery/release state, and conversation synchronization state.

Laravel owns staff identity, authentication and authorization, role/branch scope, operational Customer/Order/Complaint records, internal notes, financial/compensation actions, and local authorization/audit evidence. It stores an authorized operational read projection of Conversations and public Messages.

Laravel retains the complete authorized Public Message history within the applicable retention period to support Call Center operations, Customer 360, complaint investigation, search, SLA review and continuity during temporary synchronization lag. The projection is not a parallel authority, accepts no direct edits, follows Cloud sequence/version, exposes freshness, `last_confirmed_at` and `sync_status`, may be stale during outage, and is repaired only through synchronization/reconciliation. EA-06 remains responsible for final retention, minimization, deletion, redaction and hold policy.

## Data Ownership Matrix

| Data | Final Authority | Local Projection | Customer Visible | Internal Only |
| --- | --- | --- | --- | --- |
| Conversation identity | Cloud | Yes | Opaque reference | No |
| Conversation type | Cloud | Yes | Yes | No |
| Customer public messages | Cloud | Yes | Yes | No |
| Staff public replies | Cloud | Yes | Yes | No |
| Message sequence | Cloud | Yes | Indirectly | No |
| Conversation public state | Cloud | Yes | Yes | No |
| Assignment snapshot | Cloud | Yes | Policy-limited | No |
| Assignment history | Cloud | Yes | No | Staff only |
| SLA timestamps | Cloud | Yes | Policy-limited | No |
| Human acknowledgement | Cloud | Yes | Yes | No |
| Read cursors | Cloud | Relevant projection | Own/allowed state only | No |
| Customer delivery/release state | Cloud | Yes | Yes | No |
| Message correction/redaction history | Cloud | Authorized result | Policy-limited | Restricted original evidence |
| General Conversation Evidence | Cloud/private object boundary | Authorized metadata/reference | When explicitly public | Purpose-dependent |
| Complaint Evidence | Laravel Complaint authority with governed evidence reference | Purpose-scoped | Policy-limited | Often restricted |
| Payment Proof | Pending E-06; never generic attachment authority | Purpose-scoped | No by default | Finance-restricted |
| Internal notes | Laravel | Native local record | No | Yes |
| Staff permissions | Laravel | No cloud authority; scoped claims only | No | Yes |
| Customer identity mapping | Pending E-09 | Needed locally | Minimal reference | No |
| Order link | Laravel Order; Cloud conversation link | Yes | Authorized summary/reference | No |
| Complaint link | Laravel Complaint; Cloud conversation link | Yes | Policy-limited | No |
| Financial action | Laravel | No cloud authority | Outcome only if authorized | Yes operationally |
| Audit evidence | Each authority for its actions; correlated | Relevant evidence | No | Yes |

This matrix is conceptual and does not approve database names.

## Conversation Model

A Conversation concept contains an opaque external reference; portal/customer identity reference; optional Laravel Customer reference; branch scope; conversation type; optional Order and Complaint references; public state; assigned team/employee snapshot; priority; SLA timestamps; last-public-message projection; participant read positions; Cloud version; `last_confirmed_at`; and synchronization status.

The public reference must not expose a Laravel incremental ID. E-10 decides its exact form, E-09 decides customer identity mapping, and EA-02 decides exact statuses. Public state is separate from internal workflow notes. Each Conversation is an independent ordering aggregate.

## Message Model

A Message concept contains an opaque message reference; Conversation reference; sender type and reference; public content; per-conversation sequence; source-created and Cloud-accepted timestamps; customer-release state; delivery/synchronization state; optional governed correction/redaction reference; authorized attachment references; and Cloud version.

- Sequence, not timestamp alone, determines order; there is no global message order.
- Once accepted by Cloud, a Message is not directly edited.
- Governed corrections/redactions are explicit versioned/audited changes or tombstones, never silent replacement/removal.
- Duplicate transfer is expected; EA-03 later defines exact idempotency contracts and E-10 identifiers.
- Internal Notes and Payment Proof are not Messages.

## Ordering and Read State

Use per-Conversation aggregate sequence as authority. Use a per-participant or governed participant-class **last-read sequence cursor** as read authority; derive unread counts as projections.

Per-message booleans scale poorly and are costly to repair. Hybrid cached unread counters are acceptable only as rebuildable projections. Customer and assigned staff/team cursors are distinct. Supervisor viewing does not automatically consume the support queue's unread state. Read acknowledgement is not Human Acknowledgement; connection is not read; delivery is not completion of a Laravel operational action.

## Customer Message Flow

```text
Customer
→ Cloud API
→ Validate ownership and content
→ Commit Conversation/Message
→ Return System Receipt
→ Publish synchronization event
→ Laravel projection
→ Assignment workflow
→ Human Acknowledgement
```

Conceptually: **System Receipt** proves durable receipt of a customer submission; **Message Accepted/Cloud Accepted** proves Cloud committed the public Message; **Assigned** records staff/team responsibility; **Human Acknowledgement** proves a human explicitly accepted attention/responsibility; **Staff Reply** proves a public staff response was Cloud-accepted; **Customer Released** proves the response became available to the customer; **Customer Read** proves the applicable read cursor crossed its sequence. Resolved and Closed are later public lifecycle facts. These are not final enum names. The UI must never claim read, acknowledged, replied or delivered without corresponding evidence.

## Staff Reply Flow

```text
Admin user
→ Laravel authorization
→ local command/outbox
→ Sync Agent
→ Cloud Conversation Store
→ Cloud commit and acknowledgement
→ Laravel projection confirmation
```

Permit a staff reply to be saved locally as **Pending Sync** during an outage only when Laravel durably preserves actor, scope, content, stable command identity, creation time and target Conversation reference. Show it to staff as pending, never Sent. While pending, it does not advance customer unread state, authoritative waiting-party state or Cloud sequence and is not visible in the Portal. Retries must converge on the same logical result. Permanent failure becomes Failed Sync/Needs Review subject to E-07. An unaccepted local draft may be edited or cancelled.

This is preferable to blocking all offline drafting because it preserves staff work, while the explicit pending boundary prevents false customer-visible claims. Local unsent drafts may be edited; they are not accepted Messages.

## Assignment and SLA Authority

Cloud stores the authoritative conversation assignment/SLA timeline: unassigned queue, assigned team/employee snapshot, assignment time/history, Human Acknowledgement, first response, waiting-party changes, resolution, closure, reopen and escalation times/events. Laravel authorizes staff eligibility, branch scope and every staff-originated assignment/state action. An accepted Laravel command updates Cloud first and returns through the projection before it is confirmed.

D2 governs assignment precedence; this ADR does not approve an algorithm or exact status dictionary.

## Internal and Public Separation

Internal Notes remain Laravel-only for MVP. They use separate authorization and endpoints, do not enter public Message ordering, portal or customer notification payloads, and cannot be automatically copied into a public reply. Publishing note content requires an explicit public-reply action and content review. Sensitive notes remain protected. No current requirement justifies Cloud storage of Internal Notes.

## Complaint Conversion

```text
Conversation
→ Authorized convert-to-complaint action
→ Laravel Complaint creation
→ Complaint external reference returned
→ Conversation linked to Complaint
```

Do not copy Messages into a second editable complaint history. Laravel remains authority for the operational Complaint; each side retains a governed reference to the other. Complaint follow-ups/internal notes remain local and separate. Any public complaint follow-up must explicitly become a Cloud public Message. Conversion must later be idempotent under EA-03.

### Complaint classification and operational awareness

Laravel is authority for structured operational Complaint classification. Conceptually this may include Problem Category, Problem Subcategory, Priority/Severity, Related Order, Related Branch, Complaint Status, Follow-up Required, Resolution Summary and Last Follow-up/Update. These are conceptual facts, not schema names.

Before conversion, Cloud may own a general Conversation Topic; that topic is not Complaint Classification. After conversion, Cloud receives only the safe Complaint reference/projection needed for customer-facing or synchronization use.

Authorized Customer 360 and Call Center context must later be capable of surfacing an active-complaint indicator, problem category, priority, related order/branch, current status, follow-up requirement, last follow-up and an authorized resolution summary. This is a Laravel operational projection and must not expose sensitive internal notes or unrestricted Complaint detail.

## Message Mutability

An accepted Message has an immutable original record. Direct in-place editing and silent deletion are prohibited. **Cloud Accepted**, **Customer Released** and **Customer Read** are distinct facts.

Before Customer Release, an authorized supervisor may intercept, correct, redact or cancel customer delivery of inappropriate, incorrect or unsafe content. The original accepted content remains in restricted audit history and corrected/replacement public content is a separate governed revision/result. Preserve conceptually the original message reference/content, corrected or redacted content, staff and supervisor actors, moderation action/reason, timestamps and correlation/reference.

After Customer Release, history is never silently rewritten; an explicit governed Correction or Redaction changes customer-facing presentation while preserving original restricted evidence where law and policy permit. A missing read cursor does not guarantee interception: the guaranteed safety boundary is before Customer Release, not before Customer Read. Only an unsubmitted local draft is directly editable. Exact moderation windows, roles, automated detection, presentation, privacy deletion and hold rules remain E-08/EA-03/EA-06 or implementation decisions.

## Attachments Scope

The original text-only MVP proposal is rejected. MVP approves **Controlled Multi-Type Attachments** for integrated operational evidence. Allowed categories may include images, documents, PDF, spreadsheets, audio, video and other explicitly allowed business evidence. This is not approval for unrestricted file types; executable and unsafe active/script content is denied by default.

Attachment implementation must use private object storage with no permanent public object URLs; file-type allowlists; MIME/content validation; maximum-size limits; malware scanning; integrity checksum/hash; ownership/purpose authorization; short-lived signed or scoped access; access/action audit; retention classification; and legal/business hold support. This ADR chooses no provider, scanning product, exact MIME/extension list, size limit or signed-access duration.

Purpose determines workflow even when customer upload experience is unified:

```text
General Conversation Evidence → Conversation Attachment
Complaint Evidence → Complaint Evidence
Payment Proof → E-06 Payment Verification Evidence
```

A Conversation may hold opaque references to authorized private attachments, governed by customer/conversation ownership and public/internal visibility. Complaint conversion may link applicable evidence without unnecessarily copying binaries or creating independent mutable copies. Payment Proof is never a generic Message Attachment, never inherits generic conversation retention automatically, and remains subject to E-06 financial authorization, verification state, evidence lifecycle, privacy, audit and reconciliation. Exact evidence contracts and retention/deletion remain Phase F, E-06 and EA-06 work; no attachment schema is approved here.

## Offline and Failure Behavior

| Scenario | Customer behavior | Staff behavior | Stored authority | UI state |
| --- | --- | --- | --- | --- |
| Branch internet offline | Cloud intake continues | Reads may be stale; safe replies may queue | Cloud for accepted history | Customer receipt; staff stale/pending |
| Cloud API offline | Submission cannot be accepted; safe retry | Draft/pending command only | Last Cloud commit | Degraded; never false Sent |
| Sync Agent offline | Cloud intake continues | Projection stops; commands remain local | Cloud | Stale/pending visible |
| Laravel offline | Portal history remains available | Admin unavailable | Cloud public history | Portal available; operations delayed |
| Staff reply queued locally | No reply/unread change yet | Can inspect/cancel editable draft per policy | Local pending command only | Pending Sync |
| Cloud message not yet projected | Customer sees accepted message | Staff may not yet see it | Cloud | Projection lag/stale marker |
| Duplicate event | No duplicate message | Projection applies idempotently | Cloud | No visible duplicate |
| Out-of-order message event | Cloud order remains stable | Buffer/backfill by sequence/version | Cloud | Gap/stale until repaired |
| Lost acknowledgement | Retry/query prior result | Same command is retried/reconciled | Cloud if committed | Pending until proven |
| Conversation projection stale | Current Cloud view remains valid | Restricted stale operations per policy | Cloud | `last_confirmed_at` and stale status |

No silent overwrite is allowed. Reconciliation repairs missing/gapped projections and cannot invent acceptance.

## Privacy and Security

- Authorize customer ownership on every conversation/message read and mutation; authorize staff permission, branch/team scope and field visibility in Laravel.
- Apply least privilege, strict public/internal separation, sanitized HTML/script-free text, message-size limits, rate limits and abuse/spam controls.
- Encrypt in transit and at rest without selecting a provider here. Redact message content and secrets from routine logs; audit privileged access, corrections, redactions, exports and workflow commands.
- Do not expose Laravel IDs, employee secrets/contact data, financial/family/sensitive data, or sensitive lock-screen notification content.
- Support export controls and legal/business holds. EA-06 must decide retention, deletion and lawful-purpose rules before production.

## Search, Pagination and Reporting

Use server-side cursor/pagination for conversation lists and Message history. Messages sort stably by per-conversation sequence; conversation-list cursors must have deterministic tie-breaking. Enforce customer ownership, branch/team scope and field permissions in search. Unread counters, search documents and reports are rebuildable projections. SLA/first-response and assignment-history reporting must retain authority references and show Laravel projection freshness. No full-text engine or index technology is selected.

## Impact on Other Decisions

| Decision | E-03 Impact |
| --- | --- |
| E-04 Notification Storage | Notifications reference Cloud conversation/message facts; internal notes never enter customer payloads. |
| E-05 Pre-Approval Order Model | Conversations may link to staged or accepted orders without changing order authority. |
| E-06 Payment Verification | Must define purpose-specific Payment Proof evidence; it is not a generic Message attachment. |
| E-07 Offline and Retry | Must define Pending Sync retry, terminal failure, stale thresholds and reconciliation. |
| E-08 Conflict Resolution | Must respect authority boundaries and define correction/moderation reconciliation. |
| E-09 Unified Customer Identity | Must define portal ownership and portal-to-Laravel Customer mapping. |
| E-10 External References | Must define opaque Conversation, Message, attachment and cross-system link references. |
| EA-01 Portal Authentication | Must authenticate portal participants and secure conversation ownership. |
| EA-02 Status Dictionary | Must define exact public/workflow states and allowed transitions. |
| EA-03 Idempotency | Must define staff-send/moderation command and event keys, scope, retention and replay outcomes. |
| EA-04 Catalog Authority | No ownership change; order-linked summaries remain projections. |
| EA-05 Financial Timing | Public conversation cannot authorize or post financial effects. |
| EA-06 Privacy and Retention | Must define message/note/attachment retention classes, redaction, deletion, consent, holds and exports. |

E-03 does not resolve these decisions.

## Recommended Option

Choose **Option A — Cloud-authoritative Conversation and Public Message Storage** with an authorized Laravel operational projection, Laravel-only Internal Notes, Cloud-authoritative assignment/SLA timeline, Pending Sync staff replies, immutable accepted Messages with governed moderation revisions, per-conversation sequence, per-participant read cursor, link-only Complaint conversion with Laravel-authoritative problem classification, controlled multi-type MVP attachments, and purpose-specific evidence routing.

## Consequences

- Customers retain authoritative messaging during branch outages and all clients share one public history.
- Admin remains Laravel-mediated and can operate on an authorized local projection.
- Staff public actions acquire an asynchronous pending/confirmed lifecycle and projection freshness must be visible.
- Cloud becomes a regulated content authority requiring backup, recovery, moderation, audit and privacy controls.
- Search/reporting may use local projections but must declare freshness and cannot correct Cloud history directly.
- Moderation requires an explicit customer-release boundary and restricted preservation of immutable original evidence.
- Multi-type evidence increases private-storage, scanning, authorization, bandwidth and retention responsibilities in MVP.

## Risks

- A Cloud outage prevents new authoritative messages.
- Projection lag can hide a newly accepted customer message from staff.
- Pending replies may confuse staff or be submitted twice.
- Incorrect scope mapping can expose conversations or internal information.
- Cross-system assignment actions can be stale when staff eligibility changes.
- Deferred retention/redaction decisions can block production readiness.
- Attachments introduce malware, incorrect authorization, large-file storage/bandwidth growth and evidence-retention mismatch risks.
- Payment Proof could be incorrectly routed or retained as a generic attachment.
- Supervisors could misuse correction/redaction, and restricted original inappropriate content creates audit exposure.
- A race between supervisor interception and Customer Release may make interception impossible.

## Mitigations

- Durable Cloud storage, backups, monitoring, honest receipts and bounded client retry.
- Sequence/version gap detection, reconciliation and explicit `last_confirmed_at`/stale indicators.
- Stable command identity, Pending Sync UI and EA-03 idempotency before implementation.
- Laravel authorization, branch/team scope, minimal projection fields and access audit.
- Revalidate staff eligibility for each command and reject/review stale actions without overwrite.
- Complete EA-06 and security review before production data is accepted.
- Use private storage, allowlists, MIME/content validation, malware scanning, checksums, purpose classification, scoped access and retention classes.
- Enforce role-based moderation, mandatory reasons, immutable original evidence, audit and an authoritative Customer Release state; never promise interception after release.
- Route Payment Proof explicitly to E-06 and keep attachment/privacy/payment/idempotency risks open until their dependent decisions are approved.

## Rejected Alternatives

- **Laravel Authority** is rejected because customer messages cannot be authoritatively accepted during branch/Laravel outage; Cloud staging would become a second pre-authority with complex promotion semantics.
- **Dual Authority/dual write** is rejected because intermittent connectivity makes atomic commitment impossible and creates irreconcilable ordering, read-state, assignment and SLA histories.
- Reusing Complaint Followups, Customer Notes, ratings, call tickets or notifications as public Messages is rejected because their audience, authority, lifecycle and confidentiality differ.
- A text-only MVP is rejected by Architecture Review because integrated customer operations require controlled evidence intake; unrestricted file upload is also rejected.

## Implementation Implications for Phase F

After dependent decisions are approved, Phase F must define versioned contracts for aggregate/evidence references, commands/events, sequence gaps, release/moderation state, projection freshness, authorization claims and reconciliation; a read-only Laravel projection boundary; outbox/inbox and idempotency behavior; private attachment security; audit/observability; and tests for outage, redelivery, lost acknowledgement, release races, malicious files and privacy scope. Phase F has not started and must not infer final schema from this conceptual ADR.

## Architecture Review Decisions

1. **Cloud-authoritative Conversation Store**

   **Decision:** Approved.

   **Rationale:** Provides branch-outage customer availability and one governed public history; dual authority is prohibited.

   **Follow-up Decision:** E-09/E-10 define ownership mapping and references.

2. **Complete authorized Public Message projection in Laravel**

   **Decision:** Approved within the applicable retention period.

   **Rationale:** Supports Call Center, Customer 360, investigation, search, SLA review and continuity without making Laravel authoritative.

   **Follow-up Decision:** EA-06 defines minimization, retention, deletion, redaction and holds.

3. **Conversation state, assignment and SLA authority**

   **Decision:** Cloud owns the authoritative result/timeline; Laravel authorizes every staff action.

   **Rationale:** Keeps one workflow history while enforcing current employee, branch/team and action permissions locally.

   **Follow-up Decision:** EA-02 defines exact states; E-07/E-08 define retry/conflict handling.

4. **Internal Notes**

   **Decision:** Remain Laravel-only.

   **Rationale:** Public/internal separation and sensitive-note permission must be structural.

   **Follow-up Decision:** EA-06 defines retention and sensitive-access policy.

5. **Offline staff replies**

   **Decision:** Authorized replies may be durable Pending Sync, never Sent, until Cloud acceptance.

   **Rationale:** Preserves staff work without false customer-visible or workflow facts.

   **Follow-up Decision:** E-07 and EA-03 define retry, terminal failure and idempotency.

6. **Accepted Message mutability and supervision**

   **Decision:** The accepted original is immutable; governed Supervisor Intercept/Correction/Redaction and pre-release delivery cancellation are approved with original evidence preserved.

   **Rationale:** Enables safety correction without silent rewriting or loss of actor accountability.

   **Follow-up Decision:** E-08, EA-03 and EA-06 define conflicts, duplicate-safe moderation, roles, windows, presentation and retention.

7. **Ordering and read state**

   **Decision:** Per-conversation sequence and per-participant last-read cursor are approved; unread counts are rebuildable projections.

   **Rationale:** Deterministic aggregate ordering and cursor-based repair work with E-02 at-least-once delivery.

   **Follow-up Decision:** E-07/E-10/EA-03 define gap, reference and replay contracts.

8. **Complaint conversion and classification**

   **Decision:** Link without copied public history; Laravel owns structured Complaint classification and provides authorized Customer 360/Call Center visibility.

   **Rationale:** Preserves one public history and one operational Complaint authority.

   **Follow-up Decision:** E-09/E-10/EA-06 define identity, references and safe field visibility.

9. **MVP attachments**

   **Decision:** The text-only proposal is rejected; Controlled Multi-Type Attachments are approved for MVP, while Payment Proof remains under E-06.

   **Rationale:** Integrated operations require evidence, but purpose-specific security and financial workflows must remain distinct.

   **Follow-up Decision:** E-06, E-10 and EA-06 plus security/design work define proof handling, opaque references, allowlists, limits, scanning and retention.

10. **Communication and delivery semantics**

    **Decision:** System Receipt, Message/Cloud Accepted, Assigned, Human Acknowledgement, Staff Reply, Customer Released and Customer Read are distinct evidence-backed facts.

    **Rationale:** Prevents the UI and operations from claiming human or delivery outcomes prematurely.

    **Follow-up Decision:** EA-02 defines final terms/states; E-04 defines notification semantics without changing these facts.

## Traceability

- E-01: independent Cloud boundary, Laravel-mediated Admin, local operational/financial authority.
- E-02: durable pull/push, at-least-once transfer, aggregate ordering, reconciliation, hint-only realtime.
- D1 Customer Portal requirements/business decisions: owned customer messaging, reliable receipt, privacy and outage continuity.
- D2 Admin CRM requirements/business decisions: conversation assignment precedence, supervisor intervention, actor attribution, complaint acknowledgement and Customer 360 permissions.
- Current-system evidence: `CustomerComplaint`, `ComplaintFollowup`, `CustomerNote`, call-center/CRM routes, Admin complaint/Customer 360 components, and website rating storage/action.
- Dependencies left open: E-04 through E-10 and EA-01 through EA-06 as identified above.
