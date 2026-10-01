<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Photo metadata only: the files live on a storage disk (ADR-05), never in PostgreSQL.
     * The 6-photo limit (RG-06) is enforced by the application.
     */
    public function up(): void
    {
        Schema::create('photos', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->foreignId('artisan_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 30);
            $table->string('path', 500);
            $table->string('original_name', 255)->nullable();
            $table->string('mime_type', 50);
            $table->unsignedInteger('size_bytes');
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['disk', 'path']);
            $table->index(['artisan_id', 'position']);
        });

        // At most one primary photo per artisan.
        DB::statement('CREATE UNIQUE INDEX photos_primary_unique ON photos (artisan_id) WHERE is_primary');

        DB::statement('ALTER TABLE photos ADD CONSTRAINT photos_path_check CHECK (length(btrim(path)) > 0)');
        DB::statement("ALTER TABLE photos ADD CONSTRAINT photos_mime_type_check CHECK (mime_type IN ('image/jpeg', 'image/png', 'image/webp'))");
        DB::statement('ALTER TABLE photos ADD CONSTRAINT photos_size_check CHECK (size_bytes > 0 AND size_bytes <= 5242880)');
        DB::statement('ALTER TABLE photos ADD CONSTRAINT photos_dimensions_check CHECK ((width IS NULL OR width > 0) AND (height IS NULL OR height > 0))');
    }

    public function down(): void
    {
        Schema::dropIfExists('photos');
    }
};
