# ADR-16 : Référentiel géographique du Grand Lomé

- **Statut** : Acceptée (implémentation à venir)
- **Date** : 2026-10-27
- **Complète** : ADR-10 (§2.7 : villes et quartiers en base)
- **Liée à** : ADR-15 (conventions de l'API)

## 1. Contexte

DigiVitrine couvre le Grand Lomé : **13 communes**, Golfe 1 à 7 (préfecture du Golfe) et Agoè-Nyivé 1 à 6 (préfecture d'Agoè-Nyivé). Le modèle actuel a deux niveaux : une ville (`cities`) et ses quartiers (`neighborhoods`, unicité `(city_id, slug)`). Il contient une ville (`TG` / `lome`) et **aucun quartier** ; or le quartier est obligatoire à la création d'un profil, donc **personne ne peut encore créer de profil**.

L'audit du 24 octobre 2026 a aussi montré que le seeder actuel ignore **sans prévenir** un quartier dont le slug existe déjà. Aucune liste publique vérifiable des quartiers n'a été trouvée : les sources consultées donnent le périmètre et des populations, pas les listes.

## 2. Décisions

1. **Option « A-lite ».** La ville `Lomé` (slug `lome`) est conservée. Le contrat de l'API reste `city` + `neighborhood`. Une table `communes` (13 lignes) est ajoutée et chaque quartier est rattaché à une commune, avec une contrainte qui garantit que la commune appartient à la ville du quartier. La commune est un attribut affiché et filtrable.
2. **Slug unique dans toute la ville.** L'unicité `(city_id, slug)` est conservée. Deux lieux de même nom dans deux communes reçoivent des slugs différents, choisis et justifiés par un humain, jamais automatiquement.
3. **Colonnes à ajouter aux quartiers** : commune, type de localité (`quartier`, `village`, `localite`, `non_precise`), état de vérification (`a_verifier`, `verifie`), référence de source. Une table d'alias gère les variantes d'orthographe ; les alias seront pris en compte par la recherche.
4. **Cantons exclus** du périmètre actuel.
5. **Données dans des fichiers versionnés** : `backend/database/data/geography/`, un fichier par commune, relu dans Git. Statuts d'une liste : `a_verifier`, `partiel`, `complet` (ce dernier exige un décompte déclaré égal au nombre d'entrées, toutes vérifiées, et une relecture signée). Un quartier n'est `verifie` qu'avec deux sources distinctes dont le document est en main, dont une de niveau 1 à 3.
6. **Aucune liste présumée exhaustive** et **aucune fusion automatique** de noms voisins. Le validateur `check.php` bloque les collisions de slug, les doublons de clé de comparaison et les alias ambigus, et signale les noms très proches.
7. **Visibilité.** Les quartiers `a_verifier` sont **inactifs** : ils n'apparaissent ni dans l'API publique ni à la création d'un profil. Seuls les quartiers vérifiés, ou validés à la main pour le pilote, sont actifs.
8. **Orthographe de référence.** `Agoè-Nyivé` ; `Agoé-Nyivé`, rencontrée dans des sources officielles, est conservée comme alias, jusqu'à la lecture du texte de loi.
9. **Le seeder de chargement s'arrête** sur toute collision de slug au lieu de l'ignorer, et ne modifie jamais une ligne existante.

## 3. Alternatives écartées

| Alternative | Raison du rejet |
|---|---|
| Quartier identifié par `commune` + `neighborhood` | Changerait le contrat de l'API ; les artisans connaissent leur quartier, rarement leur commune administrative. |
| Les 13 communes comme villes | « Golfe 1 » n'est pas une ville ; la ville `lome` existante deviendrait incohérente. |
| Commune en texte libre | Aucune intégrité, doublons impossibles à contrôler. |
| Remplir les quartiers de mémoire | Un nom faux empêche un vrai artisan de se déclarer. |

## 4. État d'avancement

- **Fait** : fichiers de travail (13 communes, listes vides, registre de 10 sources, validateur auto-testé).
- **À faire** : migration, modèles, seeder de chargement, champ `commune` dans les réponses, filtre `commune` facultatif, alias dans la recherche, tests.
- **Points ouverts** : nature des libellés de Golfe 1 à 7 (chefs-lieux, cantons ou quartiers), texte de loi de 2017 et textes d'Agoè-Nyivé à localiser, communes du pilote, licences des sources collaboratives (OpenStreetMap : ODbL, GeoNames : CC BY 4.0, Wikipédia : CC BY-SA) avant tout import.

## 5. Conséquences

Le référentiel est fiable et traçable, mais le pilote dépend d'une validation humaine des quartiers de ses communes. Tant qu'aucun quartier n'est actif, la création de profil reste impossible : c'est voulu plutôt que d'accepter des données douteuses.
