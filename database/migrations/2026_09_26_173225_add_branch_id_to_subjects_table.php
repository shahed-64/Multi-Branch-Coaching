<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table) {

            // Remove global unique constraint
            $table->dropUnique(['code']);

            // Add branch
            $table->foreignId('branch_id')
                ->nullable()
                ->after('code')
                ->constrained('branches')
                ->nullOnDelete();

            // Code must be unique within each branch
            $table->unique(['branch_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {

            // Remove branch-wise unique constraint
            $table->dropUnique(['branch_id', 'code']);

            // Remove branch foreign key
            $table->dropForeign(['branch_id']);

            // Remove branch column
            $table->dropColumn('branch_id');

            // Restore global unique code
            $table->unique('code');
        });
    }
};
