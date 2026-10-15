<?php

namespace App\Support;

/**
 * User-facing API messages (French). Centralised so that every endpoint, the exception handler
 * and the tests use the same wording. Accents are written as \u{..} escapes: this file stays pure ASCII.
 */
final class ApiMessages
{
    public const INVALID_DATA = "Les donn\u{E9}es envoy\u{E9}es sont invalides.";

    public const UNAUTHENTICATED = 'Authentification requise.';

    public const FORBIDDEN = "Action non autoris\u{E9}e.";

    public const NOT_FOUND = 'Ressource introuvable.';

    public const METHOD_NOT_ALLOWED = "M\u{E9}thode non autoris\u{E9}e.";

    public const TOO_MANY_REQUESTS = "Trop de requ\u{EA}tes. R\u{E9}essayez plus tard.";

    public const BAD_REQUEST = "Requ\u{EA}te invalide.";

    public const SERVER_ERROR = 'Erreur interne du serveur.';

    public const INVALID_CREDENTIALS = 'Identifiants invalides.';

    public const ACCOUNT_DISABLED = "Ce compte est d\u{E9}sactiv\u{E9}.";

    public const ALREADY_USED = "Ce num\u{E9}ro de t\u{E9}l\u{E9}phone ou cet e-mail est d\u{E9}j\u{E0} utilis\u{E9}.";

    public const PROFILE_EXISTS = "Vous avez d\u{E9}j\u{E0} un profil artisan.";

    public const STATUS_CONFLICT = "Ce profil ne peut pas \u{EA}tre soumis dans son \u{E9}tat actuel.";
}
