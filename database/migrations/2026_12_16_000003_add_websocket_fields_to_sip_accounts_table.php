<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sip_accounts', function (Blueprint $table) {
            // Browser-Phone يحتاج منفذ ومسار الـ WebSocket منفصلَين عن اسم السيرفر —
            // sip_server وحده لا يكفي لتكوين اتصال WSS صحيح. لا قيم افتراضية حقيقية هنا،
            // الحقول nullable ليُدخلها المستخدم بنفسه من واجهة الإعدادات.
            $table->unsignedInteger('websocket_port')->nullable()->after('sip_server');
            $table->string('server_path', 100)->nullable()->after('websocket_port');
        });
    }

    public function down(): void
    {
        Schema::table('sip_accounts', function (Blueprint $table) {
            $table->dropColumn(['websocket_port', 'server_path']);
        });
    }
};
