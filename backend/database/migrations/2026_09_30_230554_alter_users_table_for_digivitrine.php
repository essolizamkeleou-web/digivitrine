<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adapts the default Laravel users table to DigiVitrine (see ADR-10 and ADR-12).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->string('phone', 16)->nullable()->unique();
            $table->string('role', 20)->default('artisan');
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('artisan', 'field_agent', 'moderator', 'admin'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_phone_e164_check CHECK (phone IS NULL OR phone ~ '^[+][1-9][0-9]{7,14}\$')");
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_identity_check CHECK (email IS NOT NULL OR phone IS NOT NULL)');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_email_lowercase_check CHECK (email IS NULL OR email = lower(email))');
    }

    public function down(): void
    {
        foreach (['users_role_check', 'users_phone_e164_check', 'users_identity_check', 'users_email_lowercase_check'] as $constraint) {
            DB::statement("ALTER TABLE users DROP CONSTRAINT IF EXISTS {$constraint}");
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn(['uuid', 'phone', 'role', 'is_active']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });
    }
};
