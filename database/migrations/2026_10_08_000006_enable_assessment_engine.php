<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table): void {
            $table->boolean('randomize_choices')->default(false);
            $table->unsignedSmallInteger('question_count')->nullable();
        });
        Schema::table('exam_attempts', function (Blueprint $table): void {
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('revision')->default(0);
        });
        Schema::table('course_enrollments', function (Blueprint $table): void {
            $table->timestamp('posttest_completed_at')->nullable();
        });
        Schema::create('lesson_document_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lesson_id')->constrained('lessons')->restrictOnDelete();
            $table->foreignId('content_id')->constrained('lesson_contents')->restrictOnDelete();
            $table->unsignedInteger('sort_order')->default(1);
            $table->timestamps();
            $table->unique(['lesson_id', 'content_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_document_links');
        Schema::table('course_enrollments', fn (Blueprint $table) => $table->dropColumn('posttest_completed_at'));
        Schema::table('exam_attempts', fn (Blueprint $table) => $table->dropColumn(['expires_at', 'revision']));
        Schema::table('exams', fn (Blueprint $table) => $table->dropColumn(['randomize_choices', 'question_count']));
    }
};
