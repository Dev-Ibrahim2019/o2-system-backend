# Decision Log

No decision below is resolved by this document.

Customer Portal requirement questions are traced to these pending decisions in [CUSTOMER_PORTAL_REQUIREMENTS.md](../requirements/CUSTOMER_PORTAL_REQUIREMENTS.md#11-architecture-decision-traceability). This link records dependencies only and does not resolve any decision.

Admin CRM requirements D-16 through D-25 are traced to the same pending decisions in [ADMIN_CRM_REQUIREMENTS.md](../requirements/ADMIN_CRM_REQUIREMENTS.md#16-architecture-traceability). D2 adds constraints for shared CRM projections, identity review, loyalty ledger operations, conversation delivery, consent-aware campaigns, operational timelines, and idempotent website-order approval; it resolves no E decision.

| Decision ID | Title | Status | Decision | Alternatives | Rationale | D1 approved constraints to preserve | Date |
| --- | --- | --- | --- | --- | --- | --- | --- |
| E-01 | Cloud Integration API location | Pending | TBD | Independent service; inside Next.js | Boundary, ownership, and deployment need agreement | Must support limited accounts, Admin review/inbox, private payment proof, complaints and rating follow-up | — |
| E-02 | Unified customer identity | Pending | TBD | UUID; mapping table; both | Must support deterministic cross-system identity | Must auto-link one verified match, isolate duplicates, support primary/backup verified phones, prevent self/duplicate referral and protect order ownership | — |
| E-03 | Portal account ownership and authentication | Pending | TBD | Cloud-owned; Laravel-owned; federated | Security and lifecycle ownership need agreement | Must support first/new-device OTP, later phone+password login, 30-day multi-device sessions, reverification, suspension split and audited recovery | — |
| E-04 | Pre-approval order storage | Pending | TBD | Cloud; Laravel; staged integration store | Must preserve intent without creating an approved order early | Must allow limited accounts to create new orders and reviewed reorder from the last two completed without copying payment data | — |
| E-05 | Order, production, and delivery status dictionary | Pending | TBD | Shared canonical states; mapped system states | Prevent contradictory lifecycle reporting | Must support direct cancellation before payment and modification request before preparation without portal-side status mutation | — |
| E-06 | Idempotency and external-reference contract | Pending | TBD | Client keys; server keys; composite contract | Prevent duplicate business and financial records | Must deduplicate referral/reward/redemption/messages/complaints/ratings and preserve rating/reversal history | — |
| E-07 | Outbox, inbox, and retry policy | Pending | TBD | Database pattern; broker; managed integration | Guarantee recoverable event delivery | Must support trustworthy tracking, one unified support inbox, configurable assignment/SLA, complaint reopen links and all cross-system projections | — |
| E-08 | Invoice, payment, and financial transaction creation point | Pending | TBD | Approval; fulfillment; settlement | Financial controls and reconciliation need agreement | Must prevent production before payment confirmation, finalize points after delivered+closed, and support ledger reversal/financial review | — |
| E-09 | Menu, price, branch, and delivery-zone source | Pending | TBD | Laravel authoritative; cloud authoritative; governed split | Avoid catalog and price divergence | Must reprice reorder/modifications, recalculate delivery fee each order and allow only branches that serve the address | — |
| E-10 | Privacy, consent, retention, notifications, and conversations | Pending | TBD | Central policy; system policies with shared contract | Legal, security, and operational requirements need agreement | Must support opt-in marketing, in-app-only MVP notices, restricted family/birth data, private files, seven-day complaint reopen and consent-controlled ratings | — |
