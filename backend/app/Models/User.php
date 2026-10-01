<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

// "role" and "is_active" are deliberately NOT mass assignable (privilege escalation).
#[Fillable(['name', 'email', 'phone', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * In-memory defaults, identical to the database defaults.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => 'artisan',
        'is_active' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * The profile owned by this account (a user has at most one active profile).
     *
     * @return HasOne<Artisan, $this>
     */
    public function artisan(): HasOne
    {
        return $this->hasOne(Artisan::class);
    }

    /**
     * Profiles created by this account (field agents create profiles for artisans).
     *
     * @return HasMany<Artisan, $this>
     */
    public function createdArtisans(): HasMany
    {
        return $this->hasMany(Artisan::class, 'created_by');
    }

    /**
     * Emails are stored trimmed and lowercase; an empty value becomes NULL.
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => ($value === null || trim($value) === '')
                ? null
                : mb_strtolower(trim($value)),
        );
    }
}
