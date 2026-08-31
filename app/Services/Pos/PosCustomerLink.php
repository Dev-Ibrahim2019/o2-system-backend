<?php

namespace App\Services\Pos;

/**
 * Outcome of one identity-resolution attempt on a cashier order.
 *
 * The service used to return a bare ?int, which made "no customer" and "why
 * there is no customer" indistinguishable to every caller. Carrying the reason
 * alongside the id is what lets the API report customer_link_status without
 * storing anything or re-deriving the decision.
 */
final class PosCustomerLink
{
    public const LINKED = 'linked';
    public const SKIPPED_INVALID_PHONE = 'skipped_invalid_phone';
    public const SKIPPED_AMBIGUOUS = 'skipped_ambiguous';
    public const SKIPPED_NO_DATA = 'skipped_no_data';

    /**
     * Internal failure — the resolver threw and was swallowed to protect the
     * sale. Kept distinct from the three "skipped" reasons on purpose: those
     * describe the input, this one describes a fault on our side, and folding
     * it into skipped_no_data would make the field lie precisely when someone
     * is trying to debug it.
     */
    public const SKIPPED_ERROR = 'skipped_error';

    private function __construct(
        public readonly ?int $customerId,
        public readonly string $status,
        /**
         * Set when an existing customer was matched under a different name.
         *
         * Carried out rather than acted on here: the ticket has to point at
         * the order that raised it, and the order does not exist yet when
         * identity is resolved. The caller raises it once it does.
         *
         * @var array{customer: \App\Models\Customer, name: ?string, phone: string}|null
         */
        public readonly ?array $conflictCandidate = null,
    ) {}

    public static function linked(int $customerId, ?array $conflictCandidate = null): self
    {
        return new self($customerId, self::LINKED, $conflictCandidate);
    }

    public static function skipped(string $status): self
    {
        return new self(null, $status);
    }
}
