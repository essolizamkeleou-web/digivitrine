# ADR-12 : Rôle unique sur `users`, `is_active` et format du téléphone

- **Statut** : Validée
- **Date** : 2026-10-01
- **Amende** : ADR-10, §2.4 (utilisateurs et rôles) et la colonne `status` de `users`
- **Liée à** : ADR-07 (UUID publics), ADR-10 (modèle de données), ADR-11 (langue du code)

## 1. Contexte

L'ADR-10 prévoyait de gérer les rôles avec un paquet de permissions (spatie/laravel-permission) et un champ `status` (`active` / `suspended`) sur `users`. Pour le MVP, quatre rôles suffisent et un compte n'en a qu'un : artisan, agent de terrain, modérateur, administrateur. Un système complet de rôles et permissions ajouterait des tables et de la complexité avant d'en avoir besoin.

## 2. Décisions

1. **Une colonne `role`** sur `users` (`varchar(20)`, défaut `artisan`), protégée par un `CHECK` sur les quatre valeurs : `artisan`, `field_agent`, `moderator`, `admin`. Un utilisateur a **un seul rôle**.
2. **Enum PHP `App\Enums\UserRole`** (string-backed) pour manipuler le rôle dans le code : autocomplétion, comparaisons sûres, pas de faute de frappe silencieuse. Pas d'enum natif PostgreSQL (évolution difficile).
3. **`is_active`** (booléen, défaut `true`) remplace `status` pour bloquer un compte. Plus simple, suffisant pour le MVP.
4. **Téléphone** : `phone` nullable, unique, au format **E.164 générique** en base (`+` suivi de 8 à 15 chiffres, sans zéro initial). La validation spécifique au Togo (`+228` et 8 chiffres, formats `90 12 34 56`, `00228…`) est faite par l'application, à la phase d'authentification. La base reste ainsi compatible avec d'autres pays.
5. **Au moins un identifiant** : `CHECK (email IS NOT NULL OR phone IS NOT NULL)`. Un compte sans e-mail ni téléphone ne pourrait ni se connecter ni être contacté. Cela ne rend pas `phone` obligatoire.
6. **E-mail en minuscules** : normalisé par le modèle, et garanti par un `CHECK`, pour éviter deux comptes `Kofi@…` et `kofi@…`.
7. **Protection contre l'assignation en masse** : seuls `name`, `email`, `phone` et `password` sont assignables en masse. `role` et `is_active` ne le sont pas, pour qu'une requête contenant `role=admin` ne puisse jamais élever un privilège.
8. **`uuid` public** (généré par PostgreSQL) et **suppression logique** (`deleted_at`), conformément aux ADR-07 et ADR-10.
9. **Migration additive** : la migration `users` de Laravel reste inchangée, une nouvelle migration la transforme.

## 3. Conséquences

**Positives** : modèle simple à comprendre, une seule table d'identité, garanties d'intégrité au niveau de la base, extension possible à d'autres pays.

**Limites assumées** :

- Un utilisateur ne peut pas cumuler plusieurs rôles. Si ce besoin apparaît, on migrera vers une table de rôles ou vers spatie/laravel-permission : les données de `role` se convertissent facilement.
- Pas de permissions fines : elles seront exprimées par des *policies* Laravel qui lisent le rôle.
- Un compte supprimé logiquement conserve son téléphone et son e-mail (contraintes d'unicité inchangées).

## 4. Alternatives écartées

| Alternative | Raison du rejet |
|---|---|
| spatie/laravel-permission dès maintenant | Quatre tables de plus pour un besoin simple ; reporté tant que les rôles multiples ne sont pas nécessaires. |
| Table `roles` maison | Même complexité sans bénéfice pour le MVP. |
| Colonne `role` en texte libre sans `CHECK` | Les fautes de frappe (`admn`) passeraient inaperçues. |
| `CHECK` limité à `+228` | Bloquerait l'extension à d'autres pays. |
| Conserver `status` (`active` / `suspended`) | Redondant avec `is_active` pour deux états. |
