# DigiVitrine Lomé

> L'inclusion numérique au service des artisans locaux

DigiVitrine Lomé est une plateforme solidaire qui donne aux artisans et micro-entrepreneurs de Lomé une vitrine en ligne gratuite : profil, services, réalisations, localisation et moyens de contact (appel, WhatsApp).

## État du projet

| Élément | État |
|---|---|
| API v1 : authentification, catalogue, artisans, recherche, galerie de photos, administration | Terminée et testée |
| Comptes du personnel (`php artisan staff:create`) | Terminé |
| Référentiel géographique du Grand Lomé | Fichiers de travail prêts ; **aucun quartier renseigné**, implémentation à venir (ADR-16) |
| Services et horaires modifiables, signalement, réclamation de profil, mot de passe oublié, table d'audit | À faire |
| Application Flutter (Android puis Windows), administration Filament | À faire |

La suite de tests comptait 419 tests au dernier commit de fonctionnalité.

## Structure du dépôt

## Prérequis

Windows 11, Git, Docker Desktop, PHP 8.4 avec les extensions `gd` (avec WebP) et `fileinfo` (`exif` recommandée), Composer 2.

## Installation (développement)

Depuis la racine du dépôt :

```powershell
docker compose up -d
```

Attendre que le conteneur `digivitrine-postgres` soit `healthy`, puis :

```powershell
cd backend
composer install
Copy-Item .env.example .env
php artisan key:generate
```

Dans `backend/.env`, renseigner la base : `DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1`, `DB_PORT=5432`, `DB_DATABASE=digivitrine`, `DB_USERNAME=digivitrine` et `DB_PASSWORD` (voir `docker-compose.yml`). Puis :

```powershell
php artisan migrate
php artisan db:seed --class=ReferenceDataSeeder
php artisan staff:create
```

`staff:create` crée le premier administrateur (mot de passe saisi deux fois, sans affichage). Voir `docs/guides/comptes-personnel.md`.

**Ne jamais lancer `migrate:fresh` ni `db:wipe` sur une base qui contient des données.**

La base de test `digivitrine_test` est créée par `infra/postgres/init` au premier démarrage d'un volume vide. Si elle manque :

```powershell
docker exec digivitrine-postgres psql -U digivitrine -d digivitrine -c "CREATE DATABASE digivitrine_test;"
```

## Lancer l'API

```powershell
cd backend
php artisan serve
```

Vérification : `http://127.0.0.1:8000/api/v1/health` répond `{"data":{"status":"ok"}}`.

## Tests et qualité

```powershell
cd backend
php artisan test                       # tests sur digivitrine_test uniquement
.\vendor\bin\pint --test               # style du code
php database/data/geography/check.php  # validateur du référentiel géographique
```

Un garde-fou dans `backend/tests/TestCase.php` refuse d'exécuter les tests sur une base dont le nom ne finit pas par `_test`.

## Documentation

- Décisions d'architecture : `docs/adr/README.md`
- API : `docs/api/` (authentification, catalogue, artisans, recherche, photos, administration)
- Guides : `docs/guides/comptes-personnel.md`
- Référentiel géographique : `backend/database/data/geography/README.md`

## Sécurité

- `backend/.env` n'est jamais versionné ; ne jamais y coller de secret dans un message ou un ticket.
- Le mot de passe de PostgreSQL de `docker-compose.yml` ne sert qu'au développement local (base liée à `127.0.0.1`). Il ne doit **jamais** être réutilisé ailleurs, et sera sorti du dépôt avant toute mise en ligne.
- Aucun rôle privilégié ne peut être demandé par l'inscription publique.

## Contribuer

Branche de travail `feature/...`, commits courts en français. Un commit n'est créé que si les tests passent, que Pint est vert et qu'aucune migration n'est en attente.
