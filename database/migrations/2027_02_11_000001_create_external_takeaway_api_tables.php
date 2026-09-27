<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API خارجي لبرنامج التيك أواي (/api/v1/external/takeaway):
 *
 * - external_api_clients: تطبيق خارجي لكل صف. المفتاح (X-API-Key) مخزّن sha256 بس (key_hash) مع بادئة
 *   للتعرّف عليه بالسجلات؛ سرّ الـwebhook مشفّر (encrypted cast) لأنه لازم ينقرا للتوقيع بـ HMAC.
 * - orders.tw_*: حالة التيك أواي منفصلة تمامًا عن orders.status (اللي عليه منطق الـPOS والمطبخ والإغلاق).
 *   tw_released_at = لحظة ما الطلب صار متاح للتيك أواي (الإغلاق F7) — null يعني الطلب مش بنطاق الـAPI.
 * - takeaway_status_events: سجل كل تغيير حالة/تعيين سائق (مين غيّر ومتى).
 * - external_webhook_deliveries/attempts: توزيع كل حدث outbox على كل تطبيق عنده webhook، وسجل كل محاولة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_api_clients', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('key_prefix', 16)->index();
            $table->char('key_hash', 64)->unique();
            $table->json('allowed_origins')->nullable();
            $table->string('webhook_url', 500)->nullable();
            $table->text('webhook_secret')->nullable();
            $table->unsignedSmallInteger('rate_limit_per_minute')->default(60);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('tw_released_at')->nullable()->index();
            $table->string('tw_status', 20)->nullable()->index();
            $table->timestamp('tw_status_updated_at')->nullable();
            $table->string('tw_driver_name', 100)->nullable();
            $table->string('tw_driver_phone', 40)->nullable();
            $table->string('tw_driver_ref', 100)->nullable();
            $table->timestamp('tw_driver_assigned_at')->nullable();
        });

        Schema::create('takeaway_status_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('external_api_client_id')->nullable()->constrained('external_api_clients')->nullOnDelete();
            $table->string('event', 30); // status_changed | driver_assigned
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->json('driver')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at');
            $table->index(['order_id', 'created_at']);
        });

        Schema::create('external_webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('external_api_client_id')->constrained('external_api_clients')->cascadeOnDelete();
            $table->foreignId('integration_outbox_id')->constrained('integration_outbox')->cascadeOnDelete();
            $table->string('event_type', 100);
            $table->string('aggregate_ref', 40)->index();
            $table->string('status', 20)->default('pending')->index(); // pending | sent | failed | dead
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamp('delivered_at')->nullable();
            $table->unsignedSmallInteger('last_status_code')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
            $table->unique(['external_api_client_id', 'integration_outbox_id'], 'ext_webhook_deliveries_client_outbox_unique');
        });

        Schema::create('external_webhook_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('external_webhook_delivery_id')->constrained('external_webhook_deliveries')->cascadeOnDelete();
            $table->timestamp('attempted_at');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('error', 500)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_webhook_attempts');
        Schema::dropIfExists('external_webhook_deliveries');
        Schema::dropIfExists('takeaway_status_events');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'tw_released_at', 'tw_status', 'tw_status_updated_at',
                'tw_driver_name', 'tw_driver_phone', 'tw_driver_ref', 'tw_driver_assigned_at',
            ]);
        });
        Schema::dropIfExists('external_api_clients');
    }
};
