<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\TogoPhone;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Creates a staff account (admin, moderator or field agent).
 *
 * The password is never an option or an argument: it is typed twice, hidden, so that it appears neither
 * in the shell history nor in the process list. Nothing sensitive is written to the logs. The role comes from
 * the existing UserRole enum; no new permission system. Accents are written as \u{..} escapes (ASCII source).
 */
class CreateStaffAccount extends Command
{
    /** Roles that can be created here. The public sign-up only creates artisans. */
    public const ROLES = ['admin', 'moderator', 'field_agent'];

    public const MIN_PASSWORD_LENGTH = 12;

    public const MAX_PASSWORD_BYTES = 72;

    public const ROLE_PROMPT = "R\u{F4}le du compte";

    public const NAME_PROMPT = 'Nom complet';

    public const PHONE_PROMPT = "T\u{E9}l\u{E9}phone (laisser vide s'il n'y en a pas)";

    public const EMAIL_PROMPT = "Adresse e-mail (laisser vide s'il n'y en a pas)";

    public const PASSWORD_PROMPT = "Mot de passe (12 caract\u{E8}res minimum, saisie masqu\u{E9}e)";

    public const CONFIRM_PROMPT = 'Confirmez le mot de passe';

    public const CONFIRM_CREATE = "Cr\u{E9}er ce compte ?";

    protected $signature = 'staff:create
                            {--role= : admin, moderator or field_agent}
                            {--name= : Full name}
                            {--phone= : Togolese phone number, for example 90 12 34 56}
                            {--email= : E-mail address}';

    protected $description = "Cr\u{E9}e un compte du personnel (administrateur, mod\u{E9}rateur ou agent de terrain) ; le mot de passe est saisi de fa\u{E7}on masqu\u{E9}e";

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error("Cette commande est interactive : le mot de passe est saisi de fa\u{E7}on masqu\u{E9}e et ne peut pas \u{EA}tre pass\u{E9} en argument.");

            return self::FAILURE;
        }

        $role = $this->option('role') ?: $this->choice(self::ROLE_PROMPT, self::ROLES, 2);

        if (! in_array($role, self::ROLES, true)) {
            $this->error("Ce r\u{F4}le ne peut pas \u{EA}tre cr\u{E9}\u{E9} ici (admin, moderator ou field_agent). Les artisans s'inscrivent par l'application.");

            return self::FAILURE;
        }

        $name = trim((string) ($this->option('name') ?: $this->ask(self::NAME_PROMPT)));
        $phone = $this->option('phone');
        $email = $this->option('email');

        if (blank($phone) && blank($email)) {
            $phone = $this->ask(self::PHONE_PROMPT);
            $email = $this->ask(self::EMAIL_PROMPT);
        }

        $phone = blank($phone) ? null : (TogoPhone::normalize($phone) ?? trim((string) $phone));
        $email = blank($email) ? null : mb_strtolower(trim((string) $email));

        $errors = $this->validateIdentity($role, $name, $phone, $email);

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        // Both prompts are always shown, then the two answers are compared.
        $password = (string) $this->secret(self::PASSWORD_PROMPT);
        $confirmation = (string) $this->secret(self::CONFIRM_PROMPT);

        $problem = $this->passwordProblem($password, $confirmation);

        if ($problem !== null) {
            $this->error($problem);

            return self::FAILURE;
        }

        $this->table(['Champ', 'Valeur'], [
            ["R\u{F4}le", $role],
            ['Nom', $name],
            ["T\u{E9}l\u{E9}phone", $phone ?? '-'],
            ['E-mail', $email ?? '-'],
        ]);

        if (! $this->confirm(self::CONFIRM_CREATE, false)) {
            $this->warn("Annul\u{E9} : aucun compte n'a \u{E9}t\u{E9} cr\u{E9}\u{E9}.");

            return self::FAILURE;
        }

        try {
            $user = DB::transaction(function () use ($role, $name, $phone, $email, $password): User {
                $user = new User;
                $user->fill(['name' => $name, 'email' => $email, 'phone' => $phone, 'password' => $password]);
                $user->role = UserRole::from($role);
                $user->save();

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            $this->error("Ce num\u{E9}ro ou cette adresse e-mail est d\u{E9}j\u{E0} utilis\u{E9} : aucun compte n'a \u{E9}t\u{E9} cr\u{E9}\u{E9}.");

            return self::FAILURE;
        }

        $user->refresh();

        // Only the public identifier and the role: no password, no personal data.
        Log::info('staff.created', ['user' => $user->uuid, 'role' => $user->role->value]);

        $this->info("Compte cr\u{E9}\u{E9}.");
        $this->line('Identifiant public : '.$user->uuid);
        $this->line('Connexion : POST /api/v1/auth/login avec "login" = '.($phone ?? $email));

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function validateIdentity(string $role, string $name, ?string $phone, ?string $email): array
    {
        $validator = Validator::make(
            ['role' => $role, 'name' => $name, 'phone' => $phone, 'email' => $email],
            [
                'role' => ['required', Rule::in(self::ROLES)],
                'name' => ['required', 'string', 'min:2', 'max:120'],
                'phone' => ['nullable', 'string', 'regex:/^\+228[0-9]{8}$/', Rule::unique('users', 'phone')],
                'email' => ['nullable', 'string', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            ],
            [
                'role.in' => "Ce r\u{F4}le ne peut pas \u{EA}tre cr\u{E9}\u{E9} ici.",
                'name.required' => 'Le nom est obligatoire.',
                'name.min' => "Le nom doit contenir au moins 2 caract\u{E8}res.",
                'name.max' => 'Le nom est trop long.',
                'phone.regex' => "Num\u{E9}ro invalide. Exemple : 90 12 34 56.",
                'phone.unique' => "Ce num\u{E9}ro de t\u{E9}l\u{E9}phone est d\u{E9}j\u{E0} utilis\u{E9}.",
                'email.email' => 'Cette adresse e-mail est invalide.',
                'email.max' => 'Cette adresse e-mail est trop longue.',
                'email.unique' => "Cette adresse e-mail est d\u{E9}j\u{E0} utilis\u{E9}e.",
            ]
        );

        $validator->after(function ($validator) use ($phone, $email): void {
            if ($phone === null && $email === null) {
                $validator->errors()->add('phone', "Un num\u{E9}ro de t\u{E9}l\u{E9}phone ou une adresse e-mail est n\u{E9}cessaire pour se connecter.");
            }
        });

        return $validator->errors()->all();
    }

    private function passwordProblem(string $password, string $confirmation): ?string
    {
        if ($password === '') {
            return 'Le mot de passe est obligatoire.';
        }

        if (! hash_equals($password, $confirmation)) {
            return 'Les deux mots de passe ne correspondent pas.';
        }

        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            return 'Le mot de passe doit contenir au moins '.self::MIN_PASSWORD_LENGTH." caract\u{E8}res.";
        }

        if (strlen($password) > self::MAX_PASSWORD_BYTES) {
            return 'Le mot de passe est trop long ('.self::MAX_PASSWORD_BYTES.' octets maximum).';
        }

        return null;
    }
}
