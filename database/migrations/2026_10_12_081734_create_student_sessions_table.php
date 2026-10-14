<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_sessions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('student_id')
                ->constrained('students')
                ->cascadeOnDelete();

            $table->foreignId('academic_session_id')
                ->constrained('academic_sessions')
                ->cascadeOnDelete();

            $table->foreignId('branch_id')
                ->constrained('branches')
                ->cascadeOnDelete();

            $table->foreignId('class_id')
                ->constrained('clss_m_s')
                ->restrictOnDelete();

            $table->foreignId('section_id')
                ->constrained('sections')
                ->restrictOnDelete();

            $table->foreignId('shift_id')
                ->nullable()
                ->constrained('shifts')
                ->nullOnDelete();

            $table->foreignId('class_group_id')
                ->nullable()
                ->constrained('class_groups')
                ->nullOnDelete();

            $table->decimal('monthly_fee', 10, 2)->default(0);

            $table->enum('status', ['active', 'completed', 'transferred'])
                ->default('active');

            $table->timestamps();

            $table->unique(
                ['student_id', 'academic_session_id'],
                'student_session_unique'
            );

            $table->index(['branch_id', 'academic_session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_sessions');
    }
};
