<?php

namespace App\Services;

use App\Exceptions\PhotoLimitReached;
use App\Models\Artisan;
use App\Models\Photo;
use App\Support\ImageNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Photo gallery of an artisan profile. Files live on a configurable disk (never in the database);
 * only metadata is stored. The name of a stored file is random, never the one sent by the client.
 */
class PhotoService
{
    public function __construct(private ImageNormalizer $images) {}

    public function disk(): string
    {
        return (string) config('digivitrine.photos_disk');
    }

    /**
     * @throws PhotoLimitReached
     * @throws ValidationException when the file is not a usable image
     */
    public function store(Artisan $artisan, UploadedFile $file): Photo
    {
        $image = $this->images->normalize($file);
        $uuid = (string) Str::uuid();
        $path = "artisans/{$artisan->uuid}/{$uuid}.webp";
        $disk = $this->disk();
        $name = $this->cleanName($file);

        try {
            return DB::transaction(function () use ($artisan, $image, $uuid, $path, $disk, $name): Photo {
                $this->lock($artisan);

                $count = Photo::query()->where('artisan_id', $artisan->id)->count();

                if ($count >= (int) config('digivitrine.photos_max_per_artisan')) {
                    throw new PhotoLimitReached;
                }

                $hasPrimary = Photo::query()->where('artisan_id', $artisan->id)->where('is_primary', true)->exists();
                $position = $count === 0 ? 0 : (int) Photo::query()->where('artisan_id', $artisan->id)->max('position') + 1;

                $photo = new Photo;
                $photo->fill([
                    'disk' => $disk,
                    'path' => $path,
                    'original_name' => $name,
                    'mime_type' => 'image/webp',
                    'size_bytes' => strlen($image['data']),
                    'width' => $image['width'],
                    'height' => $image['height'],
                    'position' => $position,
                ]);
                $photo->forceFill([
                    'uuid' => $uuid,
                    'artisan_id' => $artisan->id,
                    'is_primary' => ! $hasPrimary,
                    'is_active' => true,
                ]);
                $photo->save();

                Storage::disk($disk)->put($path, $image['data']);

                return $photo;
            });
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);

            throw $exception;
        }
    }

    /**
     * Changes the primary photo, the position, or (staff only, enforced by the request) the visibility.
     *
     * @param  array<string, mixed>  $data  validated keys: is_primary (true), position, is_active
     */
    public function update(Artisan $artisan, Photo $photo, array $data): Photo
    {
        return DB::transaction(function () use ($artisan, $photo, $data): Photo {
            $this->lock($artisan);
            $photo->refresh();

            $active = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : (bool) $photo->is_active;
            $wantsPrimary = ! empty($data['is_primary']);

            if ($wantsPrimary && ! $active) {
                throw ValidationException::withMessages([
                    'is_primary' => ["Une photo masqu\u{E9}e ne peut pas \u{EA}tre la photo principale."],
                ]);
            }

            $hidingPrimary = ! $active && $photo->is_primary;

            if (array_key_exists('position', $data)) {
                $photo->position = (int) $data['position'];
            }

            $photo->is_active = $active;

            if ($hidingPrimary) {
                $photo->is_primary = false;
            }

            if ($wantsPrimary) {
                // The database allows a single primary photo per artisan: release the old one first.
                Photo::query()
                    ->where('artisan_id', $artisan->id)
                    ->where('is_primary', true)
                    ->whereKeyNot($photo->id)
                    ->update(['is_primary' => false]);

                $photo->is_primary = true;
            }

            $photo->save();

            if ($hidingPrimary) {
                $this->promoteNext($artisan, $photo->id);
            }

            return $photo->refresh();
        });
    }

    /**
     * Deletes the row and the file. If it was the primary photo, the next active one takes over.
     */
    public function delete(Artisan $artisan, Photo $photo): void
    {
        $disk = $photo->disk;
        $path = $photo->path;

        DB::transaction(function () use ($artisan, $photo): void {
            $this->lock($artisan);
            $photo->refresh();

            $wasPrimary = (bool) $photo->is_primary;
            $photo->delete();

            if ($wasPrimary) {
                $this->promoteNext($artisan, $photo->id);
            }
        });

        Storage::disk($disk)->delete($path);
    }

    /**
     * Serialises concurrent changes to the gallery of one profile.
     */
    private function lock(Artisan $artisan): void
    {
        Artisan::query()->whereKey($artisan->id)->lockForUpdate()->first();
    }

    private function promoteNext(Artisan $artisan, int $exceptId): void
    {
        $next = Photo::query()
            ->where('artisan_id', $artisan->id)
            ->where('is_active', true)
            ->whereKeyNot($exceptId)
            ->orderBy('position')
            ->orderBy('id')
            ->first();

        if ($next !== null) {
            $next->is_primary = true;
            $next->save();
        }
    }

    /**
     * The client file name is kept for reference only: base name, harmless characters, 255 characters.
     */
    private function cleanName(UploadedFile $file): ?string
    {
        $name = basename(str_replace('\\', '/', (string) $file->getClientOriginalName()));
        $name = preg_replace('/[^\pL\pN._ -]/u', '', $name) ?? '';
        $name = Str::limit(trim($name), 255, '');

        return $name === '' ? null : $name;
    }
}
