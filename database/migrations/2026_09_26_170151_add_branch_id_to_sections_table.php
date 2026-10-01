<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->foreignId('branch_id')
                ->nullable()
                ->after('section_name')
                ->constrained('branches')
                ->nullOnDelete();

            $table->unique(['branch_id', 'section_name']);
        });
    }

    public function down(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'section_name']);
            $table->dropForeign(['branch_id']);
            $table->dropColumn('branch_id');
        });
    }
};
