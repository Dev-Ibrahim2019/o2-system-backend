<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\Support\PhoneNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class ProfileCustomerPhones extends Command
{
    protected $signature = 'crm:profile-customer-phones {--output=reports/crm_customer_phone_profile.json}';
    protected $description = 'Read-only profiling of legacy customer phone data';

    public function handle(PhoneNormalizer $normalizer): int
    {
        $summary = [
            'generated_at' => now()->toIso8601String(),
            'customers' => Customer::count(),
            'normalizable_values' => 0,
            'invalid_values' => 0,
            'duplicate_normalized_numbers' => 0,
            'customers_without_customer_phone' => 0,
            'phone_mobile_conflicts' => 0,
            'conflicts' => [],
        ];
        $owners = [];

        Customer::with('phones')->select(['id', 'phone', 'mobile'])->chunkById(500, function ($customers) use (&$summary, &$owners, $normalizer) {
            foreach ($customers as $customer) {
                if ($customer->phones->isEmpty()) {
                    $summary['customers_without_customer_phone']++;
                }

                $normalized = [];
                foreach (array_filter([$customer->phone, $customer->mobile]) as $phone) {
                    try {
                        $value = $normalizer->normalize($phone);
                        $normalized[] = $value;
                        $summary['normalizable_values']++;
                        $owners[$value][] = $customer->id;
                    } catch (InvalidArgumentException) {
                        $summary['invalid_values']++;
                        $summary['conflicts'][] = [
                            'customer_id' => $customer->id,
                            'type' => 'invalid',
                            'masked_phone' => $this->mask($phone),
                        ];
                    }
                }

                if (count(array_unique($normalized)) > 1) {
                    $summary['phone_mobile_conflicts']++;
                }
            }
        });

        foreach ($owners as $phone => $customerIds) {
            $uniqueOwners = array_values(array_unique($customerIds));
            if (count($uniqueOwners) > 1) {
                $summary['duplicate_normalized_numbers']++;
                $summary['conflicts'][] = [
                    'type' => 'duplicate',
                    'masked_phone' => $this->mask($phone),
                    'customer_ids' => $uniqueOwners,
                ];
            }
        }

        Storage::disk('local')->put($this->option('output'), json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->table(['Metric', 'Count'], collect($summary)->except(['generated_at', 'conflicts'])->map(fn ($v, $k) => [$k, $v])->values());
        $this->info('Report: storage/app/private/'.$this->option('output'));

        return self::SUCCESS;
    }

    private function mask(string $phone): string
    {
        $length = strlen($phone);
        return $length <= 4 ? str_repeat('*', $length) : substr($phone, 0, 4).str_repeat('*', max(1, $length - 6)).substr($phone, -2);
    }
}
