<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Interaction events on a profile (ADR-10 section 2.10, ADR-13). Append-only table that may grow large.
     * metadata must never contain personal data (no IP address, no phone number).
     */
    public function up(): void
    {
        Schema::create('profile_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artisan_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 30);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Statistics per artisan, event type and period (also serves queries on artisan_id).
            $table->index(['artisan_id', 'event_type', 'created_at']);
        });

        // Tiny index suited to an append-only table: range scans and purging of old events.
        DB::statement('CREATE INDEX profile_events_created_at_brin ON profile_events USING brin (created_at)');
        // Lets a user deletion find its events without scanning the whole table.
        DB::statement('CREATE INDEX profile_events_user_id_index ON profile_events (user_id) WHERE user_id IS NOT NULL');

        DB::statement("ALTER TABLE profile_events ADD CONSTRAINT profile_events_type_check CHECK (event_type IN ('profile_view', 'phone_click', 'whatsapp_click', 'share_click', 'direction_click', 'favorite_add'))");
        DB::statement("ALTER TABLE profile_events ADD CONSTRAINT profile_events_metadata_check CHECK (metadata IS NULL OR jsonb_typeof(metadata) = 'object')");
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_events');
    }
};
