<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Geographic reference of Greater Lome (ADR-16, "A-lite"): communes, and details on neighborhoods.
 * Additive only: nothing existing is modified or removed, commune_id stays nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('slug', 120);
            $table->string('prefecture', 60);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['city_id', 'slug']);
            // Target of the composite foreign key of neighborhoods: a commune must belong to the city of its neighborhoods.
            $table->unique(['id', 'city_id']);
        });

        DB::statement("ALTER TABLE communes ADD CONSTRAINT communes_slug_format_check CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*\$')");

        Schema::table('neighborhoods', function (Blueprint $table) {
            $table->unsignedBigInteger('commune_id')->nullable();
            $table->string('kind', 20)->default('non_precise');
            $table->string('verification', 20)->default('a_verifier');
            $table->string('source_ref', 255)->nullable();

            $table->index('commune_id');
        });

        DB::statement('ALTER TABLE neighborhoods ADD CONSTRAINT neighborhoods_commune_city_foreign FOREIGN KEY (commune_id, city_id) REFERENCES communes (id, city_id) ON DELETE RESTRICT');
        DB::statement("ALTER TABLE neighborhoods ADD CONSTRAINT neighborhoods_kind_check CHECK (kind IN ('quartier', 'village', 'localite', 'non_precise'))");
        DB::statement("ALTER TABLE neighborhoods ADD CONSTRAINT neighborhoods_verification_check CHECK (verification IN ('a_verifier', 'verifie'))");

        Schema::create('neighborhood_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('neighborhood_id')->constrained()->cascadeOnDelete();
            $table->string('alias', 120);
            $table->string('alias_key', 120);
            $table->timestamps();

            $table->unique(['neighborhood_id', 'alias_key']);
            $table->index('alias_key');
        });

        DB::statement("ALTER TABLE neighborhood_aliases ADD CONSTRAINT neighborhood_aliases_key_format_check CHECK (alias_key ~ '^[a-z0-9]+\$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('neighborhood_aliases');

        DB::statement('ALTER TABLE neighborhoods DROP CONSTRAINT IF EXISTS neighborhoods_commune_city_foreign');
        DB::statement('ALTER TABLE neighborhoods DROP CONSTRAINT IF EXISTS neighborhoods_kind_check');
        DB::statement('ALTER TABLE neighborhoods DROP CONSTRAINT IF EXISTS neighborhoods_verification_check');

        Schema::table('neighborhoods', function (Blueprint $table) {
            $table->dropColumn(['commune_id', 'kind', 'verification', 'source_ref']);
        });

        Schema::dropIfExists('communes');
    }
};
