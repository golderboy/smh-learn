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
        Schema::table('users', function (Blueprint $table): void {
            $table->string('root_admin_key', 20)->nullable()->unique();
        });
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_username_digits');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_username_digits CHECK ((root_admin_key IS NOT NULL AND root_admin_key = 'PRIMARY' AND username = 'admins') OR (root_admin_key IS NULL AND CHAR_LENGTH(username) BETWEEN 1 AND 15 AND username NOT REGEXP '[^0-9]'))");
        Schema::create('master_imports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('token')->unique();
            $table->string('kind', 30);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('file_name', 255);
            $table->string('sha256', 64);
            $table->longText('rows'); // Encrypted cast: identifiers and contact details stay private.
            $table->timestamp('expires_at');
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();
        });
        // Archived questions retain their original codes and historical snapshots.
        DB::statement('ALTER TABLE questions DROP INDEX questions_course_id_code_unique, ADD active_code VARCHAR(50) GENERATED ALWAYS AS (CASE WHEN is_active = 1 THEN code ELSE NULL END) STORED, ADD UNIQUE KEY questions_course_active_code_unique (course_id, active_code)');
    }

    public function down(): void
    {
        if (DB::table('users')->whereNotNull('root_admin_key')->exists()) {
            throw new LogicException('Remove the protected root administrator only through an approved database recovery before rolling back.');
        }
        Schema::dropIfExists('master_imports');
        DB::statement('ALTER TABLE questions DROP INDEX questions_course_active_code_unique, DROP COLUMN active_code, ADD UNIQUE KEY questions_course_id_code_unique (course_id, code)');
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_username_digits');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('root_admin_key'));
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_username_digits CHECK (CHAR_LENGTH(username) BETWEEN 1 AND 15 AND username NOT REGEXP '[^0-9]')");
    }
};
