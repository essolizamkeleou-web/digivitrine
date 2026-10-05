# API : catalogue (v1)

Données de référence **publiques** : aucune authentification, lecture seule (toute autre méthode HTTP répond `405`).
Les identifiants sont des **slugs** (minuscules, chiffres, tirets). Aucun identifiant interne ni date n'est exposé.
Seuls les éléments **actifs** sont retournés ; un élément inactif ou inconnu répond `404` (`{"message": "Ressource introuvable."}`).

Les réponses réussies portent un en-tête `Cache-Control: public, max-age=300` (durée réglable par `DIGIVITRINE_REFERENCE_CACHE_SECONDS`), pour économiser les données sur les réseaux lents.

## Pagination

Les listes courtes (catégories, villes) ne sont pas paginées. Les quartiers le sont :

| Paramètre | Règle |
|---|---|
| `per_page` | 1 à 200, **100** par défaut |
| `page` | 1 ou plus |

Réponse paginée : `{ "data": [...], "links": {...}, "meta": { "current_page", "per_page", "total", ... } }`. Paramètre invalide : `422`.

## Villes et pays

Un slug de ville est unique **par pays**. Le pays par défaut est configuré (`DIGIVITRINE_DEFAULT_COUNTRY`, `TG`) ; ajouter `?country=GH` pour une ville d'un autre pays.

## Endpoints

| Méthode et chemin | Contenu |
|---|---|
| `GET /api/v1/categories` | Catégories triées, avec leurs sous-catégories |
| `GET /api/v1/categories/{category}` | Une catégorie et ses sous-catégories |
| `GET /api/v1/categories/{category}/subcategories` | Les sous-catégories d'une catégorie |
| `GET /api/v1/cities` | Villes (filtre `?country=`) |
| `GET /api/v1/cities/{city}` | Une ville |
| `GET /api/v1/cities/{city}/neighborhoods` | Quartiers d'une ville, paginés |
| `GET /api/v1/neighborhoods` | Tous les quartiers, paginés, filtres `?city=` et `?country=` |

Il n'existe pas de `GET /services` global : les services sont du texte libre propre à chaque artisan et figurent dans la fiche de l'artisan.

## Exemples

```bash
curl https://exemple.tg/api/v1/categories
```

```json
{
  "data": [
    {
      "slug": "couture",
      "name": "Couture",
      "subcategories": [
        { "slug": "vetements-homme", "name": "Vêtements homme" },
        { "slug": "vetements-femme", "name": "Vêtements femme" }
      ]
    }
  ]
}
```

```bash
curl "https://exemple.tg/api/v1/cities/lome/neighborhoods?per_page=50"
```

```json
{
  "data": [ { "slug": "exemple", "name": "Exemple" } ],
  "links": { "first": "...", "last": "...", "prev": null, "next": null },
  "meta": { "current_page": 1, "per_page": 50, "total": 1 }
}
```

La liste des quartiers de Lomé reste à fournir et à valider avec le terrain : tant qu'elle est vide, ces endpoints retournent `"data": []`.