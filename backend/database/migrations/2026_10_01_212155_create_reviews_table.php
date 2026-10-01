<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Artisan reviews (ADR-10 section 2.9, ADR-13). Structure only: reviews stay disabled in the product until V3.
     * "An artisan cannot review their own profile" is enforced by the application (it spans several tables).
     */
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->foreignId('artisan_id')->constrained()->cascadeOnDelete();
            // The review outlives its author's account (anonymised). NULLs do not collide in the unique index below.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('rating');
            $table->text('comment')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamps();

            // One review per user and artisan (also serves queries on artisan_id).
            $table->unique(['artisan_id', 'user_id']);
            $table->index(['artisan_id', 'status']);
            $table->index('user_id');
        });

        DB::statement('ALTER TABLE reviews ADD CONSTRAINT reviews_rating_check CHECK (rating BETWEEN 1 AND 5)');
        DB::statement("ALTER TABLE reviews ADD CONSTRAINT reviews_status_check CHECK (status IN ('pending', 'published', 'hidden'))");
        DB::statement('ALTER TABLE reviews ADD CONSTRAINT reviews_comment_check CHECK (comment IS NULL OR length(btrim(comment)) > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
