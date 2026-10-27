# ADR-13 : Avis, favoris, signalements, événements et notifications

- **Statut** : Validée
- **Date** : 2026-10-01
- **Amende** : ADR-10, sections 2.6 à 2.11
- **Liée à** : ADR-07 (UUID publics), ADR-10 (modèle de données), ADR-12 (rôle unique sur `users`)

## 1. Contexte

L'ADR-10 décrivait le modèle cible. L'implémentation des blocs D7 et D8 a nécessité quelques précisions et écarts, consignés ici pour que la documentation reste fidèle au code.

## 2. Décisions des blocs D7 (rappel des écarts)

| Table | Écart par rapport à l'ADR-10 | Raison |
|---|---|---|
| `services` | Pas de colonne de prix | Hors périmètre du MVP ; ajout possible par migration additive. |
| `photos` | `is_active` (booléen) à la place du statut `visible` / `hidden` ; `size_bytes` | Plus simple ; contraintes sur le type MIME (JPEG, PNG, WebP) et la taille (5 Mo maximum). La limite de 6 photos reste applicative (RG-06). |
| `artisan_categories` | Clé primaire composite `(artisan_id, category_id)`, pas d'`id` | Garantit l'absence de doublons ; la sous-catégorie est liée par une clé étrangère composite vers `subcategories(id, category_id)`. |
| `opening_hours` | Jour fermé : aucune heure ; jour ouvert : deux heures, fermeture strictement après l'ouverture | Un seul créneau « fermé » par jour ; les horaires qui passent minuit ne sont pas gérés. |

## 3. Décisions du bloc D8

### 3.1 `reviews`

- `uuid`, `artisan_id` (suppression en cascade), `user_id` (**facultatif**, `SET NULL` : l'avis survit à la suppression du compte, conformément à l'ADR-10 §4), `rating` (1 à 5, `CHECK`), `comment` (facultatif, non vide s'il est présent), `status`.
- Statuts : `pending` (par défaut), `published`, `hidden` (enum `ReviewStatus`). Les avis restent désactivés côté produit jusqu'à la V3.
- **Un seul avis par utilisateur et par artisan** (unicité sur `(artisan_id, user_id)`). Les avis anonymisés (`user_id` nul) ne se bloquent pas entre eux.
- « Un artisan ne peut pas noter son propre profil » est une règle **applicative** : elle porte sur plusieurs tables.

### 3.2 `favorites`

- Clé primaire `id` et **unicité sur `(user_id, artisan_id)`**, au lieu de la clé composite prévue dans l'ADR-10. Raison : Eloquent ne sait pas supprimer un enregistrement identifié par une clé composite, ce qui rendait le modèle `Favorite` peu pratique.
- Suppression en cascade des deux côtés.

### 3.3 `reports`

- `uuid`, `artisan_id` (cascade), `user_id` facultatif (`SET NULL`, signalements anonymes possibles), `reason`, `description`, `status`, `resolved_by` (`SET NULL`), `resolved_at`.
- Motifs (enum `ReportReason`) : `false_information`, `inappropriate_content`, `duplicate_profile`, `wrong_contact`, `other`. Statuts (enum `ReportStatus`) : `open`, `resolved`, `dismissed`.
- Contraintes : le motif `other` exige une description ; une description présente n'est jamais vide ; un signalement ouvert n'a ni date ni auteur de résolution ; un signalement clos a une date de résolution.
- **`resolved_by` n'est volontairement pas exigé** pour un signalement clos : si le compte du modérateur est supprimé, la clé étrangère (`SET NULL`) mettrait la colonne à vide et violerait la contrainte.
- Ce document remplace le nom `handled_by` de l'ADR-10 par `resolved_by`.

### 3.4 `profile_events`

- `artisan_id` (cascade), `event_type`, `user_id` facultatif (`SET NULL`), `metadata` (`jsonb` facultatif, **doit être un objet JSON**), `created_at` seulement (pas de `updated_at`).
- Types (enum `ProfileEventType`) : `profile_view`, `phone_click`, `whatsapp_click`, `share_click`, `direction_click`, `favorite_add`.
- **Aucune donnée personnelle** dans `metadata` (ni adresse IP, ni numéro de téléphone) : règle de conception, à faire respecter par le code d'écriture.
- Index prévus pour un volume élevé : `(artisan_id, event_type, created_at)` pour les statistiques, **BRIN** sur `created_at` (très léger, adapté à une table qui ne fait que croître, utile pour purger ou agréger les anciennes données), et index partiel sur `user_id` pour que la suppression d'un compte ne parcoure pas toute la table.

### 3.5 Notifications : mécanisme natif de Laravel

- La table `notifications` suit le schéma standard de Laravel (`id` UUID, `type`, `notifiable_type`, `notifiable_id`, `data`, `read_at`, `timestamps`), avec `data` en `jsonb`.
- Le **titre** et le **corps** d'une notification sont les clés `title` et `body` du contenu `data`. L'utilisateur destinataire est `notifiable_id`.
- Aucun modèle ni factory maison : le trait `Notifiable` de `User` fournit déjà `$user->notifications`, `unreadNotifications` et `markAsRead()`.
- Raison : une table maison nommée `notifications` entrerait en conflit avec le canal « database » de Laravel, qui écrit dans cette table et attend ce schéma. Une seconde architecture serait inutile.
- Limite : la relation étant polymorphe, aucune clé étrangère n'est possible sur `notifiable_id`. Une suppression physique d'un compte laisse des notifications orphelines (à nettoyer par une tâche planifiée, plus tard).

## 4. Alternatives écartées

| Alternative | Raison du rejet |
|---|---|
| Table `notifications` maison (`user_id`, `title`, `body`) | Conflit avec le canal « database » de Laravel, deux systèmes à maintenir. |
| Clé primaire composite sur `favorites` | Modèle Eloquent peu pratique (suppression, relations). |
| Colonne `platform` sur `profile_events` | Peut être portée par `metadata` tant qu'aucune statistique ne l'exige. |
| Contrainte en base interdisant l'auto-évaluation | Nécessite un déclencheur multi-tables ; règle laissée à l'application. |

## 5. Conséquences

- Le modèle de données de la phase « fondation » est complet : 15 tables (10 pour le catalogue et les artisans, 5 pour les interactions).
- Restent hors périmètre : abonnements, publicités, paiements, notifications push, statistiques avancées.
