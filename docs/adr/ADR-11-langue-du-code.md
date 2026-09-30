# ADR-11 : Langue du code et de la documentation

- **Statut** : Validée
- **Date** : 2026-09-30
- **Remplace** : ADR-09 (« Code en français (métier), sans accents »)

## 1. Contexte

L'ADR-09 prévoyait des noms métier en français. Les décisions ultérieures, notamment le modèle de données validé (`artisans`, `neighborhoods`, `categories`, `photos`, `reviews`…), utilisent des noms anglais. Deux conventions dans le même projet créeraient des incohérences (`quartiers` à côté de `categories`, par exemple).

## 2. Décision

| Élément | Langue |
|---|---|
| Noms de classes, méthodes, variables, fichiers de code | **Anglais**, sans accents |
| Tables, colonnes, index, contraintes | **Anglais**, `snake_case` |
| Routes et clés JSON de l'API | **Anglais** |
| Valeurs techniques stockées (statuts, types d'événements) | **Anglais** (exemple : `validated`, `whatsapp_click`) |
| Interface utilisateur, messages d'erreur et de validation | **Français**, via les fichiers de traduction (`lang/`), pour permettre d'autres langues plus tard (RNF-11) |
| Documentation (ADR, cahier des charges, guides, README) | **Français** |
| Messages de commit | Préfixe de la convention en anglais (`feat:`, `fix:`, `docs:`, `refactor:`, `test:`, `chore:`), description en **français** |

Les termes métier togolais sans équivalent évident (par exemple le nom d'un quartier) restent des **données** en base, jamais des noms de code.

## 3. Conséquences

- L'ADR-09 passe au statut « Remplacée par ADR-11 ».
- Les valeurs affichées à l'utilisateur (« Validé », « En attente ») sont des traductions des valeurs techniques, et non les valeurs stockées.
- Toute documentation qui mentionnait des noms de tables en français devra être alignée.

## 4. Alternative écartée

Tout en français : aurait rendu le code moins lisible pour les outils, les bibliothèques et les futurs contributeurs, et aurait mélangé deux langues à cause des noms imposés par Laravel (`created_at`, `belongsTo`…).
