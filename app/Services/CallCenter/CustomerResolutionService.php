<?php

namespace App\Services\CallCenter;

use App\Models\Customer;
use App\Services\Support\PhoneNormalizer;
use Illuminate\Database\Eloquent\Builder;

class CustomerResolutionService
{
    public function __construct(private readonly PhoneNormalizer $phones) {}

    public function resolve(string $phone, bool $lock = false): array
    {
        $normalized = $this->phones->normalize($phone);
        $legacy = $this->phones->legacyValue($normalized);
        $tail = substr(ltrim($normalized, '+'), -9);

        $query = Customer::query()
            ->where(function (Builder $query) use ($normalized, $legacy, $tail) {
                $query
                    ->where('phone', $legacy)
                    ->orWhere('mobile', $legacy)
                    ->orWhere('phone', 'like', "%{$tail}")
                    ->orWhere('mobile', 'like', "%{$tail}")
                    ->orWhereHas('phones', fn (Builder $phones) => $phones
                        ->where('normalized_phone', $normalized)
                        ->orWhere('normalized_phone', 'like', "%{$tail}"));
            })
            ->withMax('orders', 'created_at')
            ->orderBy('id')
            ->limit(10);

        if ($lock) {
            $query->lockForUpdate();
        }

        $candidates = $query->get([
            'id', 'name', 'phone', 'mobile', 'code', 'status', 'category',
            'city', 'address', 'branch_id', 'loyalty_points',
        ]);

        $status = match ($candidates->count()) {
            0 => 'not_found',
            1 => 'found',
            default => 'multiple',
        };

        return [
            'status' => $status,
            'normalized_phone' => $normalized,
            'customer' => $status === 'found' ? $candidates->first() : null,
            'candidates' => $status === 'multiple' ? $candidates->values() : [],
        ];
    }
}
