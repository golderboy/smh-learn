<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained('course_categories')->restrictOnDelete();
            $table->string('code', 50)->unique();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('course_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('version_name', 150);
            $table->text('description')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['course_id', 'version_number']);
        });
        Schema::create('course_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_version_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->unsignedSmallInteger('fiscal_year');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('enrollment_mode', 20)->default('optional');
            $table->boolean('is_mandatory')->default(false);
            $table->decimal('passing_score', 5, 2)->default(80);
            $table->unsignedSmallInteger('max_attempts')->default(1);
            $table->decimal('required_progress_percent', 5, 2)->default(100);
            $table->decimal('required_video_watch_percent', 5, 2)->default(80);
            $table->boolean('pretest_required')->default(false);
            $table->boolean('posttest_required')->default(false);
            $table->boolean('points_enabled')->default(false);
            $table->boolean('certificate_enabled')->default(false);
            $table->string('status', 20)->default('draft');
            $table->timestamps();
            $table->index(['status', 'start_date', 'end_date']);
        });
        Schema::create('course_modules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_version_id')->constrained()->restrictOnDelete();
            $table->string('title', 200);
            $table->unsignedInteger('sort_order')->default(1);
            $table->timestamps();
        });
        Schema::create('lessons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('module_id')->constrained('course_modules')->restrictOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(1);
            $table->boolean('is_required')->default(true);
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });
        Schema::create('lesson_contents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lesson_id')->constrained()->restrictOnDelete();
            $table->string('title', 255);
            $table->string('content_type', 20);
            $table->string('storage_path', 255)->nullable();
            $table->string('original_name', 255)->nullable();
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->longText('body')->nullable();
            $table->text('external_url')->nullable();
            $table->boolean('is_required')->default(true);
            $table->unsignedInteger('sort_order')->default(1);
            $table->timestamps();
        });
        Schema::create('course_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_session_id')->constrained()->restrictOnDelete();
            $table->string('assignment_type', 20);
            $table->unsignedBigInteger('target_id')->default(0);
            $table->date('due_date')->nullable();
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['course_session_id', 'assignment_type', 'target_id'], 'assignments_session_target_idx');
        });
        Schema::create('course_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('assignment_id')->nullable()->constrained('course_assignments')->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('position_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('department_name', 150)->nullable();
            $table->string('position_name', 150)->nullable();
            $table->string('state', 32)->default('NOT_STARTED');
            $table->decimal('progress_percent', 5, 2)->default(0);
            $table->date('due_date')->nullable();
            $table->timestamp('enrolled_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('content_completed_at')->nullable();
            $table->timestamp('pretest_completed_at')->nullable();
            $table->timestamp('posttest_passed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique(['course_session_id', 'user_id']);
            $table->index(['user_id', 'state']);
        });
        Schema::create('learning_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('enrollment_id')->constrained('course_enrollments')->restrictOnDelete();
            $table->foreignId('content_id')->constrained('lesson_contents')->restrictOnDelete();
            $table->decimal('last_position_seconds', 10, 2)->default(0);
            $table->decimal('watched_seconds', 10, 2)->default(0);
            $table->decimal('watch_percent', 5, 2)->default(0);
            $table->json('watched_ranges')->nullable();
            $table->uuid('playback_key')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['enrollment_id', 'content_id']);
        });
        Schema::create('question_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->timestamps();
            $table->unique(['course_id', 'name']);
        });
        Schema::create('questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('question_categories')->restrictOnDelete();
            $table->string('code', 50);
            $table->string('question_type', 32)->default('SINGLE_CHOICE');
            $table->string('usage', 10);
            $table->text('question_text');
            $table->text('explanation')->nullable();
            $table->decimal('max_score', 8, 2);
            $table->string('difficulty', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['course_id', 'code']);
        });
        Schema::create('exams', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_version_id')->constrained()->restrictOnDelete();
            $table->string('exam_type', 10);
            $table->string('status', 20)->default('draft');
            $table->string('grading_mode', 32)->nullable();
            $table->unsignedSmallInteger('time_limit_minutes')->nullable();
            $table->boolean('randomize_questions')->default(false);
            $table->string('result_policy', 10)->nullable();
            $table->string('show_answer_policy', 20)->default('NEVER');
            $table->timestamps();
            $table->unique(['course_version_id', 'exam_type']);
        });
        Schema::create('exam_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exam_id')->constrained()->restrictOnDelete();
            $table->foreignId('question_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('sort_order');
            $table->json('question_snapshot');
            $table->unique(['exam_id', 'question_id']);
        });
        Schema::create('question_imports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('token')->unique();
            $table->foreignId('course_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('file_name', 255);
            $table->string('sha256', 64);
            $table->json('rows');
            $table->timestamp('expires_at');
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();
            $table->unique(['course_version_id', 'sha256']);
        });
        Schema::create('exam_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exam_id')->constrained()->restrictOnDelete();
            $table->foreignId('enrollment_id')->constrained('course_enrollments')->restrictOnDelete();
            $table->unsignedSmallInteger('attempt_number');
            $table->string('status', 32);
            $table->timestamp('started_at');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('graded_at')->nullable();
            $table->foreignId('graded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->decimal('score', 10, 2)->nullable();
            $table->decimal('max_score', 10, 2)->nullable();
            $table->decimal('percentage', 5, 2)->nullable();
            $table->boolean('passed')->nullable();
            $table->json('rules_snapshot');
            $table->timestamps();
            $table->unique(['exam_id', 'enrollment_id', 'attempt_number']);
        });
        Schema::create('exam_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('attempt_id')->constrained('exam_attempts')->restrictOnDelete();
            $table->foreignId('question_id')->constrained()->restrictOnDelete();
            $table->json('question_snapshot');
            $table->text('answer_text')->nullable();
            $table->decimal('score_awarded', 8, 2)->nullable();
            $table->text('grading_feedback')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();
            $table->unique(['attempt_id', 'question_id']);
        });
    }

    public function down(): void
    {
        foreach (['exam_answers', 'exam_attempts', 'question_imports', 'exam_questions', 'exams', 'questions', 'question_categories', 'learning_progress', 'course_enrollments', 'course_assignments', 'lesson_contents', 'lessons', 'course_modules', 'course_sessions', 'course_versions', 'courses'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
