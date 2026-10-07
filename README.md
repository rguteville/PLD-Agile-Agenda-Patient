# AURA – Agenda patient et organisation des soins

SI miroir du service de réanimation d'un hôpital (projet AGILE, 4IF INSA Lyon).

**Documentation du projet (dossier de cadrage, modèle de données, backlog)** : [🚑 PLD Agile – Agenda Patient (Notion)](https://app.notion.com/p/PLD-Agile-Agenda-Patient-3f04f190af9b80ceaae0c2f31cffa627?source=copy_link)

## Sommaire
 
1. [Stack et versions](#stack-et-versions)
2. [Structure du projet](#structure-du-projet)
3. [Installation](#installation)
4. [Travailler au quotidien](#travailler-au-quotidien)
5. [Intégration continue](#intégration-continue)
6. [VM de démo et déploiement](#vm-de-démo-et-déploiement)
7. [Zone dépannage rapide](#zone-dépannage-rapide)
8. [Règles de l'équipe](#règles-de-léquipe)

---

## Stack et versions
 
Il est préférable d'utiliser de **les mêmes versions que la VM de démo**, pour qu'un code qui marche sur un poste marche aussi sur la machine virtuelle de démonstration.
 
| Outil | Version | Rôle |
| --- | --- | --- |
| PHP | **8.4** | Langage serveur |
| MariaDB | **11.8** | Base de données |
| Composer | 2.x | Dépendances PHP et commandes du projet |

## Structure du projet
 
```text
.github/workflows/  CI (GitHub Actions)
config/             config.example.php versionné ; config.php local, jamais commité
database/           structure et données fictives de la base
public/             point d'entrée de l'application web (index.php)
scripts/            deploy.sh (permet la mise à jour de la VM de démo)
src/                code PHP en couches 
  Controller/       reçoit les requêtes HTTP
  Service/          implémente l'interaction entre les controllers et le domain
  Domain/           objets métier
  View/             vues
tests/              tests unitaires
```
 
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
 
| Système | Commande |
| --- | --- |
| macOS | `mariadb` (votre compte macOS est administrateur) |
| Windows | `mariadb -u root -p` (mot de passe choisi à l'installation) |
| Linux | `sudo mariadb` |
 
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

### 4. Configurer l'application
 
```bash
cp config/config.example.php config/config.php     # Windows : copy config\config.example.php config\config.php
```
 
Dans `config/config.php`, modifiez deux valeurs :
 
- `'127.0.0.1'` → `'localhost'`
- `''` (mot de passe) → le mot de passe de l'étape 3
Ce fichier est ignoré par Git : il ne doit pas apparaître dans un commit.
 
### 5. Lancer le site
 
En local, PHP a un serveur intégré :
 
```bash
php -S localhost:8000 -t public
```
 
Ouvrez **http://localhost:8000**. Arrêt : `Ctrl + C`.
 
---
 
## Travailler au quotidien
 
Modèle **GitHub Flow** : `main` est toujours fonctionnelle, on crée une branche par ticket.
 
```bash
git switch main && git pull                      # partir de la dernière version
git switch -c feature/US01-admission             # une branche par ticket
 
# ... coder ...
composer cs:fix && composer cs && composer test  # mêmes vérifications que le CI
git add src/ templates/ tests/
git commit -m "feat(admission): création d'un séjour (US01)"
git push -u origin feature/US01-admission
```
 
Puis ouvrez une **pull request** vers `main` sur GitHub. Merge quand le CI est vert et qu'un autre membre a approuvé.
 
| Préfixe de branche | Usage | Exemple |
| --- | --- | --- |
| `feature/` | nouvelle user story | `feature/US03-allergies` |
| `fix/` | correction de bug | `fix/US06-tri-agenda` |
| `docs/` | documentation, diagrammes | `docs/diagramme-sequence` |
| `chore/` | outillage, scripts | `chore/script-deploiement` |
| `ci/` | workflow de CI | `ci/tests-integration` |
 
Pour récupérer les nouveautés de `main` dans votre branche : `git fetch origin && git merge origin/main` (ou le bouton **Update branch** de la pull request).
 
### Commandes
 
| Commande | Rôle |
| --- | --- |
| `composer install` | Installer les dépendances |
| `composer test` | Lancer les tests |
| `composer cs` | Vérifier le style PSR-12 |
| `composer cs:fix` | Corriger automatiquement le style |
| `php -S localhost:8000 -t public` | Lancer le site en local |
 
---
 
## Intégration continue
 
À chaque push sur `main` et à chaque pull request, GitHub Actions vérifie :
 
1. `composer.json` est valide et les dépendances s'installent ;
2. la syntaxe PHP et le style PSR-12 ;
3. `database/schema.sql` se charge dans une base MariaDB 11.8 vide ;
4. les tests passent.

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
mariadb -u aura -p aura < database/seed_fictif.sql
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

## Zone dépannage rapide
 
| Problème | Solution |
| --- | --- |
| `php -m` ne liste pas `pdo_mysql` / `openssl` (Windows) | `php --ini` doit afficher `C:\php\php.ini`. Sinon le fichier s'appelle peut-être `php.ini.txt` (affichez les extensions de fichiers). Utilisez un chemin absolu : `extension_dir = "C:\php\ext"`. |
| `mysql: command not found` | Avec MariaDB 11, utilisez la commande `mariadb`. |
| `Access denied for user 'aura'@'localhost'` | Vérifiez le mot de passe dans `config/config.php` et `'host' => 'localhost'`. Sous Windows, créez aussi l'utilisateur `'aura'@'127.0.0.1'` avec les mêmes droits. |
| `composer --version` n'indique pas PHP 8.4 | Un autre PHP passe avant dans le PATH : vérifiez `which php` (macOS/Linux) ou `where php` (Windows). |
| `Repository not found` au clonage | Invitation au dépôt non acceptée, ou mauvais compte GitHub connecté. |
| `git pull` refusé sur la VM (`untracked working tree files would be overwritten`) | Un fichier a été créé à la main sur la VM (souvent `composer.lock`) : supprimez-le, puis relancez le déploiement. |
| Le CI échoue sur « Style de code » | Lancez `composer cs:fix` en local, committez, poussez. |
 
---
 
## Règles de l'équipe
 
1. **Jamais de push direct sur `main`** : une branche par ticket, fusion par pull request.
2. **CI vert et relecture** par un autre membre avant toute fusion.
3. **Aucun identifiant ni mot de passe dans le dépôt** 
4. **On ne modifie pas le code directement sur la VM** : on passe par GitHub puis `deploy.sh`.
5. La structure de la base ne change que par `database/schema.sql`, géré par le responsable BDD.
