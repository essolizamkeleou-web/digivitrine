<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subcategories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('slug', 120);
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Also serves as the index on category_id (leading column).
            $table->unique(['category_id', 'slug']);

            // Target of the composite foreign key of artisan_categories (ADR-10, section 2.6).
            $table->unique(['id', 'category_id'], 'subcategories_id_category_id_unique');
        });

        DB::statement("ALTER TABLE subcategories ADD CONSTRAINT subcategories_slug_format_check CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*\$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('subcategories');
    }
};
