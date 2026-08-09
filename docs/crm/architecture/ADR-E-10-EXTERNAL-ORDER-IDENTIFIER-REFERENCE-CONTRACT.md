# ADR E-10 — External Order Identifier and Reference Contract

## Status

**[R] Proposed — Ready for Architecture Review**

Recommended: **Option A — Typed Stable External Reference Contract**.

This proposal defines identifier and reference semantics only. It does not approve formats, generators, columns, schemas, migrations, routes, headers, middleware, APIs, idempotency mechanics, financial numbering, UI, or Phase F implementation.

## Context

A Website Order exists first as a Cloud-authoritative staged intent and later, if accepted, as a Laravel-authoritative operational Order. One stable identity must survive that handoff without confusing aggregate identity with database keys, display numbers, revisions, commands, retries, traces, payment evidence, or authorization.

Core invariants:

```text
Public Order Ref ≠ Laravel Order PK.
Public Order Ref ≠ Order Number.
Order Ref ≠ Revision Ref.
Order Ref ≠ Command Ref.
Command Ref ≠ Correlation Ref.
Correlation Ref ≠ Idempotency Key.
Payment Reference ≠ Payment ID ≠ Order ID.
Resource reference ≠ authorization token.
Stable reference never changes meaning.
Cancelled, deleted, unlinked, or merged references are never reused.
```

## Approved E-01 through E-09 Constraints

- Cloud owns public integration and the pre-acceptance Staged Website Order; Laravel owns accepted operational Orders (E-01/E-05).
- Transfer is durable, at-least-once, retryable, and reconciled; unknown outcomes are queried by stable correlation before replay (E-02/E-07).
- Conversation, Notification, Payment Proof, and identity domains retain their approved authorities (E-03/E-04/E-06/E-09).
- Approval targets one exact immutable staged revision, never `latest` (E-05/E-08).
- Cloud establishes the conditional acceptance fence for the exact eligible revision; one valid authoritative Laravel commit wins and conflicts are explicit (E-08).
- Portal Account, Laravel Customer, and their governed Link remain different identities. E-10 supplies reference principles without reopening E-09.

## Current-System Evidence

### Laravel backend

- `orders.id` is an auto-increment database primary key. `OrderResource` exposes it as `id`, controllers and routes address Orders by numeric route/model identity, and related records use numeric foreign keys.
- `orders.order_number` has a database unique constraint. `Order::generateOrderNumber()` creates `ORD-YYYYMMDD-NNNN` by reading the last matching Order ordered by internal ID. It is a date-scoped, sequential human display number; it is not branch-prefixed. The database constraint is global, but generation remains an implementation pattern whose concurrency and deployed migration state require runtime verification.
- POS and Call Center creation both create Laravel-native Orders and assign the generated `order_number`. No inspected Order UUID, ULID, stable public/external Order reference, staged Order mapping, revision reference, acceptance reference, or correlation field exists.
- APIs/resources expose raw Order, Customer, Invoice, Payment, Production Ticket, Order Item, and related IDs. Existing Admin clients use those IDs for routes and mutations. This is an internal operational contract, not approval for public Cloud/Portal use.
- Invoices have internal IDs and unique human `number` values generated as `INV-YYYYMMDD-NNNN`. Payments have internal IDs, unique generated `number` values, and nullable customer/provider `reference_number` evidence.
- `payment_confirmations` has an internal ID, numeric `order_id`, customer/provider `reference_number`, normalized reference unique per payment method, and a unique `idempotency_key`. Those values have distinct semantics despite current adjacency.
- `idempotency_records` stores unique `(scope, key)`, request hash, status, optional polymorphic resource ID, response, and response status. This is partial local duplicate protection, not the final EA-03 contract.
- Call Tickets have a nullable unique `external_call_id`, showing a domain-specific external-reference precedent. Production Tickets use internal IDs plus department/date-sequenced `ticket_number`; no global external ticket identity was found.
- Customer and related models use incremental internal IDs. Soft deletion exists for Orders and Customers; no governed external alias/mapping for merged Customers was found.

### React Admin / Call Center

- The React application treats numeric `order.id`, `customer.id`, `invoice.id`, and related IDs as API references, route/query parameters, component keys, and mutation targets.
- It displays `order_number` for staff discussion and search while using numeric Order IDs for API operations. Payment `reference_number` is captured separately.
- No stable Website Public Order Ref, staged Revision Ref, Handoff Ref, or general typed cross-system reference model was found.

### Next.js website

- Current checkout builds customer/order text and opens WhatsApp; it does not durably create, identify, revise, accept, or track a Website Order.
- Reservation/rating/local page parameters and cart item keys are not a durable Website Order identity contract.
- E-10 therefore defines target architecture; it does not validate current client-local or WhatsApp values as Order references.

## Decision Drivers

- Preserve one customer tracking identity before and after Laravel acceptance.
- Prevent duplicate Orders under retry, lost acknowledgement, and reconciliation.
- Bind approval to exact customer-consented content.
- Keep public contracts independent of database topology, branch sequences, and migrations.
- Prevent enumeration and PII leakage while retaining explicit authorization.
- Separate business display, workflow tracing, commands, evidence, and financial references.
- Make reference misuse detectable through semantic typing and durable mappings.

## Terminology

- **Internal Database Primary Key:** persistence identity inside one database implementation.
- **Public Order Ref:** immutable opaque identity of one externally addressable logical Order.
- **Order Number:** human-readable operational/display reference.
- **Revision Ref:** exact immutable customer-consented staged Order revision.
- **Command Ref:** identity of one logical requested action.
- **Idempotency Key:** duplicate/replay protection input governed by EA-03.
- **Correlation Ref:** workflow/observability grouping across commands, events, and systems.
- **Causation Ref:** reference to the command/event that directly caused another fact.
- **Handoff Ref:** identity of the Cloud-to-Laravel acceptance transition/result.
- **Payment Proof Ref:** exact immutable evidence object or evidence revision.
- **Payment Reference:** customer/provider financial reference, not an O2 aggregate identity.
- **External Ref:** stable typed cross-system identity mapped to an internal record where needed.

## Identifier Anti-Patterns

- One `x-request-id` used as Order, command, correlation, idempotency, handoff, and Payment identity.
- Public Laravel auto-increment IDs or predictable database URLs.
- Phone, email, amount, name, branch, or timestamp used as canonical identity.
- Human Order Number used as cross-system join, replay key, or Payment identity.
- File path, object-storage key, or signed URL used as Proof identity.
- Recomputing an acceptance result from “latest Order,” similar phone, amount, or creation time.

## Options Considered

### Option A — Typed Stable External References

Opaque immutable Public Order Ref; separate Revision, Command, Correlation, Handoff, Proof, and domain refs; internal IDs stay internal; Order Number remains human-facing.

### Option B — Laravel Numeric IDs Everywhere

Use temporary Cloud identity before acceptance and switch to Laravel primary keys afterward.

### Option C — Human Order Number as Universal Identity

Use `order_number` for URLs, synchronization, idempotency, Payment correlation, and support.

## Detailed Comparison

| Criterion | Typed External References | Laravel IDs Everywhere | Order Number as Identity |
| --- | --- | --- | --- |
| Pre-acceptance tracking | Strong | High Risk | Weak |
| Authority handoff | Strong | Weak | Weak |
| Public enumeration safety | Strong | High Risk | Weak |
| Retry/reconciliation | Strong | High Risk | Weak |
| Revision safety | Strong | High Risk | High Risk |
| Branch independence | Strong | Weak | Weak |
| Database migration safety | Strong | High Risk | Weak |
| Human usability | Acceptable | Weak | Strong |
| API clarity | Strong | Weak | Weak |
| Idempotency compatibility | Strong | Weak | High Risk |
| Payment reference separation | Strong | Weak | High Risk |
| Support/debugging | Strong | Acceptable | Strong |
| Long-term scalability | Strong | Weak | Weak |
| Implementation complexity | Acceptable | Strong | Strong |

## Recommended Reference Model

```text
Logical Website Order → immutable Public Order Ref
Each material customer edit → immutable Revision Ref
Each logical action → Command Ref
Distributed workflow → Correlation Ref
Cloud→Laravel authority transfer → Handoff Ref
Payment evidence/version → Payment Proof Ref
Laravel rows → internal database IDs
Human operation → Order Number
```

Different semantic concepts require different typed identifiers. Phase F may choose related representations, but no consumer may treat their meanings as interchangeable.

## Public Order Identity

One logical Website Order receives one immutable Public Order Ref at first durable Cloud staged-order creation. The customer tracks that same reference through Received, review, acceptance, payment, production, delivery, cancellation, and history. Acceptance does not mint a replacement public identity.

## Public Order Generation Authority

Cloud generates the Website Public Order Ref because the logical aggregate exists there before Laravel has an Order. Laravel accepts, stores, and maps that exact reference during handoff. Multiple systems may not independently mint competing refs for the same Website Order.

The ref must be opaque, immutable, globally collision-resistant within the authoritative environment, non-sequential to customers, URL-safe when encoded, stable under retry, independent of branch/database deployment, and free of PII. Exact UUID/ULID/other format remains Phase F.

## Laravel Internal Order Identity

`orders.id` remains internal persistence identity. Cloud and public Portal contracts must not depend on it or expose it as the canonical Order identity. After acceptance a durable mapping relates Public Order Ref to one Laravel Order PK.

## Human Order Number

`order_number` remains a human/business display reference for receipts, staff, kitchen, customer support, and search. Current generation is date-sequenced and globally unique by intended schema, but that does not make it the stable public/cross-system identity. A staged Order may have its Public Order Ref before any Laravel Order Number exists.

Support may look up by Public Order Ref, Order Number, or authorized customer evidence, but only the typed canonical ref is the cross-system join key.

## Staged Order Identity

A Cloud staged record may have an internal Cloud PK separate from its Public Order Ref. Even if Phase F stores one representation in both roles, their semantics remain distinct.

## Revision Identity

Every material staged edit creates an immutable Revision Ref and, where useful, a monotonic revision sequence. Acceptance carries both:

```text
Approve OrderRef O1 / RevisionRef R3
```

It never means `approve latest`. Client timestamps are not revision identity.

## Revision Immutability

R3 never changes meaning after R4 exists. Customer consent, staff review, conflict evidence, audit, and reconciliation retain the exact prior snapshot/ref. The accepted Cloud revision binds the initial Laravel state; later Laravel aggregate versions are independent operational history and do not rewrite it.

## Acceptance / Handoff Reference

A stable Handoff Ref identifies the conditional authority-transfer transition/result for one Public Order Ref and exact Revision Ref. It is distinct from the aggregate, revision, command, correlation, and idempotency identities, even if Phase F derives it safely from a defined tuple.

Conceptual durable mapping:

```text
Public Order Ref
+ Exact Accepted Revision Ref
+ Handoff Ref
↔ Laravel Order PK
+ Laravel Order Number
+ authoritative acceptance outcome
```

## Command Identity

Command Ref identifies one logical requested action, such as Accept O1/R3, Cancel O1, Verify Proof P2, or Release Kitchen. Different actions against one Order have different Command Refs. Transport retries reuse the logical command identity and do not create a new aggregate or acceptance decision.

## Idempotency Relationship

Command Ref and Idempotency Key are semantically separate by default. E-10 supplies stable target/action references; EA-03 owns key scope, payload hash, reuse conflict, retention, replay response, and header/API mechanics. Idempotency must not depend on mutable display numbers.

## Correlation Identity

Correlation Ref groups a distributed workflow or trace across Portal submission, Cloud processing, Agent delivery, Laravel commit, and projection. One correlation may contain several commands; one Order may have many correlations. Correlation is neither uniqueness authority nor an access token.

## Causation

Where commands/events form a chain, a Causation Ref should identify the direct cause—for example, Order Accepted caused by Accept Order Command. This improves audit/tracing without requiring event sourcing and remains separate from correlation and aggregate identity.

## Payment Proof Reference

Each exact uploaded proof/evidence revision has an immutable logical Proof Ref. Verification binds Order/Public Order context plus exact Proof Ref/version. Replacing rejected Proof V1 creates Proof V2; it never changes V1’s meaning. Object path and temporary signed URL are not Proof identity.

## Payment Reference

Customer, bank, wallet, card, or provider references are external financial evidence. They are not Order Ref, Public Order Ref, Payment internal ID, Command Ref, or Proof Ref. Method-specific uniqueness and financial effect remain E-06/EA-05 concerns.

## Invoice / Payment References

Invoice PK, Invoice Number, Payment PK, generated Payment Number, provider/customer Payment Reference, and any future safe external financial refs are distinct. E-10 does not change numbering or posting timing; EA-05 owns final financial semantics.

## Portal / Customer / Link References

All identity references crossing Cloud↔Laravel—including Portal Account, Laravel Customer projection, Identity Link, Identity Review, merge/unlink operation, and Organization Membership—must use stable opaque typed external refs. Phone/email are evidence or attributes, never Portal/Customer cross-system refs. Laravel incremental Customer IDs remain internal. E-09 authority and cardinality remain unchanged.

## Reference Namespaces

Contracts must unambiguously distinguish types such as `OrderRef`, `OrderRevisionRef`, `CommandRef`, `HandoffRef`, `PaymentProofRef`, `PortalAccountRef`, `CustomerRef`, and `IdentityLinkRef`. Human/debug prefixes may help misuse detection and support, but E-10 does not approve exact prefix syntax. API/schema type semantics are mandatory even without encoded prefixes.

## Identifier Generation Authorities

| Reference | Generator / authority |
| --- | --- |
| Website Public Order Ref | Cloud at durable staged creation |
| Portal Account Ref | Cloud Portal authority |
| Conversation/Message/Notification/Proof refs | Approved Cloud domain authority |
| Laravel Customer External Ref | Laravel Customer authority when cross-system use is needed |
| Laravel-native Order Public Ref | Laravel under the common Order reference contract |
| Command/Handoff Ref | Originating authoritative producer under the versioned contract |
| Internal PK/display number | Owning application/database domain |

## Local-Native Orders

POS and Call Center Orders originate in Laravel. Laravel should assign every native Order an opaque Public Order Ref at creation under the same global external Order namespace. This is operationally simpler than late backfill, guarantees a ref before first external exposure, and avoids source-dependent API behavior. Cloud must not recreate their identity. Exact rollout/backfill remains Phase F.

## Global Order Namespace

Website, POS, and Call Center Orders should share one collision-resistant public Order namespace so externally addressable Orders cannot collide across sources or branches. Human Order Numbers may retain controlled operational formatting/scoping.

## Environment Isolation

Production, staging, and development require isolated databases, credentials, routing, and mapping authority. References are authoritative only inside their environment unless an explicit cross-environment migration contract exists. Do not encode sensitive environment data merely for convenience, and never let test refs resolve in production.

## Immutability / Non-Reuse

Once issued, a stable ref never changes meaning or returns to an allocation pool. Cancellation, soft deletion, unlink, archival, restoration, and merge do not permit reuse.

## Soft Delete / Merge

A soft-deleted Order/Customer ref continues to identify its historical entity and cannot identify a replacement row. For E-09 Customer merge, a source Customer Ref may remain a governed historical alias resolving to canonical lineage; exact alias/redirect persistence remains Phase F.

## Authorization Boundary

```text
Knowing Public Order Ref ≠ authorization to access Order.
```

Every public/authenticated route must authorize the Portal Account and Customer/Order relationship independently. Opacity reduces enumeration risk but does not replace authorization. Resource refs are never bearer credentials; signed/scoped tokens are separate temporary authorization artifacts.

## URL Stability

`/account/orders/[publicOrderId]` retains the same identity from Staged through Accepted, Paid, Preparing, Delivered, or Cancelled. It never redirects merely to substitute a Laravel numeric PK after handoff.

## Cross-System Mapping

| Fact | Meaning | Authority |
| --- | --- | --- |
| Public Order Ref ↔ Cloud staged PK | Which staged aggregate owns the ref | Cloud before handoff |
| Public Order Ref + Revision Ref | Which exact customer-consented snapshot | Cloud |
| Public Order Ref + Revision Ref + Handoff Ref | Which acceptance transition/result | Cloud eligibility + Laravel commit boundary |
| Public Order Ref ↔ Laravel Order PK | Which operational Order resulted | Laravel at accepted commit |
| Laravel Order PK ↔ Order Number | Internal row and display number | Laravel |

## Mapping Uniqueness

- One Public Order Ref identifies at most one logical Order.
- One accepted Website Public Order Ref maps to at most one Laravel Order.
- One Laravel Order has at most one canonical Public Order Ref unless a governed migration alias is explicitly recorded.
- Public Order Ref plus exact accepted Revision Ref returns the same durable acceptance result under reconciliation.
- Mapping creation is transactional/conditional at the authoritative boundary; exact constraints remain Phase F.

## Revision-to-Laravel Mapping

The acceptance record permanently identifies the Cloud revision used to create initial Laravel state. Later Cloud revisions cannot retroactively become accepted, and post-acceptance Laravel edits do not rewrite the original consent/handoff evidence.

## Cloud Revision vs Laravel Version

Cloud Staged Revision and Laravel Order aggregate/version are separate counters/references. Handoff binds one Cloud revision to initial Laravel state; Laravel then evolves under its own authority. No shared mutable cross-system version counter is approved.

## Retry Interaction

E-07 retry reuses stable aggregate, revision, command, and handoff references. Delivery attempts may have separate attempt metadata, but a network retry never mints a new Public Order Ref or logical acceptance identity.

If the fence exists, Laravel commits, and the response is lost, reconciliation queries the stable Public Order/Revision/Handoff mapping and returns the prior authoritative result. It never guesses from latest Order, phone, amount, or timestamp. Indeterminate outcomes remain blocked/reviewable rather than producing a second Order.

## Conflict Interaction

E-08 commands name target aggregate plus exact revision/version/state. Stable refs enable `Approve O1/R3`; E-08 still owns authority, preconditions, winners, loser outcomes, and review. E-10 does not decide conflicts.

## Privacy / Logging

Identifiers must not embed phone, email, customer name, address, amount, branch business data, or mutable facts. Log the minimum typed refs needed for audit/diagnosis and keep secrets, signed URLs, and proof contents out of identifiers/logs. EA-06 owns retention/privacy.

## Object Storage References

Attachment/Proof logical ref is stable; private object-storage key is an implementation detail; signed URL is short-lived authorization. None may silently replace another.

## Conversation / Notification References

The same stable opaque typed-reference principles apply to cross-system Conversation, Message, Notification, and Attachment refs without changing E-03/E-04 storage or behavior.

## Reference Lifecycle Matrix

| Reference | Created By | Visibility | Mutable? | Reusable? | Purpose |
| --- | --- | --- | ---: | ---: | --- |
| Laravel Order PK | Laravel DB | Internal-only | No | No | Persistence identity |
| Public Order Ref | Order origin authority | Authenticated-public / cross-system | No | No | Logical Order identity |
| Order Number | Laravel | Controlled display | No after issue | No | Human business discussion |
| Staged Revision Ref | Cloud | Internal cross-system / authorized | No | No | Exact consented snapshot |
| Command Ref | Command origin | Internal cross-system | No | No | Logical action identity |
| Idempotency Key | Request origin under EA-03 | Security-sensitive internal | No within scope | EA-03 | Replay protection input |
| Correlation Ref | Workflow origin | Internal cross-system | No | No | Trace/workflow grouping |
| Acceptance/Handoff Ref | Handoff origin | Internal cross-system | No | No | Authority-transfer result |
| Payment Proof Ref | Cloud proof authority | Restricted | No | No | Exact evidence/version |
| Customer-supplied Payment Ref | Customer/provider | Restricted evidence | Source-defined | Method-defined | External financial evidence |
| Portal Account Ref | Cloud | Internal cross-system | No | No | Portal identity reference |
| Customer External Ref | Laravel | Internal cross-system | No | No | Canonical Customer projection ref |
| Identity Link Ref | Laravel/CRM | Restricted cross-system | No | No | Governed authorization decision ref |

## Semantics Matrix

| Identifier | Answers Which Question? |
| --- | --- |
| Public Order Ref | Which logical Order is this? |
| Revision Ref | Which customer-consented staged revision? |
| Command Ref | Which logical action was requested? |
| Idempotency Key | Is this a replay/duplicate under EA-03? |
| Correlation Ref | Which distributed workflow/trace contains this? |
| Handoff Ref | Which authority-transfer attempt/result is this? |
| Payment Proof Ref | Which exact evidence object/version? |
| Payment Reference | Which external customer/provider payment reference? |
| Order Number | What human-readable business number should people discuss? |

## Security

- Enforce authorization independent of every reference.
- Use opaque non-sequential public refs and type-safe validation.
- Reject cross-type substitution and environment confusion.
- Do not reveal internal PKs, storage keys, sensitive payloads, or PII in public refs.
- Rate-limit and audit sensitive lookups; minimize customer-safe projections.
- Treat Idempotency Keys, signed URLs, and access tokens according to their own security contracts, never as resource identity.

## Impact on Other Decisions

| Decision | E-10 Impact |
| --- | --- |
| EA-01 Portal Authentication | Authenticates and authorizes access to public refs; identity never grants itself access. |
| EA-02 Status Dictionary | Status vocabulary remains separate from identifier ownership. |
| EA-03 Idempotency | Defines key scope, hashing, retention, replay, reuse conflict, headers, and responses using E-10 refs. |
| EA-04 Catalog/Pricing | Catalog/item refs may later reuse the general typed-reference principles. |
| EA-05 Financial Posting | Owns Invoice/Payment numbering, financial references, uniqueness, and posting semantics. |
| EA-06 Privacy/Retention | Owns identifier, mapping, log, audit, alias, and evidence retention/privacy. |

## Recommended Option

Recommend **Option A — Typed Stable External Reference Contract**: immutable Public Order Ref created by Cloud for Website Orders, exact Revision Refs, separate Command/Correlation/Handoff/Proof refs, internal database IDs kept internal, and human Order Numbers kept separate.

## Consequences

- Customer tracking and URLs remain stable through authority handoff.
- Retry/reconciliation can recover one authoritative result without heuristic matching.
- Typed contracts reduce accidental cross-domain identifier reuse.
- All Laravel-native Orders should receive an external ref, requiring later rollout/backfill design.
- Durable mappings, uniqueness, authorization, audit, and API compatibility add Phase F work.
- Existing Admin numeric-ID APIs may remain internal while public/cross-system APIs adopt the new contract.

## Risks

| Risk | Conceptual mitigation |
| --- | --- |
| Numeric Laravel ID exposed/enumerated | Keep PK internal; opaque public ref plus authorization. |
| Cloud/Laravel collision or changed ID at acceptance | Cloud mints once; Laravel conditionally maps same ref. |
| Two Laravel Orders for one Website Order | Unique canonical mapping plus stable handoff reconciliation. |
| One Laravel Order mapped to multiple public refs | One canonical ref; governed migration aliases only. |
| Wrong/latest revision approved | Exact immutable Order Ref + Revision Ref precondition. |
| Retry mints new identity | Reuse logical aggregate/command/handoff refs. |
| Command/correlation/idempotency confusion | Typed semantic fields and EA-03 separation. |
| Payment ref confused with Order/Payment ID | Separate namespaces, fields, validation, and Finance Review. |
| Proof overwritten | Immutable Proof Ref per evidence revision. |
| Human Order Number collision/reuse | Keep display-only; preserve intended uniqueness and never use as canonical join. |
| Branch sequence/restore collision | Collision-resistant branch-independent public namespace. |
| PII or environment leaked in ID | Opaque values; environment isolation outside sensitive ID content. |
| Signed URL treated as identity | Stable logical ref separate from temporary authorization. |
| Soft-deleted ref reused | Permanent non-reuse invariant. |
| Customer merge loses lineage | Preserve old CustomerRef as governed alias/history under E-09. |
| Staging ref reaches production | Isolated credentials/databases/routing and environment validation. |

## Mitigations

Use stable typed opaque refs, single generation authority, durable conditional mappings, exact immutable revisions, non-reuse, independent authorization, environment isolation, minimal logging, E-07 reconciliation, E-08 conflict preconditions, and EA-03 idempotency semantics.

## Rejected Alternatives

- **Laravel primary key as public identity:** Cloud Order exists first; numeric PKs couple APIs to persistence and invite enumeration.
- **Human Order Number as universal identity:** formatting, date/sequence/scoping, support use, and legacy behavior differ from canonical technical identity.
- **New public ID after acceptance:** breaks tracking/URL continuity and complicates lost-response recovery.
- **One identifier for everything:** aggregate, revision, action, replay, tracing, handoff, and Payment answer different questions.
- **Phone/Payment reference correlation shortcut:** mutable/nonunique evidence cannot prove Order identity or authoritative outcome.

## Implementation Implications for Phase F

Phase F must choose formats/generators, typed schemas and API representations, mapping constraints, rollout/backfill for Laravel-native Orders, environment validation, authorization, logging, aliases, handoff transactions, reconciliation queries, and compatibility behavior. EA-03 must define exact idempotency. No implementation choice is approved by this proposal.

## Architecture Review Questions

### Question 1

Do we approve a typed stable external-reference model where public/cross-system identifiers are separate from internal database primary keys and human display numbers?

**Recommendation:** Yes.

### Question 2

Should every Website Order receive one immutable Public Order ID when the Cloud Staged Order is first durably created, and should that same ID survive Laravel acceptance and the entire Order lifecycle?

**Recommendation:** Yes.

### Question 3

Should Laravel internal `orders.id` remain internal and never become the customer-facing/cross-system Order identity?

**Recommendation:** Yes.

### Question 4

Should human-readable `order_number` remain a separate business/display reference rather than the canonical public/cross-system identifier?

**Recommendation:** Yes.

### Question 5

Should every material Staged Website Order revision have its own immutable revision identity/version so approval explicitly targets Public Order Ref plus exact Revision Ref rather than `latest`?

**Recommendation:** Yes.

### Question 6

Should Command ID, Correlation ID, Handoff/Acceptance Reference, and Idempotency Key remain semantically separate even if Phase F later chooses related underlying representations?

**Recommendation:** Yes. EA-03 still owns exact idempotency behavior.

### Question 7

Should Payment Proof references and customer/provider Payment references remain separate from Order, Payment-record, and command identity, with no identifier reuse between those domains?

**Recommendation:** Yes.

### Question 8

Should all stable references crossing Cloud↔Laravel boundaries—including Customer/Portal/Identity-Link references from E-09—be opaque, immutable, and non-reusable, while incremental database IDs stay implementation-internal?

**Recommendation:** Yes.

### Question 9

Should knowing any Public/External resource reference never itself grant authorization, with authenticated/scoped authorization enforced separately?

**Recommendation:** Yes.

### Question 10

Should exact identifier encoding/generator, type-prefix syntax, DB column types, API header names, idempotency mechanics, and final routing implementation remain deferred to Phase F/EA-03 while E-10 approves semantic reference contracts?

**Recommendation:** Yes.

These are recommendations for Architecture Review. None is approved by this proposal.

## Traceability

- D1: pre-acceptance customer tracking, immutable history URL, authorization, and exact customer-consented revision.
- D2: one external ref processes once; review, sync, retry, Payment, support lookup, audit, and reconciliation remain traceable.
- E-01 through E-09 remain approved and unchanged.
- EA-01 through EA-06 remain pending. Phase F remains not started.
