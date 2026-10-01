<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_groups', function (Blueprint $table) {
            $table->dropUnique(['group_name']);

            $table->foreignId('branch_id')
                ->nullable()
                ->after('group_name')
                ->constrained('branches')
                ->nullOnDelete();

            $table->unique(['branch_id', 'group_name']);
        });
    }

    public function down(): void
    {
        Schema::table('class_groups', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'group_name']);
            $table->dropForeign(['branch_id']);
            $table->dropColumn('branch_id');

            $table->unique('group_name');
        });
    }
};
