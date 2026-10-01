<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institute_infos', function (Blueprint $table) {
            $table->foreignId('branch_id')
                ->nullable()
                ->after('id')
                ->constrained('branches')
                ->cascadeOnDelete();

            $table->index('branch_id');
        });
    }

    public function down(): void
    {
        Schema::table('institute_infos', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropIndex(['branch_id']);
            $table->dropColumn('branch_id');
        });
    }
};
