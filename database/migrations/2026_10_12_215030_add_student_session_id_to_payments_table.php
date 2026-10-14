
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('student_session_id')
                ->nullable()
                ->after('student_id')
                ->constrained('student_sessions')
                ->cascadeOnDelete();

            $table->index(
                ['student_session_id', 'month'],
                'payments_session_month_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_session_month_index');
            $table->dropConstrainedForeignId('student_session_id');
        });
    }
};
