<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * سجل نشاطات موظفي CRM — رقابي، يراه مدير CRM والـ super-admin فقط
 * (crm.staff-activity.view). يسجّل ما يفعله الموظفون على العملاء والشكاوى،
 * ويعلّم (is_flagged) الحركات الغريبة: تعديل عميل من فرع آخر، تغيير اسم/رقم
 * عميل، حظره، إلغاء شكوى أو تعديل شكوى مسندة لغيره، إلغاء تصنيف الحساسية،
 * حذف ملاحظة، ومحاولة الوصول لشكوى خارج نطاقه.
 *
 * منفصل عن audit_logs: ذاك سجل تغييرات عام لكل الموديلات بلا سياق فرع ولا
 * تعليم، وتبويب "النشاط" في ملف العميل يقرؤه لكل من يرى العميل.
 *
 * ومعه صلاحية crm.customers.filter-by-branch — فلترة العملاء ولوحة التحكم
 * حسب الفرع أداة إدارية لمدير CRM والـ super-admin، لا لموظف الفرع.
 */
return new class extends Migration
{
    private const VIEW_ACTIVITY = 'crm.staff-activity.view';
    private const FILTER_BY_BRANCH = 'crm.customers.filter-by-branch';

    public function up(): void
    {
        Schema::create('crm_staff_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // فرع الموظف لحظة الحركة — لا يُقرأ لاحقًا من users حتى لا يعيد
            // نقلُ الموظف لفرع آخر كتابةَ التاريخ.
            $table->unsignedBigInteger('actor_branch_id')->nullable();
            $table->string('action', 60);
            $table->string('subject_type', 30);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedBigInteger('subject_branch_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->boolean('is_flagged')->default(false);
            $table->json('flags')->nullable();
            $table->json('details')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['is_flagged', 'created_at'], 'csal_flagged_created_index');
            $table->index(['user_id', 'created_at'], 'csal_user_created_index');
            $table->index(['subject_type', 'subject_id'], 'csal_subject_index');
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::findOrCreate(self::VIEW_ACTIVITY, 'web');
        Permission::findOrCreate(self::FILTER_BY_BRANCH, 'web');

        Role::whereIn('name', ['crm-manager', 'super-admin'])
            ->get()
            ->each(function (Role $role) {
                $role->givePermissionTo(self::VIEW_ACTIVITY);
                $role->givePermissionTo(self::FILTER_BY_BRANCH);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_staff_activity_logs');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::whereIn('name', [self::VIEW_ACTIVITY, self::FILTER_BY_BRANCH])->delete();
    }
};
