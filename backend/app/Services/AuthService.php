<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\ApiMessages;
use App\Support\TogoPhone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthService
{
    /**
     * Hash used to spend the same time when the account does not exist (timing attack mitigation).
     */
    private static ?string $dummyHash = null;

    /**
     * Creates an artisan account. The role is NEVER taken from the client: public sign-ups are always artisans.
     *
     * @param  array<string, mixed>  $data  validated data (name, phone, password, optional email)
     * @return array{user: User, token: string}
     */
    public function register(array $data, ?string $deviceName = null): array
    {
        $user = new User;
        $user->fill(Arr::only($data, ['name', 'email', 'phone', 'password']));
        $user->role = UserRole::Artisan;

        try {
            // A savepoint when already inside a transaction, so a unique violation does not poison it.
            DB::transaction(fn () => $user->save());
        } catch (UniqueConstraintViolationException) {
            // Race between the validation rule and the insert: answer like a normal validation error.
            throw ValidationException::withMessages(['phone' => [ApiMessages::ALREADY_USED]]);
        }

        // Reload so that database defaults (uuid, is_active) are present.
        $user->refresh();

        return ['user' => $user, 'token' => $this->issueToken($user, $deviceName)];
    }

    /**
     * Returns the user when the credentials are valid, null otherwise (same outcome and
     * similar duration whether the account is unknown or the password is wrong).
     */
    public function authenticate(string $login, string $password): ?User
    {
        $user = $this->findByLogin($login);

        $hash = $user?->password ?? (self::$dummyHash ??= Hash::make(Str::random(40)));
        $valid = Hash::check($password, $hash);

        return ($user !== null && $valid) ? $user : null;
    }

    /**
     * Creates a Sanctum token for one device. The plain-text value is only available here.
     */
    public function issueToken(User $user, ?string $deviceName = null): string
    {
        $name = trim((string) $deviceName);
        $name = Str::limit($name !== '' ? $name : 'mobile', 100, '');

        return $user->createToken($name)->plainTextToken;
    }

    private function findByLogin(string $login): ?User
    {
        $login = trim($login);

        if (str_contains($login, '@')) {
            return User::where('email', mb_strtolower($login))->first();
        }

        $phone = TogoPhone::normalize($login);

        return $phone === null ? null : User::where('phone', $phone)->first();
    }
}
