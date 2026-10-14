<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_attendances', function (Blueprint $table) {
            $table->foreignId('student_session_id')
                ->nullable()
                ->after('student_id')
                ->constrained('student_sessions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('student_attendances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('student_session_id');
        });
    }
};
