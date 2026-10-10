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
        Schema::create('welfare_stores', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 200);
            $table->string('contact', 250)->nullable();
            $table->string('status', 20)->default('PENDING');
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('welfare_store_users', function (Blueprint $table): void {
            $table->foreignId('welfare_store_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->primary(['welfare_store_id', 'user_id']);
        });
        Schema::table('rewards', function (Blueprint $table): void {
            $table->foreignId('welfare_store_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('discount_satang')->default(0);
            $table->unsignedSmallInteger('valid_days')->default(90);
        });
        Schema::table('reward_redemptions', function (Blueprint $table): void {
            $table->foreignId('welfare_store_id')->nullable()->constrained()->restrictOnDelete();
            $table->uuid('coupon_id')->nullable()->unique();
            $table->uuid('request_key')->nullable();
            $table->string('coupon_hash', 64)->nullable()->unique();
            $table->text('coupon_code')->nullable();
            $table->string('reward_name', 200)->nullable();
            $table->string('store_name', 200)->nullable();
            $table->unsignedInteger('discount_satang')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('receive_request_key')->nullable();
            $table->foreignId('point_transaction_id')->nullable()->unique()->constrained('point_transactions')->restrictOnDelete();
            $table->unique(['user_id', 'request_key']);
            $table->index(['welfare_store_id', 'received_at']);
        });
        Schema::create('welfare_claims', function (Blueprint $table): void {
            $table->id();
            $table->uuid('claim_id')->unique();
            $table->foreignId('welfare_store_id')->constrained()->restrictOnDelete();
            $table->uuid('request_key');
            $table->string('selection_hash', 64);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('organization_name', 150);
            $table->string('store_name', 200);
            $table->unsignedInteger('coupon_count');
            $table->unsignedBigInteger('total_satang');
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->unique(['welfare_store_id', 'request_key']);
        });
        Schema::create('welfare_claim_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('welfare_claim_id')->constrained()->restrictOnDelete();
            $table->foreignId('reward_redemption_id')->unique()->constrained()->restrictOnDelete();
            $table->uuid('coupon_id');
            $table->string('reward_name', 200);
            $table->string('holder_name', 200);
            $table->unsignedInteger('discount_satang');
            $table->timestamp('received_at');
        });
        DB::table('roles')->insertOrIgnore(['code' => 'welfare-shop', 'name' => 'ร้านสวัสดิการ', 'is_system' => true, 'created_at' => now(), 'updated_at' => now()]);
        $role = DB::table('roles')->where('code', 'welfare-shop')->value('id');
        $admin = DB::table('roles')->where('code', 'administrator')->value('id');
        foreach (['welfare.manage' => 'อนุมัติร้านและจัดการคูปอง', 'welfare.receive' => 'รับคูปองของร้านที่ได้รับมอบหมาย', 'welfare.reports' => 'ดูรายงานคูปองของร้านที่ได้รับมอบหมาย', 'welfare.claims' => 'จัดทำใบสรุปส่งเบิกของร้านที่ได้รับมอบหมาย'] as $code => $name) {
            DB::table('permissions')->insertOrIgnore(['code' => $code, 'name' => $name, 'module' => 'สวัสดิการ', 'created_at' => now(), 'updated_at' => now()]);
            $id = DB::table('permissions')->where('code', $code)->value('id');
            if ($admin !== null) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $admin, 'permission_id' => $id]);
            }
            if ($code !== 'welfare.manage') {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => $id]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('welfare_claim_items');
        Schema::dropIfExists('welfare_claims');
        Schema::table('reward_redemptions', function (Blueprint $table): void {
            $table->dropForeign(['welfare_store_id']);
            $table->dropForeign(['received_by']);
            $table->dropForeign(['point_transaction_id']);
            $table->dropUnique(['user_id', 'request_key']);
            $table->dropIndex(['welfare_store_id', 'received_at']);
            $table->dropColumn(['welfare_store_id', 'coupon_id', 'request_key', 'coupon_hash', 'coupon_code', 'reward_name', 'store_name', 'discount_satang', 'expires_at', 'received_by', 'receive_request_key', 'point_transaction_id']);
        });
        Schema::table('rewards', function (Blueprint $table): void {
            $table->dropForeign(['welfare_store_id']);
            $table->dropColumn(['welfare_store_id', 'discount_satang', 'valid_days']);
        });
        Schema::dropIfExists('welfare_store_users');
        Schema::dropIfExists('welfare_stores');
        $ids = DB::table('permissions')->where('module', 'สวัสดิการ')->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        $roles = DB::table('roles')->where('code', 'welfare-shop')->pluck('id');
        DB::table('user_roles')->whereIn('role_id', $roles)->delete();
        DB::table('roles')->whereIn('id', $roles)->delete();
    }
};
