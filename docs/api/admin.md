# API : administration (v1)

Endpoints réservés au **personnel** : comptes **actifs** de rôle `admin` ou `moderator`. Sans jeton : `401`. Artisan, agent de terrain ou compte désactivé : `403`. Les modérateurs et les administrateurs ont les mêmes droits sur ces endpoints.
Limite : 120 requêtes par minute et par utilisateur.

Les personnes (propriétaire, créateur, validateur, signalant) sont identifiées par leur `uuid`, leur nom et leur rôle. Les identifiants internes ne sont jamais exposés.

## Endpoints

| Méthode et chemin | Rôle |
|---|---|
| `GET /api/v1/admin/artisans` | Liste de tous les profils (tous statuts, profils supprimés exclus) |
| `GET /api/v1/admin/artisans/{uuid}` | Fiche complète d'un profil |
| `PATCH /api/v1/admin/artisans/{uuid}/validate` | Valider |
| `PATCH /api/v1/admin/artisans/{uuid}/reject` | Refuser (motif obligatoire) |
| `PATCH /api/v1/admin/artisans/{uuid}/suspend` | Suspendre (motif obligatoire) |
| `GET /api/v1/admin/reports` | Liste des signalements |
| `PATCH /api/v1/admin/reports/{uuid}` | Clore un signalement |

## Liste des profils

Paramètres : `status` (`draft`, `pending`, `validated`, `rejected`, `suspended`), `category`, `subcategory`, `city`, `neighborhood`, `country` (slugs), `q` (nom commercial, casse et accents ignorés, 100 caractères maximum), `per_page` (1 à 50, 20 par défaut), `page`.
Avec `status=pending`, les profils les **plus anciens** sont en tête (file de modération) ; sinon les plus récents.

Chaque ligne : `uuid`, `business_name`, `phone`, `status`, `status_reason`, `neighborhood`, `category`, `has_owner`, `created_at`, `validated_at`.

## Fiche d'un profil

Tout le contenu public de la fiche (voir `artisans.md`), plus : `status`, `status_reason`, `owner`, `created_by`, `validated_by` (chacun : `uuid`, `name`, `role`, ou `null`), `validated_at`, `updated_at`. Les photos **masquées** sont incluses (`is_active: false`).

## Changements de statut

| Action | Depuis | Vers | Corps |
|---|---|---|---|
| `validate` | `pending`, `suspended` | `validated` | aucun |
| `reject` | `pending` | `rejected` | `{"reason": "..."}` |
| `suspend` | `validated` | `suspended` | `{"reason": "..."}` |

- `reason` : texte de 3 à 500 caractères. Il est visible du propriétaire dans `status_reason` de son profil, qui peut alors le corriger et le **soumettre à nouveau** (`POST /artisans/{uuid}/submit`).
- **Valider** enregistre le modérateur (`validated_by`) et la date (`validated_at`) et efface le motif. Un profil `validated` devient public : liste, fiche et recherche.
- **Suspendre** retire immédiatement le profil de la liste, de la fiche et de la recherche publiques. Le valider à nouveau le rétablit.
- Toute autre transition répond **`409`** : `{"message": "Ce changement de statut n'est pas possible depuis l'état actuel."}`. Rien n'est modifié. Un double clic ou deux modérateurs simultanés : le second reçoit `409` (la ligne est verrouillée pendant le contrôle).
- Les champs `status`, `validated_by`, `validated_at`, `user_id` envoyés dans le corps sont **ignorés** : seul le compte authentifié compte.
- **Un membre du personnel ne peut pas valider, refuser ni suspendre un profil dont il est propriétaire** (`403`) : un autre modérateur doit le faire. L'autorisation est vérifiée **avant** la validation du motif.

```bash
curl -X PATCH https://exemple.tg/api/v1/admin/artisans/5c1d0e7a-6a1b-4a52-9a43-0d52a9b0c6e2/reject \
  -H "Authorization: Bearer 1|k3J..." \
  -H "Content-Type: application/json" \
  -d '{ "reason": "Photos manquantes" }'
```

## Signalements

`GET /api/v1/admin/reports` : paramètres `status` (`open`, `resolved`, `dismissed`), `reason` (`false_information`, `inappropriate_content`, `duplicate_profile`, `wrong_contact`, `other`), `artisan` (uuid d'un profil), `per_page` (1 à 100, 20 par défaut), `page`. Avec `status=open`, les plus anciens sont en tête.

Chaque ligne : `uuid`, `reason`, `description`, `status`, `artisan` (`uuid`, `business_name`, `status`), `reporter` (`uuid`, `name`, ou `null` pour un signalement anonyme), `resolved_by`, `resolved_at`, `created_at`.

`PATCH /api/v1/admin/reports/{uuid}` avec `{"status": "resolved"}` ou `{"status": "dismissed"}` : seul un signalement **ouvert** peut être clos (sinon `409`). Le modérateur et la date sont enregistrés. **Clore un signalement ne modifie pas le profil** : pour le suspendre, appeler `suspend`.

## Piste d'audit

Chaque changement de statut d'un profil et chaque clôture de signalement écrit une ligne dans les journaux de l'application (`artisan.status_changed`, `report.closed`) avec l'acteur, son rôle, l'état avant et après, et le motif. Ce n'est pas encore une table d'audit en base.

## Erreurs

| Code | Cas |
|---|---|
| 401 | Jeton absent, invalide ou expiré |
| 403 | Rôle insuffisant, compte désactivé, ou profil dont le membre du personnel est propriétaire |
| 404 | Profil ou signalement inconnu (ou profil supprimé) |
| 409 | Transition de statut impossible depuis l'état actuel |
| 422 | Paramètre invalide, motif absent, trop court ou trop long, statut de signalement invalide |
