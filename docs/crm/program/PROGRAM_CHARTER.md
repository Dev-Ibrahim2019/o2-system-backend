# O2 Customer CRM & Operations Platform — Program Charter

## Purpose

Establish a governed, secure CRM and operations platform that connects customer engagement, ordering, fulfillment, finance, and service across the three O2 systems without creating competing sources of truth.

## Systems

| System | Repository | Responsibility |
| --- | --- | --- |
| Backend | `Dev-Ibrahim2019/o2-system-backend` | Operational records, approved orders, invoices, payments, accounting, production, loyalty |
| Admin Frontend | `Dev-Ibrahim2019/o2-company-front` | Staff CRM, call-center, administration, and operational workspaces |
| External Website | `Hussein-M-AbuShawish/O2-Gaza-Project` | Customer portal, discovery, and pre-approval ordering |

## First-release scope

- A governed customer identity and interaction record.
- Safe handoff of website orders into local operations.
- Order, production, invoice, payment, and delivery visibility.
- Role-based staff workflows, auditability, and operational reporting.
- Reliable integration with idempotency, retries, and observable event handling.

## Deferred

- Decisions E-01 through E-10 until the architecture phase.
- Production-readiness claims until the runtime verification backlog is complete.
- Features outside the approved D-phase requirements baseline.

## Accountability

| Area | Accountable party |
| --- | --- |
| Program scope and acceptance | Product/program owner |
| Architecture and integration contracts | Technical lead |
| Laravel operational data and controls | Backend owner |
| Staff experience and access control | Admin frontend owner |
| Portal identity and pre-approval orders | Website owner |
| Financial controls and reconciliation | Finance owner |
| Privacy, consent, retention, and security | Security/data owner |
| Deployment and runtime verification | Operations owner |

## Sources of truth

| Data | Source of truth |
| --- | --- |
| Operational customer | Local Laravel |
| Approved order | Local Laravel |
| Invoice | Local Laravel |
| Payment | Local Laravel |
| Accounting | Local Laravel |
| Production | Local Laravel |
| Website account | Cloud system, pending E decision |
| Pre-approval order | Cloud system, pending E decision |
| Final order status | Local Laravel |
| Menu and prices | Laravel, pending synchronization contract |
| Loyalty points | Local Laravel |

Items marked “pending E decision” are not final architecture decisions.

## Security principles

- Least privilege, explicit authorization, and separation of duties.
- Server-side validation and authorization for every mutation.
- No secrets or privileged sessions in browser-accessible persistent storage.
- Idempotent external writes, immutable audit evidence, and traceable correlation IDs.
- Data minimization, explicit consent, defined retention, and protected sensitive data.
- Fail safely; reconcile financial and operational records before claiming success.

## Success criteria

- A single approved requirements baseline and decision trail are maintained.
- Each cross-system data flow has an owner, contract, security controls, and recovery path.
- Duplicate orders, invoices, and payments are prevented or deterministically reconciled.
- Status changes are observable end to end and measurable against agreed SLAs.
- Critical risks are mitigated and runtime verification is complete before production readiness.
