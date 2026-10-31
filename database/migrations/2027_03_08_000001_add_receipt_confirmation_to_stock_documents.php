<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * تأكيد الاستلام الفعلي لمستند الصرف: من استلم ومتى (توثيق فقط — الحركة تحدث عند الترحيل).
 * يحل محل مفتاح "مسلّمة / غير مسلّمة" اليدوي؛ delivery_status يبقى ويُضبط "delivered" عند التأكيد.
 * عكس مستند مستلَم يحتاج inventory.documents.reverse_received (تُمنح لـ super-admin، وبقية الأدوار من شاشة الأدوار).
 */
return new class extends Migration
{
    private const PERMISSION = 'inventory.documents.reverse_received';

    public function up(): void
    {
        Schema::table('stock_documents', function (Blueprint $table) {
            $table->foreignId('received_by')->nullable()->after('reversal_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable()->after('received_by');
        });

        // ما عُلِّم "مسلّمة" يدوياً سابقاً يُعدّ مستلَماً (بلا اسم مستلِم).
        DB::table('stock_documents')->where('delivery_status', 'delivered')->update(['received_at' => DB::raw('updated_at')]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate(self::PERMISSION, 'web');
        Role::where('name', 'super-admin')->first()?->givePermissionTo(self::PERMISSION);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::where('name', self::PERMISSION)->delete();

        Schema::table('stock_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('received_by');
            $table->dropColumn('received_at');
        });
    }
};
