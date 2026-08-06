# Decision Log

No decision below is resolved by this document. The catalog defines the questions that Phase E must decide; D1 and D2 only constrain acceptable answers.

## Canonical Architecture Decisions

| Decision ID | Canonical Decision | Status | D1/D2 constraints to preserve |
| --- | --- | --- | --- |
| E-01 | [Cloud Integration API Location](../architecture/ADR-E-01-CLOUD-INTEGRATION-LOCATION.md) | [x] Approved — 2026-08-06 | Independent Cloud Integration Service; outbound-only local Sync Agent; Laravel remains final authority and is not publicly exposed; no durable integration ownership in Next.js; Admin is Laravel-mediated by default; independent staging and economical managed services are required; initial Range B is 10 branches/100k accounts/5k orders/day/25k messages/day/2.5k proofs/day; retention periods defer to EA-06; failure ownership splits across Integration Operations, CRM/Call Center, and Finance. |
| E-02 | [Synchronization Mode: Polling vs WebSocket vs Hybrid](../architecture/ADR-E-02-SYNCHRONIZATION-MODE.md) | [R] Proposed — Ready for Architecture Review | Hybrid durable synchronization is proposed: outbound durable HTTP pull, Local Outbox push, optional wake-up hint, adaptive fallback polling, and reconciliation. Final retry, conflict, reference, and idempotency rules remain pending. |
| E-03 | Conversation and Message Storage | Pending | Preserve one governed conversation history, assignment and SLA evidence, internal/public separation, ordering, and deduplication. |
| E-04 | Notification Storage | Pending | Preserve in-app MVP delivery, auditability, preferences, delivery state, and separation of operational and marketing notices. |
| E-05 | Website Order Pre-Approval Model | Pending | A website order remains pre-approval intent until authorized approval; no production or premature Laravel order/financial records. |
| E-06 | Payment Verification | Pending | Payment proof is private evidence, verification is distinct from order approval, and no production starts before confirmation. |
| E-07 | Offline and Retry Policy | Pending | Retries are bounded, observable, safe, and recoverable; unresolved outcomes enter Needs Review without silent partial success. |
| E-08 | Conflict Resolution Policy | Pending | Define authority and reconciliation for stale, concurrent, or contradictory customer, catalog, order, and financial state. |
| E-09 | Unified Customer Identity | Pending | One Laravel operational Customer; verified phone alone does not resolve ambiguity; link, unlink, and merge remain controlled and audited. |
| E-10 | External Order Identifier and Reference Contract | Pending | Preserve unique external/correlation references, idempotent processing, and prevention of duplicate orders, invoices, payments, and tickets. |

E-01 is **[x] Approved** on 2026-08-06. E-02 through E-10 remain **Pending**.

## Supporting Architecture Decision Topics

Supporting topics refine the canonical decisions but do not replace or renumber them.

| Topic ID | Supporting Topic | Related canonical E decision | Related later phase | Status | D1/D2 constraints | Separate ADR later? |
| --- | --- | --- | --- | --- | --- | --- |
| EA-01 | Portal Authentication and Sessions | E-01,E-09 | I,U | Pending | First/new-device verification, secure recovery, limited/restricted states, revocation, and audited identity operations. | Yes — security and lifecycle contract. |
| EA-02 | Canonical Status Dictionary | E-05,E-08 | K,L,M,N,T | Pending | Business actions govern transitions; tracking is consistent; production cannot precede payment confirmation. | Yes — lifecycle mapping and transition contract. |
| EA-03 | Idempotency Contract | E-07,E-10 | F,K,L,O,V | Pending | Duplicate order, invoice, payment, production ticket, notification, and interaction effects must be prevented or reconciled. | Yes — keys, scope, retention, and replay semantics. |
| EA-04 | Catalog and Pricing Authority | E-05,E-08 | J,K | Pending | Prices should not normally diverge; differences require repricing and recorded customer approval before order approval. | Yes — menu, branch, delivery-zone, and price ownership. |
| EA-05 | Financial Posting Timing | E-05,E-06,E-08 | O,P,Q | Pending | Verification and approval are distinct; no orphan financial records; refunds/reversals and loyalty changes remain traceable. | Yes — invoice, payment, accounting, and loyalty posting points. |
| EA-06 | Privacy, Consent and Retention | E-03,E-04,E-09 | R,S,U | Pending | Minimize PII, protect proof/internal notes/family data, separate operational notices from marketing, and retain consent/audit evidence. | Yes — lawful purpose, retention, deletion, and channel policy. |

All EA-01 through EA-06 topics remain **Pending**.

## Traceability Guidance

- D1 traceability is maintained in [CUSTOMER_PORTAL_REQUIREMENTS.md](../requirements/CUSTOMER_PORTAL_REQUIREMENTS.md#11-architecture-decision-traceability).
- D2 traceability is maintained in [ADMIN_CRM_REQUIREMENTS.md](../requirements/ADMIN_CRM_REQUIREMENTS.md#16-architecture-traceability).
- Unified Customer Identity maps to E-09.
- External references map to E-10; idempotency details additionally map to EA-03.
- Payment-proof verification maps to E-06.
- Pre-approval website-order state maps to E-05.
- Synchronization transport maps to E-02.
- Conversation storage maps to E-03; notification storage maps to E-04.
- Offline retries map to E-07; conflict resolution maps to E-08; Cloud API location maps to E-01.
