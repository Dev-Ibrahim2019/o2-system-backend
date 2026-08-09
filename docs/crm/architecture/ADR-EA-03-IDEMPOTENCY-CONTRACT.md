# ADR EA-03 — Idempotency Contract

## Status

**[x] Approved — 2026-08-09**

## Approved Decision

**Retrying Transport ≠ Re-executing Business Effect.** Every externally retryable sensitive mutation uses stable duplicate-protection identity. This includes Website Order acceptance and creation, Payment verification, Customer creation, Identity Link creation, cancellation, Kitchen Release, refund/reversal, and comparable commands.

## Canonical Duplicate Contract

```text
Same Idempotency Scope + Same Idempotency Key + Same Canonical Request Payload
→ Same Logical Operation
→ Return Previous Durable Result
→ No Second Business Effect

Same Scope + Same Key + Different Canonical Payload
→ Idempotency Conflict
→ Do Not Execute
```

An in-flight duplicate never starts a second execution. The endpoint may report or recover an accepted/in-progress outcome; exact HTTP behavior is Phase F.

## Identifier Separation

```text
Command Ref ≠ Idempotency Key ≠ Correlation Ref ≠ Aggregate Ref
```

E-10 semantics remain distinct even if Phase F chooses technically related representations.

## Unknown Outcomes and Transaction Safety

A timeout, lost acknowledgement, or network failure does not prove failure. Reconcile using stable E-10 references, recover the prior durable result when committed, and never execute blindly. Unresolved cases enter Needs Review; financial ambiguity enters Needs Finance Review, preserving E-07/E-08.

Within one authoritative database, validation, authoritative business effect, and durable idempotency outcome must be transactionally safe as far as technically possible. Cross-system workflows do not claim distributed ACID; they use stable references, unique invariants, durable outcomes, and reconciliation.

## Retention

Idempotency/deduplication evidence uses a governed configurable EA-06 retention policy. No fixed duration is approved. High-risk Order and financial operations retain sufficient evidence for safe replay and reconciliation, and a key cannot be reused while its deduplication contract remains active.

## Architecture Review Decisions

1. **Approved:** transport retry never authorizes duplicate business execution; stable protection is mandatory.
2. **Approved:** same scope/key/payload returns the prior durable result without another effect.
3. **Approved:** same scope/key with a different canonical payload is a conflict and is not executed.
4. **Approved:** in-flight duplicates do not create parallel business execution.
5. **Approved:** unknown outcomes reconcile by stable reference before replay and escalate when unresolved.
6. **Approved:** Command, Idempotency, Correlation, and Aggregate references retain separate semantics.
7. **Approved:** authoritative local effects and their idempotency outcomes are transactionally safe where technically possible; cross-system ACID is not claimed.
8. **Approved:** retention is configurable under EA-06, with no key reuse while the dedupe contract is active.

## Phase F Implications

Phase F defines the `Idempotency-Key` header, scope encoding, canonical payload hashing, record schema, leases, response format, HTTP codes, unique constraints, and retention configuration. No implementation is approved or performed here.
