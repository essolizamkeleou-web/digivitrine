# API : galerie de photos (v1)

Chaque profil artisan a une galerie de **6 photos maximum** (réglable par `DIGIVITRINE_PHOTOS_MAX`).
Les photos sont des fichiers stockés sur un disque configurable (`DIGIVITRINE_PHOTOS_DISK`, `public` en développement, un stockage objet plus tard) : **aucune image n'est stockée dans la base de données**. Seules les métadonnées le sont.
Le disque et le chemin du fichier ne sont **jamais** exposés : l'API ne retourne qu'une URL.

## Endpoints

| Méthode et chemin | Accès | Réponse |
|---|---|---|
| `GET /api/v1/artisans/{uuid}/photos` | Public (jeton facultatif) | Photos actives, triées par `position` |
| `POST /api/v1/artisans/{uuid}/photos` | Jeton | `201` : la photo créée |
| `PATCH /api/v1/artisans/{uuid}/photos/{photoUuid}` | Jeton | `200` : la photo modifiée |
| `DELETE /api/v1/artisans/{uuid}/photos/{photoUuid}` | Jeton | `204` |

Un profil non public (brouillon, en attente, refusé, suspendu) répond `404` à tout autre compte que son propriétaire, l'agent qui l'a créé et le personnel. Une photo d'un autre profil dans l'URL répond `404`.

## Qui peut quoi

| Action | Autorisé |
|---|---|
| Voir les photos actives | Tout le monde (profil validé), ou les personnes qui peuvent voir le profil |
| Voir aussi les photos **masquées** | Propriétaire, agent créateur, personnel |
| Envoyer, changer la position, choisir la photo principale, supprimer | Mêmes droits que la modification du profil : propriétaire, agent créateur d'un profil non réclamé, personnel. **Pas pour un profil suspendu** (personnel seulement). Sinon `403` (profil visible) ou `404` (profil non public) |
| Masquer ou réafficher une photo (`is_active`) | Modérateurs et administrateurs uniquement |

L'autorisation est vérifiée **avant** la validation du fichier.

## Envoi d'une photo

`POST` en `multipart/form-data`, un seul champ : `photo`.

| Règle | Valeur |
|---|---|
| Formats | JPEG, PNG, WebP. Le type est lu **dans le contenu du fichier** : le nom et l'extension envoyés ne comptent pas |
| Taille | 5 Mo maximum (`DIGIVITRINE_PHOTOS_MAX_KB`) |
| Dimensions | de 200 à 5 000 pixels de côté, 16 mégapixels maximum |
| Refusés | GIF, SVG, PDF, fichiers texte ou PHP renommés en `.jpg`, fichiers corrompus |

La photo est **décodée puis ré-encodée en WebP** (1 600 pixels maximum sur le plus grand côté, qualité 80) : le fichier stocké est une vraie image, sans métadonnées EXIF ni contenu caché. L'orientation des photos de téléphone est appliquée avant (si l'extension PHP `exif` est présente). Le fichier est nommé `artisans/{uuid du profil}/{uuid de la photo}.webp` : le nom envoyé par le client n'est jamais utilisé.

```bash
curl -X POST https://exemple.tg/api/v1/artisans/5c1d0e7a-6a1b-4a52-9a43-0d52a9b0c6e2/photos \
  -H "Authorization: Bearer 1|k3J..." \
  -F "photo=@atelier.jpg"
```

Réponse `201` :

```json
{
  "data": {
    "uuid": "0b6f3d1e-2c4a-4f0e-9b7d-3a1c5e8f2d44",
    "url": "https://exemple.tg/storage/artisans/5c1d0e7a-.../0b6f3d1e-....webp",
    "width": 1280,
    "height": 960,
    "is_primary": true,
    "is_active": true,
    "position": 0
  }
}
```

La **première** photo d'un profil devient la photo principale. Une 7ᵉ photo (ou la limite configurée) répond `409` : `{"message": "Limite de photos atteinte pour ce profil."}`.

## Modification d'une photo

`PATCH` en JSON. Champs acceptés :

| Champ | Règle |
|---|---|
| `is_primary` | `true` seulement : désigne cette photo comme principale (**une seule** par profil, l'ancienne est libérée). Pour changer de photo principale, on choisit **une autre** photo. Une photo masquée ne peut pas être principale (`422`) |
| `position` | entier de 0 à 100 (ordre d'affichage) |
| `is_active` | `true` ou `false`, **personnel uniquement** (`422` pour les autres). Masquer la photo principale désigne automatiquement la suivante |

Les autres champs envoyés (`path`, `disk`, `artisan_id`, `uuid`…) sont ignorés.

## Suppression

`DELETE` supprime la ligne **et le fichier**. Si la photo supprimée était la principale, la photo active suivante (selon `position`) le devient.

## Erreurs

| Code | Cas |
|---|---|
| 401 | Jeton absent, invalide ou expiré |
| 403 | Compte désactivé, ou profil visible mais non modifiable par ce compte |
| 404 | Profil ou photo inconnu, ou profil non public pour ce compte |
| 409 | Limite de photos atteinte |
| 422 | Fichier invalide (champ `photo`), `is_primary` ou `position` invalide, `is_active` envoyé par un non-membre du personnel |
| 429 | Plus de 30 envois en une heure |

## Notes d'exploitation

- Pour afficher les images en développement : `php artisan storage:link`.
- `upload_max_filesize` et `post_max_size` de `php.ini` doivent valoir au moins `6M`, sinon PHP refuse les fichiers de plus de 2 Mo avant l'application.
- Les photos ne sont pas contrôlées avant publication : le personnel peut les masquer après coup (modération a posteriori).
