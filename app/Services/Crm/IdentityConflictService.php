<?php

namespace App\Services\Crm;

use App\Models\Customer;
use App\Models\CustomerIdentityConflict;
use App\Models\CustomerPhone;
use App\Models\Order;
use App\Models\User;
use App\Services\CustomerIdentityService;
use App\Services\Support\PhoneNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Opens and resolves identity-conflict tickets.
 *
 * A conflict is: an incoming order carried a phone that already belongs to a
 * customer, but under a different name. The existing behaviour — keep the
 * stored name, ignore the incoming one — is preserved exactly; this service
 * only adds the paper trail and the operator's decision.
 */
class IdentityConflictService
{
    public function __construct(
        private readonly PhoneNormalizer $phones,
        private readonly CustomerIdentityService $identity,
    ) {}

    /**
     * Schedule a conflict check for after the current transaction commits.
     *
     * THE entry point every channel uses. recordIfConflicting() must never run
     * inside the transaction that creates the order, and every caller used to
     * have to remember that and wire DB::afterCommit() itself — the Call
     * Center did, the cashier path did not, which is precisely why cashier
     * orders opened no tickets. Centralising the scheduling here means a new
     * channel gets the guarantee by calling one method, not by re-deriving it.
     *
     * Outside a transaction DB::afterCommit() runs the callback immediately —
     * correct for the cashier path, where identity resolution happens before
     * the order transaction opens.
     */
    public function queueIfConflicting(
        Customer $customer,
        ?string $incomingName,
        string $incomingPhone,
        string $channel,
        ?int $orderId = null,
    ): void {
        DB::afterCommit(fn () => $this->recordIfConflicting(
            customer: $customer,
            incomingName: $incomingName,
            incomingPhone: $incomingPhone,
            channel: $channel,
            orderId: $orderId,
        ));
    }

    /**
     * Record a conflict, if the incoming name really does differ.
     *
     * NEVER call this inside the order transaction. It is written to be
     * called from DB::afterCommit() so that a failure here can never roll
     * back, slow down, or alter the response of an order or a live call —
     * every failure path below logs and returns null.
     */
    public function recordIfConflicting(
        Customer $customer,
        ?string $incomingName,
        string $incomingPhone,
        string $channel,
        ?int $orderId = null,
    ): ?CustomerIdentityConflict {
        try {
            if (! $this->namesDiffer($customer->name, $incomingName)) {
                return null;
            }

            $normalized = $this->phones->normalize($incomingPhone);

            // A number an operator has already declared shared is expected to
            // carry several names — re-raising a ticket per order would bury
            // the queue in noise.
            if ($this->isKnownSharedNumber($normalized)) {
                return null;
            }

            // One open ticket per (customer, incoming name) — a regular who
            // orders weekly under a nickname is one decision, not fifty.
            $existing = CustomerIdentityConflict::query()
                ->open()
                ->where('customer_id', $customer->id)
                ->where('incoming_name', trim($incomingName))
                ->first();

            if ($existing) {
                return $existing;
            }

            return CustomerIdentityConflict::create([
                'customer_id' => $customer->id,
                'source_channel' => $channel,
                'source_order_id' => $orderId,
                'incoming_name' => trim($incomingName),
                'incoming_phone_normalized' => $normalized,
                'status' => CustomerIdentityConflict::STATUS_OPEN,
            ]);
        } catch (\Throwable $exception) {
            // Deliberately swallowed: a bookkeeping ticket must never be able
            // to break an order that has already been committed.
            Log::warning('Identity conflict ticket could not be opened', [
                'customer_id' => $customer->id,
                'channel' => $channel,
                'order_id' => $orderId,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Names differ enough to be worth a human's time.
     *
     * Case, surrounding whitespace and repeated inner spaces are not a
     * conflict — "أحمد  جرادة " and "أحمد جرادة" are the same person typed
     * twice, and raising a ticket for that trains operators to ignore the
     * queue. Arabic diacritics and spelling variants are intentionally NOT
     * normalised here: those are real differences a human should judge.
     */
    /**
     * The incoming name worth storing on the order, or null.
     *
     * One place decides this, reusing the very check that raises the ticket —
     * so an order can never be flagged as carrying a different name while no
     * ticket is raised for it, or the reverse.
     */
    public function incomingNameFor(Customer $customer, ?string $incomingName): ?string
    {
        return $this->namesDiffer($customer->name, $incomingName) ? trim($incomingName) : null;
    }

    private function namesDiffer(string $storedName, ?string $incomingName): bool
    {
        if (blank($incomingName)) {
            return false;
        }

        $clean = fn (string $value) => mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)));

        return $clean($storedName) !== $clean($incomingName);
    }

    public function isKnownSharedNumber(string $normalizedPhone): bool
    {
        return CustomerIdentityConflict::query()
            ->where('incoming_phone_normalized', $normalizedPhone)
            ->where('resolution', CustomerIdentityConflict::RESOLUTION_MARKED_SHARED_NUMBER)
            ->exists();
    }

    /**
     * Apply an operator's decision. Runs in a transaction: a resolution that
     * fails halfway must not leave a ticket marked resolved with no effect.
     */
    public function resolve(
        CustomerIdentityConflict $conflict,
        string $resolution,
        User $actor,
        ?string $note = null,
    ): CustomerIdentityConflict {
        if ($conflict->status !== CustomerIdentityConflict::STATUS_OPEN) {
            throw ValidationException::withMessages([
                'resolution' => 'تم البتّ في هذه التذكرة مسبقًا.',
            ]);
        }

        return DB::transaction(function () use ($conflict, $resolution, $actor, $note) {
            $customer = Customer::lockForUpdate()->findOrFail($conflict->customer_id);
            $extraNote = null;
            $createdCustomerId = null;

            switch ($resolution) {
                case CustomerIdentityConflict::RESOLUTION_KEPT_ORIGINAL:
                    // Documentation only — the stored name already won when
                    // the order was created. Nothing to change.
                    break;

                case CustomerIdentityConflict::RESOLUTION_RENAMED_CUSTOMER:
                    $customer->update(['name' => $conflict->incoming_name]);
                    break;

                case CustomerIdentityConflict::RESOLUTION_CREATED_NEW_CUSTOMER:
                case CustomerIdentityConflict::RESOLUTION_MARKED_SHARED_NUMBER:
                    $new = $this->splitIntoNewCustomer($conflict, $customer);
                    $createdCustomerId = $new->id;
                    $extraNote = "أُنشئ عميل جديد #{$new->id} ({$new->code}) بنفس الرقم.";
                    break;

                default:
                    throw ValidationException::withMessages([
                        'resolution' => 'قرار غير معروف.',
                    ]);
            }

            $conflict->update([
                'status' => CustomerIdentityConflict::STATUS_RESOLVED,
                'resolution' => $resolution,
                'created_customer_id' => $createdCustomerId,
                'resolution_note' => trim(implode(' ', array_filter([$note, $extraNote]))) ?: null,
                'resolved_by' => $actor->id,
                'resolved_at' => now(),
            ]);

            return $conflict->fresh(['customer', 'resolver']);
        });
    }

    /**
     * Orders that MIGHT belong to the newly split-off customer.
     *
     * Deliberately a suggestion, never an action. Three conditions, all
     * required: same phone (normalised), byte-identical incoming name, and the
     * order is still attached to the original customer.
     *
     * Even all three together are not proof — the same wrong number can be
     * typed for genuinely different people, and two different people can share
     * a common name. So this returns a list for a human to pick from; nothing
     * here moves an order.
     *
     * Orders store the phone as typed, so the normalised comparison happens in
     * PHP. The candidate set is scoped to one customer first, so it stays small.
     *
     * @return array<int, array<string, mixed>>
     */
    public function candidateOrders(CustomerIdentityConflict $conflict): array
    {
        return Order::query()
            ->where('customer_id', $conflict->customer_id)
            // The name as typed, not the official one the order displays.
            // Orders predating this column carry NULL and are never candidates.
            ->where('incoming_customer_name', $conflict->incoming_name)
            ->orderByDesc('created_at')
            ->get(['id', 'order_number', 'created_at', 'total', 'customer_phone'])
            ->filter(fn (Order $order) => $order->customer_phone !== null
                && $this->phones->tryNormalize($order->customer_phone) === $conflict->incoming_phone_normalized)
            ->map(fn (Order $order) => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'created_at' => $order->created_at,
                'total' => $order->total,
            ])
            ->values()
            ->all();
    }

    /**
     * Move the orders a reviewer explicitly chose onto the split-off customer.
     *
     * Only ids present in the request move, and only ids that are still
     * candidates — an order that is not on that list cannot be reassigned
     * through this endpoint, whatever the caller sends.
     *
     * Callable long after the ticket closed: a reviewer may need to check the
     * receipts before deciding, and that should not force them to keep the
     * ticket open.
     *
     * @param  array<int, int>  $orderIds
     * @return array{reassigned: array<int,int>, left: array<int,int>}
     */
    public function reassignOrders(CustomerIdentityConflict $conflict, array $orderIds, User $actor): array
    {
        if ($conflict->created_customer_id === null) {
            throw ValidationException::withMessages([
                'orders' => 'هذه التذكرة لم تُنشئ عميلًا منفصلًا، فلا يوجد إلى من تُسنَد الطلبات.',
            ]);
        }

        $candidates = collect($this->candidateOrders($conflict))->pluck('id');
        $chosen = collect($orderIds)->map(fn ($id) => (int) $id)->unique();

        $unknown = $chosen->diff($candidates);
        if ($unknown->isNotEmpty()) {
            throw ValidationException::withMessages([
                'orders' => 'طلبات خارج قائمة المرشَّحين: ' . $unknown->implode('، '),
            ]);
        }

        $left = $candidates->diff($chosen);

        return DB::transaction(function () use ($conflict, $chosen, $left, $actor) {
            if ($chosen->isNotEmpty()) {
                Order::whereIn('id', $chosen)->update(['customer_id' => $conflict->created_customer_id]);
            }

            $entry = $chosen->isEmpty()
                ? 'راجَع ' . $actor->name . ' الطلبات المرشَّحة ولم يُسنِد أيًّا منها.'
                : 'أُعيد إسناد الطلب/الطلبات ' . $chosen->implode('، ') . ' إلى العميل #' . $conflict->created_customer_id
                    . ($left->isNotEmpty() ? '؛ تُرك ' . $left->implode('، ') . ' بقرار المراجع.' : '.');

            $conflict->update([
                'resolution_note' => trim(implode(' ', array_filter([$conflict->resolution_note, $entry]))),
            ]);

            return ['reassigned' => $chosen->values()->all(), 'left' => $left->values()->all()];
        });
    }

    public function dismiss(CustomerIdentityConflict $conflict, User $actor, ?string $note = null): CustomerIdentityConflict
    {
        if ($conflict->status !== CustomerIdentityConflict::STATUS_OPEN) {
            throw ValidationException::withMessages(['status' => 'تم البتّ في هذه التذكرة مسبقًا.']);
        }

        $conflict->update([
            'status' => CustomerIdentityConflict::STATUS_DISMISSED,
            'resolution_note' => $note,
            'resolved_by' => $actor->id,
            'resolved_at' => now(),
        ]);

        return $conflict->fresh(['customer', 'resolver']);
    }

    /**
     * Create a second customer on the same number — the shared-household case.
     *
     * Both the original and the new phone row are flagged is_shared, because
     * the exclusive_phone unique index exempts a number only when every row
     * holding it is flagged. Going through CustomerIdentityService::create()
     * with the phone omitted is deliberate: that service's
     * assertPhonesAvailable() would reject the number (correctly, for every
     * other caller), so the phone row is attached here instead — the one
     * place where sharing has been explicitly authorised.
     */
    private function splitIntoNewCustomer(CustomerIdentityConflict $conflict, Customer $original): Customer
    {
        $normalized = $conflict->incoming_phone_normalized;
        $legacy = $this->phones->legacyValue($normalized);

        $new = $this->identity->create([
            'name' => $conflict->incoming_name,
            'branch_id' => $original->branch_id,
            'city' => $original->city,
            'status' => 'active',
            'source' => $original->source,
        ], Customer::TYPE_OPERATIONAL);

        CustomerPhone::where('normalized_phone', $normalized)->update(['is_shared' => true]);

        CustomerPhone::create([
            'customer_id' => $new->id,
            'phone' => $legacy,
            'normalized_phone' => $normalized,
            'type' => 'mobile',
            'is_primary' => true,
            'is_verified' => false,
            'is_shared' => true,
        ]);

        // Keep the legacy denormalised column consistent with the phone row.
        // It carries no unique index, so this cannot collide.
        $new->update(['phone' => $legacy]);

        return $new->fresh();
    }
}
