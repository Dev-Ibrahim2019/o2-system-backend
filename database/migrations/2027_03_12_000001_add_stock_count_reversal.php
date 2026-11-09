<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * عكس الجرد المعتمد (صفحة الأقسام ← تبويب الجرد): الجرد المعتمد لا يُعدَّل، وتصحيحه بعكس تسوياته.
 *
 * - stock_counts.status: تُضاف الحالة reversed (القيم القديمة كما هي).
 * - من عكس ومتى ولماذا.
 * - الصلاحية inventory.counts.reverse (لـ super-admin، مثل باقي صلاحيات المخزون).
 */
return new class extends Migration
{
    private const PERMISSION = 'inventory.counts.reverse';

    public function up(): void
    {
        DB::statement("ALTER TABLE stock_counts MODIFY status ENUM('draft','posted','reversed') NOT NULL DEFAULT 'draft'");
        Schema::table('stock_counts', function (Blueprint $table) {
            $table->foreignId('reversed_by')->nullable()->after('posted_at')->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable()->after('reversed_by');
            $table->string('reversal_reason', 500)->nullable()->after('reversed_at');
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate(self::PERMISSION, 'web');
        Role::where('name', 'super-admin')->first()?->givePermissionTo(self::PERMISSION);
    }

    public function down(): void
    {
        // الجرد المعكوس يعود "معتمداً" (حركاته العكسية تبقى في الكاردكس — الحركات لا تُحذف).
        DB::table('stock_counts')->where('status', 'reversed')->update(['status' => 'posted']);
        Schema::table('stock_counts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn(['reversed_at', 'reversal_reason']);
        });
        DB::statement("ALTER TABLE stock_counts MODIFY status ENUM('draft','posted') NOT NULL DEFAULT 'draft'");

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::where('name', self::PERMISSION)->delete();
    }
};
