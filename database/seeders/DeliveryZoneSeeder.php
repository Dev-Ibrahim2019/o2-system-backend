<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\DeliveryZone;
use Illuminate\Database\Seeder;

class DeliveryZoneSeeder extends Seeder
{
    public function run(): void
    {
        Branch::query()->each(function (Branch $branch) {
            DeliveryZone::query()->firstOrCreate(
                [
                    'branch_id' => $branch->id,
                    'code' => 'DEFAULT',
                ],
                [
                    'name' => 'منطقة التوصيل الافتراضية',
                    'base_fee' => 0,
                    'estimated_minutes' => 30,
                    'is_active' => true,
                    'notes' => 'منطقة افتراضية أنشأها Seeder. حدّث الرسوم والنطاق من إدارة مناطق التوصيل.',
                ],
            );
        });
    }
}
