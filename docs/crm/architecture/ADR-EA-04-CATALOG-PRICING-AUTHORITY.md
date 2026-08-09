# ADR EA-04 — Catalog and Pricing Authority

## Status

**[x] Approved — 2026-08-09**

## Approved Decision

Laravel is the Catalog and Pricing authority for Categories, Items, Modifiers/Options, branch availability and pricing, commercial eligibility, applicable discount/promotion rules, and Laravel-owned delivery pricing inputs. Cloud is a customer-facing read projection, not a second editable master.

## Browsing and Normal Price Changes

**Browsing Catalog ≠ Commercial Order Revision.** If no submitted commercial Order revision captured an earlier price, Laravel price changes synchronize to Cloud and the Website displays the newest price normally. No notification or re-consent is required merely because the displayed Catalog price changed.

A client-side cart is not authority. Checkout/submission refreshes and revalidates commercial values, shows the current total, and obtains confirmation of current values rather than silently submitting stale cached pricing. Exact UX is Phase F.

## Submitted Revision and Acceptance

A submitted Staged Website Order revision is an immutable commercial snapshot containing item reference, quantity, displayed unit price, discounts, fees, delivery fee, commercial total, and relevant catalog/pricing version evidence.

Laravel revalidates item existence, branch eligibility, availability, current authoritative price, discounts, delivery fee, and commercial rules before acceptance. A material difference after submission creates a new revision, displays the changed commercial result, and requires explicit customer re-consent; an already-consented revision is never silently repriced.

Cloud may show last-known Catalog data under a freshness policy, but cached price is never final acceptance authority. Preserving E-05, a Cloud Staged Website Order makes no hard inventory reservation; availability is a projection and Laravel revalidates at authoritative boundaries.

## Architecture Review Decisions

1. **Approved:** Laravel is Catalog/Pricing authority; Cloud is a read projection.
2. **Approved:** browsing does not freeze a price or create commercial commitment.
3. **Approved:** without a submitted revision, a price update displays normally with no notification or re-consent.
4. **Approved:** a client cart must refresh/revalidate current commercial values at checkout/submission.
5. **Approved:** each submitted revision preserves an immutable commercial snapshot.
6. **Approved:** Laravel performs authoritative commercial revalidation before acceptance.
7. **Approved:** a material post-submission difference requires a new revision and explicit re-consent.
8. **Approved:** Cloud freshness/caching does not override Laravel, and no hard Cloud inventory reservation is introduced.

## Phase F Implications

Phase F defines Catalog external references, sync payloads, snapshot schema, versions, cache freshness, cart refresh UX, discount integration, and branch-item structures. No implementation is approved or performed here.
