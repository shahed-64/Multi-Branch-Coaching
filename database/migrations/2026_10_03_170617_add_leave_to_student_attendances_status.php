<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("
            ALTER TABLE student_attendances
            MODIFY status ENUM('present', 'absent', 'late', 'leave')
            NOT NULL DEFAULT 'present'
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("
            ALTER TABLE student_attendances
            MODIFY status ENUM('present', 'absent', 'late')
            NOT NULL DEFAULT 'present'
        ");
    }
};
