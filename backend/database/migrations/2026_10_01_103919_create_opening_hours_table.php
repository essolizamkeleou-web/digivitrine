<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per time slot (a lunch break means two rows for the same day).
     * Day of week follows ISO 8601: 1 = Monday ... 7 = Sunday.
     * Slots that cross midnight and overlapping slots are not handled in this version.
     */
    public function up(): void
    {
        Schema::create('opening_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artisan_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->boolean('is_closed')->default(false);
            $table->timestamps();

            // Also serves as the index on artisan_id (leading column).
            $table->unique(['artisan_id', 'day_of_week', 'opens_at']);
        });

        // NULL opening times are distinct for a unique index, so closed days need their own rule.
        DB::statement('CREATE UNIQUE INDEX opening_hours_closed_day_unique ON opening_hours (artisan_id, day_of_week) WHERE is_closed');

        DB::statement('ALTER TABLE opening_hours ADD CONSTRAINT opening_hours_day_check CHECK (day_of_week BETWEEN 1 AND 7)');
        DB::statement('ALTER TABLE opening_hours ADD CONSTRAINT opening_hours_times_check CHECK ((is_closed AND opens_at IS NULL AND closes_at IS NULL) OR (NOT is_closed AND opens_at IS NOT NULL AND closes_at IS NOT NULL AND closes_at > opens_at))');
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_hours');
    }
};
