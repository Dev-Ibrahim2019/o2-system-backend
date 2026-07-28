<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\CustomerPhone;
use App\Services\Support\PhoneNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class BackfillCustomerPhones extends Command
{
    protected $signature = 'crm:backfill-customer-phones {--apply : Persist conflict-free phone rows}';
    protected $description = 'Dry-run by default; backfill customer_phones only with --apply';

    public function handle(PhoneNormalizer $normalizer): int
    {
        $apply = (bool) $this->option('apply');
        $stats = ['candidates' => 0, 'would_insert' => 0, 'inserted' => 0, 'conflicts' => 0, 'invalid' => 0, 'existing' => 0];

        Customer::select(['id', 'phone', 'mobile'])->chunkById(250, function ($customers) use (&$stats, $normalizer, $apply) {
            foreach ($customers as $customer) {
                foreach (array_unique(array_filter([$customer->phone, $customer->mobile])) as $index => $phone) {
                    $stats['candidates']++;
                    try {
                        $normalized = $normalizer->normalize($phone);
                    } catch (InvalidArgumentException) {
                        $stats['invalid']++;
                        continue;
                    }

                    $existing = CustomerPhone::withTrashed()->where('normalized_phone', $normalized)->first();
                    if ($existing) {
                        $existing->customer_id === $customer->id ? $stats['existing']++ : $stats['conflicts']++;
                        continue;
                    }

                    $stats['would_insert']++;
                    if ($apply) {
                        DB::transaction(fn () => CustomerPhone::create([
                            'customer_id' => $customer->id,
                            'phone' => $normalized,
                            'normalized_phone' => $normalized,
                            'type' => 'mobile',
                            'is_primary' => $index === 0 && ! CustomerPhone::where('customer_id', $customer->id)->where('is_primary', true)->exists(),
                            'is_verified' => false,
                        ]));
                        $stats['inserted']++;
                    }
                }
            }
        });

        $this->table(['Metric', 'Count'], collect($stats)->map(fn ($v, $k) => [$k, $v])->values());
        $this->info($apply ? 'Backfill applied.' : 'Dry run only. No data was changed.');

        return self::SUCCESS;
    }
}
