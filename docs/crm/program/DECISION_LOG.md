# Decision Log

No decision below is resolved by this document.

| Decision ID | Title | Status | Decision | Alternatives | Rationale | Date |
| --- | --- | --- | --- | --- | --- | --- |
| E-01 | Cloud Integration API location | Pending | TBD | Independent service; inside Next.js | Boundary, ownership, and deployment need agreement | — |
| E-02 | Unified customer identity | Pending | TBD | UUID; mapping table; both | Must support deterministic cross-system identity | — |
| E-03 | Portal account ownership and authentication | Pending | TBD | Cloud-owned; Laravel-owned; federated | Security and lifecycle ownership need agreement | — |
| E-04 | Pre-approval order storage | Pending | TBD | Cloud; Laravel; staged integration store | Must preserve intent without creating an approved order early | — |
| E-05 | Order, production, and delivery status dictionary | Pending | TBD | Shared canonical states; mapped system states | Prevent contradictory lifecycle reporting | — |
| E-06 | Idempotency and external-reference contract | Pending | TBD | Client keys; server keys; composite contract | Prevent duplicate business and financial records | — |
| E-07 | Outbox, inbox, and retry policy | Pending | TBD | Database pattern; broker; managed integration | Guarantee recoverable event delivery | — |
| E-08 | Invoice, payment, and financial transaction creation point | Pending | TBD | Approval; fulfillment; settlement | Financial controls and reconciliation need agreement | — |
| E-09 | Menu, price, branch, and delivery-zone source | Pending | TBD | Laravel authoritative; cloud authoritative; governed split | Avoid catalog and price divergence | — |
| E-10 | Privacy, consent, retention, notifications, and conversations | Pending | TBD | Central policy; system policies with shared contract | Legal, security, and operational requirements need agreement | — |
