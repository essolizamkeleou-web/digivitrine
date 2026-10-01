<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 120);
            $table->char('country_code', 2)->default('TG');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['country_code', 'slug']);
        });

        DB::statement("ALTER TABLE cities ADD CONSTRAINT cities_slug_format_check CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*\$')");
        DB::statement("ALTER TABLE cities ADD CONSTRAINT cities_country_code_check CHECK (country_code ~ '^[A-Z]{2}\$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('cities');
    }
};
