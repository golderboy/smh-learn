<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('questions')->where('question_type', 'ESSAY')->exists()) {
            throw new RuntimeException('Existing essay questions require an explicit conversion before this migration.');
        }
        Schema::table('questions', function (Blueprint $table): void {
            $table->string('question_type', 32)->default('SINGLE_CHOICE')->change();
        });
        if (Schema::hasColumn('questions', 'model_answer')) {
            Schema::table('questions', fn (Blueprint $table) => $table->dropColumn('model_answer'));
        }
        Schema::create('question_choices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('question_id')->constrained()->restrictOnDelete();
            $table->string('choice_key', 1);
            $table->text('choice_text');
            $table->boolean('is_correct')->default(false);
            $table->unsignedSmallInteger('sort_order');
            $table->timestamps();
            $table->unique(['question_id', 'choice_key']);
        });
        Schema::table('exam_answers', function (Blueprint $table): void {
            $table->string('selected_answer', 1)->nullable();
            $table->boolean('is_correct')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('exam_answers', fn (Blueprint $table) => $table->dropColumn(['selected_answer', 'is_correct']));
        Schema::dropIfExists('question_choices');
    }
};
