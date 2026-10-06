# AURA – Agenda patient et organisation des soins

SI miroir du service de réanimation (projet AGILE, 4IF INSA Lyon). Stack : PHP, MariaDB, Apache, MVC.

## Installation

```bash
git clone <url-du-depot> aura && cd aura
composer install
cp config/config.example.php config/config.php   # puis renseignez VOS identifiants
mysql -u <utilisateur> -p <base> < database/schema.sql
```

Apache doit servir uniquement le dossier `public/`.

## Commandes

- `composer test` : lancer les tests
- `composer cs` / `composer cs:fix` : vérifier / corriger le style PSR-12

## Règles de travail

1. Jamais de push direct sur `main` : une branche par user story (`feature/US01-admission`).
2. Pull request vers `main` : CI verte et relecture par un autre membre.
3. Aucune donnée réelle de patient, aucun identifiant ni mot de passe dans le dépôt.
