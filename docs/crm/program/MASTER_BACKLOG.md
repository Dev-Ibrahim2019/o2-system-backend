# Master Backlog

Status: `[x]` complete, `[R]` drafted and ready for review, `[ ]` not started, `[!]` blocked, `[?]` manual verification required.

| ID | Phase | Task | Priority | System | Status | Dependencies | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- |
| A-01 | A | Confirm program name and purpose | P0 | Program | [x] | — | Program charter |
| A-02 | A | Identify the three systems | P0 | Program | [x] | A-01 | Program charter |
| A-03 | A | Define first-release scope | P0 | Program | [x] | A-01 | Program charter |
| A-04 | A | Record deferred scope | P1 | Program | [x] | A-03 | Program charter |
| A-05 | A | Assign accountability | P0 | Program | [x] | A-02 | Program charter |
| A-06 | A | Establish sources of truth | P0 | Program | [x] | A-02 | Program charter |
| A-07 | A | Establish security principles | P0 | Program | [x] | A-03 | Program charter |
| A-08 | A | Define success criteria | P0 | Program | [x] | A-03 | Program charter |
| B-01 | B | Inspect Git state in all repositories | P0 | All | [x] | — | Completed without discarding changes |
| B-02 | B | Verify repository remotes | P0 | All | [x] | B-01 | Remotes match expected repositories |
| B-03 | B | Preserve pre-existing local changes | P0 | Admin/Website | [x] | B-01 | Kept in working trees |
| B-04 | B | Create program branch in Backend | P0 | Backend | [x] | B-01 | `feature/customer-crm-operations` |
| B-05 | B | Create program branch in Admin | P0 | Admin | [x] | B-01 | `feature/customer-crm-operations` |
| B-06 | B | Create program branch in Website | P0 | Website | [x] | B-01 | `feature/customer-crm-operations` |
| B-07 | B | Push program branches | P0 | All | [x] | B-04..B-06 | Tracking branches pushed to origin |
| B-08 | B | Establish governance documents | P0 | Backend | [x] | A-01..A-08 | This documentation set |
| B-09 | B | Create draft pull requests | P1 | All | [?] | B-07 | Update after GitHub operation |
| B-10 | B | Cross-link pull requests | P1 | All | [?] | B-09 | Update after PR creation |
| B-11 | B | Apply documented commit naming | P1 | Backend | [x] | B-08 | Conventional documentation commits |
| B-12 | B | Verify branch protection | P0 | All | [!] | GitHub access | Verified via GitHub API: default `main` branches are not protected |
| C-01..C-25 | C | Current-state audit work package | P0 | All | [x] | — | See [CURRENT_STATE_AUDIT.md](../CURRENT_STATE_AUDIT.md) |
| D-01..D-15 | D | Customer Portal Requirements | P0 | All | [x] | A-C | Business approved; implementation depends on E decisions. |
| D-16..D-25 | D | Admin CRM Requirements | P0 | Backend/Admin | [x] | D-01..D-15 | Business approved; implementation depends on canonical E and supporting architecture decisions. |
| E-01 | E | Cloud Integration API Location | P0 | All | [x] | D | Approved: independent Cloud Integration Service with outbound-only local Sync Agent; dependent transport, identity, order, payment, retry and policy decisions remain pending. |
| E-02 | E | Synchronization Mode | P0 | All | [x] | E-01 | Approved: Hybrid Durable Synchronization using outbound long polling/durable HTTP pull, Local Outbox HTTP push, adaptive fallback and mandatory reconciliation; WebSocket/SSE is deferred and hint-only. |
| E-03 | E | Conversation and Message Storage | P0 | All | [x] | E-01 | Approved: Cloud-authoritative conversations/public messages with Laravel operational projection; Laravel-only Internal Notes; governed Pending Sync and moderation semantics; link-based Complaint conversion with Laravel problem classification; controlled multi-type MVP attachments with Payment Proof remaining under E-06. |
| E-04 | E | Notification Storage | P0 | All | [x] | E-01 | Approved: Cloud-authoritative customer Notification store with In-App MVP, one logical Notification plus future channel attempts, per-item read state, minimal Laravel operational projection, immutable released history with governed correction/supersession, and separate internal staff alerts; consent/retention remains EA-06. |
| E-05 | E | Website Order Pre-Approval Model | P0 | All | [R] | E-01 | Proposed — Ready for Architecture Review; see ADR E-05. |
| E-06 | E | Payment Verification | P0 | All | [ ] | E-01 | Pending |
| E-07 | E | Offline and Retry Policy | P0 | All | [ ] | E-01 | Pending |
| E-08 | E | Conflict Resolution Policy | P0 | All | [ ] | E-01 | Pending |
| E-09 | E | Unified Customer Identity | P0 | All | [ ] | E-01 | Pending |
| E-10 | E | External Order Identifier and Reference Contract | P0 | All | [ ] | E-01 | Pending |
| F-01..F-25 | F | Foundation and integration contracts | P0 | All | [ ] | E | Not started |
| G-01..G-25 | G | Customer identity and profile | P1 | All | [ ] | F | Not started |
| H-01..H-25 | H | Customer interactions and call center | P1 | Backend/Admin | [ ] | G | Not started |
| I-01..I-25 | I | Portal identity and access | P1 | Website/Backend | [ ] | E,F | Not started |
| J-01..J-25 | J | Catalog and pricing synchronization | P0 | All | [ ] | E,F | Not started |
| K-01..K-25 | K | Pre-approval ordering | P0 | Website/Backend | [ ] | E,F,J | Not started |
| L-01..L-25 | L | Order approval and lifecycle | P0 | Backend/Admin | [ ] | K | Not started |
| M-01..M-25 | M | Production workflow | P1 | Backend/Admin | [ ] | L | Not started |
| N-01..N-25 | N | Delivery workflow | P1 | Backend/Admin | [ ] | L | Not started |
| O-01..O-25 | O | Invoicing and payments | P0 | Backend | [ ] | L,E | Not started |
| P-01..P-25 | P | Accounting and reconciliation | P0 | Backend | [ ] | O | Not started |
| Q-01..Q-25 | Q | Loyalty | P2 | Backend/Admin | [ ] | G,L | Not started |
| R-01..R-25 | R | Feedback, consent, and retention | P1 | All | [ ] | E,G | Not started |
| S-01..S-25 | S | Notifications and conversations | P1 | All | [ ] | E,F | Not started |
| T-01..T-25 | T | Reporting and SLA measurement | P1 | Backend/Admin | [ ] | H,L | Not started |
| U-01..U-25 | U | Security hardening | P0 | All | [ ] | E | Not started |
| V-01..V-25 | V | Reliability and recovery | P0 | All | [ ] | F | Not started |
| W-01..W-25 | W | Data migration and reconciliation | P0 | All | [ ] | E,F | Not started |
| X-01..X-25 | X | End-to-end verification | P0 | All | [ ] | G-W | Not started |
| Y-01..Y-25 | Y | Release readiness | P0 | All | [ ] | X | Not started |
| Z-01..Z-25 | Z | Rollout and continuous improvement | P1 | All | [ ] | Y | Not started |
