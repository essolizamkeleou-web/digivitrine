# ADR-15 : Conventions de l'API v1 (catalogue, artisans, recherche, photos, administration)

- **Statut** : Validée
- **Date** : 2026-10-27
- **Complète** : ADR-14 (API v1 et authentification Sanctum)
- **Liée à** : ADR-07, ADR-10, ADR-12, ADR-13, ADR-14

## 1. Contexte

Cinq blocs d'API ont été construits après l'ADR-14 : catalogue, artisans, recherche, galerie de photos, administration, ainsi que la commande de création des comptes du personnel. Les règles communes qui en découlent sont consignées ici. Ce document décrit ce qui est **implémenté** ; ce qui manque est listé en §4. Le détail des endpoints est dans `docs/api/`.

## 2. Décisions

1. **Identifiants publics.** Les données de référence (catégories, sous-catégories, villes, quartiers) sont identifiées par un **slug** ; les artisans, photos, signalements et personnes par un **uuid**. Aucun identifiant interne (`id`) n'est exposé.
2. **404 plutôt que 403 pour ce qui n'est pas public.** Un profil non validé (brouillon, en attente, refusé, suspendu) ou supprimé répond `404` à tout autre compte que son propriétaire, l'agent qui l'a créé (profil non réclamé) et le personnel. `403` n'est utilisé que pour un profil **visible** que le compte n'a pas le droit de modifier. L'autorisation est vérifiée **avant** la validation des données, pour qu'un inconnu ne reçoive jamais d'erreurs qui confirmeraient l'existence d'un profil.
3. **Cycle de vie d'un profil.** L'artisan ne choisit jamais le statut (champ interdit, `422`). Il **soumet** son profil (`POST /artisans/{uuid}/submit` : de `draft` ou `rejected` vers `pending`, sinon `409`). Le personnel valide, refuse ou suspend ; les transitions autorisées viennent de l'enum `ArtisanStatus`, toute autre donne `409`. Refuser et suspendre exigent un **motif** (3 à 500 caractères) visible du propriétaire. La ligne est verrouillée pendant le contrôle (deux modérateurs simultanés : le second reçoit `409`). `validated_by` et `validated_at` sont posés par le serveur.
4. **Quatre yeux.** Un membre du personnel ne peut ni valider, ni refuser, ni suspendre un profil dont il est propriétaire (`ArtisanPolicy::moderate`).
5. **Revalidation (RF-18).** Un changement du nom commercial ou de la catégorie par un non-membre du personnel renvoie le profil en validation. Les photos ne la déclenchent pas : modération **a posteriori** (le personnel peut masquer une photo).
6. **Un profil par utilisateur** (`409` sinon). Les agents de terrain et le personnel créent des profils sans propriétaire.
7. **Données de contact.** Les listes (annuaire, recherche) ne contiennent **aucun numéro de téléphone** ; la fiche détaillée contient `phone`, `whatsapp_url` et `call_url`.
8. **Format.** Erreurs : `{message}` ou `{message, errors}`, sans fuite d'exception même en mode debug. Pagination : 20 éléments par défaut, 50 au plus (100 pour les signalements). Les listes de référence publiques sont mises en cache (300 secondes).
9. **Recherche.** Casse et accents ignorés (fonction `translate()` de PostgreSQL, aucune extension), mots vides retirés, tous les mots doivent être trouvés (6 mots au plus), un mot d'au moins 6 lettres est cherché par ses 5 premières, classement par nom. Seuls les mots réduits à `[a-z0-9]` atteignent la requête, par paramètres liés. **Limite connue** : `soudeur` ne trouve pas `soudure`. Les extensions `pg_trgm` et `unaccent` sont disponibles mais non utilisées : une recherche tolérante aux fautes viendra plus tard.
10. **Photos.** Le type est lu dans le **contenu** du fichier, jamais dans son nom. L'image est décodée puis ré-encodée en WebP (1 600 pixels au plus, qualité 80) : plus de métadonnées ni de contenu caché. Nom de fichier aléatoire, 5 Mo, 200 à 5 000 pixels, 16 mégapixels, **6 photos** par profil, une seule photo principale (garantie par un index partiel), disque configurable ; le disque et le chemin ne sont jamais exposés. Limite : 30 envois par heure et par utilisateur.
11. **Comptes du personnel.** Aucun chemin public ne crée un rôle privilégié (l'inscription force `artisan`). Les comptes `admin`, `moderator` et `field_agent` se créent par `php artisan staff:create` : commande interactive, mot de passe saisi deux fois sans affichage, jamais en option ni en argument, 12 caractères au moins, journal `staff.created` limité à l'uuid et au rôle.
12. **Piste d'audit.** Chaque changement de statut d'un profil et chaque clôture de signalement écrit une ligne dans les journaux (`artisan.status_changed`, `report.closed`) avec l'acteur, l'état avant et après, et le motif. Ce n'est **pas** la table d'audit du cahier des charges (RF-26).
13. **Organisation du code.** `routes/api.php` charge automatiquement `routes/api/*.php` sous `/api/v1` ; la logique métier est dans `app/Services`, les droits dans `app/Policies`. Les tests tournent sur `digivitrine_test` ; un garde-fou dans `tests/TestCase.php` refuse toute autre base. Dans les tests, un objet créé par une fabrique doit être rechargé (`refresh()`) avant de lire son `uuid`, généré par PostgreSQL.

## 3. Conséquences

- Contrat stable pour l'application Flutter, qui n'a besoin que de slugs et d'uuid.
- Aucune fuite d'existence de profil par les codes d'erreur.
- Dette assumée : pas de table d'audit, recherche non tolérante aux fautes, photos non contrôlées avant publication.

## 4. Ce qui n'est pas encore implémenté

Services et horaires modifiables par l'artisan (lecture seule aujourd'hui), dépôt d'un signalement par un visiteur, réclamation d'un profil créé par un agent, favoris et avis, réinitialisation du mot de passe, table d'audit, notifications, quartiers de Lomé (voir l'ADR-16).

## 5. Alternatives écartées

| Alternative | Raison du rejet |
|---|---|
| `403` pour un profil non public | Confirmerait son existence. |
| Statut modifiable par l'artisan | Contournerait la modération. |
| Stocker l'image telle qu'envoyée | Contenu et métadonnées non maîtrisés. |
| Création des administrateurs par l'API publique | Risque d'élévation de privilège. |
| Revalidation à chaque photo | Un profil validé quitterait l'annuaire à chaque envoi. |
