# ADR E-03 — Conversation and Message Storage

## Status

**[R] Proposed — Ready for Architecture Review**

This proposal selects an authority boundary and conceptual semantics only. It does not approve a schema, table or class names, retention periods, attachment implementation, exact identifiers, exact statuses, queues/jobs, APIs, WebSocket/SSE, complaint implementation, or Phase F work.

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
8. MVP is text-only. Payment Proof is governed by E-06, not by message attachments.
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

Cloud owns the Conversation aggregate, public Messages, per-conversation sequence, customer-visible state, assignment snapshot/history, SLA and acknowledgement evidence, per-participant read cursors, and delivery/synchronization state.

Laravel owns staff identity, authentication and authorization, role/branch scope, operational Customer/Order/Complaint records, internal notes, financial/compensation actions, and local authorization/audit evidence. It stores an authorized operational read projection of Conversations and public Messages.

The Laravel projection is not a parallel authority, accepts no direct edits, follows Cloud sequence/version, exposes freshness and synchronization state, may be stale during outage, and is repaired only through synchronization/reconciliation.

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

A Message concept contains an opaque message reference; Conversation reference; sender type and reference; public content; per-conversation sequence; source-created and Cloud-accepted timestamps; delivery/synchronization state; optional correction/redaction reference; and Cloud version.

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

Conceptually: **System Receipt** proves the request was durably received; **Message Accepted** proves the public Message was committed; **Assigned** records ownership; **Human Acknowledged** records a person accepting attention; **Staff Replied** proves an accepted public reply; **Resolved** and **Closed** are later public lifecycle facts. These are not final enum names. The portal must never claim a human read or reply without evidence.

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

Permit a staff reply to be saved locally as **Pending Sync** during an outage only when Laravel can safely preserve actor, scope, content and a stable command identity. Show it to staff as pending, never Sent; do not advance customer unread state or final waiting-party state before Cloud acknowledgement. Retries must converge on the same logical result. Permanent failure becomes Failed Sync/Needs Review subject to E-07.

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

## Message Mutability

Choose immutability after Cloud acceptance, with governed correction/redaction and audit. This protects chronology, assignment/SLA evidence and customer trust. Incorrect replies and abusive content require visible governed correction or redaction; privacy deletion must use an audited redaction/tombstone consistent with EA-06; legal/business hold can prevent destructive action. Only an unsubmitted local draft is directly editable. Exact correction windows and redaction policy remain open.

## Attachments Scope

MVP conversations are text-only. General attachments, voice, video, chatbot and rich media are deferred. Payment Proof remains under E-06, and complaint images do not automatically become Message attachments. Future attachments require private object storage, malware scanning, size/type limits, signed access, retention and audit. No attachment schema is approved here.

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
| E-06 Payment Verification | Payment Proof is separate private evidence, not a Message attachment. |
| E-07 Offline and Retry | Must define pending-command retry, terminal failure, stale thresholds and reconciliation. |
| E-08 Conflict Resolution | Must respect Cloud public-history authority and Laravel operational-record authority. |
| E-09 Unified Customer Identity | Must define portal-to-Laravel Customer mapping and ambiguity handling. |
| E-10 External References | Must define opaque Conversation/Message and cross-system link references. |
| EA-01 Portal Authentication | Must authenticate portal participants and secure conversation ownership. |
| EA-02 Status Dictionary | Must define exact public/workflow states and allowed transitions. |
| EA-03 Idempotency | Must define command/event keys, scope, retention and replay outcomes. |
| EA-04 Catalog Authority | No ownership change; order-linked summaries remain projections. |
| EA-05 Financial Timing | Public conversation cannot authorize or post financial effects. |
| EA-06 Privacy and Retention | Must define message/note retention, redaction, deletion, consent, holds and exports. |

E-03 does not resolve these decisions.

## Recommended Option

Choose **Option A — Cloud-authoritative Conversation and Public Message Storage**. Replicate an authorized operational read projection to Laravel; keep Internal Notes Laravel-only; authorize all staff actions in Laravel; confirm staff replies and public state changes only after Cloud commit; use per-conversation sequence and per-participant read cursors; link complaint conversion without copying history.

## Consequences

- Customers retain authoritative messaging during branch outages and all clients share one public history.
- Admin remains Laravel-mediated and can operate on an authorized local projection.
- Staff public actions acquire an asynchronous pending/confirmed lifecycle and projection freshness must be visible.
- Cloud becomes a regulated content authority requiring backup, recovery, moderation, audit and privacy controls.
- Search/reporting may use local projections but must declare freshness and cannot correct Cloud history directly.

## Risks

- A Cloud outage prevents new authoritative messages.
- Projection lag can hide a newly accepted customer message from staff.
- Pending replies may confuse staff or be submitted twice.
- Incorrect scope mapping can expose conversations or internal information.
- Cross-system assignment actions can be stale when staff eligibility changes.
- Deferred retention/redaction decisions can block production readiness.

## Mitigations

- Durable Cloud storage, backups, monitoring, honest receipts and bounded client retry.
- Sequence/version gap detection, reconciliation and explicit `last_confirmed_at`/stale indicators.
- Stable command identity, Pending Sync UI and EA-03 idempotency before implementation.
- Laravel authorization, branch/team scope, minimal projection fields and access audit.
- Revalidate staff eligibility for each command and reject/review stale actions without overwrite.
- Complete EA-06 and security review before production data is accepted.

## Rejected Alternatives

- **Laravel Authority** is rejected because customer messages cannot be authoritatively accepted during branch/Laravel outage; Cloud staging would become a second pre-authority with complex promotion semantics.
- **Dual Authority/dual write** is rejected because intermittent connectivity makes atomic commitment impossible and creates irreconcilable ordering, read-state, assignment and SLA histories.
- Reusing Complaint Followups, Customer Notes, ratings, call tickets or notifications as public Messages is rejected because their audience, authority, lifecycle and confidentiality differ.

## Implementation Implications for Phase F

After approval and dependent decisions, Phase F must define versioned contracts for aggregate references, commands/events, sequence gaps, projection freshness, authorization claims and reconciliation; a read-only Laravel projection boundary; outbox/inbox and idempotency behavior; audit/observability; and tests for outage, redelivery, lost acknowledgement and privacy scope. It must not begin until authorized by the program and must not infer final schema from this conceptual ADR.

## Architecture Review Questions

1. **Do we approve a Cloud-authoritative Conversation Store?** Recommendation: Yes; it is the only option that combines branch-outage customer availability with one governed history.
2. **Does Laravel project the complete Public Message history within the applicable retention window?** Recommendation: Yes for authorized operational use, subject to EA-06 minimization and retention rules.
3. **Does Cloud own conversation state, assignment and the SLA timeline?** Recommendation: Yes; Laravel authorizes staff actions and Cloud commits the authoritative conversation result.
4. **Do Internal Notes remain Laravel-only?** Recommendation: Yes for MVP, with separate endpoints and permissions.
5. **May Laravel save a staff reply as Pending Sync during outage?** Recommendation: Yes only with durable actor/scope/content and stable command identity; never show it as Sent.
6. **Are Messages immutable after Cloud acceptance?** Recommendation: Yes, with governed audited correction/redaction only.
7. **Do we approve per-conversation sequence and per-participant read cursor?** Recommendation: Yes; derive unread counters as projections.
8. **Does complaint conversion link without copying history?** Recommendation: Yes; Laravel owns the Complaint and Cloud retains the Conversation.
9. **Does MVP remain text-only?** Recommendation: Yes; defer general attachments and keep Payment Proof under E-06.
10. **What distinction is approved between System Receipt, Human Acknowledgement and Staff Reply?** Recommendation: Receipt proves durable system intake, acknowledgement proves human attention, and reply proves a Cloud-accepted public response.

## Traceability

- E-01: independent Cloud boundary, Laravel-mediated Admin, local operational/financial authority.
- E-02: durable pull/push, at-least-once transfer, aggregate ordering, reconciliation, hint-only realtime.
- D1 Customer Portal requirements/business decisions: owned customer messaging, reliable receipt, privacy and outage continuity.
- D2 Admin CRM requirements/business decisions: conversation assignment precedence, supervisor intervention, actor attribution, complaint acknowledgement and Customer 360 permissions.
- Current-system evidence: `CustomerComplaint`, `ComplaintFollowup`, `CustomerNote`, call-center/CRM routes, Admin complaint/Customer 360 components, and website rating storage/action.
- Dependencies left open: E-04 through E-10 and EA-01 through EA-06 as identified above.
