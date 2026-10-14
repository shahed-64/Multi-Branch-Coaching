<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('results', function (Blueprint $table) {
            $table->string('class_name_snapshot')->nullable();
            $table->string('section_name_snapshot')->nullable();
            $table->string('shift_name_snapshot')->nullable();
            $table->string('class_group_name_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('results', function (Blueprint $table) {
            $table->dropColumn([
                'class_name_snapshot',
                'section_name_snapshot',
                'shift_name_snapshot',
                'class_group_name_snapshot',
            ]);
        });
    }
};
