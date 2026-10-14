<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
public function up(): void
{
    Schema::table('student_attendances', function (Blueprint $table) {
        // Keep a separate index for the student foreign key
        $table->index('student_id', 'student_attendances_student_id_index');
    });

    Schema::table('student_attendances', function (Blueprint $table) {
        // Remove the old student + date unique constraint
        $table->dropUnique('student_attendances_student_id_date_unique');

        // Allow attendance separately for each student session
        $table->unique(
            ['student_session_id', 'date'],
            'student_attendance_session_date_unique'
        );
    });
}

    public function down(): void
    {
        Schema::table('student_attendances', function (Blueprint $table) {
            $table->dropUnique('student_attendance_session_date_unique');

            $table->unique(
                ['student_id', 'date'],
                'student_attendances_student_id_date_unique'
            );
        });
    }
};
