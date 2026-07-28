<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerPhone;
use App\Services\Support\PhoneNormalizer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerIdentityService
{
    public function __construct(private readonly PhoneNormalizer $phones) {}

    public function create(array $data, ?array $address = null): Customer
    {
        return DB::transaction(function () use ($data, $address) {
            [$data, $phoneRows] = $this->preparePhones($data);
            $this->assertPhonesAvailable($phoneRows);

            if (empty($data['code'])) {
                $data['code'] = $this->nextCode();
            }
            $data['status'] ??= 'active';
            $data['currency'] ??= 'ILS';
            $data['risk_level'] ??= 'low';
            $data['payment_terms'] ??= 'net30';
            $data['credit_days'] ??= 30;

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
