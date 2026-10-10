<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_contents', function (Blueprint $table): void {
            $table->string('video_source', 20)->nullable();
            $table->string('video_id', 11)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lesson_contents', fn (Blueprint $table) => $table->dropColumn(['video_source', 'video_id']));
    }
};
