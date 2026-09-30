# ADR-10 : Modèle de données initial DigiVitrine

- **Statut** : Validée
- **Date** : 2026-09-30
- **Phase du projet** : fondation du backend (avant authentification, API et administration)
- **Liée à** : ADR-01 (monolithe modulaire), ADR-05 (stockage d'images), ADR-07 (UUID publics), ADR-11 (langue du code)

## 1. Contexte

DigiVitrine doit référencer des artisans de Lomé, permettre de les rechercher par métier et par quartier, afficher leurs services, leurs photos et leurs contacts, et mesurer l'usage. Les profils sont créés soit par l'artisan, soit par un agent de terrain, puis validés par un modérateur.

Il faut figer un modèle de données relationnel avant d'écrire l'API, pour que l'authentification, la recherche, l'administration et l'application Flutter s'appuient sur une base stable.

## 2. Décisions

### 2.1 PostgreSQL 17

- Version utilisée en développement : PostgreSQL 17.11 (image Docker `postgres:17`), supportée jusqu'en novembre 2029.
- Même version visée en production, pour avoir le même comportement partout.
- Raisons : intégrité référentielle stricte, contraintes `CHECK`, index partiels, extensions `pg_trgm` et `unaccent` (recherche tolérante aux fautes), PostGIS possible plus tard.
- Les extensions de recherche ne sont **pas** installées dans cette mission : elles arriveront avec la phase « recherche », par une migration dédiée.

### 2.2 Modèle relationnel

Le modèle est relationnel et normalisé. Les règles métier qui peuvent être garanties par la base le sont (clés étrangères, unicité, `CHECK`) ; les autres (par exemple « 6 photos maximum ») sont appliquées par le code applicatif.

### 2.3 Identifiants (ADR-07)

- Chaque table a une clé primaire interne `bigint` auto-incrémentée.
- Les entités adressées individuellement par les clients (`users`, `artisans`, `services`, `photos`, `reviews`, `reports`) ont en plus une colonne `uuid` unique, seule exposée dans l'API. Cela évite de révéler des identifiants séquentiels et de permettre l'énumération.
- Les tables de référence (`cities`, `neighborhoods`, `categories`, `subcategories`) ont un `slug` unique, lisible dans les filtres et les liens.

### 2.4 Utilisateurs et rôles

- **Une seule table `users`** pour tous les types d'acteurs. Il n'existe pas de tables séparées pour artisans, agents ou administrateurs.
- Rôles prévus : `artisan`, `field_agent`, `moderator`, `admin`. Ils seront gérés par un paquet dédié (spatie/laravel-permission), installé dans la phase « rôles et permissions », pas dans cette mission.
- La table `users` par défaut de Laravel est adaptée par une **nouvelle migration** (aucune modification de la migration déjà appliquée) :
  - ajout de `phone` : facultatif, unique, normalisé `+228XXXXXXXX` ;
  - `email` devient facultatif (l'unicité est conservée ; PostgreSQL accepte plusieurs valeurs nulles) ;
  - ajout de `status` : `active` ou `suspended`, protégé par un `CHECK` ;
  - ajout de `uuid` et de la suppression logique (`deleted_at`).
- `phone` n'est pas encore obligatoire. Cette décision sera revue à la phase « authentification ».

### 2.5 Profils artisans

Table `artisans`, qui représente la vitrine et pas le compte :

- `user_id` : propriétaire du profil, **facultatif et unique**. Un agent de terrain peut créer un profil pour un artisan qui n'a pas encore de compte ; l'artisan le réclamera ensuite.
- `created_by` : auteur de la création, **obligatoire** (l'artisan lui-même, un agent ou un administrateur).
- `validated_by` et `validated_at` : traçabilité de la validation.
- `neighborhood_id` : quartier obligatoire.
- `business_name`, `description`, `phone`, `whatsapp_phone`, `address`, `latitude`, `longitude`.
- `status` : `draft`, `pending`, `validated`, `rejected`, `suspended`.
- `status_reason` : motif.
- Suppression logique (`deleted_at`) pour conserver l'historique et l'audit.

Contraintes garanties par la base :

- `status` limité aux cinq valeurs.
- Un profil `rejected` ou `suspended` **doit** avoir un motif non vide.
- `phone` et `whatsapp_phone` respectent le format `+228` suivi de 8 chiffres.
- `latitude` entre -90 et 90, `longitude` entre -180 et 180, et les deux renseignées ensemble ou pas du tout.
- Un numéro de téléphone ne peut appartenir qu'à un seul profil **non supprimé** : index unique partiel sur `phone` là où `deleted_at IS NULL`.

Cycle de vie : `draft → pending → validated`, ou `pending → rejected → pending` après correction, ou `validated → suspended`. La règle des transitions est appliquée par le code (service), pas par la base.

### 2.6 Catégories et sous-catégories

- `categories` (exemple : Couture) et `subcategories` (exemple : Couture pour femmes) sont deux tables liées par `subcategories.category_id`.
- Relation N-N entre artisans et catégories par la table `artisan_categories`, qui contient aussi `subcategory_id` (facultatif) et `is_primary`.
- Une clé étrangère **composite** `(subcategory_id, category_id)` vers `subcategories(id, category_id)` garantit qu'une sous-catégorie appartient bien à la catégorie indiquée.
- Unicité `(artisan_id, category_id)` et une seule catégorie principale par artisan (index partiel sur `is_primary`).
- Les tables de synonymes (« couturier », « tailleur ») et d'alias de quartiers sont volontairement **reportées** à la phase « recherche ».

### 2.7 Localisation

- `cities` et `neighborhoods` sont entièrement en base : rien n'est codé en dur (règle RG-08 et besoin d'extension à d'autres villes).
- `cities` porte un code pays (`TG` par défaut) pour préparer d'autres pays.
- Un quartier appartient à une seule ville ; unicité `(city_id, slug)`.
- Coordonnées en `decimal` (latitude, longitude). **PostGIS est reporté à la V2** : il ne sera nécessaire qu'au tri et au filtrage par distance.

### 2.8 Services, horaires et galerie

- `services` : nom, description, prix « à partir de » facultatif (franc CFA, entier), position d'affichage.
- `opening_hours` : une ligne par créneau (jour ISO 1 à 7, heure d'ouverture et de fermeture), ce qui permet une pause de midi. Contrainte : fermeture postérieure à l'ouverture. Les horaires qui passent minuit ne sont pas gérés dans cette version.
- `photos` : chemin et disque de stockage (abstraction prévue par ADR-05), dimensions, taille, `position`, `is_primary` (une seule image principale par artisan, par index partiel) et statut de modération (`visible` ou `hidden`). La limite de 6 photos (RG-06) est vérifiée par le code.

### 2.9 Avis, favoris, signalements

Les tables sont créées, **sans logique ni route** dans cette mission :

- `reviews` : note de 1 à 5 (`CHECK`), commentaire, statut de modération (`pending`, `published`, `hidden`), un seul avis par utilisateur et par artisan. Les avis restent désactivés côté produit jusqu'à la V3.
- `favorites` : table pivot entre utilisateurs et artisans, clé primaire composite.
- `reports` : signalement d'un profil, avec motif, détails, statut (`open`, `resolved`, `dismissed`) et modérateur qui l'a traité. `user_id` est facultatif pour garder la possibilité de signalements anonymes.

### 2.10 Événements et statistiques

`profile_events` enregistre les actions liées à un profil : vue, clic sur appel, clic sur WhatsApp, partage. Colonnes : `artisan_id`, `event_type`, `user_id` facultatif, `platform` facultative (android, windows), date. **Aucune adresse IP ni donnée personnelle** n'est stockée. Cette table est la base des statistiques artisan (V2) et des indicateurs du pilote. L'écriture se fera plus tard, avec une file de jobs pour ne pas ralentir l'API.

### 2.11 Notifications

On utilise la table native de Laravel (`notifications`), créée par la commande artisan prévue pour cela. Aucune table maison, aucune logique dans cette mission.

### 2.12 Statuts : chaînes de caractères, Enums PHP et `CHECK`

Les statuts sont stockés comme `string` en base, protégés par un `CHECK`, et représentés dans le code par des Enums PHP. L'enum natif PostgreSQL est écarté : il est pénible à faire évoluer (ajout et retrait de valeurs).

## 3. Index prévus

| Table | Index |
|---|---|
| `artisans` | `status`, `neighborhood_id`, `created_by`, `validated_by`, `(status, neighborhood_id)` pour la recherche publique ; unicité partielle sur `phone` ; `user_id` unique |
| `neighborhoods` | `city_id` ; unicité `(city_id, slug)` |
| `subcategories` | `category_id` ; unicité `(id, category_id)` |
| `artisan_categories` | `category_id`, `subcategory_id` ; unicité `(artisan_id, category_id)` ; unicité partielle de la catégorie principale |
| `services`, `photos`, `opening_hours` | `artisan_id` |
| `reviews` | `artisan_id`, `user_id` ; unicité `(artisan_id, user_id)` |
| `reports` | `artisan_id`, `status` |
| `profile_events` | `(artisan_id, event_type, created_at)` |

## 4. Règles de suppression (clés étrangères)

- `artisans → neighborhoods` : `RESTRICT` (on ne supprime pas un quartier utilisé).
- `artisans → users` (propriétaire) : `SET NULL`. Si le compte disparaît, la vitrine reste.
- `artisans → users` (`created_by`, `validated_by`) : `RESTRICT`, pour préserver la traçabilité.
- Tables enfants d'un artisan (`services`, `photos`, `opening_hours`, `artisan_categories`, `favorites`, `profile_events`) : `CASCADE`. Les profils étant supprimés logiquement en pratique, la suppression physique reste exceptionnelle.
- `reviews` et `reports` : conservés même si le compte de l'auteur disparaît (`SET NULL` sur `user_id` quand c'est possible).

## 5. Alternatives écartées

| Alternative | Raison du rejet |
|---|---|
| UUID comme clé primaire partout | Index plus lourds, jointures plus lentes, aucun bénéfice réel ici. Un `uuid` public suffit. |
| Une seule table de catégories auto-référencée | Plus flexible, mais moins lisible pour un projet pédagogique. Le modèle à deux tables a été validé. |
| Enum natif PostgreSQL | Évolution difficile. |
| Tables séparées par type d'utilisateur | Duplication de l'authentification et des permissions. |
| PostGIS dès maintenant | Complexité inutile avant la géolocalisation (V2). |
| Stockage des événements dans les journaux applicatifs | Impossible à interroger proprement pour les statistiques. |

## 6. Conséquences

**Positives** : intégrité garantie par la base, modèle évolutif vers d'autres villes et pays, traçabilité (qui a créé, qui a validé), séparation nette entre compte et vitrine.

**À surveiller** :

- Index unique partiel sur `phone` et clé étrangère composite : instructions SQL spécifiques à PostgreSQL, écrites en SQL brut dans les migrations. Le projet n'est donc **plus portable vers SQLite**, d'où la décision de tester sur PostgreSQL.
- `profile_events` peut grossir vite : prévoir une politique de conservation et d'agrégation avant la V2.
- La règle « 6 photos maximum » et les transitions de statut reposent sur le code : elles devront être couvertes par des tests.

## 7. Hors périmètre de cette décision

Authentification, rôles et permissions, API REST, recherche avancée (synonymes, alias, trigrammes), géolocalisation PostGIS, administration Filament, paiements, publicités, IA, notifications avancées.
