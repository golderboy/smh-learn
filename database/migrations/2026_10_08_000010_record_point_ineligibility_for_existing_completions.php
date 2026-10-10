<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_enrollments', fn (Blueprint $table) => $table->boolean('points_ineligible')->default(false));
    }

    public function down(): void
    {
        Schema::table('course_enrollments', fn (Blueprint $table) => $table->dropColumn('points_ineligible'));
    }
};
