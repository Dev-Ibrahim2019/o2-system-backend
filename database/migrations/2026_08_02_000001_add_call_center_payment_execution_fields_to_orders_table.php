<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'payment_policy')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->enum('payment_policy', ['manual_confirmation', 'instant_debit'])
                    ->nullable()->after('status');
            });
        }

        if (! Schema::hasColumn('orders', 'payment_status')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->enum('payment_status', $this->paymentStatuses())
                    ->nullable()->after('payment_policy');
            });
        } else {
            $this->normalizeExistingPaymentStatuses();

            Schema::table('orders', function (Blueprint $table) {
                $table->enum('payment_status', $this->paymentStatuses())
                    ->nullable()
                    ->default(null)
                    ->change();
            });
        }

        if (! Schema::hasColumn('orders', 'kitchen_release_status')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->enum('kitchen_release_status', [
                    'held',
                    'releasing',
                    'released',
                    'release_failed',
                ])->nullable()->after('payment_status');
            });
        }

        if (! Schema::hasColumn('orders', 'kitchen_released_at')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->timestamp('kitchen_released_at')->nullable()->after('kitchen_release_status');
            });
        }

        if (! Schema::hasColumn('orders', 'kitchen_released_by')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->foreignId('kitchen_released_by')
                    ->nullable()
                    ->after('kitchen_released_at')
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }

        foreach (['payment_policy', 'payment_status', 'kitchen_release_status'] as $column) {
            if (! $this->hasSingleColumnIndex($column)) {
                Schema::table('orders', function (Blueprint $table) use ($column) {
                    $table->index($column);
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'kitchen_released_by')) {
            $foreign = collect(Schema::getForeignKeys('orders'))
                ->first(fn (array $key) => in_array('kitchen_released_by', $key['columns'], true));

            if ($foreign) {
                Schema::table('orders', function (Blueprint $table) use ($foreign) {
                    $table->dropForeign($foreign['name']);
                });
            }
        }

        foreach (['kitchen_released_by', 'kitchen_released_at', 'kitchen_release_status', 'payment_status', 'payment_policy'] as $column) {
            if (Schema::hasColumn('orders', $column)) {
                Schema::table('orders', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }

    private function normalizeExistingPaymentStatuses(): void
    {
        $canonical = $this->paymentStatuses();
        $legacyMap = [
            'UNPAID' => 'unpaid',
            'AWAITING_CONFIRMATION' => 'awaiting_confirmation',
            'PROCESSING' => 'processing',
            'PAID' => 'paid',
            'FAILED' => 'failed',
            'REFUNDED' => 'refunded',
        ];

        $counts = DB::table('orders')
            ->select('payment_status', DB::raw('COUNT(*) as total'))
            ->groupBy('payment_status')
            ->get();

        $unexpected = $counts->filter(function (object $row) use ($canonical, $legacyMap): bool {
            return $row->payment_status !== null
                && ! in_array($row->payment_status, $canonical, true)
                && ! array_key_exists($row->payment_status, $legacyMap);
        });

        if ($unexpected->isNotEmpty()) {
            throw new RuntimeException(
                'Unknown orders.payment_status values; migration stopped without mapping: '.$unexpected->toJson()
            );
        }

        foreach ($legacyMap as $legacy => $normalized) {
            DB::table('orders')->where('payment_status', $legacy)->update(['payment_status' => $normalized]);
        }
    }

    private function hasSingleColumnIndex(string $column): bool
    {
        return collect(Schema::getIndexes('orders'))->contains(
            fn (array $index): bool => array_values($index['columns']) === [$column]
        );
    }

    private function paymentStatuses(): array
    {
        return [
            'unpaid',
            'awaiting_confirmation',
            'processing',
            'paid',
            'failed',
            'refunded',
        ];
    }
};
