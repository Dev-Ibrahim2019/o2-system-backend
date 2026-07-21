<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_titles', function (Blueprint $table) {
            if (! Schema::hasColumn('job_titles', 'name_ar')) $table->string('name_ar')->nullable()->after('name');
            if (! Schema::hasColumn('job_titles', 'name_en')) $table->string('name_en')->nullable()->after('name_ar');
            if (! Schema::hasColumn('job_titles', 'department_id')) $table->foreignId('department_id')->nullable()->after('description')->constrained('departments')->nullOnDelete();
            if (! Schema::hasColumn('job_titles', 'default_operational_role')) $table->string('default_operational_role', 40)->nullable()->after('department_id');
            if (! Schema::hasColumn('job_titles', 'requires_vehicle')) $table->boolean('requires_vehicle')->default(false)->after('default_operational_role');
        });

        Schema::table('employees', function (Blueprint $table) {
            if (! Schema::hasColumn('employees', 'job_title_id')) $table->foreignId('job_title_id')->nullable()->after('jobTitleId')->constrained('job_titles')->nullOnDelete();
            if (! Schema::hasColumn('employees', 'is_operations_enabled')) $table->boolean('is_operations_enabled')->default(false)->after('vehicle_type');
        });

        if (Schema::hasColumn('employees', 'jobTitleId') && Schema::hasColumn('employees', 'job_title_id')) {
            DB::table('employees')->whereNull('job_title_id')->whereNotNull('jobTitleId')->orderBy('id')->each(function ($employee) {
                $legacy = trim((string) $employee->jobTitleId);
                $jobTitleId = ctype_digit($legacy)
                    ? DB::table('job_titles')->where('id', (int) $legacy)->value('id')
                    : DB::table('job_titles')->where('name', $legacy)->orWhere('name_ar', $legacy)->orWhere('name_en', $legacy)->value('id');
                if ($jobTitleId) DB::table('employees')->where('id', $employee->id)->update(['job_title_id' => $jobTitleId]);
            });
        }
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (Schema::hasColumn('employees', 'job_title_id')) $table->dropConstrainedForeignId('job_title_id');
            if (Schema::hasColumn('employees', 'is_operations_enabled')) $table->dropColumn('is_operations_enabled');
        });
        Schema::table('job_titles', function (Blueprint $table) {
            if (Schema::hasColumn('job_titles', 'department_id')) $table->dropConstrainedForeignId('department_id');
            $columns = array_values(array_filter(['name_ar', 'name_en', 'default_operational_role', 'requires_vehicle'], fn ($column) => Schema::hasColumn('job_titles', $column)));
            if ($columns) $table->dropColumn($columns);
        });
    }
};
