<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerFamilyMember;
use App\Models\CustomerOccasion;
use App\Models\CustomerPhone;
use App\Services\Support\PhoneNormalizer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Identity-only customer creation/update — domain-neutral on purpose.
 *
 * This service does NOT decide, default, or infer two things that belong
 * to the calling workflow, not to identity creation:
 *  - Financial fields (currency, risk_level, payment_terms, credit_days) —
 *    the Financial/Accounting workflow sets these explicitly before
 *    calling create(). See CustomerFinancialController::store().
 *  - customer_type (operational vs financial) — the caller MUST pass the
 *    already-decided type via $customerType. This service never invents
 *    it and never trusts a value that might have arrived from client
 *    input inside $data; the explicit parameter is the only source of
 *    truth and always overrides anything in $data.
 */
class CustomerIdentityService
{
    public function __construct(private readonly PhoneNormalizer $phones) {}

    public function create(array $data, string $customerType, ?array $address = null): Customer
    {
        return DB::transaction(function () use ($data, $customerType, $address) {
            [$data, $phoneRows] = $this->preparePhones($data);
            $this->assertPhonesAvailable($phoneRows);

            if (empty($data['code'])) {
                $data['code'] = $this->nextCode();
            }
            $data['status'] ??= 'active';
            $data['customer_type'] = $customerType;

            try {
                $customer = Customer::create($data);
                $this->syncPhones($customer, $phoneRows);

                if ($address && array_filter($address, fn ($value) => $value !== null && $value !== '')) {
                    $this->createAddress($customer, $address + ['is_default' => true]);
                }
            } catch (QueryException $exception) {
                if (in_array($exception->getCode(), ['23000', '23505'], true)) {
                    throw ValidationException::withMessages([
                        'phone' => 'رقم الهاتف مرتبط بعميل آخر.',
                    ]);
                }
                throw $exception;
            }

            return $customer->load(['branch:id,name', 'primaryPhone', 'address']);
        });
    }

    public function update(Customer $customer, array $data): Customer
    {
        return DB::transaction(function () use ($customer, $data) {
            [$data, $phoneRows] = $this->preparePhones($data);
            $this->assertPhonesAvailable($phoneRows, $customer->id);

            $customer->update($data);
            if ($phoneRows !== []) {
                $this->syncPhones($customer, $phoneRows);
            }

            return $customer->load(['branch:id,name', 'primaryPhone', 'address']);
        });
    }

    public function createAddress(Customer $customer, array $data): CustomerAddress
    {
        return DB::transaction(function () use ($customer, $data) {
            if (! empty($data['is_default'])) {
                $customer->addresses()->update(['is_default' => false]);
            }

            return $customer->addresses()->create($data);
        });
    }

    /**
     * The label this app uses to mark a customer's work address — matches
     * the existing Arabic-label convention already used for the default
     * home address ('منزل', see CallCenterService::createCustomer()).
     * Not a DB enum — customer_addresses.label is a free-text column.
     */
    public const WORK_ADDRESS_LABEL = 'العمل';

    /**
     * Create/update/remove a customer's work address without touching their
     * default (delivery) address unless the caller explicitly passes
     * is_default in $data. Upserts by (customer_id, label) so editing never
     * creates a duplicate row — same identity-service pattern as
     * syncPhones()/syncBirthdayOccasion().
     */
    public function syncWorkAddress(Customer $customer, ?array $data): ?CustomerAddress
    {
        $existing = $customer->addresses()->where('label', self::WORK_ADDRESS_LABEL)->first();

        if (! $data || ! array_filter($data, fn ($v) => $v !== null && $v !== '')) {
            $existing?->update(['is_active' => false]);

            return null;
        }

        $payload = array_merge($data, ['label' => self::WORK_ADDRESS_LABEL, 'is_active' => true]);

        if (! empty($payload['is_default'])) {
            $customer->addresses()->update(['is_default' => false]);
        }

        if ($existing) {
            $existing->update($payload);

            return $existing->fresh();
        }

        return $customer->addresses()->create($payload);
    }

    /**
     * Create/update/remove a customer's birthday occasion. Shared by both
     * CRM and Call Center customer creation/editing — extracted from what
     * was previously inline-duplicated logic in
     * CallCenterService::createCustomer() so both domains use one path.
     * Upserts by (owner, occasion_type='birthday') so editing the
     * date never creates a duplicate occasion; passing null removes it.
     */
    public function syncBirthdayOccasion(Customer $customer, ?string $birthDate, ?int $createdBy = null): ?CustomerOccasion
    {
        // withTrashed(): clearing a birth date soft-deletes this row, and a
        // plain first() cannot see it. Re-entering the date then created a
        // second row instead of bringing the old one back, so every
        // clear/re-set cycle left another dead row behind — invisible in the
        // UI, but accumulating under the customer forever.
        $existing = $customer->occasions()
            ->withTrashed()
            ->where('occasion_type', 'birthday')
            ->first();

        return $this->syncBirthdayOccasionRow($existing, $customer, $birthDate, 'عيد ميلاد ' . $customer->name, $createdBy);
    }

    /**
     * Create/update/remove a family member's birthday occasion — same
     * upsert-not-duplicate, restore-not-recreate mechanics as
     * syncBirthdayOccasion() above, factored into syncBirthdayOccasionRow()
     * so both call it instead of a second copy of the same four states.
     *
     * The one real difference: syncBirthdayOccasion() finds "existing" by
     * (owner, occasion_type='birthday') because a customer has exactly one
     * birthday. A family member can't use that — one customer can have many
     * family members, each with their own birthday occasion under the same
     * occasionable — so this finds "existing" via occasion_id, a link
     * this method itself maintains on the family member row (see the
     * migration's comment on that column).
     */
    public function syncFamilyMemberBirthdayOccasion(CustomerFamilyMember $member, ?int $createdBy = null): ?CustomerOccasion
    {
        $existing = $member->occasion_id
            ? CustomerOccasion::withTrashed()->find($member->occasion_id)
            : null;

        $title = 'عيد ميلاد ' . $member->name . ' (' . (CustomerFamilyMember::RELATIONSHIP_LABELS[$member->relationship] ?? $member->relationship) . ')';

        $occasion = $this->syncBirthdayOccasionRow($existing, $member->customer, $member->birth_date?->toDateString(), $title, $createdBy);

        if ($member->occasion_id !== $occasion?->id) {
            // Quiet: this is bookkeeping for the sync itself, not a change
            // the caller asked for — must not fire model events a second
            // time on top of whatever create/update just fired.
            $member->occasion_id = $occasion?->id;
            $member->saveQuietly();
        }

        return $occasion;
    }

    /**
     * The four birthday-occasion states every caller above was duplicating:
     * clear an active one, restore+update a trashed one, update an active
     * one, or create fresh. $existing is whatever the caller found (or
     * null) — this method owns none of that lookup, only what happens next,
     * so syncBirthdayOccasion() and syncFamilyMemberBirthdayOccasion() can
     * disambiguate "existing" however their own shape requires while still
     * sharing one real implementation instead of two that drift apart.
     *
     * $title is applied on create only, exactly as the original
     * syncBirthdayOccasion() always did — an existing occasion's title is
     * never touched on update, so a family member's occasion title going
     * stale after their name changes is the same accepted limitation the
     * customer's own birthday occasion already has, not a new one.
     */
    private function syncBirthdayOccasionRow(?CustomerOccasion $existing, Customer $occasionable, ?string $date, string $title, ?int $createdBy): ?CustomerOccasion
    {
        if (! $date) {
            // Already trashed is already the desired state; deleting again
            // would only move the timestamp.
            if ($existing && ! $existing->trashed()) {
                $existing->delete();
            }

            return null;
        }

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            $existing->update(['date' => $date, 'is_active' => true]);

            return $existing->fresh();
        }

        return $occasionable->occasions()->create([
            'occasion_type' => 'birthday',
            'title' => $title,
            'date' => $date,
            'repeats_annually' => true,
            'is_active' => true,
            'created_by' => $createdBy,
        ]);
    }

    /**
     * POST-equivalent for a family member. Validation (name/relationship
     * required, birth_date optional) is the controller's job, same division
     * as everywhere else in this service.
     */
    public function createFamilyMember(Customer $customer, array $data, ?int $createdBy = null): CustomerFamilyMember
    {
        $member = $customer->familyMembers()->create([
            'name' => $data['name'],
            'relationship' => $data['relationship'],
            'birth_date' => $data['birth_date'] ?? null,
            'created_by' => $createdBy,
        ]);

        $this->syncFamilyMemberBirthdayOccasion($member, $createdBy);

        return $member->fresh();
    }

    public function updateFamilyMember(CustomerFamilyMember $member, array $data): CustomerFamilyMember
    {
        $member->update(array_intersect_key($data, array_flip(['name', 'relationship', 'birth_date'])));
        $member = $member->fresh();

        $this->syncFamilyMemberBirthdayOccasion($member, $member->created_by);

        return $member->fresh();
    }

    /**
     * Soft delete, matching the model — and its birthday occasion goes with
     * it (also soft, also only if it wasn't already gone), so clearing a
     * family member never leaves an orphaned occasion still showing up on
     * the calendar for someone who no longer appears anywhere else.
     */
    public function deleteFamilyMember(CustomerFamilyMember $member): void
    {
        if ($member->occasion_id) {
            $occasion = CustomerOccasion::withTrashed()->find($member->occasion_id);
            if ($occasion && ! $occasion->trashed()) {
                $occasion->delete();
            }
        }

        $member->delete();
    }

    public function findByPhone(string $phone): ?Customer
    {
        $normalized = $this->phones->normalize($phone);
        $legacy = $this->phones->legacyValue($normalized);

        return Customer::whereHas('phones', fn ($query) => $query->where('normalized_phone', $normalized))
            ->orWhere('phone', $legacy)
            ->orWhere('mobile', $legacy)
            ->first();
    }

    private function preparePhones(array $data): array
    {
        $phoneRows = [];
        foreach (['phone' => 'mobile', 'mobile' => 'mobile'] as $field => $type) {
            if (! array_key_exists($field, $data) || blank($data[$field])) {
                continue;
            }

            try {
                $normalized = $this->phones->normalize((string) $data[$field]);
            } catch (\InvalidArgumentException $exception) {
                throw ValidationException::withMessages([$field => $exception->getMessage()]);
            }

            $phoneRows[$normalized] = [
                'phone' => (string) $data[$field],
                'normalized_phone' => $normalized,
                'type' => $type,
                'is_primary' => $field === 'phone',
            ];
            $data[$field] = $this->phones->legacyValue($normalized);
        }

        if ($phoneRows !== [] && ! collect($phoneRows)->contains('is_primary', true)) {
            $first = array_key_first($phoneRows);
            $phoneRows[$first]['is_primary'] = true;
        }

        return [$data, array_values($phoneRows)];
    }

    private function assertPhonesAvailable(array $phoneRows, ?int $exceptCustomerId = null): void
    {
        foreach ($phoneRows as $row) {
            $normalized = $row['normalized_phone'];
            $legacy = $this->phones->legacyValue($normalized);

            $exists = CustomerPhone::where('normalized_phone', $normalized)
                ->when($exceptCustomerId, fn ($query) => $query->where('customer_id', '!=', $exceptCustomerId))
                ->exists()
                || Customer::query()
                    ->when($exceptCustomerId, fn ($query) => $query->whereKeyNot($exceptCustomerId))
                    ->where(fn ($query) => $query->where('phone', $legacy)->orWhere('mobile', $legacy))
                    ->exists();

            if ($exists) {
                throw ValidationException::withMessages(['phone' => 'رقم الهاتف مرتبط بعميل آخر.']);
            }
        }
    }

    private function syncPhones(Customer $customer, array $phoneRows): void
    {
        foreach ($phoneRows as $row) {
            if ($row['is_primary']) {
                $customer->phones()->update(['is_primary' => false]);
            }
            $customer->phones()->updateOrCreate(
                ['normalized_phone' => $row['normalized_phone']],
                $row,
            );
        }
    }

    private function nextCode(): string
    {
        return 'CUS-' . str_pad((string) ((Customer::withTrashed()->max('id') ?? 0) + 1), 6, '0', STR_PAD_LEFT);
    }
}
