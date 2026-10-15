<?php

namespace App\Services;

use App\Enums\ArtisanStatus;
use App\Enums\UserRole;
use App\Models\Artisan;
use App\Models\User;
use App\Support\ApiMessages;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ArtisanService
{
    private const FIELDS = ['business_name', 'description', 'phone', 'whatsapp_phone', 'address', 'latitude', 'longitude'];

    public function __construct(private CatalogueResolver $catalogue) {}

    public function ownsAProfile(User $user): bool
    {
        return Artisan::query()->where('user_id', $user->id)->exists();
    }

    /**
     * Creates a draft. An artisan owns the profile; any other role creates an unowned one,
     * to be claimed later by the artisan (RF-17).
     *
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data): Artisan
    {
        $resolved = $this->catalogue->resolve($data);
        $artisan = new Artisan;

        try {
            DB::transaction(function () use ($actor, $data, $resolved, $artisan): void {
                $artisan->fill(Arr::only($data, self::FIELDS));
                $artisan->neighborhood_id = $resolved['neighborhood']->id;
                $artisan->forceFill([
                    'user_id' => $actor->role === UserRole::Artisan ? $actor->id : null,
                    'created_by' => $actor->id,
                    'status' => ArtisanStatus::Draft,
                ]);
                $artisan->save();

                $artisan->categories()->attach($resolved['category']->id, [
                    'subcategory_id' => $resolved['subcategory']?->id,
                    'is_primary' => true,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['phone' => [ApiMessages::ALREADY_USED]]);
        }

        return $artisan->refresh();
    }

    /**
     * Applies the changes. Only the allow-listed fields are taken; the status never comes from the client.
     * Changing the business name or the category of a validated profile sends it back to review (RF-18),
     * unless a staff member does it.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, Artisan $artisan, array $data): Artisan
    {
        $resolved = $this->catalogue->resolve($data);

        try {
            DB::transaction(function () use ($actor, $artisan, $data, $resolved): void {
                $artisan->fill(Arr::only($data, self::FIELDS));

                if ($resolved['neighborhood'] !== null) {
                    $artisan->neighborhood_id = $resolved['neighborhood']->id;
                }

                $categoryChanged = false;

                if ($resolved['category'] !== null) {
                    $current = $artisan->categories()->wherePivot('is_primary', true)->first();
                    $categoryChanged = $current === null || (int) $current->id !== (int) $resolved['category']->id;
                }

                $sensitiveChange = $artisan->isDirty('business_name') || $categoryChanged;

                if (! $this->isStaff($actor) && $artisan->status === ArtisanStatus::Validated && $sensitiveChange) {
                    $artisan->forceFill([
                        'status' => ArtisanStatus::Pending,
                        'validated_by' => null,
                        'validated_at' => null,
                    ]);
                }

                $artisan->save();

                if ($resolved['category'] !== null) {
                    $artisan->categories()->sync([
                        $resolved['category']->id => [
                            'subcategory_id' => $resolved['subcategory']?->id,
                            'is_primary' => true,
                        ],
                    ]);
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['phone' => [ApiMessages::ALREADY_USED]]);
        }

        return $artisan->refresh();
    }

    /**
     * Sends a draft or a rejected profile to review.
     */
    public function submit(Artisan $artisan): Artisan
    {
        $artisan->forceFill(['status' => ArtisanStatus::Pending, 'status_reason' => null])->save();

        return $artisan->refresh();
    }

    private function isStaff(User $user): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Moderator], true);
    }
}
