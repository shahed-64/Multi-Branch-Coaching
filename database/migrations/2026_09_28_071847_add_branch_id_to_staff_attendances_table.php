<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_attendances', function (Blueprint $table) {
            $table->foreignId('branch_id')
                ->nullable()
                ->after('staff_id')
                ->constrained('branches')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('staff_attendances', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropColumn('branch_id');
        });
    }
};
