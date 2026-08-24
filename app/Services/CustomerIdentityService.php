<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerAddress;
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
     * Upserts by (customer_id, occasion_type='birthday') so editing the
     * date never creates a duplicate occasion; passing null removes it.
     */
    public function syncBirthdayOccasion(Customer $customer, ?string $birthDate, ?int $createdBy = null): ?CustomerOccasion
    {
        $existing = $customer->occasions()->where('occasion_type', 'birthday')->first();

        if (! $birthDate) {
            $existing?->delete();

            return null;
        }

        if ($existing) {
            $existing->update(['date' => $birthDate, 'is_active' => true]);

            return $existing->fresh();
        }

        return $customer->occasions()->create([
            'occasion_type' => 'birthday',
            'title' => 'عيد ميلاد ' . $customer->name,
            'date' => $birthDate,
            'repeats_annually' => true,
            'is_active' => true,
            'created_by' => $createdBy,
        ]);
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
