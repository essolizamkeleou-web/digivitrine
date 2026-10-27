# Décisions d'architecture (ADR)

| N°     | Décision                                            | Statut                                    |
|--------|-----------------------------------------------------|-------------------------------------------|
| ADR-01 | Monolithe modulaire                                 | Validée                                   |
| ADR-02 | Monorepo Git                                        | Validée                                   |
| ADR-03 | API REST versionnée `/api/v1`                       | Validée                                   |
| ADR-04 | Administration avec Filament (web)                  | Validée                                   |
| ADR-05 | Stockage d'images derrière une abstraction          | Validée                                   |
| ADR-06 | File de jobs en base, Redis plus tard               | Validée                                   |
| ADR-07 | UUID publics + identifiants internes                | Validée                                   |
| ADR-08 | Stack : Flutter, Laravel 13, Filament 5, PostgreSQL | Validée                                   |
| ADR-09 | Code en français (métier), sans accents             | Remplacée par ADR-11                      |
| [ADR-10](ADR-10-modele-de-donnees-initial.md) | Modèle de données initial | Validée (§2.4 amendé par ADR-12, §2.6 à 2.11 par ADR-13) |
| [ADR-11](ADR-11-langue-du-code.md)            | Langue du code et de la documentation | Validée |
| [ADR-12](ADR-12-role-unique-sur-users.md)     | Rôle unique sur `users`, `is_active` et format du téléphone | Validée |
| [ADR-13](ADR-13-interactions-evenements-notifications.md) | Avis, favoris, signalements, événements et notifications | Validée |
| [ADR-14](ADR-14-api-v1-authentification-sanctum.md) | API v1, authentification Sanctum, format JSON et limitation de débit | Validée |
| [ADR-15](ADR-15-conventions-api-v1.md)        | Conventions de l'API v1 (catalogue, artisans, recherche, photos, administration) | Validée |
| [ADR-16](ADR-16-referentiel-geographique-grand-lome.md) | Référentiel géographique du Grand Lomé | Acceptée (implémentation à venir) |

Seuls les ADR 10 à 16 ont un fichier détaillé pour l'instant. Les ADR 01 à 09 sont listés comme décisions prises ; leurs fichiers seront rédigés plus tard.
