# API : recherche d'artisans (v1)

`GET /api/v1/search/artisans` : **public**, aucune authentification (un jeton, valide ou non, ne change pas le résultat). Limité à 240 requêtes par minute par adresse IP.

Seuls les profils **validés et non supprimés** sont retournés. Les brouillons, profils en attente, refusés, suspendus ou supprimés n'apparaissent jamais, même s'ils correspondent à la recherche.

## Paramètres

| Paramètre | Règle |
|---|---|
| `q` | Texte libre, 100 caractères maximum |
| `category`, `subcategory`, `city`, `neighborhood` | Slugs (voir `catalogue.md`) |
| `country` | Code à 2 lettres, utile pour `city` (pays par défaut sinon) |
| `available` | `1` : profils **ouverts maintenant** (jour et heure du serveur, UTC, qui est l'heure de Lomé). `0` ou absent : pas de filtre |
| `per_page` | 1 à 50, 20 par défaut |
| `page` | 1 ou plus |

Paramètre invalide : `422`. Une recherche sans paramètre liste tous les profils visibles.

## Comment `q` est interprété

1. **Casse et accents ignorés** : `AGOÈ`, `agoe` et `Agoè` sont équivalents.
2. Le texte est découpé en **mots**. La ponctuation, les mots d'un seul caractère et les mots vides (`a`, `au`, `de`, `la`, `chez`, `pour`…) sont ignorés. **6 mots maximum**.
3. **Chaque mot doit être trouvé** (ET), dans l'un de ces champs : nom commercial, description, nom de la catégorie, de la sous-catégorie, des services **actifs**, du quartier, de la ville.
4. Un mot de **6 lettres ou plus** est cherché par ses **5 premières lettres** : `couturier` trouve « Couture », `mecanicien` trouve « Mécanique », `menuisier` trouve « Menuiserie ».
5. **Classement** : les profils dont le nom contient le plus de mots cherchés d'abord, puis l'ordre alphabétique.

### Limites connues

- Ce n'est **pas** une recherche tolérante aux fautes de frappe. `soudeur` ne trouve pas « Soudure » (`soude` contre `soudu`). Des synonymes et une recherche par similarité sont prévus dans une phase ultérieure.
- Un mot court (moins de 6 lettres) doit apparaître tel quel.

### Sécurité

Les mots sont réduits aux caractères `a-z` et `0-9` avant la requête, et transmis par paramètres liés : `%`, `_`, `'`, `;` n'ont aucun effet.

## Réponse

Même format que `GET /api/v1/artisans` : résumés paginés, **sans numéro de téléphone** (ils sont dans la fiche `GET /api/v1/artisans/{uuid}`).

```bash
curl "https://exemple.tg/api/v1/search/artisans?q=couturier%20tokoin&available=1&per_page=10"
```

```json
{
  "data": [
    {
      "uuid": "5c1d0e7a-6a1b-4a52-9a43-0d52a9b0c6e2",
      "business_name": "Atelier Kofi",
      "description": "Robes et costumes sur mesure",
      "neighborhood": { "slug": "tokoin", "name": "Tokoin", "city": { "slug": "lome", "name": "Lomé", "country_code": "TG" } },
      "category": { "slug": "couture", "name": "Couture" },
      "subcategory": { "slug": "vetements-homme", "name": "Vêtements homme" },
      "primary_photo": null
    }
  ],
  "links": { "first": "...", "last": "...", "prev": null, "next": null },
  "meta": { "current_page": 1, "per_page": 10, "total": 1 }
}
```

## Exemples

| Requête | Effet |
|---|---|
| `?q=couturier` | Profils dont un champ contient « coutu » |
| `?q=mecanicien&neighborhood=agoe` | Mécaniciens du quartier `agoe` |
| `?category=couture&neighborhood=tokoin` | Filtres seuls, sans texte |
| `?q=couture&available=1` | Couture, ouvert maintenant |

Erreurs : `422` (paramètre invalide), `429` (trop de requêtes).
