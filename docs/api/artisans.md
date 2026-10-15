# API : profils artisans (v1)

Identifiant d'un profil : son `uuid`. Les identifiants internes (`id`, `user_id`, `created_by`, `validated_by`) ne sont jamais exposés.
Les références au catalogue (`category`, `subcategory`, `city`, `neighborhood`) se font par **slug** (voir `catalogue.md`).

## Règles de visibilité

| Profil | Qui le voit |
|---|---|
| `validated` | Tout le monde, sans compte |
| `draft`, `pending`, `rejected`, `suspended` | Son propriétaire, l'agent de terrain qui l'a créé, les modérateurs et administrateurs. **Pour tous les autres : `404`** (jamais `403`, pour ne pas révéler l'existence d'un brouillon) |

Un jeton est facultatif sur les routes de lecture : valide, il révèle les profils non publics de son titulaire ; invalide ou compte désactivé, il est traité comme un visiteur.
`status` et `status_reason` ne sont retournés qu'au propriétaire, au créateur et au personnel.

L'autorisation de modifier est vérifiée **avant** la validation des données : un compte qui ne peut pas voir un profil reçoit `404`, sans aucun retour de validation.

## Règles de statut

- Un profil est créé en `draft`.
- Le client **ne peut jamais envoyer `status`** (`422`). Il **soumet** son profil : `POST /artisans/{uuid}/submit` fait passer `draft` ou `rejected` à `pending`. Les autres états répondent `409`.
- `validated` et `suspended` ne peuvent être posés que par le personnel (administration, à venir).
- Un artisan (ou l'agent qui gère le profil) qui change le **nom commercial** ou la **catégorie** d'un profil `validated` le remet en `pending`. Les autres champs ne changent pas le statut. Une modification par le personnel non plus.
- Un profil `suspended` ne peut être modifié que par le personnel.

## Qui peut écrire

| Action | Autorisé |
|---|---|
| Créer | Tout compte actif. Un **artisan** crée **son** profil (un seul, sinon `409`). Agent de terrain, modérateur, administrateur : profil **sans propriétaire**, à réclamer plus tard |
| Modifier | Le propriétaire ; l'agent créateur tant que le profil n'est pas réclamé ; le personnel. Sinon `403` (profil visible) ou `404` (profil non public) |
| Soumettre | Le propriétaire, l'agent créateur d'un profil non réclamé, le personnel |

## Champs d'écriture

| Champ | Règle |
|---|---|
| `business_name` | 2 à 150 caractères (obligatoire sauf PATCH) |
| `description` | 2000 caractères maximum |
| `phone` | numéro togolais (`90 12 34 56`, `+228 90123456`, `00228 90123456`), enregistré `+228XXXXXXXX`, **unique** parmi les profils non supprimés |
| `whatsapp_phone` | idem, facultatif |
| `address` | 255 caractères maximum |
| `latitude`, `longitude` | nombres, **ensemble ou pas du tout** (`-90 à 90`, `-180 à 180`) ; `null` pour effacer |
| `category` | slug d'une catégorie active (obligatoire sauf PATCH) ; remplace la catégorie principale |
| `subcategory` | slug d'une sous-catégorie active **de cette catégorie** |
| `city`, `neighborhood` | slugs, **toujours ensemble** ; le quartier doit appartenir à la ville ; `country` facultatif (pays par défaut sinon) |

`PUT` remplace le profil (les champs obligatoires doivent être présents) ; `PATCH` ne change que les champs envoyés. Ces champs sont **ignorés** s'ils sont envoyés : `user_id`, `created_by`, `validated_by`, `uuid`, `id`, `status_reason`.

## Endpoints

| Méthode et chemin | Accès | Réponse |
|---|---|---|
| `GET /api/v1/artisans` | Public | Liste paginée (`per_page` 20 par défaut, 50 maximum). Filtres : `category`, `subcategory`, `city`, `neighborhood`, `country`. Profils validés uniquement, triés par nom |
| `GET /api/v1/artisans/{uuid}` | Public (jeton facultatif) | Fiche complète |
| `POST /api/v1/artisans` | Jeton | `201` |
| `PUT` / `PATCH /api/v1/artisans/{uuid}` | Jeton | `200` |
| `POST /api/v1/artisans/{uuid}/submit` | Jeton | `200`, ou `409` |

## Fiche d'un artisan

```json
{
  "data": {
    "uuid": "5c1d0e7a-6a1b-4a52-9a43-0d52a9b0c6e2",
    "business_name": "Atelier Kofi",
    "description": "Couture sur mesure.",
    "phone": "+22890123456",
    "whatsapp_phone": "+22891234567",
    "whatsapp_url": "https://wa.me/22891234567?text=Bonjour%2C%20je%20vous%20contacte%20depuis%20DigiVitrine.",
    "call_url": "tel:+22890123456",
    "address": "Rue des Artisans",
    "latitude": 6.1319,
    "longitude": 1.2228,
    "neighborhood": { "slug": "quartier-un", "name": "Quartier Un", "city": { "slug": "lome", "name": "Lomé", "country_code": "TG" } },
    "categories": [ { "slug": "couture", "name": "Couture", "subcategory": { "slug": "vetements-homme", "name": "Vêtements homme" }, "is_primary": true } ],
    "services": [ { "uuid": "...", "name": "Robe sur mesure", "description": null } ],
    "opening_hours": [ { "day_of_week": 1, "opens_at": "08:00", "closes_at": "17:00", "is_closed": false } ],
    "photos": [ { "uuid": "...", "url": "https://.../storage/....webp", "width": 1280, "height": 960, "is_primary": true, "position": 0 } ],
    "created_at": "2026-10-05T09:30:00+00:00"
  }
}
```

Pour le propriétaire, le créateur et le personnel, `status` et `status_reason` s'ajoutent. La **liste** ne contient pas les numéros de téléphone (nom, extrait de description, quartier, catégorie, sous-catégorie, photo principale) : ils sont dans la fiche.

`whatsapp_url` est construit sans le `+` (`https://wa.me/228…`) ; il vaut `null` si le profil n'a pas de numéro WhatsApp.

## Erreurs

| Code | Cas |
|---|---|
| 401 | Jeton absent, invalide ou expiré (écriture) |
| 403 | Compte désactivé, ou profil visible mais non modifiable par ce compte |
| 404 | Profil inconnu, supprimé, ou non public pour ce compte |
| 409 | Un artisan a déjà un profil ; soumission impossible dans l'état actuel |
| 422 | Données invalides (champ par champ), référence au catalogue inconnue ou incohérente, `status` envoyé |