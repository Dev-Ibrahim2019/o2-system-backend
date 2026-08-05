# Risk Register

| Risk ID | Risk | Severity | Evidence | Impact | Proposed mitigation | Status | Owning phase |
| --- | --- | --- | --- | --- | --- | --- | --- |
| RISK-001 | Duplicate Order, Invoice, or Payment during website-order approval | Critical | Cross-system approval crosses trust and transaction boundaries | Duplicate fulfillment and financial loss | E-06 contract, unique constraints, idempotent orchestration, reconciliation | Open | E/F |
| RISK-002 | Website catalog or prices diverge from Laravel | Critical | Multiple catalog clients/sources are present | Incorrect charges and customer disputes | Decide E-09; version and validate synchronized catalogs | Open | E/J |
| RISK-003 | Synchronization events are lost or duplicated without Outbox/Inbox | Critical | No verified durable delivery contract | Inconsistent system state | Decide E-07; durable outbox/inbox, deduplication, retries, monitoring | Open | E/F |
| RISK-004 | Customers duplicate because phone formats differ | High | Identity currently depends on inconsistently normalized contact data | Fragmented history and loyalty | Decide E-02; canonical E.164 normalization and controlled merge | Open | E/G |
| RISK-005 | Order and Production states conflict | High | No approved shared state dictionary | Incorrect SLA and fulfillment actions | Decide E-05; transition rules and reconciliation | Open | E/L/M |
| RISK-006 | FreePBX path is generally experimental | High | Audit found an experimental integration path | Unreliable call-center workflow | Define supported boundary, authentication, monitoring, and fallback | Open | D/H |
| RISK-007 | Customer Portal mutations are public and replay/guessable | High | Audit identified insufficient mutation protection | Unauthorized or repeated changes | Strong authentication, authorization, CSRF/replay controls, rate limits, idempotency | Open | D/I/U |
| RISK-008 | Admin session is stored in localStorage | High | Audit identified browser-persistent session storage | Token theft through XSS | Move to hardened server-managed session and reduce token exposure | Open | D/U |
| RISK-009 | Execution events and SLA timeline are absent | High | No verified end-to-end execution event model | Weak traceability and performance management | Define immutable events, timestamps, correlation, and SLA metrics | Open | D/T |
| RISK-010 | Feedback data lacks established Consent and Retention | High | No approved privacy policy for collected feedback | Privacy and compliance exposure | Decide E-10; capture consent and enforce retention/deletion | Open | E/R |
| RISK-011 | Website start command references missing `server.js` | Medium | Audit found script/file mismatch | Deployment startup failure | Correct only in an approved implementation phase and verify deployment | Open | D/Y |
| RISK-012 | Axios clients and menu/branch sources are duplicated | Medium | Audit found multiple clients and data sources | Inconsistent behavior and configuration drift | Consolidate behind approved contracts after E-09 | Open | E/F/J |
