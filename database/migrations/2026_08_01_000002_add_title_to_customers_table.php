<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void { Schema::table('customers', fn (Blueprint $table) => $table->string('title', 32)->nullable()->after('name_en')); }
    public function down(): void { Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('title')); }
};
