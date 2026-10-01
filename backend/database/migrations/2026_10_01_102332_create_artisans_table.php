<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Artisan profiles (the storefront, not the account). See ADR-10, section 2.5.
     */
    public function up(): void
    {
        Schema::create('artisans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();

            // Owner account: optional, a field agent can create a profile before the artisan has an account.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('validated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('neighborhood_id')->constrained()->restrictOnDelete();

            $table->string('business_name', 150);
            $table->text('description')->nullable();
            $table->string('phone', 16);
            $table->string('whatsapp_phone', 16)->nullable();
            $table->string('address', 255)->nullable();
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();

            $table->string('status', 20)->default('draft');
            $table->text('status_reason')->nullable();
            $table->timestamp('validated_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // PostgreSQL does not index foreign keys automatically.
            $table->index('neighborhood_id');
            $table->index('created_by');
            $table->index('validated_by');
            // Public search: status first, so it also serves queries on status alone.
            $table->index(['status', 'neighborhood_id']);
        });

        // One active profile per phone number and per owner account (soft-deleted rows are ignored).
        DB::statement('CREATE UNIQUE INDEX artisans_phone_active_unique ON artisans (phone) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX artisans_user_id_active_unique ON artisans (user_id) WHERE deleted_at IS NULL');

        DB::statement("ALTER TABLE artisans ADD CONSTRAINT artisans_status_check CHECK (status IN ('draft', 'pending', 'validated', 'rejected', 'suspended'))");
        DB::statement("ALTER TABLE artisans ADD CONSTRAINT artisans_status_reason_check CHECK (status NOT IN ('rejected', 'suspended') OR (status_reason IS NOT NULL AND length(btrim(status_reason)) > 0))");
        DB::statement("ALTER TABLE artisans ADD CONSTRAINT artisans_validation_trace_check CHECK (status <> 'validated' OR (validated_by IS NOT NULL AND validated_at IS NOT NULL))");
        DB::statement("ALTER TABLE artisans ADD CONSTRAINT artisans_phone_format_check CHECK (phone ~ '^[+]228[0-9]{8}\$')");
        DB::statement("ALTER TABLE artisans ADD CONSTRAINT artisans_whatsapp_format_check CHECK (whatsapp_phone IS NULL OR whatsapp_phone ~ '^[+]228[0-9]{8}\$')");
        DB::statement('ALTER TABLE artisans ADD CONSTRAINT artisans_business_name_check CHECK (length(btrim(business_name)) > 0)');
        // Written explicitly: a CHECK that evaluates to NULL passes, so a lone latitude must be rejected on purpose.
        DB::statement('ALTER TABLE artisans ADD CONSTRAINT artisans_coordinates_check CHECK ((latitude IS NULL AND longitude IS NULL) OR (latitude IS NOT NULL AND longitude IS NOT NULL AND latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180))');
    }

    public function down(): void
    {
        Schema::dropIfExists('artisans');
    }
};
