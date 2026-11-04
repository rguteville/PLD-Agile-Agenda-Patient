# AURA – Agenda patient et organisation des soins

SI miroir du service de réanimation d'un hôpital (projet AGILE, 4IF INSA Lyon).

**Documentation du projet (dossier de cadrage, modèle de données, backlog)** : [🚑 PLD Agile – Agenda Patient (Notion)](https://app.notion.com/p/PLD-Agile-Agenda-Patient-3f04f190af9b80ceaae0c2f31cffa627?source=copy_link)

## Sommaire

1. [Stack et versions](#stack-et-versions)
2. [Structure du projet](#structure-du-projet)
3. [Pages et routes](#pages-et-routes)
4. [Installation](#installation)
5. [Travailler au quotidien](#travailler-au-quotidien)
6. [Intégration continue](#intégration-continue)
7. [VM de démo et déploiement](#vm-de-démo-et-déploiement)
8. [Comment tester (scénario de démo)](#comment-tester-scénario-de-démo)
9. [Zone dépannage rapide](#zone-dépannage-rapide)
10. [Règles de l'équipe](#règles-de-léquipe)
11. [Conventions](#conventions)

**Reprendre le projet** : [docs/REPRISE.md](docs/REPRISE.md) (lancement, cheminement d'une requête, où trouver
chaque fonctionnalité, règles RG1 à RG10, hypothèses, dette, ajouter une page ou une règle).

Documentation détaillée : [architecture](docs/ARCHITECTURE.md), [tests](docs/TESTS.md),
[interface](docs/UI-GUIDE.md), [système de design initial](docs/ui.md), [audits](docs/audit/),
[journal des changements](CHANGELOG.md).

---

Les bonnes pratiques

- nom d'attributs SQL et noms de variables camelCase : exempleDeCamel
- noms de classes et tables PascalCase : ExempleDePascal
- code et BD en anglais (HL7) ; affichage et commentaires en français

## Stack et versions

Il est préférable d'utiliser **les mêmes versions que la VM de démo**, pour qu'un code qui marche sur un poste marche aussi sur la machine virtuelle de démonstration.

| Outil    | Version  | Rôle                                   |
| -------- | -------- | -------------------------------------- |
| PHP      | **8.4**  | Langage serveur                        |
| MariaDB  | **11.8** | Base de données                        |
| Composer | 2.x      | Dépendances PHP et commandes du projet |

## Structure du projet

```text
.github/workflows/  CI (GitHub Actions) : validate, php -l, PSR-12, schéma + données, PHPUnit
bin/                scripts en ligne de commande : generate-agenda.php (génération quotidienne de l'agenda, cron)
config/             config.example.php versionné ; config.php local, jamais commité
database/           schema.sql (structure complète), test.sql (données fictives), demo-today.sql (démo recalée
                    sur le jour courant), migrations/ (ALTER additifs pour une base existante)
docs/               ARCHITECTURE.md, TESTS.md, UI-GUIDE.md, ui.md (système de design initial), audit/ (audits datés)
public/             racine web : index.php (routes), css/style.css (unique), js/ (vanilla), fonts/ (vendorisées)
scripts/            deploy.sh (mise à jour de la VM de démo)
src/                code PHP (PSR-4, namespace Aura\)
  Core/             Router, Database (PDO unique), View (rendu + échappement), Csrf, DateInput (JJ/MM/AAAA)
  Controller/       lit la requête, mappe les champs, appelle un service, répond HTML ou JSON
  Service/          règles métier ; validation pure ; écriture en transaction
  Dao/              seul endroit où apparaissent les colonnes SQL ; requêtes préparées
  Domain/           entités immuables, catalogues (RecordFields, ClinicalScales)
  View/             layouts/, components/, pages/
tests/              miroir de src/ ; Dao/* et *IntegrationTest = base réelle, sautés sans DB_HOST ; Http/ = bout en bout
CHANGELOG.md        une entrée par pull request
```

---

## Pages et routes

Toutes les routes sont déclarées dans `public/index.php` (chemins exacts, identifiants en query string ou dans le
corps du POST). Détail des réponses, des jetons CSRF et de l'authentification factice :
[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md#pages-et-endpoints).

| Écran ou action | Routes |
| --- | --- |
| Admission | `GET /` (formulaire, affichable sans base), `POST /patients/create` |
| Recherche d'un patient à l'admission (JSON) | `GET /patients/recherche?q=`, `GET /patients/fiche?id=`, `GET /patients/recherche-ipp?ipp=` |
| Liste des patients | `GET /patients?q=` |
| Modification de l'identité | `GET /patients/modifier?id=`, `POST /patients/modifier?id=` |
| Suppression logique d'un patient | `GET /patients/supprimer?id=`, `POST /patients/supprimer?id=` |
| Dossier du séjour | `GET /sejour?id=` |
| Allergies depuis le dossier | `POST /sejour/allergies/ajouter`, `POST /sejour/allergies/retirer` |
| Modifications du dossier (JSON) | `POST /sejour/champ/modifier` (un champ), `POST /sejour/partie/modifier` (une partie), `POST /sejour/cloturer` (fin de séjour), `POST /sejour/deces` (décès, qui termine le séjour) |
| Prescriptions | `GET /care-plans?idEpisodeOfCare=`, `GET /care-plans/new?idEpisodeOfCare=`, `POST /care-plans/create`, `POST /care-plans/validate`, `POST /care-plans/stop`, `GET /care-plans/protocol-lines` (JSON) |
| Agenda du patient | `GET /agenda-patient?id=` (ou `?numSejour=`), `&date=AAAA-MM-JJ`, `&vue=semaine` |
| Action sur un soin | `POST /agenda-patient/soin` (réalisé, non effectué, note) |
| Agenda du service | `GET /agenda-service?idHealthcareService=&date=&vue=lits\|liste&sejour=` |
| Équipes par rotation | `GET /equipes`, `POST /equipes/creer`, `POST /equipes/retirer` |
| Mon planning | `GET /mon-planning?idPractitioner=&date=&rotation=matin\|apres-midi\|nuit` |

Toute autre adresse affiche la page 404.

---

## Installation

### 1. Installer les outils

<details>
<summary><b>macOS</b></summary>
Avec [Homebrew](https://brew.sh) (si `brew --version` ne répond pas, installez-le d'abord et lancez les commandes « Next steps » qu'il affiche) :
 
```bash
brew install php@8.4 mariadb@11.8 composer gh
```
 
Ces versions ne sont pas activées par défaut (les paquets `php` et `mariadb` sans numéro installent des versions plus récentes). Ajoutez-les au PATH :
 
```bash
echo 'export PATH="$(brew --prefix php@8.4)/bin:$(brew --prefix mariadb@11.8)/bin:$PATH"' >> ~/.zshrc
source ~/.zshrc
brew services start mariadb@11.8     # démarre MariaDB maintenant et à chaque connexion
```
 
</details>
<details>
<summary><b>Windows</b></summary>
1. **Git** : installez Git for Windows (git-scm.com), options par défaut.
2. **PHP 8.4** :
   - téléchargez sur windows.php.net la version **PHP 8.4 – VS17 x64 Non Thread Safe** (Zip) ;
   - décompressez dans `C:\php` (pour avoir `C:\php\php.exe`) ;
   - copiez `php.ini-development` en `php.ini` et modifiez-le :
```ini
     extension_dir = "C:\php\ext"
     extension=curl
     extension=fileinfo
     extension=mbstring
     extension=openssl
     extension=pdo_mysql
     extension=zip
     date.timezone = Europe/Paris
```
   - ajoutez `C:\php` au **Path** (recherche Windows « variables d'environnement » → Path → Modifier → Nouveau).
3. **Composer** : lancez `Composer-Setup.exe` (getcomposer.org) et choisissez `C:\php\php.exe`.
4. **MariaDB 11.8** : installeur `.msi` de mariadb.org (choisissez bien la **11.8**). Notez le mot de passe root demandé. Ajoutez au Path le dossier `bin` de MariaDB (par exemple `C:\Program Files\MariaDB 11.8\bin`).
5. **Redémarrez VS Code** pour qu'il voie le nouveau Path.
  
</details>
<details>
<summary><b>Linux (Debian / Ubuntu)</b></summary>
**Debian 13** (même base que la VM) fournit directement PHP 8.4 et MariaDB 11.8 :
 
```bash
sudo apt update
sudo apt install php8.4-cli php8.4-mysql php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip \
                 mariadb-server mariadb-client composer git
```
 
**Ubuntu** : les versions fournies peuvent être différentes. Vérifiez avec `apt policy php8.4-cli mariadb-server` ; sinon ajoutez le dépôt `ppa:ondrej/php` pour PHP 8.4 et le dépôt officiel MariaDB (outil de configuration sur mariadb.org) pour la 11.8.
 
</details>

**Vérification (tous systèmes)** :

```bash
php -v               # PHP 8.4.x
mariadb --version    # 11.8.x
composer --version   # doit indiquer « PHP version 8.4.x »
php -m               # doit lister pdo_mysql, mbstring, openssl
```

### 2. Récupérer le projet

```bash
git config --global user.name "Prénom Nom"
git config --global user.email "email-de-votre-compte-github"

git clone https://github.com/rguteville/PLD-Agile-Agenda-Patient.git aura
cd aura
composer install
```

À la première connexion, Git ouvre le navigateur pour vous authentifier sur GitHub. Sur macOS, `gh auth login` fait la même chose.

### 3. Créer la base de données locale

Chaque poste a sa propre base, indépendante de celle des autres et de la VM. Seule la structure est partagée, via `database/schema.sql`.

Ouvrez une session administrateur MariaDB :

| Système | Commande                                                    |
| ------- | ----------------------------------------------------------- |
| macOS   | `mariadb` (votre compte macOS est administrateur)           |
| Windows | `mariadb -u root -p` (mot de passe choisi à l'installation) |
| Linux   | `sudo mariadb`                                              |

Puis créez la base et un utilisateur dédié :

```sql
CREATE DATABASE aura CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'aura'@'localhost' IDENTIFIED BY 'mot-de-passe';
GRANT ALL PRIVILEGES ON aura.* TO 'aura'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

Chargez la structure, puis les données de démonstration quand le fichier existe :

```bash
# macOS / Linux
mariadb -u aura -p aura < database/schema.sql
mariadb -u aura -p aura < database/test.sql

# Windows (PowerShell)
mariadb -u aura -p aura -e "source database/schema.sql"
mariadb -u aura -p aura -e "source database/test.sql"
```

**Quand `schema.sql` change sur `main`**, recréez votre base (les données locales sont perdues, ce sont des données de test) :

```bash
mariadb -u aura -p -e "DROP DATABASE aura; CREATE DATABASE aura CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

puis rechargez `schema.sql` et `test.sql` comme ci-dessus.

**Pour une démonstration**, recalez les données de démo sur le jour courant (le 14 octobre 2026 du jeu de démo
devient aujourd'hui ; rejouable sans effet) : `mariadb -u aura -p aura < database/demo-today.sql`. Ne le faites pas
sur la base du CI : les tests s'appuient sur les dates fixes de `test.sql`.

### 4. Configurer l'application

```bash
cp config/config.example.php config/config.php     # Windows : copy config\config.example.php config\config.php
```

Dans `config/config.php`, modifiez deux valeurs :

- `'127.0.0.1'` → `'localhost'`
- `''` (mot de passe) → le mot de passe de l'étape 3

Ce fichier est ignoré par Git : il ne doit pas apparaître dans un commit. Les variables d'environnement `DB_HOST`,
`DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` sont prioritaires sur ses valeurs ; sans `config.php`, le modèle
`config.example.php` n'est utilisé que si `DB_HOST` est défini (cas du CI).

Tant que `config/config.php` n'existe pas, les pages qui accèdent à la base affichent « Configuration manquante : copiez config/config.example.php en config/config.php et renseignez la base. » : la commande `cp` ci-dessus suffit à le créer. Si le message est « Connexion à la base impossible », vérifiez l'hôte, le mot de passe et que MariaDB est démarré (étape 3).

### 5. Lancer le site

En local, PHP a un serveur intégré :

```bash
php -S localhost:8000 -t public public/index.php
```

Ouvrez **http://localhost:8000**. Arrêt : `Ctrl + C`. Le dernier argument (`public/index.php`) est nécessaire : il sert les fichiers CSS et JS de `public/` et envoie toutes les autres URL au routeur.

### 6. Sur la VM Debian LAMP (Apache)

Même base et même `config/config.php` qu'en local (étapes 3 et 4), puis un hôte virtuel dont la racine est
`public/` et qui renvoie toute URL sans fichier vers `index.php` :

```apache
# /etc/apache2/sites-available/aura.conf
<VirtualHost *:80>
    DocumentRoot /var/www/aura/public
    <Directory /var/www/aura/public>
        AllowOverride None
        Require all granted
        FallbackResource /index.php
    </Directory>
</VirtualHost>
```

```bash
sudo apt install php8.4 libapache2-mod-php8.4 php8.4-mysql php8.4-mbstring
sudo a2dissite 000-default && sudo a2ensite aura && sudo systemctl reload apache2
cd /var/www/aura && composer install --no-dev --optimize-autoloader
curl -fsS http://localhost/ > /dev/null && echo OK
```

Aucun accès internet n'est nécessaire à l'exécution : police, icônes et scripts sont dans le dépôt.
**Base existante** après une mise à jour qui ajoute des colonnes : rejouer les fichiers de `database/migrations/`
dans l'ordre des dates (`mariadb -u aura -p aura < database/migrations/<fichier>.sql`), ou recréer la base.

### 7. Génération quotidienne de l'agenda (cron)

À l'enregistrement d'une prescription, AURA planifie ses soins sur 7 jours. Chaque jour, `bin/generate-agenda.php`
prolonge l'agenda de tous les séjours en cours : pour chaque ligne de prescription active (non arrêtée), il crée les
soins planifiés manquants de maintenant jusqu'à la fin du jour J + 7. Il est **idempotent** :
une heure déjà planifiée n'est jamais recréée, un soin existant n'est jamais modifié et rien n'est créé dans le
passé (RG9). Relancé deux fois de suite, le second passage affiche « 0 soin créé ».

```bash
php bin/generate-agenda.php
# Exemple sur les données de démo le 03/11/2026 au soir :
# 11 soins créés pour 2 séjours (3 séjours en cours avec une prescription active, 5 lignes examinées).
# Relancé aussitôt : 0 soin créé pour 0 séjour (...).
```

Même configuration que le site (`config/config.php`, ou variables `DB_*`). Code de sortie 0 si tout va bien, 1 en
cas d'erreur (message sur la sortie d'erreur, rien n'est enregistré). Sur la VM, tous les jours à 0 h 05
(`crontab -e` avec l'utilisateur qui exécute le site, par exemple `www-data`) :

```cron
5 0 * * * cd /var/www/aura && php bin/generate-agenda.php >> /var/log/aura/generate-agenda.log 2>&1
```

Le dossier du journal doit exister et être accessible en écriture (`sudo mkdir -p /var/log/aura && sudo chown
www-data /var/log/aura`). Le script fixe lui-même le fuseau Europe/Paris, comme le site.

### 8. Lancer les tests

```bash
composer test            # les deux suites ; sans variables DB_*, les tests avec base sont sautés
composer test:fast       # suite « aura » seulement (métier, rendu, contrôleurs, DAO)
composer test:http       # suite « http » de bout en bout (base requise)
composer cs              # style PSR-12
```

Avec la base de test `aura_test` (création expliquée dans [docs/TESTS.md](docs/TESTS.md#base-de-test)) :

```bash
export DB_HOST=127.0.0.1 DB_PORT=3306 DB_NAME=aura_test DB_USER=aura_ci DB_PASSWORD=aura_ci
composer test
```

Détails, couverture et comment écrire un test : [docs/TESTS.md](docs/TESTS.md).

---

## Travailler au quotidien

Modèle **GitHub Flow** : `main` est toujours fonctionnelle, on crée une branche par fonctionnalité.

```bash
git switch main && git pull                      # partir de la dernière version
git switch -c feature/admission                  # une branche par fonctionnalité

# ... coder ...
composer cs:fix && composer cs && composer test  # mêmes vérifications que le CI
git add src/ public/ tests/
git commit -m "feat(admission): création d'un séjour"
git push -u origin feature/admission
```

Puis ouvrez une **pull request** vers `main` sur GitHub. Merge quand le CI est vert et qu'un autre membre a approuvé.

| Préfixe de branche | Usage                     | Exemple                    |
| ------------------ | ------------------------- | -------------------------- |
| `feature/`         | nouvelle user story       | `feature/allergies`         |
| `fix/`             | correction de bug         | `fix/tri-agenda`           |
| `docs/`            | documentation, diagrammes | `docs/diagramme-sequence`  |
| `chore/`           | outillage, scripts        | `chore/script-deploiement` |
| `ci/`              | workflow de CI            | `ci/tests-integration`     |

Pour récupérer les nouveautés de `main` dans votre branche : `git fetch origin && git merge origin/main` (ou le bouton **Update branch** de la pull request).

### Commandes

| Commande                                           | Rôle                              |
| -------------------------------------------------- | --------------------------------- |
| `composer install`                                 | Installer les dépendances         |
| `composer test`                                    | Lancer les tests (deux suites)    |
| `composer test:fast` / `test:http` / `test:coverage` | Une suite, ou la couverture (pcov) |
| `composer cs`                                      | Vérifier le style PSR-12          |
| `composer cs:fix`                                  | Corriger automatiquement le style |
| `php -S localhost:8000 -t public public/index.php` | Lancer le site en local           |

---

## Intégration continue

À chaque push sur `main` et à chaque pull request (quelle que soit sa base), GitHub Actions vérifie :

1. `composer.json` est valide et les dépendances s'installent ;
2. la syntaxe PHP (`src`, `tests`, `public`, `config`, `bin`) et le style PSR-12 ;
3. `database/schema.sql` puis `database/test.sql` se chargent dans une base MariaDB 11.8 vide ;
4. les deux suites de tests passent (unitaires, DAO, intégration, puis HTTP de bout en bout), avec un rapport de
   couverture en artefact.

Voir onglet **Actions** du dépôt.

---

## VM de démo et déploiement

La VM de démo est la machine **Debian-HCL-LAMP-git-Docker** (VM « fille » lancée depuis VirtualBox dans la VM « mère » de l'hexanôme). Elle sert la branche `main`, c'est-à-dire la version montrée au PO.

Points d'attention :

- une seule connexion à la fois sur la VM mère : **déconnectez-vous** en partant ;
- au démarrage, le clavier peut être en QWERTY (chiffres sans Maj, A↔Q, Z↔W) (j'en ai fait les frais ahah);

### Déployer une nouvelle version

Après la fusion d'une pull request dans `main`, sur la VM de démo :

```bash
cd /var/www/aura
bash scripts/deploy.sh
```

Le script récupère `main`, installe les dépendances et vérifie que le site répond. Il refuse de déployer si des fichiers ont été modifiés directement sur la VM.

**Si `schema.sql` a changé**, le script ne touche pas à la base. Il faut la recréer :

```bash
mariadb -u aura -p -e "DROP DATABASE aura; CREATE DATABASE aura CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mariadb -u aura -p aura < database/schema.sql
mariadb -u aura -p aura < database/test.sql
```

### Checklist avant une démo

- [ ] CI vert sur `main`
- [ ] Release créée sur GitHub (`v0.1` pour le sprint 1, `v0.2`…)
- [ ] Version déployée sur la VM, base rechargée avec les données de démo
- [ ] Scénario de démo répété une fois
- [ ] Plus aucune fusion dans `main` jusqu'à la démo
      Pour déployer exactement une release plutôt que `main` :

```bash
cd /var/www/aura
git fetch --tags
git checkout v0.1
composer install --no-dev --optimize-autoloader
```

`bash scripts/deploy.sh` ramène ensuite la VM sur `main`.

---

## Comment tester (scénario de démo)

Scénarios manuels avec les données de démo (`database/test.sql`, jour de référence : mercredi 14 octobre 2026),
serveur lancé sur http://localhost:8000. Pour une démonstration « en direct », recaler d'abord la démo sur le jour
courant (`database/demo-today.sql`, voir l'installation) : les dates ci-dessous deviennent alors aujourd'hui.

1. **Admission d'un nouveau patient** : `/`, nom + prénom, cocher deux allergènes et choisir leur criticité,
   ouvrir « Scores » et choisir ASA III, « Mesures » 180 cm / 80 kg, sexe M, Admettre → dossier du séjour avec
   IMC, poids théorique, Vt et allergies dans le bandeau. Une date de chirurgie `30/02/2026` est refusée sous le
   champ, section ouverte. Sans IPP saisi, un IPP est attribué automatiquement et s'affiche dans le bandeau.
2. **Patient existant** : `/`, taper « simp » dans « IPP, nom ou prénom » → Simpson Marge (date de naissance, âge,
   IPP, dernier séjour) ; flèche bas, Entrée → carte en lecture seule du patient (groupe sanguin, allergies) et
   formulaire pré-rempli ; le groupe sanguin déjà connu ne se modifie pas à l'admission (réservé au médecin).
   « Ce n'est pas ce patient » vide le formulaire. Un patient décédé (« fle » → Fletcher Jessica) affiche une
   alerte. L'IPP `100000001` donne un résultat unique. Admettre un patient déjà hospitalisé est refusé.
3. **Édition en place** : `/sejour?id=1`, section « Dossier complet », survoler « ATCD médicaux » (un crayon
   apparaît), cliquer, modifier, « Confirmer » → « Enregistré » et ligne d'historique ; Échap restaure la valeur.
   Dans deux onglets, modifier le même champ : le second est refusé (valeur modifiée entre-temps, rechargée).
   Le groupe sanguin et le décès n'ont pas de crayon : ils passent par un médecin (scénarios 4 et 5).
4. **Modifier une partie, fin de séjour** : sur `/sejour?id=1`, crayon « Modifier » à côté de « Dossier » ou d'un
   titre de section → formulaire de la partie → « Enregistrer » → choisir un médecin, saisir un mot de passe
   quelconque (authentification factice) → « Valider ». « Mettre fin au séjour » passe par la même fenêtre avec
   la date et l'heure de sortie.
5. **Décès** : sur le dossier d'un séjour en cours, « Déclarer le décès » → date et heure, médecin, mot de passe
   quelconque → le patient est « Décédée » ou « Décédé » (bandeau, dossier, liste des patients avec un cœur barré à côté du
   nom), le séjour se termine à l'heure du décès et les soins encore prévus sont annulés.
6. **Menu du dossier et allergies** : sur `/sejour?id=1`, le menu de gauche reste visible au défilement ; « Agenda
   du jour » est en tête ; les prescriptions en cours sont dans la colonne de droite. `/sejour?id=8` → « Aucune
   allergie connue » (vert) ; `/sejour?id=1` → Pénicilline (Élevée) en rouge plein et Latex (Faible) en orange
   dans le bandeau ; ajout et retrait depuis la section « Allergies connues ».
7. **Prescriptions** : `/care-plans?idEpisodeOfCare=1` (colonne « Période »), nouvelle prescription (« Du / Au ») ;
   prescrire à nouveau le paracétamol déjà en cours affiche un avertissement sans bloquer ; un médicament dont le
   principe actif est un allergène du patient (amoxicilline pour Simpson) déclenche une alerte et n'est accepté
   qu'avec « Je prescris malgré l'allergie » ; catégorie « Protocoles » ; validation par un médecin réanimateur ;
   « Arrêter » une ligne depuis le dossier annule ses soins encore prévus.
8. **Agenda du patient et action sur un soin** : `/agenda-patient?id=1&date=2026-10-14` → les
   soins passés non validés sont « En retard » et épinglés en tête. « Valider » (soin en retard, ou prévu avant
   la fin de la rotation en cours) → « Réalisé » ou « Non effectué » (motif obligatoire), un soignant et un mot de
   passe quelconque → statut, « par … » et heure de réalisation. Valider deux fois le même soin est refusé ; un
   soin d'une rotation à venir aussi. « Note » sur tout soin, modifiable par son auteur seulement. Hypothèse
   « catégorie habilitée » : un(e) aide-soignant(e) valide un soin, pas un médicament. « Semaine » affiche
   lundi → dimanche, un clic sur un jour y revient.
9. **Agenda du service** : onglet « Agenda service ». « Lits » : un patient par ligne, du plus
   urgent au moins urgent (retards, soins à faire dans la rotation, prochain soin) ; cliquer un patient
   (`?sejour=`) ouvre à côté le panneau de ce lit : allergies, référent, soins de la rotation avec « Valider » et
   « Note ». Les allergies ne sont que dans ce panneau. « Liste de travail » (`?vue=liste`) : les soins de tout le
   service en retard, maintenant (deux heures), plus tard (replié), réalisés (replié).
10. **Équipes et « Mon planning »** : onglet « Équipes », 14/10/2026, rotation du matin (ou
    après-midi, ou nuit) → équipes A à D de démo ; composer une équipe (un patient n'a qu'une équipe par
    rotation). « Mon planning », Thomas Girard → soins de Simpson et Skywalker pendant la rotation, avec les
    mêmes actions.
11. **Suppression d'un patient** : `/patients`, « Supprimer » sur la ligne de Peter Parker (séjour
    clos, aucun soin réalisé) → page de confirmation avec son identité → « Confirmer la suppression » → retour à
    la liste, message « Patient supprimé », Parker n'apparaît plus ni dans la liste, ni dans la recherche de
    l'admission, ni par son IPP `100000004` (qui reste réservé). « Supprimer » sur Marge Simpson (séjour en cours)
    affiche le motif du refus, sans bouton de confirmation ; un patient dont un soin a été réalisé est refusé de
    la même façon. Aussi accessible depuis `/patients/modifier?id=`.
12. **Génération de l'agenda** : `php bin/generate-agenda.php` deux fois de suite → la seconde
    exécution affiche « 0 soin créé » ; l'agenda des séjours en cours est rempli jusqu'à J + 7.
13. **Accessibilité** : parcourir le formulaire d'admission et le dossier au clavier seul (Tab, Entrée, Échap,
    flèches dans la liste de suggestions) ; `?theme=light` ou `?theme=dark` pour forcer le thème.

## Zone dépannage rapide

| Problème                                                                          | Solution                                                                                                                                                                                          |
| --------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `php -m` ne liste pas `pdo_mysql` / `openssl` (Windows)                           | `php --ini` doit afficher `C:\php\php.ini`. Sinon le fichier s'appelle peut-être `php.ini.txt` (affichez les extensions de fichiers). Utilisez un chemin absolu : `extension_dir = "C:\php\ext"`. |
| `mysql: command not found`                                                        | Avec MariaDB 11, utilisez la commande `mariadb`.                                                                                                                                                  |
| `Access denied for user 'aura'@'localhost'`                                       | Vérifiez le mot de passe dans `config/config.php` et `'host' => 'localhost'`. Sous Windows, créez aussi l'utilisateur `'aura'@'127.0.0.1'` avec les mêmes droits.                                 |
| `composer --version` n'indique pas PHP 8.4                                        | Un autre PHP passe avant dans le PATH : vérifiez `which php` (macOS/Linux) ou `where php` (Windows).                                                                                              |
| `Repository not found` au clonage                                                 | Invitation au dépôt non acceptée, ou mauvais compte GitHub connecté.                                                                                                                              |
| `git pull` refusé sur la VM (`untracked working tree files would be overwritten`) | Un fichier a été créé à la main sur la VM (souvent `composer.lock`) : supprimez-le, puis relancez le déploiement.                                                                                 |
| Le CI échoue sur « Style de code »                                                | Lancez `composer cs:fix` en local, committez, poussez. Un simple avertissement (ligne > 120 caractères) suffit.                                                                                   |
| `Unknown column 'medicalHistory'` ou page d'accueil sans liste de services        | Base locale non migrée : rejouez `database/migrations/*.sql` dans l'ordre, ou recréez la base avec `schema.sql` + `test.sql`.                                                                     |
| « Session expirée » lors d'une modification en place du dossier                   | Le jeton de session a changé (cookies effacés, `session.save_path` illisible sur la VM) : rechargez la page. Vérifiez que PHP peut écrire dans son dossier de sessions.                            |
| Les tests avec base sont tous « skipped »                                         | Normal sans `DB_HOST` : créez la base `aura_test` et exportez les variables `DB_*` (voir `docs/TESTS.md`).                                                                                        |
| La police ou les icônes ne s'affichent pas sur la VM                              | Vérifiez que `public/fonts/` est déployé et servi (`curl -I http://localhost/fonts/source-sans-3-latin.woff2`). Aucun CDN n'est utilisé.                                                        |

---

## Règles de l'équipe

1. **Jamais de push direct sur `main`** : une branche par fonctionnalité, fusion par pull request.
2. **CI vert et relecture** par un autre membre avant toute fusion.
3. **Aucun identifiant ni mot de passe dans le dépôt**
4. **On ne modifie pas le code directement sur la VM** : on passe par GitHub puis `deploy.sh`.
5. La structure de la base ne change que par `database/schema.sql`, géré par le responsable BDD.
6. **Convention de nommage (révisée le 2026-11-03)** : les tables et colonnes SQL de `database/schema.sql` sont en **camelCase anglais** (`Patient.lastName`, `EpisodeOfCare.admissionDate`), comme le diagramme de classes HL7 du dossier. **Tout le code** (classes, méthodes, variables, clés, noms de tests, fichiers) est aussi en **anglais** (`Patient::getLastName()`, `EpisodeOfCare::getAdmissionDate()`) ; seuls l'affichage (textes, URL) et les commentaires restent en **français**. Aucune colonne SQL ne doit apparaître hors de `src/Dao/`.
7. **Numéro de séjour** : format `AAAA-NNNNNN`, soit l'année de la date d'admission, un tiret et un compteur sur 6 chiffres qui repart à `000001` chaque année (ex. `2026-000108`). Il est généré uniquement par `EpisodeOfCareDao::insert()`, jamais saisi ni fourni par un formulaire (RG1). La colonne `EpisodeOfCare.episodeNumber` est `NOT NULL UNIQUE` : la base refuse tout doublon. Les jeux de données (`database/test.sql`) doivent suivre ce format.

---

## Conventions

- **Nommage** : SQL en camelCase anglais HL7 (`Patient.lastName`), traduction uniquement dans `src/Dao/`. **Tout le code
  en anglais HL7** (`EpisodeOfCare`, `PatientRecordService`, `CarePlan`), décision du 2026-11-03 qui remplace
  celle du 2026-10-21 (classes historiques en français). Commentaires, messages
  utilisateur, URL, commits et PR en français.
- **Branches** : une par fonctionnalité, depuis `main` à jour : `feature/nom-court`, `fix/…`, `docs/…`, `chore/…`, `ci/…`.
  Pour des changements dépendants, PR empilées (`gh pr create --base <branche précédente>`), fusionnées dans l'ordre.
- **Commits** : Conventional Commits en français, impératif court : `feat(admission): …`, `fix(cs): …`,
  `test(sejour): …`, `docs: …`, `style(ui): …`. Pas de trailer d'attribution automatique.
- **Pull request** : titre = fonctionnalité + résumé ; corps avec Objectif, Changements, Comment tester, Hypothèses et
  décisions prises seul, Points à vérifier par le relecteur, Non fait / dette laissée. CI vert et relecture par un
  autre membre avant fusion ; tout POST ajouté sans CSRF est signalé.
- **Suppression** : logique (`removedAt DATETIME NULL`, filtrée par les DAO ; les clés étrangères, sans `ON DELETE`,
  refusent la suppression physique d'une ligne référencée) ;
  unicité sur les seules lignes actives par une colonne générée `activeKey = IF(removedAt IS NULL, 1, NULL)`.
  Exception connue : le retrait d'une allergie est physique (dette tracée).
- **Schéma** : `database/schema.sql` est géré par le responsable BDD ; une PR propose un diff et, pour les bases
  existantes, un fichier additif dans `database/migrations/` (jamais de `DROP`, rejouable).
- **IHM** : `style.css` unique, bloc ajouté en fin de fichier sous `/* ===== AURA-NN : … ===== */` ; composants de
  `src/View/components/` réutilisés avant tout HTML à la main ; tout échappé par `View::e()` ; voir `docs/UI-GUIDE.md`.
- **Tests** : une fonctionnalité sans test de sa règle métier n'est pas terminée ; voir `docs/TESTS.md`.
