# Référentiel géographique du Grand Lomé

Données de travail pour rattacher chaque quartier, village ou localité à sa commune. **Cet état est une préparation** : aucune liste de quartiers n'est renseignée, aucune liste n'est présumée exhaustive, et rien de ce dossier n'est encore lu par l'application (pas de migration, de modèle, de seeder ni de changement de l'API).

## Périmètre

- Ville `Lomé` (`TG` / `lome`), conservée telle quelle. Le contrat de l'API reste `city` + `neighborhood`.
- **13 communes** : Golfe 1 à 7 (préfecture du Golfe) et Agoè-Nyivé 1 à 6 (préfecture d'Agoè-Nyivé).
- Les **cantons** sont exclus du périmètre actuel.
- Les libellés fournis par le brief du projet pour Golfe 1 à 7 sont conservés dans `brief_label`, avec la nature `a_confirmer` : ce ne sont pas, à ce stade, des quartiers.

## Arborescence

## Statuts

**Liste d'une commune** (`list_status`) :

| Statut | Signification | Conditions |
|---|---|---|
| `a_verifier` | Rien n'est validé. Peut contenir des candidats | aucune |
| `partiel` | Liste connue mais **non exhaustive** | au moins un quartier |
| `complet` | Liste validée comme exhaustive | `declared_count` égal au nombre de quartiers, **tous** `verifie`, `reviewed_by` et `reviewed_on` renseignés |

**Quartier** (`verification`) : `a_verifier` ou `verifie`. Un quartier est `verifie` seulement avec **2 sources distinctes dont le document est en main** (`located: true`), **dont une de niveau 1 à 3**, plus `verified_by` et `verified_on`.

**Types de localité** (`kind`) : `quartier`, `village`, `localite`, `non_precise`. Le type n'est précisé que si une source le dit. Sinon `non_precise`.

## Gabarit d'un quartier (emplacements à remplacer)

```json
{
    "name": "<nom de référence : graphie de la meilleure source>",
    "commune": "<slug de la commune, identique au nom du fichier>",
    "slug": "<slug décidé par un humain : minuscules, chiffres et tirets>",
    "kind": "<quartier | village | localite | non_precise>",
    "aliases": ["<variante d'orthographe>"],
    "sources": ["<identifiant présent dans sources.json>"],
    "verification": "a_verifier",
    "verified_by": null,
    "verified_on": null,
    "distinct_from": [],
    "slug_reason": "",
    "notes": ""
}
```

## Règles de normalisation

1. **Nom** : la graphie de la meilleure source, accents, traits d'union et apostrophes conservés. Jamais en majuscules intégrales.
2. **Slug** : `Str::slug(nom)` (ASCII, minuscules, tirets, 120 caractères maximum), **stable** : jamais modifié après publication. Corriger un nom, c'est modifier `name`, pas `slug`. Un slug différent doit être justifié dans `slug_reason`.
3. **Clé de comparaison** (jamais affichée) : minuscules, sans accents, sans espaces, tirets, apostrophes ni points. `Bè Kpota`, `Bè-Kpota` et `Be Kpota` ont la même clé.
4. **Variantes d'orthographe** : des **alias** du même lieu, jamais deux lignes.
5. **Aucune fusion automatique.** Deux noms voisins restent deux entrées tant qu'une source ne dit pas qu'ils désignent le même lieu. Les noms confirmés différents se déclarent dans `distinct_from` (slugs).
6. **Slug unique dans toute la ville**, pas seulement dans la commune (c'est l'unicité actuelle de la base). Pour deux lieux de même nom dans deux communes, un humain choisit des slugs distincts et le justifie.
7. En cas de **contradiction entre sources**, les deux sont enregistrées dans `notes` et la décision est humaine.
8. Un quartier retiré passe à inactif plus tard dans la base (jamais supprimé : des profils peuvent s'y rattacher).

## Sources

`sources.json` classe chaque source en 6 niveaux : 1 texte légal, 2 recensement ou statistique officielle, 3 administration (ministère, mairie), 4 base collaborative (recoupement), 5 source secondaire (repérage de candidats, jamais une preuve seule), 6 information interne. Une source dont le document n'est pas en main (`located: false`) ne peut pas servir de preuve. Les décomptes de subdivisions cités dans `notes` viennent de sources secondaires non vérifiées : ils servent de **cible de contrôle**, pas de preuve.

Licences à respecter avant tout import : OpenStreetMap (ODbL), GeoNames (CC BY 4.0), Wikipédia (CC BY-SA).

## Contrôles

```powershell
cd backend
php database/data/geography/check.php              # valide les fichiers réels
php database/data/geography/check.php --strict     # les avertissements deviennent des erreurs
php database/data/geography/check.php --self-test  # prouve que chaque contrôle détecte son défaut
```

**Erreurs** (code de sortie 1) : collision de slug dans la ville ; même clé de comparaison dans une commune ; alias égal au nom d'un voisin ou partagé ; slug différent de `Str::slug(nom)` sans justification ; source inconnue ou absente ; type, statut ou commune invalides ; `verifie` ou `complet` non justifiés ; fichier absent ou inattendu ; `distinct_from` vers un slug inconnu ; BOM ou caractères corrompus.

**Avertissements** : noms très proches dans une commune ; même nom dans plusieurs communes ; alias croisés entre communes ; alias inutile.

La sortie distingue, pour chaque commune, les quartiers **renseignés**, **vérifiés**, **déclarés** (si un décompte est connu) et **manquants**.

## Ajouter un quartier

1. Repérer le nom dans une source, noter son identifiant (l'ajouter à `sources.json` s'il manque).
2. Ajouter l'entrée dans le fichier de la bonne commune, en `a_verifier`.
3. Lancer `check.php` ; traiter chaque erreur et chaque avertissement (jamais de fusion automatique).
4. Passer à `verifie` seulement avec deux sources distinctes en main, dont une de niveau 1 à 3, puis renseigner `verified_by` et `verified_on`.
5. Passer la commune à `partiel` dès qu'elle a un quartier, à `complet` seulement avec une relecture et un décompte déclaré.

## Ce qui n'est pas encore fait

Migration (`communes`, colonnes de `neighborhoods`, alias), modèles, seeder de chargement, ressources de l'API, filtre `commune`, prise en compte des alias dans la recherche. Décisions en attente : visibilité publique des quartiers `a_verifier`, orthographe de référence, source légale à localiser.
