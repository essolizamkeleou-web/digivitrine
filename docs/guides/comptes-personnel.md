# Comptes du personnel : administrateurs, modérateurs, agents de terrain

L'inscription publique (`POST /api/v1/auth/register`) ne crée que des comptes **artisan** : un rôle `admin`, `moderator` ou `field_agent` demandé par le client est refusé. Les comptes du personnel se créent donc **uniquement en ligne de commande**, par une personne qui a accès au serveur. Le **premier administrateur** se crée ainsi : sans lui, aucun profil ne peut être validé.

## Créer un compte

Depuis le dossier `backend` :

```powershell
php artisan staff:create
```

La commande pose les questions manquantes : rôle, nom, téléphone et/ou e-mail, puis le mot de passe **deux fois**, saisie masquée. Elle affiche un récapitulatif et demande confirmation (la réponse par défaut est « non »).

Les valeurs peuvent aussi être passées en options. **Le mot de passe, lui, ne se passe jamais en option** : il n'existe aucune option pour cela.

```powershell
php artisan staff:create --role=admin --name="Nom Prénom" --phone="90 12 34 56"
php artisan staff:create --role=moderator --name="Nom Prénom" --email="moderateur@exemple.tg"
```

| Option | Valeurs |
|---|---|
| `--role` | `admin`, `moderator` ou `field_agent` (`artisan` est refusé : les artisans s'inscrivent par l'application) |
| `--name` | 2 à 120 caractères |
| `--phone` | numéro togolais : `90 12 34 56`, `90123456`, `+228 90123456`, `00228 90123456`. Enregistré sous la forme `+228XXXXXXXX` |
| `--email` | adresse valide, enregistrée en minuscules |

Il faut **au moins un téléphone ou un e-mail** (il sert d'identifiant de connexion). Si ni `--phone` ni `--email` n'est donné, la commande les demande.

## Rôles

| Rôle | Ce qu'il peut faire |
|---|---|
| `admin`, `moderator` | Endpoints `/api/v1/admin/*` : valider, refuser, suspendre des profils, traiter les signalements (mêmes droits) |
| `field_agent` | Créer des profils pour des artisans (sans accès à l'administration) |

Le rôle est celui du système existant (`UserRole`) : aucune nouvelle table de permissions.

## Sécurité

- **Mot de passe** : saisi de façon masquée, deux fois, **12 caractères minimum** (72 octets maximum). Il n'apparaît ni dans l'historique du terminal, ni dans la liste des processus, ni dans les journaux. Il est haché avec le mécanisme de Laravel avant d'être enregistré.
- **Mode non interactif refusé** : la commande ne s'exécute pas avec `--no-interaction`, pour qu'un mot de passe ne soit jamais transmis par un script.
- **Doublons** : téléphone et e-mail sont vérifiés (comptes supprimés compris) **avant** la saisie du mot de passe.
- **Journal** : la commande écrit `staff.created` avec **uniquement** l'identifiant public du compte et son rôle (ni mot de passe, ni nom, ni téléphone, ni e-mail).
- Exécutez-la seulement sur un serveur de confiance. Choisissez un mot de passe unique, jamais réutilisé ailleurs.

## Vérifier et utiliser le compte

Le compte se connecte par l'API avec son téléphone ou son e-mail :

```powershell
php artisan serve
```

Dans un autre terminal :

```powershell
$secure = Read-Host "Mot de passe" -AsSecureString
$plain = [System.Net.NetworkCredential]::new("", $secure).Password
$body = @{ login = "90 12 34 56"; password = $plain } | ConvertTo-Json
Invoke-RestMethod -Method Post -Uri http://127.0.0.1:8000/api/v1/auth/login -ContentType "application/json" -Body $body
```

La réponse contient un jeton à envoyer dans l'en-tête `Authorization: Bearer <jeton>`. Voir `docs/api/authentication.md` et `docs/api/admin.md`.

## Dépannage

| Message | Cause | Action |
|---|---|---|
| « Cette commande est interactive… » | Lancée avec `--no-interaction` ou sans terminal | Lancer dans un terminal ordinaire |
| « Ce rôle ne peut pas être créé ici » | Rôle `artisan` ou inconnu | Choisir `admin`, `moderator` ou `field_agent` |
| « déjà utilisé » | Téléphone ou e-mail déjà pris | Utiliser une autre valeur ou le compte existant |
| « Les deux mots de passe ne correspondent pas » | Faute de frappe | Relancer la commande |
| « Annulé » | Réponse « non » à la confirmation | Relancer la commande |

## Limites actuelles

Il n'existe pas encore de commande pour **changer le mot de passe**, **désactiver** ou **supprimer** un compte du personnel, ni de procédure de mot de passe oublié. Ces points sont prévus dans les tâches suivantes.
