# API : communes du Grand Lomé (v1)

Les quartiers sont rattachés à l'une des 13 communes du Grand Lomé (Golfe 1 à 7, Agoè-Nyivé 1 à 6). **Le contrat de création d'un profil ne change pas** : il utilise toujours `city` et `neighborhood`.

Seuls les quartiers **actifs** sont visibles. Un quartier non vérifié est inactif (ADR-16) : tant qu'aucun quartier n'est activé, les listes de quartiers restent vides.

## Endpoints publics

| Méthode et chemin | Réponse |
|---|---|
| `GET /api/v1/communes` | Communes actives, dans l'ordre de création, paginées |
| `GET /api/v1/communes/{slug}` | Une commune |
| `GET /api/v1/communes/{slug}/neighborhoods` | Quartiers **actifs** de la commune, triés par nom, paginés |

Paramètres : `city` (slug de la ville), `country` (code à 2 lettres, pays par défaut sinon), `per_page` (1 à 50, 20 par défaut), `page`. Une commune inconnue ou inactive répond `404` ; un paramètre invalide `422` ; limite de débit des routes publiques.

Une commune : `slug`, `name`, `prefecture` (`golfe` ou `agoe-nyive`) et `city` (`slug`, `name`, `country_code`). Aucun identifiant interne n'est exposé.

## Champ `commune` dans les réponses

L'objet `neighborhood` des réponses (annuaire, fiche, recherche, administration, catalogue des quartiers) contient `commune` (`slug`, `name`) quand le quartier est rattaché à une commune. Le champ est **absent** sinon.

## Filtre `commune`

`?commune=<slug>` est accepté par `GET /api/v1/artisans`, `GET /api/v1/search/artisans` et `GET /api/v1/admin/artisans`, et se combine avec les autres filtres (`neighborhood`, `category`...). Slug inconnu : liste vide. Slug de format invalide : `422`.

## Recherche

`q` trouve aussi le **nom de la commune** (`golfe` trouve les profils de Golfe 1 à 7) et les **alias** des quartiers (variantes d'orthographe), casse et accents ignorés.
