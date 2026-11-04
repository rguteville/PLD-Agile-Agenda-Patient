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
12. [Glossaire](#glossaire)

**Reprendre le projet** : [docs/REPRISE.md](docs/REPRISE.md) (lancement, cheminement d'une requête, où trouver
chaque fonctionnalité, règles RG1 à RG10, hypothèses, dette, ajouter une page ou une règle).

Documentation détaillée : [architecture](docs/ARCHITECTURE.md), [tests](docs/TESTS.md),
[interface](docs/UI-GUIDE.md), [journal des changements](CHANGELOG.md). Un glossaire termine chaque document.

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
docs/               REPRISE.md (reprise du projet), ARCHITECTURE.md, TESTS.md, UI-GUIDE.md (interface)
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
| Prescriptions | `GET /care-plans?idEpisodeOfCare=`, `GET /care-plans/new?idEpisodeOfCare=`, `POST /care-plans/create?idEpisodeOfCare=`, `POST /care-plans/validate?idEpisodeOfCare=`, `POST /care-plans/stop?idEpisodeOfCare=`, `GET /care-plans/protocol-lines?idPlanDefinition=&startDate=` (JSON) |
| Agenda du patient | `GET /agenda-patient?id=` (ou `?numSejour=`), `&date=AAAA-MM-JJ`, `&vue=semaine` |
| Action sur un soin | `POST /agenda-patient/soin` (réalisé, non effectué, note) |
| Agenda du service | `GET /agenda-service?idHealthcareService=&date=&vue=lits\|liste&sejour=` |
| Équipes par rotation | `GET /equipes?idHealthcareService=&date=&rotation=matin\|apres-midi\|nuit`, `POST /equipes/creer`, `POST /equipes/retirer` |
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

Avec la base de test `aura_test` (création expliquée dans [docs/TESTS.md](docs/TESTS.md#22-créer-la-base-de-test-aura_test-et-lutilisateur-aura_ci)) :

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
- [ ] Version déployée sur la VM, base rechargée avec les données de démo
- [ ] Scénario de démo répété une fois
- [ ] Plus aucune fusion dans `main` jusqu'à la démo

---

## Comment tester (scénario de démo)

Scénarios manuels avec les données de démo (`database/test.sql`, jour de référence : mercredi 14 octobre 2026),
serveur lancé sur http://localhost:8000. Pour une démonstration « en direct », recaler d'abord la démo sur le jour
courant (`database/demo-today.sql`, voir l'installation) : les dates ci-dessous deviennent alors aujourd'hui.

1. **Admission d'un nouveau patient** : `/`, nom + prénom, cocher deux allergènes et choisir leur criticité,
   ouvrir « Scores et état antérieur » et choisir ASA III, « Mesures » 180 cm / 80 kg, sexe M, Admettre → dossier du séjour avec
   IMC, poids théorique, Vt et allergies dans le bandeau. Une date de chirurgie `30/02/2026` est refusée sous le
   champ, section ouverte. Sans IPP saisi, un IPP est attribué automatiquement et s'affiche dans le bandeau.
2. **Patient existant** : `/`, taper « simp » dans « IPP, nom ou prénom » → Simpson Marge (date de naissance, âge,
   IPP, dernier séjour) ; flèche bas, Entrée → carte en lecture seule du patient (groupe sanguin, allergies) et
   formulaire pré-rempli ; le groupe sanguin déjà connu ne se modifie pas à l'admission (réservé au médecin).
   « Ce n'est pas ce patient » vide le formulaire. Un patient décédé (« fle » → Fletcher Jessica) affiche une
   alerte. L'IPP `100000001` donne un résultat unique. Admettre un patient déjà hospitalisé est refusé.
3. **Édition en place** : `/sejour?id=1`, section « Dossier complet », survoler « ATCD médicaux » (un crayon
   apparaît), cliquer, modifier, « Confirmer » → choisir un médecin et saisir un mot de passe quelconque (authentification
   factice) → « Enregistré » et ligne d'historique au nom du médecin ; Échap restaure la valeur.
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
   service en retard, doses de lignes arrêtées à tracer (s'il y en a), maintenant (deux heures), plus tard
   (replié), réalisés (replié).
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
  Attention : une PR empilée se fusionne dans sa branche de base, pas dans `main`. Après chaque fusion, supprimer
  la branche fusionnée (GitHub redirige alors la PR suivante vers `main`), ou viser `main` dès le départ.
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
- **IHM** : `style.css` unique, bloc ajouté en fin de fichier sous un commentaire `/* ===== Sujet du bloc ===== */` ; composants de
  `src/View/components/` réutilisés avant tout HTML à la main ; tout échappé par `View::e()` ; voir `docs/UI-GUIDE.md`.
- **Tests** : une fonctionnalité sans test de sa règle métier n'est pas terminée ; voir `docs/TESTS.md`.

## Glossaire

Les acronymes et les termes techniques ou métier employés dans ce document.

| Terme | Signification |
| --- | --- |
| AAAA-NNNNNN | Format du numéro de séjour : année d'admission sur quatre chiffres, un tiret, puis un compteur sur six chiffres qui repart à 000001 chaque année (exemple : 2026-000108). Généré par l'application, jamais saisi. |
| Admission | Entrée d'un patient dans le service : création ou reprise du patient, ouverture d'un séjour, saisie des allergies. C'est la page d'accueil d'AURA (`/`). |
| Agenda du patient | Écran des soins planifiés d'un séjour, pour un jour (par tranches de rotation, retards en tête) ou une semaine. |
| Agenda du service | Écran de tous les patients du service : onglet « Lits » (un patient par ligne, du plus urgent au moins urgent) et onglet « Liste de travail ». |
| Aide-soignant(e) | Soignant qui assiste l'infirmier pour les soins d'hygiène et de confort. Hypothèse du projet : un aide-soignant peut valider un soin, pas l'administration d'un médicament. |
| Allergène | Substance à laquelle un patient est allergique. Dans AURA, les allergènes sont choisis dans un référentiel (table `Substance`). |
| Apache | Serveur web utilisé sur la VM de démonstration (pile LAMP). Il sert le dossier `public/` et envoie toute adresse sans fichier vers `index.php`. |
| Artefact | Fichier produit par une exécution du CI et téléchargeable depuis GitHub, ici le rapport de couverture HTML. |
| ASA | Classification de l'état de santé d'un patient avant une anesthésie, de I (bonne santé) à VI, publiée par l'American Society of Anesthesiologists. Valeurs et source dans `ClinicalScales`. |
| ATCD | Abréviation médicale d'« antécédents » (maladies, opérations, traitements passés du patient). Exemple : « ATCD médicaux ». |
| Authentification factice | Fenêtre qui demande de choisir un praticien dans une liste et de saisir un mot de passe, mais qui ne vérifie pas ce mot de passe. Elle remplace provisoirement une vraie authentification ; le nom choisi est enregistré dans la trace. |
| Autoload (PSR-4) | Chargement automatique des classes PHP par Composer : la classe `Aura\Service\AgendaService` est lue dans `src/Service/AgendaService.php`, selon la norme PSR-4. |
| Bandeau patient | Zone fixée en haut de chaque écran de séjour : identité du patient, séjour, poids, taille et allergies (règle RG2). |
| BDD | Base de données. Le « responsable BDD » est le membre de l'équipe qui décide des changements de `database/schema.sql`. |
| Branche | Ligne de développement séparée dans Git. On crée une branche par fonctionnalité ou correction, puis on la fusionne dans `main` par une pull request. |
| camelCase | Écriture des noms composés en collant les mots, le premier en minuscule et les suivants avec une majuscule (`lastName`, `admissionDate`). Utilisée pour les colonnes SQL et les variables. |
| Catégorie habilitée | Catégorie de soignant autorisée à valider un type de soin (règle RG7). Hypothèse du projet : reconnue par le libellé du rôle du soignant. |
| CDN | Content Delivery Network : serveur externe qui fournit des fichiers communs (polices, bibliothèques). AURA n'en utilise aucun : tout est dans le dépôt, car la VM n'a pas accès à internet. |
| CI | Intégration continue : vérifications lancées automatiquement par GitHub Actions à chaque push sur `main` et à chaque pull request (syntaxe, style, schéma, tests). Fichier `.github/workflows/ci.yml`. |
| Clé étrangère (FK) | Colonne qui désigne une ligne d'une autre table (par exemple `EpisodeOfCare.idPatient`). La base refuse une valeur qui ne correspond à aucune ligne, et refuse d'effacer une ligne encore désignée. |
| Colonne générée (`activeKey`) | Colonne calculée par la base à partir d'une autre colonne. Ici `activeKey` vaut 1 tant que `removedAt` est vide et NULL sinon : une contrainte d'unicité qui l'inclut ne porte que sur les lignes actives. |
| Commit | Enregistrement d'un ensemble de changements dans l'historique Git, avec un message. |
| Composant | Morceau de vue réutilisable de `src/View/components/` (bandeau patient, formulaire, badge de statut…). |
| Composer | Gestionnaire de dépendances de PHP. `composer install` installe les bibliothèques ; `composer test`, `composer cs`… lancent les commandes déclarées dans `composer.json`. |
| Contrainte d'unicité (UNIQUE) | Contrainte SQL qui interdit deux lignes avec la même valeur dans une colonne ou un groupe de colonnes. Exemple : `EpisodeOfCare.episodeNumber`, le numéro de séjour. |
| Conventional Commits | Format des messages de commit : `type(portée): résumé`, par exemple `feat(admission): création d'un séjour`. |
| Couverture de code | Part du code exécutée par les tests, mesurée par l'extension pcov. |
| Criticité | Gravité estimée d'une allergie : « Élevée » ou « Faible », ou non évaluée. Elle règle la couleur de l'allergie dans le bandeau. |
| cron | Planificateur de tâches de Linux. Sur la VM, il lance chaque jour `bin/generate-agenda.php` (ligne ajoutée par `crontab -e`). |
| CSRF | Cross-Site Request Forgery : attaque qui fait envoyer une requête par le navigateur d'un utilisateur à son insu. Parade : un jeton de session secret, exigé sur chaque POST (`Csrf::check()`). |
| CSS | Langage de mise en forme des pages web. AURA a un seul fichier : `public/css/style.css`. |
| DAO | Data Access Object : classe de `src/Dao/` qui lit et écrit une table. C'est le seul endroit où apparaissent les noms de colonnes SQL. |
| Debian | Distribution Linux de la VM de démonstration (Debian 13 fournit PHP 8.4 et MariaDB 11.8). |
| Déclaration du décès | Action réservée à un médecin (authentification factice) : le patient est marqué décédé, le séjour en cours se termine à l'heure du décès et ses soins encore prévus sont annulés (`POST /sejour/deces`). |
| Diagramme de classes | Schéma des classes du code, de leurs méthodes publiques et de leurs dépendances. |
| Données de démo | Jeu de données fictives de `database/test.sql`, dont le jour de référence est le mercredi 14 octobre 2026. `database/demo-today.sql` le recale sur le jour courant pour une démonstration. |
| Échappement | Transformation des caractères spéciaux (`<`, `>`, `&`, guillemets) avant de les afficher dans une page, pour qu'une donnée ne soit jamais interprétée comme du HTML ou du JavaScript. Fait par `View::e()`. |
| Édition en ligne | Modification d'un seul champ du dossier directement dans la page (crayon, puis « Confirmer »). Elle passe par l'authentification factice d'un médecin et est tracée. |
| Entité immuable | Objet de `src/Domain/` qui représente une ligne de données (patient, séjour…). Ses valeurs sont fixées à la création et lues par des accesseurs (`get…()`). |
| Équipe de rotation | Groupe de soignants composé pour une rotation donnée, avec les patients dont il a la charge. Un patient n'appartient qu'à une équipe par rotation. |
| Fuseau horaire | Zone d'heure légale. AURA fixe Europe/Paris, quel que soit le réglage de PHP. |
| Fusion (merge) | Intégration des changements d'une branche dans une autre, ici d'une branche de fonctionnalité dans `main`, par une pull request. |
| GET, POST | Méthodes HTTP. GET lit une page sans rien modifier ; POST envoie des données à enregistrer. |
| gh | Outil en ligne de commande de GitHub (connexion, création de pull requests). |
| Git | Logiciel de gestion de versions : il garde l'historique de chaque fichier et permet de travailler à plusieurs sur des branches. |
| GitHub Flow | Façon de travailler avec Git : `main` toujours fonctionnelle, une branche par changement, fusion par pull request après vérification. |
| GitHub, GitHub Actions | Site qui héberge le dépôt Git, les pull requests et le CI (GitHub Actions, onglet « Actions » du dépôt). |
| HL7 | Health Level Seven : famille de normes d'échange de données de santé. AURA reprend les noms anglais des ressources de sa norme FHIR (`Patient`, `EpisodeOfCare`, `CarePlan`, `Appointment`…) pour ses tables et classes. |
| Homebrew | Gestionnaire de paquets de macOS, utilisé pour installer PHP, MariaDB et Composer. |
| HTML | Langage des pages web. Les vues PHP de `src/View/` produisent du HTML. |
| HTTP | Protocole d'échange entre le navigateur et le serveur web. Un code de réponse dit le résultat : 200 réussi, 303 redirection, 400 requête invalide, 403 refusé, 404 introuvable, 409 conflit, 422 saisie invalide. |
| Hypothèse | Choix fait faute de réponse du service ou du PO, signalé « HYPOTHÈSE » dans le code et à confirmer. |
| Idempotent | Se dit d'une opération qu'on peut relancer sans changer le résultat : un second passage de `bin/generate-agenda.php` ne crée aucun soin. |
| IHM | Interface homme-machine : les écrans de l'application. |
| IMC | Indice de masse corporelle : poids (kg) divisé par le carré de la taille (m). Calculé par AURA à partir des mesures d'admission. |
| INSA Lyon, 4IF | Institut national des sciences appliquées de Lyon ; 4IF désigne la 4e année du département informatique, où le projet a été réalisé. |
| IPP | Identifiant permanent du patient dans l'établissement. Dans AURA : 6 à 9 chiffres, facultatif à la saisie (patient admis en urgence), attribué automatiquement s'il manque ; celui d'un patient supprimé reste réservé. |
| JavaScript (JS) | Langage exécuté par le navigateur. Dans AURA, un fichier par fonctionnalité dans `public/js/`, sans bibliothèque ; il n'est qu'un confort, le serveur revalide tout. |
| Jeton CSRF | Valeur secrète liée à la session du navigateur, envoyée avec chaque POST (champ `csrf` ou en-tête `X-CSRF-Token`). Sans jeton valide, le serveur refuse l'écriture (403). |
| JSON | Format texte d'échange de données (`{"ok": true}`), utilisé par les réponses aux requêtes envoyées en JavaScript. |
| LAMP | Linux, Apache, MariaDB (ou MySQL), PHP : pile logicielle de la VM de démonstration. |
| Ligne arrêtée | Ligne de prescription stoppée par un médecin (`stoppedAt`) : ses soins encore prévus sont annulés, rien n'est effacé. |
| Liste de travail | Onglet de l'agenda du service : les soins de tout le service regroupés par urgence (en retard, doses de lignes arrêtées à tracer, maintenant, plus tard, réalisés). |
| MariaDB | Système de base de données SQL (version 11.8), compatible avec MySQL. |
| Médecin référent | Médecin désigné responsable du patient pendant son séjour, choisi à l'admission. |
| Migration | Script SQL de `database/migrations/` qui ajoute à une base existante ce qu'un changement de `schema.sql` a introduit (colonnes, index, contraintes), sans effacer les données. |
| Mon planning | Écran d'un soignant : les soins des patients de ses équipes pendant une rotation, avec les mêmes actions que l'agenda. |
| Notion | Outil de documentation en ligne où se trouvent le cahier des charges, le dossier de cadrage et le backlog (lien en tête du README). |
| NULL | Absence de valeur dans une colonne SQL. Par exemple `removedAt` vaut NULL tant que la ligne n'est pas supprimée, `dischargeDate` tant que le séjour est en cours. |
| PascalCase | Écriture des noms composés en collant les mots, chacun avec une majuscule (`EpisodeOfCare`). Utilisée pour les classes et les tables. |
| PATH | Variable d'environnement qui liste les dossiers où le système cherche les commandes (`php`, `mariadb`, `composer`). |
| pcov | Extension PHP qui mesure la couverture de code pendant les tests (`composer test:coverage`). |
| PDO | PHP Data Objects : interface de PHP pour parler à une base de données. AURA ouvre une seule connexion PDO (`Database::get()`), seulement quand une page en a besoin. |
| PHP | Langage du serveur (version 8.4). AURA n'utilise aucun framework. |
| PHPUnit | Outil de tests automatiques pour PHP (version 10). Configuration dans `phpunit.xml.dist`. |
| PLD Agile, hexanôme | Cadre pédagogique du projet à l'INSA Lyon : projet de 4e année mené en méthode agile par un hexanôme, l'équipe d'étudiants qui a réalisé AURA. |
| PO (Product Owner) | Personne qui porte le besoin, priorise le backlog et valide ce qui est livré. |
| Poids théorique | Poids prédit à partir du sexe et de la taille (formule du protocole ARDSNet). Il sert à régler le volume courant d'un patient ventilé. |
| PR (pull request) | Demande de fusion d'une branche dans une autre sur GitHub. Elle est relue par un autre membre et doit avoir un CI vert avant d'être fusionnée. |
| Prescription | Décision d'un prescripteur de donner un médicament ou de faire un soin à un patient, avec une date de début, une fréquence éventuelle et une date de fin facultative. Chaque ligne de prescription génère des soins planifiés. |
| Principe actif | Substance d'un médicament qui produit son effet. AURA relie chaque médicament à sa substance pour alerter si elle est un allergène du patient. |
| Protocole | Modèle d'ordonnance (table `PlanDefinition`) : un groupe de lignes de prescription types, choisi pour pré-remplir une nouvelle ordonnance. |
| PSR-12 | Règles de présentation du code PHP (indentation, accolades, longueur des lignes), publiées par le PHP-FIG. Vérifiées par `composer cs`. |
| PSR-4 | Norme de chargement automatique des classes PHP : le nom complet de la classe donne le chemin du fichier (`Aura\` correspond à `src/`). |
| Push | Envoi des commits d'une branche locale vers le dépôt GitHub. |
| Query string | Partie d'une adresse après le `?`, qui porte des paramètres (`/sejour?id=1`). AURA y passe tous les identifiants. |
| QWERTY | Disposition de clavier anglo-saxonne. La VM peut démarrer avec elle : des touches sont alors inversées par rapport au clavier français (A et Q, Z et W). |
| Réanimation | Service hospitalier qui prend en charge les patients dont une ou plusieurs fonctions vitales sont défaillantes ; les soins y sont planifiés heure par heure. |
| Référentiel | Liste de valeurs de référence communes (services, médicaments, substances, types de soin, praticiens), chargée par `database/test.sql`. |
| Requête préparée | Requête SQL envoyée à la base séparément de ses valeurs. Une valeur saisie ne peut donc jamais être exécutée comme du SQL (protection contre l'injection SQL). |
| RG (RG1 à RG10) | Règle de gestion : règle métier numérotée du dossier de cadrage. Leur énoncé et le code qui les applique sont dans `docs/REPRISE.md`, leurs tests dans `docs/TESTS.md`. |
| Rotation | Période de travail d'une équipe soignante. AURA en a trois par jour : matin de 6 h à 14 h, après-midi de 14 h à 22 h, nuit de 22 h à 6 h (`CareTeam::SHIFTS`, bornes à confirmer avec le service). Paramètre d'URL `rotation`. |
| Schéma | Structure de la base (tables, colonnes, contraintes), entièrement décrite par `database/schema.sql`. |
| Séjour | Passage d'un patient dans le service, de l'admission à la sortie ou au décès (table `EpisodeOfCare`). Un patient n'a qu'un séjour en cours à la fois ; chaque séjour a un numéro unique. |
| Session, cookie | La session est une mémoire du serveur associée à un navigateur par un cookie. AURA ne s'en sert que pour le jeton CSRF : il n'y a pas de compte utilisateur. L'attribut `Secure` réserverait le cookie aux connexions HTTPS. |
| SI | Système d'information. AURA est un « SI miroir » : il reproduit, pour le projet, les fonctions du système d'information d'un service de réanimation. |
| Soin en retard | Soin planifié non validé dont l'heure prévue est passée (règle RG8). Le retard est calculé à l'affichage, jamais enregistré, et ces soins sont épinglés en tête de l'agenda. |
| Soin planifié | Occurrence datée d'une ligne de prescription : une administration ou un acte à faire à une heure donnée (table `Appointment`). Statuts : prévu, réalisé, non effectué, annulé. |
| SQL | Langage des bases de données relationnelles (création de tables, lectures, écritures). |
| Suppression logique | La ligne n'est pas effacée : une date est écrite dans `removedAt` et les DAO l'ignorent ensuite. L'historique reste en base (traçabilité). |
| Traçabilité, trace | Enregistrement de qui a fait quoi et quand (table `AuditEvent` et colonnes d'horodatage), exigé par la règle RG10. |
| Transaction | Groupe d'écritures en base qui réussissent toutes ou sont toutes annulées. Dans AURA : `Transaction::run()`. |
| URL | Adresse d'une page web (`http://localhost:8000/sejour?id=1`). |
| utf8mb4 | Jeu de caractères de MariaDB qui couvre tout l'Unicode (UTF-8 jusqu'à 4 octets par caractère, émojis compris). |
| Validation d'un soin | Déclaration qu'un soin planifié a été réalisé ou non effectué, avec le soignant, l'heure de réalisation et l'heure de saisie (règle RG7). Possible une seule fois, au plus tard jusqu'à la fin de la rotation en cours. |
| VirtualBox, VM mère et fille | Logiciel de virtualisation. La VM de démonstration (VM « fille ») tourne dans VirtualBox, à l'intérieur de la VM « mère » de l'hexanôme. |
| VM | Machine virtuelle : ordinateur simulé. La VM de démonstration (Debian, Apache, MariaDB, PHP) sert la branche `main`. |
| VS Code | Visual Studio Code, éditeur de code de Microsoft. |
| VS17 x64 Non Thread Safe | Variante de PHP pour Windows à télécharger : compilée avec Visual Studio 2022 (VS17), 64 bits, sans sûreté des fils d'exécution (Non Thread Safe), adaptée à l'usage en ligne de commande. |
| Vt (volume courant) | Volume d'air insufflé à chaque cycle par le respirateur. AURA affiche une plage de 6 à 8 ml par kg de poids théorique, à titre indicatif. |
