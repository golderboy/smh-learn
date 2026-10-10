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
        foreach (['departments', 'positions'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->id();
                $table->string('code', 50)->unique();
                $table->string('name', 150);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('employee_code', 50)->unique();
            $table->string('username', 15)->unique();
            $table->string('password_hash');
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('display_name', 210);
            $table->string('email')->nullable()->unique();
            $table->string('phone', 30)->nullable();
            $table->foreignId('department_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('position_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('employment_status', 100)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_demo')->default(false);
            $table->boolean('must_change_password')->default(true);
            $table->unsignedInteger('session_version')->default(1);
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_username_digits CHECK (CHAR_LENGTH(username) BETWEEN 1 AND 15 AND username NOT REGEXP '[^0-9]')");

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
        Schema::dropIfExists('positions');
        Schema::dropIfExists('departments');
    }
};
