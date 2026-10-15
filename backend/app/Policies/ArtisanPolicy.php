<?php

namespace App\Policies;

use App\Enums\ArtisanStatus;
use App\Enums\UserRole;
use App\Models\Artisan;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Who can see and change an artisan profile.
 *
 * A profile that is not validated is invisible to strangers: the answer is a 404, never a 403,
 * so that the existence of a draft is not revealed (IDOR).
 */
class ArtisanPolicy
{
    public function view(?User $user, Artisan $artisan): bool|Response
    {
        if ($artisan->status === ArtisanStatus::Validated) {
            return true;
        }

        return $this->viewPrivate($user, $artisan) ? true : Response::denyAsNotFound();
    }

    /**
     * Private fields (status, reason): owner, creator and staff only.
     */
    public function viewPrivate(?User $user, Artisan $artisan): bool
    {
        return $user !== null
            && ($this->isStaff($user) || $this->isOwner($user, $artisan) || $this->isCreator($user, $artisan));
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Artisan $artisan): bool|Response
    {
        $visible = $this->view($user, $artisan);

        if ($visible !== true) {
            return $visible;
        }

        if ($this->isStaff($user)) {
            return true;
        }

        if ($this->manages($user, $artisan) && $artisan->status !== ArtisanStatus::Suspended) {
            return true;
        }

        return Response::deny();
    }

    public function submit(User $user, Artisan $artisan): bool|Response
    {
        $visible = $this->view($user, $artisan);

        if ($visible !== true) {
            return $visible;
        }

        return ($this->isStaff($user) || $this->manages($user, $artisan)) ? true : Response::deny();
    }

    private function isStaff(User $user): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Moderator], true);
    }

    private function isOwner(User $user, Artisan $artisan): bool
    {
        return $artisan->user_id !== null && (int) $artisan->user_id === (int) $user->id;
    }

    private function isCreator(User $user, Artisan $artisan): bool
    {
        return (int) $artisan->created_by === (int) $user->id;
    }

    /**
     * The owner, or the field agent who created a profile that nobody has claimed yet.
     */
    private function manages(User $user, Artisan $artisan): bool
    {
        return $this->isOwner($user, $artisan)
            || ($artisan->user_id === null && $this->isCreator($user, $artisan));
    }
}
