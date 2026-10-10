<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_sessions', fn (Blueprint $table) => $table->timestamp('points_activated_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('course_sessions', fn (Blueprint $table) => $table->dropColumn('points_activated_at'));
    }
};
