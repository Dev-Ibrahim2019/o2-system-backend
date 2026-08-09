# Phase F Executable Backlog

Status: `[ ]` ready, `[>]` current, `[x]` complete, `[!]` blocked. Priorities: P0 blocker, P1 first end-to-end workflow, P2 MVP completion, P3 post-MVP.

| ID | Priority | Milestone | Repository | Executable task | Dependencies | Status |
| --- | --- | --- | --- | --- | --- | --- |
| F-01A | P0 | M1 | Backend | Add stable Laravel Order/Customer integration refs and transaction-safe Outbox foundation with tests. | Architecture Gate | [x] |
| F-01B | P0 | M1 | New Cloud repo | Create `o2-cloud-integration` service/database/test/deploy foundation and versioned contract envelope. | F-01A contract | [>] |
| F-01C | P0 | M1 | Backend `sync-agent/` | Create outbound Agent, durable cursor/checkpoint store, machine identity, health and narrow Laravel integration boundary. | F-01A,F-01B | [ ] |
| F-01D | P0 | M1 | Backend/Cloud/Agent | Prove durable command→Laravel receipt→Outbox result→Cloud ACK and idempotent replay. | F-01A..C | [ ] |
| F-01E | P0 | M1 | All service repos | Establish canonical status/reference/contract packages, structured logs and baseline metrics. | F-01D | [ ] |
| F-02A | P1 | M2 | Cloud/Website | Portal Account, password, phone OTP, server-managed HttpOnly session, CSRF and basic abuse controls. | F-01 | [ ] |
| F-02B | P1 | M2 | Cloud/Backend/Agent | Customer Link evaluation, Limited Account and Identity Review synchronization. | F-02A | [ ] |
| F-02C | P1 | M2 | Cloud/Website/Admin | Device/session registry, revoke/logout-all, suspension, recovery and review UI. | F-02A,B | [ ] |
| F-03A | P1 | M3 | Backend | Add Catalog refs/versions and publish item/category/branch price/availability changes through Outbox. | F-01 | [ ] |
| F-03B | P1 | M3 | Cloud/Agent | Apply versioned Catalog projection with freshness and reconciliation. | F-03A | [ ] |
| F-03C | P1 | M3 | Website | Replace hard-coded menu incrementally with Cloud Catalog reads and stale-aware cache. | F-03B | [ ] |
| F-04A | P1 | M4 | Cloud/Website | Staged Order, immutable revision/commercial snapshot, submit/edit/cancel and Public Order Ref. | F-02,F-03 | [ ] |
| F-04B | P1 | M4 | Backend/Agent/Admin | Exact-revision review, acceptance fence, Handoff mapping and one transactional Laravel Order. | F-04A | [ ] |
| F-04C | P1 | M4 | All | Recover lost ACK, publish accepted tracking and verify one staged Order produces at most one Laravel Order. | F-04B | [ ] |
| F-05A | P1 | M5 | Cloud/Website/Admin | Private proof upload/revisions/access and review projection. | F-04 | [ ] |
| F-05B | P1 | M5 | Backend/Agent | Idempotent verification, Payment allocation, partial state, Invoice timing and unknown-outcome reconciliation. | F-05A | [ ] |
| F-05C | P2 | M5 | Backend/Admin | Authorized Account/Credit AR settlement, clearance evaluation and Finance Review. | F-05B | [ ] |
| F-05D | P1 | M5 | Backend/Admin | Explicit Kitchen Release after clearance; governed cancellation/refund/void/reversal and overpayment review. | F-05B,C | [ ] |
| F-06A | P1 | M4/M6 | Website | Build account auth shell, synchronized menu checkout, staged Order history/detail/tracking. | F-02–F-04 | [ ] |
| F-06B | P2 | M6 | Website/Cloud | Profile, addresses, rewards, messages, Notifications, support, ratings and settings. | F-06A,F-05 | [ ] |
| F-07A | P1 | M4 | Admin/Backend | Add Website Order review to existing Call Center workspace with exact revision and portal context. | F-04 | [ ] |
| F-07B | P1 | M5 | Admin/Backend | Add Payment/Finance and Identity Review queues with permissions/audit. | F-02,F-05 | [ ] |
| F-07C | P2 | M6 | Admin/Backend | Extend Customer360 with link, messages, complaints, loyalty, tracking support and assignment. | F-06 | [ ] |
| F-08A | P0 | M1 | Cloud/Agent/Backend | Implement retry classes, replay contract, durable ACK/cursors and Agent recovery. | F-01 | [ ] |
| F-08B | P1 | M4/M5 | All | Implement unknown outcome, cursor-gap and authority-aware reconciliation. | F-04,F-05 | [ ] |
| F-08C | P2 | M7 | All | Quarantine/poison handling, Needs Review/Finance Review controls and failure injection. | F-08B | [ ] |
| F-09A | P0 | M1/M2 | All | Machine credentials, secrets, structured logging, health and least-privilege baseline. | F-01 | [ ] |
| F-09B | P1 | M2–M5 | All | Permissions, sensitive access audit, OTP limits, CSRF/CORS and private-proof enforcement. | F-02–F-05 | [ ] |
| F-09C | P2 | M7 | All | Metrics, sync-lag/backlog alerts, dashboards, incident runbooks and access review. | F-08 | [ ] |
| F-10A | P2 | M7 | Backend/Admin | Inventory legacy status data/consumers; add compatibility mapping and safe migration/backfill commands. | Functional slices | [ ] |
| F-10B | P2 | M7 | All | Backfill refs, validate mappings/counts, rehearse rollback and old-client compatibility in staging. | F-10A | [ ] |
| F-10C | P2 | M7 | All | Pilot one branch/workflow, observe/reconcile, approve cutover, roll out and later remove compatibility. | F-10B,F-09 | [ ] |
| F-P3 | P3 | Post-MVP | All | Optional channels, advanced promotions, wallet/automatic overpayment handling, and decorative enhancements only after M7. | MVP rollout | [ ] |

## Ready Definition

F-01A may begin when its migration baseline is verified, names are checked against deployed schema, tests can run, and rollback remains additive. It is complete only when focused tests and relevant existing regression tests pass and no public Laravel exposure or legacy contract break is introduced.
