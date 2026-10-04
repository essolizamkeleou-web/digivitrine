# API : authentification (v1)

URL de base : `/api/v1`. Format : JSON (`Content-Type: application/json`).
Documentation de la décision : `docs/adr/ADR-14-api-v1-authentification-sanctum.md`.

## Principes

- **Authentification par jeton** (Laravel Sanctum). Après l'inscription ou la connexion, envoyer le jeton dans l'en-tête `Authorization: Bearer <jeton>`.
- Un jeton par appareil (téléphone, ordinateur). Il expire après **90 jours** ; l'application doit alors redemander la connexion.
- Le jeton n'est retourné **qu'une seule fois**. Il n'est jamais renvoyé par `/auth/me`.
- Les identifiants internes ne sont jamais exposés : un utilisateur est identifié par son `uuid`.

## Numéros de téléphone (Togo)

Formats acceptés : `90 12 34 56`, `90123456`, `+228 90123456`, `00228 90123456` (les espaces, points et tirets sont ignorés). Le numéro est enregistré et retourné sous la forme `+22890123456`. Les numéros d'autres pays ne sont pas acceptés pour l'instant.

## Format des réponses

Succès : `{ "data": ... }`. Déconnexion : `204` sans corps.

Erreur simple :

```json
{ "message": "Identifiants invalides." }
```

Erreur de validation (`422`) :

```json
{
  "message": "Les données envoyées sont invalides.",
  "errors": {
    "phone": ["Numéro invalide. Exemple : 90 12 34 56."]
  }
}
```

| Code | Signification |
|---|---|
| 200, 201, 204 | Succès |
| 401 | Non authentifié, jeton invalide, expiré ou révoqué, ou identifiants invalides |
| 403 | Compte désactivé, ou rôle insuffisant |
| 404 | Ressource introuvable |
| 405 | Méthode HTTP non autorisée |
| 422 | Données invalides |
| 429 | Trop de requêtes (voir l'en-tête `Retry-After`, en secondes) |
| 500 | Erreur du serveur (message générique, aucun détail technique) |

## Limitation de débit

| Route | Limite |
|---|---|
| `POST /auth/login` | 5 par minute pour un même identifiant depuis une même adresse IP, 60 par minute par adresse IP |
| `POST /auth/register` | 10 par minute et 50 par heure par adresse IP |
| Routes protégées | 120 par minute par utilisateur |
| Routes publiques | 240 par minute par adresse IP |

---

## GET /api/v1/health

Vérifie que l'API répond. **Accès public.**

Réponse `200` :

```json
{ "data": { "status": "ok" } }
```

---

## POST /api/v1/auth/register

Crée un compte **artisan** et retourne un jeton. **Accès public.**

| Paramètre | Type | Obligatoire | Règle |
|---|---|---|---|
| `name` | texte | oui | 2 à 120 caractères |
| `phone` | texte | oui | numéro togolais (voir plus haut), unique |
| `email` | texte | non | adresse valide, unique (insensible à la casse) |
| `password` | texte | oui | 8 à 72 caractères |
| `password_confirmation` | texte | oui | identique à `password` |
| `device_name` | texte | non | nom du jeton (100 caractères maximum), `mobile` par défaut |
| `role` | texte | non | seule la valeur `artisan` est acceptée |

Le rôle d'un compte créé ici est **toujours `artisan`**. Les rôles `field_agent`, `moderator` et `admin` ne peuvent pas être demandés (`422`). Les champs `is_active`, `uuid`, `id` et `email_verified_at` envoyés par le client sont ignorés.

Requête :

```bash
curl -X POST https://exemple.tg/api/v1/auth/register \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Kofi Mensah",
    "phone": "90 12 34 56",
    "password": "mot-de-passe-1",
    "password_confirmation": "mot-de-passe-1",
    "device_name": "Pixel 7"
  }'
```

Réponse `201` :

```json
{
  "data": {
    "user": {
      "uuid": "9d7c6a52-3b0e-4f6e-8a55-1f7d2c0f3a11",
      "name": "Kofi Mensah",
      "email": null,
      "phone": "+22890123456",
      "role": "artisan",
      "is_active": true,
      "email_verified_at": null,
      "created_at": "2026-10-02T09:30:00+00:00"
    },
    "token": "1|k3J...jeton-en-clair-affiche-une-seule-fois",
    "token_type": "Bearer"
  }
}
```

Erreurs : `422` (données invalides, numéro ou e-mail déjà utilisé, rôle refusé), `429`.

---

## POST /api/v1/auth/login

Retourne un jeton pour un appareil. **Accès public.**

| Paramètre | Type | Obligatoire | Règle |
|---|---|---|---|
| `login` | texte | oui | numéro de téléphone **ou** adresse e-mail |
| `password` | texte | oui | |
| `device_name` | texte | non | nom du jeton, `mobile` par défaut |

Requête :

```bash
curl -X POST https://exemple.tg/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{ "login": "90 12 34 56", "password": "mot-de-passe-1", "device_name": "Windows PC" }'
```

Réponse `200` : même forme qu'à l'inscription (`data.user`, `data.token`, `data.token_type`).

Erreurs :

| Code | Cas |
|---|---|
| 401 | `{"message": "Identifiants invalides."}` : mot de passe incorrect **ou** compte inconnu (réponse identique) |
| 403 | `{"message": "Ce compte est désactivé."}` : seulement après un mot de passe correct |
| 422 | Champs manquants ou mal formés |
| 429 | Trop de tentatives |

---

## POST /api/v1/auth/logout

Révoque le jeton utilisé pour cette requête (les autres appareils restent connectés). **Authentification requise.**

```bash
curl -X POST https://exemple.tg/api/v1/auth/logout \
  -H "Authorization: Bearer 1|k3J..."
```

Réponse `204`, sans corps. Erreurs : `401`, `403` (compte désactivé).

---

## GET /api/v1/auth/me

Retourne le compte authentifié. **Authentification requise.**

```bash
curl https://exemple.tg/api/v1/auth/me \
  -H "Authorization: Bearer 1|k3J..."
```

Réponse `200` :

```json
{
  "data": {
    "uuid": "9d7c6a52-3b0e-4f6e-8a55-1f7d2c0f3a11",
    "name": "Kofi Mensah",
    "email": null,
    "phone": "+22890123456",
    "role": "artisan",
    "is_active": true,
    "email_verified_at": null,
    "created_at": "2026-10-02T09:30:00+00:00",
    "artisan": {
      "uuid": "5c1d0e7a-6a1b-4a52-9a43-0d52a9b0c6e2",
      "business_name": "Atelier Kofi",
      "status": "draft"
    }
  }
}
```

`artisan` vaut `null` tant que l'utilisateur n'a pas de profil. Le mot de passe, l'identifiant interne et les jetons ne sont jamais retournés.

Erreurs : `401` (jeton absent, invalide, expiré ou révoqué), `403` (compte désactivé).