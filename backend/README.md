# DigiVitrine Lomé : backend

API Laravel 13 (PHP 8.4, PostgreSQL 17, Sanctum) de DigiVitrine Lomé, servie sous `/api/v1`.

L'installation, le lancement et les tests sont décrits dans le [README du dépôt](../README.md).

## Commandes utiles

```powershell
php artisan serve                       # serveur de développement
php artisan test                        # tests (base digivitrine_test)
.\vendor\bin\pint --test                # style du code
php artisan route:list --path=api/v1    # routes de l'API
php artisan staff:create                # créer un compte du personnel
```

## Documentation

- API : `../docs/api/`
- Décisions d'architecture : `../docs/adr/README.md`
- Comptes du personnel : `../docs/guides/comptes-personnel.md`
- Référentiel géographique : `database/data/geography/README.md`
