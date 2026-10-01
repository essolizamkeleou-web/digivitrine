<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('neighborhoods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('slug', 120);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Also serves as the index on city_id (leading column).
            $table->unique(['city_id', 'slug']);
        });

        DB::statement("ALTER TABLE neighborhoods ADD CONSTRAINT neighborhoods_slug_format_check CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*\$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('neighborhoods');
    }
};
