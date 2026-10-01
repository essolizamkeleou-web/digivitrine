<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Artisan <-> category link with an optional subcategory (ADR-10, section 2.6).
     */
    public function up(): void
    {
        Schema::create('artisan_categories', function (Blueprint $table) {
            $table->foreignId('artisan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('subcategory_id')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->primary(['artisan_id', 'category_id']);
            $table->index('category_id');
            $table->index('subcategory_id');

            // The subcategory must belong to the given category.
            // Not enforced when subcategory_id is NULL.
            $table->foreign(['subcategory_id', 'category_id'])
                ->references(['id', 'category_id'])
                ->on('subcategories')
                ->restrictOnDelete();
        });

        // At most one primary category per artisan.
        DB::statement('CREATE UNIQUE INDEX artisan_categories_primary_unique ON artisan_categories (artisan_id) WHERE is_primary');
    }

    public function down(): void
    {
        Schema::dropIfExists('artisan_categories');
    }
};
