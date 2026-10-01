<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reports against an artisan profile (ADR-10 section 2.9, ADR-13).
     */
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->foreignId('artisan_id')->constrained()->cascadeOnDelete();
            // Anonymous reports are allowed, and a report outlives the account that filed it.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 30);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('open');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('artisan_id');
            $table->index('user_id');
            $table->index('resolved_by');
            // Moderation queue: oldest open reports first.
            $table->index(['status', 'created_at']);
        });

        DB::statement("ALTER TABLE reports ADD CONSTRAINT reports_reason_check CHECK (reason IN ('false_information', 'inappropriate_content', 'duplicate_profile', 'wrong_contact', 'other'))");
        DB::statement("ALTER TABLE reports ADD CONSTRAINT reports_status_check CHECK (status IN ('open', 'resolved', 'dismissed'))");
        DB::statement('ALTER TABLE reports ADD CONSTRAINT reports_description_check CHECK (description IS NULL OR length(btrim(description)) > 0)');
        DB::statement("ALTER TABLE reports ADD CONSTRAINT reports_other_requires_description_check CHECK (reason <> 'other' OR description IS NOT NULL)");
        // resolved_by is not required for a closed report: the FK sets it to NULL if the moderator account is deleted.
        DB::statement("ALTER TABLE reports ADD CONSTRAINT reports_resolution_check CHECK ((status = 'open' AND resolved_at IS NULL AND resolved_by IS NULL) OR (status <> 'open' AND resolved_at IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
