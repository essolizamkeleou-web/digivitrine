# ADR-14 : API v1, authentification Sanctum, format JSON et limitation de débit

- **Statut** : Validée
- **Date** : 2026-10-02
- **Liée à** : ADR-03 (API REST versionnée), ADR-07 (UUID publics), ADR-12 (rôle unique sur `users`), ADR-13

## 1. Contexte

Les applications Flutter (Android et Windows) communiquent avec le backend par une API REST en JSON. Il faut une authentification adaptée aux applications natives, un format de réponse unique, et une protection contre les abus, dans un contexte de réseaux mobiles où beaucoup d'utilisateurs partagent la même adresse IP.

## 2. Décisions

### 2.1 Authentification : jetons Sanctum

- **Laravel Sanctum** (jetons d'accès personnels, en-tête `Authorization: Bearer`). Pas de session, pas de cookie, pas de JWT maison.
- Un utilisateur peut avoir **plusieurs jetons** (un par appareil : téléphone, ordinateur). La déconnexion ne révoque que le jeton utilisé.
- **Expiration : 90 jours** (`SANCTUM_TOKEN_EXPIRATION`, en minutes, 129 600 par défaut). Compromis entre sécurité et confort pour des utilisateurs peu habitués au numérique.
- Le jeton n'est retourné qu'**une seule fois**, à l'inscription ou à la connexion. Il n'est jamais renvoyé ensuite.

### 2.2 Inscription et identifiants

- Identifiant principal : le **numéro de téléphone togolais**. Accepté sous les formes `90 12 34 56`, `90123456`, `+228 90123456`, `00228 90123456` ; stocké sous la forme `+228XXXXXXXX` (règle RG-01). Les numéros étrangers ne sont pas acceptés pour l'instant, car `artisans.phone` est limité au Togo.
- L'e-mail est facultatif. La base garde `phone` facultatif (comptes du personnel créés autrement) ; **l'API l'exige à l'inscription**, ce qui lève la réserve de l'ADR-12.
- La connexion accepte le téléphone **ou** l'e-mail dans un même champ `login`.
- **L'inscription publique crée toujours un compte `artisan`.** Un champ `role` autre que `artisan` est refusé (422). Les rôles `field_agent`, `moderator` et `admin` ne s'obtiennent que par une action d'un administrateur. Les champs `is_active`, `uuid`, `id`, `email_verified_at` envoyés par le client sont ignorés.
- Mot de passe : 8 à 72 caractères, confirmation obligatoire, aucune règle de composition. Pas de vérification par un service externe de mots de passe compromis.

### 2.3 Format des réponses

- Succès : `{ "data": ... }`. Suppression ou déconnexion : `204` sans corps.
- Erreur : `{ "message": "..." }`. Erreur de validation (422) : `{ "message": "...", "errors": { "champ": ["..."] } }`.
- Les messages sont en français. Toute requête sous `/api/*` reçoit du JSON, même sans l'en-tête `Accept: application/json`.
- **Aucune erreur interne n'est exposée** : exception SQL ou autre, même avec `APP_DEBUG=true`, devient `500` avec un message générique. Le détail reste dans les journaux.
- Les messages sont centralisés dans une classe (`ApiMessages`) pour rester identiques partout et dans les tests.

### 2.4 Réponses publiques : `UserResource`

Un modèle Eloquent n'est jamais retourné directement. `UserResource` n'expose que : `uuid`, `name`, `email`, `phone`, `role`, `is_active`, `email_verified_at`, `created_at`, et le résumé du profil artisan quand il est chargé. L'`id` interne, le mot de passe et le `remember_token` n'en font jamais partie.

### 2.5 Contrôle d'accès

- `auth:sanctum` pour l'authentification.
- Middleware **`active`** : un compte désactivé est refusé (403) **même avec un jeton encore valide**.
- Middleware **`role:admin,moderator`** : contrôle côté serveur du rôle. Préparé et testé, utilisé par les futures routes d'administration. Un nom de rôle inconnu n'autorise personne.
- Aucune confiance accordée au rôle envoyé par le client.

### 2.5 bis Connexion : message unique

Un mauvais mot de passe et un compte inexistant renvoient la **même** réponse (401, « Identifiants invalides. »), avec un temps de calcul équivalent (une vérification de hachage factice quand le compte n'existe pas). Un compte désactivé n'est signalé (403) qu'**après** une authentification réussie.

### 2.6 Limitation de débit

| Limiteur | Règle | Clé |
|---|---|---|
| `auth-login` | 5 par minute, et 60 par minute | identifiant normalisé **+** IP, puis IP seule |
| `auth-register` | 10 par minute, 50 par heure | IP |
| `api` (routes protégées) | 120 par minute | identifiant de l'utilisateur |
| `public-api` (routes publiques) | 240 par minute | IP |

Raison de la clé « identifiant + IP » pour la connexion : sur les réseaux mobiles, de nombreux utilisateurs partagent la même adresse IP publique. Une limite par IP seule bloquerait des utilisateurs légitimes ; une limite par compte freine le devinage de mot de passe. Le plafond par IP reste élevé pour cette raison. L'identifiant est normalisé (`90 12 34 56` et `+22890123456` partagent le même compteur). En production derrière un proxy, il faudra configurer les proxys de confiance pour que l'IP lue soit celle du client.

### 2.7 Détails d'implémentation

- **Pas de redirection vers une page de connexion.** Laravel redirige par défaut un visiteur non authentifié vers `route('login')`. Ce projet n'a pas de page de connexion web : pour les requêtes `/api/*`, la règle `redirectGuestsTo` renvoie `null`, et le gestionnaire d'exceptions répond `401` en JSON, **même sans l'en-tête `Accept: application/json`**. Aucune route `login` n'est créée.
- **Enregistrement du provider des limiteurs de débit.** `ApiServiceProvider` est déclaré dans `bootstrap/app.php` (`withProviders`), qui l'ajoute aux providers de `bootstrap/providers.php` sans modifier ce fichier.
- **Table des jetons.** Elle vient de la migration publiée par Sanctum (`personal_access_tokens`), sans clé étrangère (relation polymorphe).

## 3. Conséquences

- Les routes `/api/v1/auth/register`, `login`, `logout`, `me` et `/api/v1/health` sont disponibles ; les autres domaines (`artisans`, `categories`, `cities`, `neighborhoods`, `photos`, `services`, `reports`, `favorites`, `reviews`) arrivent par groupes de routes séparés.
- Limite connue : l'inscription révèle qu'un numéro ou un e-mail est déjà utilisé (422). C'est inévitable pour guider l'utilisateur ; la limitation de débit freine l'exploitation.
- Pas de vérification du numéro par SMS (hors périmètre du MVP) ni de réinitialisation du mot de passe : à traiter dans une phase dédiée.
- Les jetons expirés devront être purgés par une tâche planifiée (`sanctum:prune-expired`), à prévoir au déploiement.

## 4. Alternatives écartées

| Alternative | Raison du rejet |
|---|---|
| JWT maison | Risque d'erreurs de sécurité ; Sanctum couvre le besoin et permet la révocation. |
| Session et cookies | Inadapté aux applications natives. |
| Limitation par IP seule | Bloquerait les utilisateurs derrière une même IP mobile. |
| Rôle choisi à l'inscription | Risque d'élévation de privilège. |
| Documentation OpenAPI maintenant | Reportée ; la documentation Markdown suffit tant que l'API est petite. |
