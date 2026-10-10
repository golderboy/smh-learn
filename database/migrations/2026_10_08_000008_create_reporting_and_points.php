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
        Schema::create('point_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 150);
            $table->unsignedInteger('points');
            $table->decimal('threshold_percent', 5, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        foreach ([['COMPLETE_COURSE', 'จบหลักสูตร', 20, null], ['PASS_POSTTEST', 'ผ่าน Posttest', 30, null], ['HIGH_SCORE', 'คะแนน Posttest สูง', 10, 90]] as [$code, $name, $points, $threshold]) {
            DB::table('point_rules')->insert(['code' => $code, 'name' => $name, 'points' => $points, 'threshold_percent' => $threshold, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        Schema::table('course_sessions', fn (Blueprint $table) => $table->json('point_rules_snapshot')->nullable());
        Schema::create('point_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('course_enrollments')->restrictOnDelete();
            $table->string('transaction_type', 32);
            $table->string('reference_type', 32);
            $table->unsignedBigInteger('reference_id');
            $table->string('idempotency_key', 100)->unique();
            $table->integer('points');
            $table->string('description', 1000);
            $table->json('rule_snapshot')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->index(['user_id', 'created_at']);
            $table->unique(['enrollment_id', 'transaction_type']);
        });
        Schema::create('report_exports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('token')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('request_key');
            $table->string('report_type', 20);
            $table->string('format', 5);
            $table->json('filters');
            $table->string('scope_fingerprint', 64);
            $table->string('status', 20)->default('QUEUED');
            $table->string('storage_path')->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->string('error_message', 255)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['user_id', 'request_key']);
        });
        // V1 reward schema is prepared; no redemption route/feature is enabled in Phase 6.
        Schema::create('rewards', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->unsignedInteger('required_points');
            $table->unsignedInteger('inventory')->default(0);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
        Schema::create('reward_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('reward_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('points_used');
            $table->string('status', 20)->default('REQUESTED');
            $table->timestamp('requested_at');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
        });
        $permissions = [
            'reports.view.all' => ['ดูรายงานทั้งองค์กร', 'รายงาน', 'administrator'],
            'reports.view.assigned' => ['ดูรายงานหมวดที่ควบคุม', 'รายงาน', 'instructor'],
            'reports.view.department' => ['ดูรายงานแผนกของตน', 'รายงาน', 'department-manager'],
            'points.manage' => ['กำหนดกฎและปรับแต้ม', 'แต้ม', 'administrator'],
        ];
        foreach ($permissions as $code => [$name, $module, $roleCode]) {
            $id = DB::table('permissions')->insertGetId(['code' => $code, 'name' => $name, 'module' => $module, 'created_at' => now(), 'updated_at' => now()]);
            $roleIds = DB::table('roles')->whereIn('code', array_unique(['administrator', $roleCode]))->pluck('id');
            foreach ($roleIds as $roleId) {
                DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $id]);
            }
        }
    }

    public function down(): void
    {
        foreach (['reward_redemptions', 'rewards', 'report_exports', 'point_transactions', 'point_rules'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('course_sessions', fn (Blueprint $table) => $table->dropColumn('point_rules_snapshot'));
        $ids = DB::table('permissions')->whereIn('code', ['reports.view.all', 'reports.view.assigned', 'reports.view.department', 'points.manage'])->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
