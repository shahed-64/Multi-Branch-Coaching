<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {

            // Existing global unique constraint remove
            $table->dropUnique(['name']);

            // Branch relation
            $table->foreignId('branch_id')
                ->nullable()
                ->after('name')
                ->constrained('branches')
                ->nullOnDelete();

            // Same shift name allowed in different branches
            // but duplicate name is not allowed within same branch
            $table->unique(['branch_id', 'name']);

        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {

            $table->dropUnique(['branch_id', 'name']);

            $table->dropForeign(['branch_id']);

            $table->dropColumn('branch_id');

            $table->unique('name');

        });
    }
};
