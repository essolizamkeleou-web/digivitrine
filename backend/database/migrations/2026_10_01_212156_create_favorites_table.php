<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Profiles saved by users (ADR-13). Surrogate key so that Eloquent can delete a row.
     */
    public function up(): void
    {
        Schema::create('favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('artisan_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // One favorite per user and artisan (also serves queries on user_id).
            $table->unique(['user_id', 'artisan_id']);
            $table->index('artisan_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favorites');
    }
};
