# Reprendre AURA

Ce document s'adresse à une équipe qui reprend le projet sans l'équipe actuelle. Il dit comment lancer le
site, comment le code est organisé, où se trouve chaque fonctionnalité et chaque règle métier, ce qui reste
fragile et comment ajouter une page ou une règle. Il complète le [README](../README.md) (installation détaillée
par système, VM, déploiement, scénarios de démo) sans le répéter.

Autres documents : [ARCHITECTURE.md](ARCHITECTURE.md) (diagrammes, modèle de données), [TESTS.md](TESTS.md)
(suites, base de test, règles RG reliées aux tests), [UI-GUIDE.md](UI-GUIDE.md) (charte, composants,
accessibilité). Le cahier des charges, le dossier de cadrage et le backlog (tickets AURA-NN) sont dans Notion,
page « PLD Agile - Agenda Patient » (lien en tête du README).

## 1. Installer et lancer

Prérequis : PHP 8.4 (extensions `pdo_mysql`, `mbstring`), MariaDB 11.8, Composer 2. Les commandes par système
sont dans le README, section « Installation ».

```bash
composer install
# Base locale (en administrateur MariaDB) :
#   CREATE DATABASE aura CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
#   CREATE USER 'aura'@'localhost' IDENTIFIED BY '<mot de passe>';
#   GRANT ALL PRIVILEGES ON aura.* TO 'aura'@'localhost';
mariadb -u aura -p aura < database/schema.sql    # structure
mariadb -u aura -p aura < database/test.sql      # données fictives (jour de référence : 14/10/2026)
cp config/config.example.php config/config.php    # puis y mettre le mot de passe (fichier ignoré par Git)
php -S localhost:8000 -t public public/index.php  # http://localhost:8000
```

- `database/demo-today.sql` recale les données de démo sur le jour courant (pour une démonstration ; jamais sur
  la base de test, dont les tests attendent les dates fixes de `test.sql`).
- `php bin/generate-agenda.php` prolonge l'agenda des séjours en cours jusqu'à J + 7 (à lancer chaque jour par
  cron sur la VM, voir le README).
- Variables `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` : prioritaires sur `config.php` ; sans
  `config.php`, `config.example.php` n'est lu que si `DB_HOST` est défini (c'est ainsi que tourne le CI).
- La page d'admission (`/`) s'affiche même sans base ; les autres pages affichent un message de configuration.

Vérifier avant de pousser (ce que fait le CI GitHub, `.github/workflows/ci.yml`) :

```bash
composer validate --strict
find src tests public config bin -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null
composer cs                      # PSR-12 ; un seul avertissement (ligne > 120 caractères) fait échouer
export DB_HOST=127.0.0.1 DB_PORT=3306 DB_NAME=aura_test DB_USER=aura_ci DB_PASSWORD=aura_ci
composer test                    # suites « aura » et « http » ; sans DB_*, les tests avec base sont sautés
```

La base `aura_test` se crée comme indiqué dans [TESTS.md](TESTS.md#base-de-test). Les tests ne couvrent pas le
JavaScript : ouvrir les écrans modifiés dans un navigateur reste nécessaire.

## 2. Architecture

PHP sans framework, MVC fait maison, autoload PSR-4 (`Aura\` correspond à `src/`).

| Dossier | Rôle | Règle |
| --- | --- | --- |
| `public/index.php` | Contrôleur frontal : fuseau Europe/Paris, filtre UTF-8, câblage des dépendances (une closure par écran), table des routes, filet d'erreurs | Seul endroit où les objets sont construits ; la connexion n'est ouverte que par les routes qui en ont besoin |
| `src/Core/` | `Router` (méthode + chemin exacts, pas de paramètre dans l'URL), `Database` (PDO unique, paresseux), `View` (rendu, `View::e()`), `Csrf`, `DateInput` (JJ/MM/AAAA) | Aucune règle métier |
| `src/Controller/` | Lit `$_GET` / `$_POST` / le corps JSON, vérifie le jeton CSRF, traduit les noms de champs du formulaire en clés du domaine, appelle un service, répond en HTML ou en JSON | Ne valide rien lui-même |
| `src/Service/` | Règles métier. La validation est pure (sans PDO ni HTTP, testable sans base) ; l'écriture passe par `Transaction::run()` | Une règle = une méthode testée |
| `src/Dao/` | Accès à la base, requêtes préparées, traduction colonnes SQL vers entités | Seul endroit où un nom de colonne apparaît |
| `src/Domain/` | Entités immuables (un accesseur par colonne), constantes métier, catalogues (`RecordFields`, `ClinicalScales`) | Pas d'accès à la base |
| `src/View/` | `layouts/main.php`, `components/` (réutilisables), `pages/` (un écran chacune) | Tout ce qui vient des données passe par `View::e()` |
| `public/js/` | Un script vanilla par fonctionnalité, chargé par le paramètre `$scripts` de `View::page()` | Confort seulement : le serveur revalide tout |
| `public/css/style.css` | Feuille unique | Nouveau bloc en fin de fichier, sous `/* ===== AURA-NN : … ===== */` |
| `database/` | `schema.sql` (structure, gérée par le responsable BDD), `test.sql` (données fictives), `migrations/` (ajouts pour une base existante), `demo-today.sql` | Le schéma ne change que par `schema.sql` |
| `bin/generate-agenda.php` | Génération quotidienne de l'agenda (cron) | Même configuration que le site |
| `tests/` | Miroir de `src/` ; `tests/Http` = bout en bout | Voir [TESTS.md](TESTS.md) |

### Cheminement d'une requête : l'admission de bout en bout

1. **Formulaire** (`GET /`) : `public/index.php` lit les listes de référence (services, médecins, allergènes) par
   `ReferenceDataDao` et `SubstanceDao`, puis `PatientController::showAdmissionForm()` rend
   `pages/patient/admission.php` avec `components/form` et `components/field`. Scripts : `form.js` (envoi AJAX),
   `admission-search.js` (recherche d'un patient connu), `allergy-picker.js`, `form-sections.js`, `date-input.js`.
2. **Recherche d'un patient déjà connu** (facultatif) : `admission-search.js` appelle
   `GET /patients/recherche?q=` puis `GET /patients/fiche?id=` (JSON, `PatientSearchService`). La sélection reste
   un choix explicite de l'utilisateur (identitovigilance).
3. **Envoi** (`POST /patients/create`) : `form.js` envoie le formulaire avec `Accept: application/json`. Dans
   `public/index.php`, la closure `$admitPatient` construit `PatientController` avec `AdmissionService` et ses
   dépendances (`PatientDao`, `EpisodeOfCareService`, `Transaction`, `AllergyIntoleranceDao`, `AuditEventDao`,
   `RecordEditService`).
4. **Contrôleur** (`PatientController::create()`) : vérifie le jeton CSRF (403 sinon), lit les champs, les
   traduit en clés du domaine (`toDomain()`, par exemple `lastname` devient `lastName`, `H` devient `M`), puis
   appelle `AdmissionService::validate()`.
5. **Validation pure** (`AdmissionService::validate()`) : nom et prénom obligatoires, IPP facultatif mais bien
   formé (RG1), date de naissance, mesures, service et médecin référent pris dans les listes, champs
   complémentaires par `FieldValidationService` (catalogue `RecordFields`). En cas d'erreur : réponse 422
   `{ok: false, errors: {champ: message}}` en AJAX, ou formulaire réaffiché avec les valeurs saisies ; rien
   n'est écrit.
6. **Écriture** (`AdmissionService::admit()`) : dans une seule transaction (`runAdmission()`), le patient est
   créé par `PatientDao::insert()` (IPP attribué par `nextIpp()` s'il est absent) ou repris s'il a été
   sélectionné ; le séjour est ouvert par `EpisodeOfCareService::openEpisodeOfCare()` (un seul séjour en cours
   par patient, lit libre), qui appelle `EpisodeOfCareDao::insert()` : c'est là, et seulement là, que le numéro
   `AAAA-NNNNNN` est calculé (`MAX + 1 … FOR UPDATE`, RG1). Les allergies sont ajoutées par
   `AllergyIntoleranceDao`, le rattachement est tracé par `AuditEventDao` (RG10). Un interblocage entre deux
   admissions simultanées rejoue la transaction (trois essais au plus). Un refus métier (patient déjà
   hospitalisé, lit occupé) lève `InvalidArgumentException` : le contrôleur l'affiche, rien n'est écrit.
7. **Réponse** : `{ok: true, redirect: '/sejour?id=…'}` en JSON (form.js suit la redirection) ou redirection 303.
   Le navigateur fait alors `GET /sejour?id=…` : POST, redirection, GET, si bien que recharger la page ne crée
   pas de second séjour.
8. **Dossier** (`GET /sejour?id=…`) : `EpisodeOfCareController::show()` demande à `PatientRecordService::record()`
   la vue-modèle (bandeau RG2, sections, historique) et rend `pages/episode/record.php`.

Toutes les autres écritures suivent le même schéma : jeton CSRF, contrôleur qui traduit, service qui valide
(pur) puis écrit dans `Transaction::run()`, trace dans `AuditEvent`, réponse JSON ou redirection.

## 3. Où trouver chaque fonctionnalité

| Fonctionnalité (ticket) | Routes | Contrôleur | Services | Vues et scripts |
| --- | --- | --- | --- | --- |
| Admission (AURA-10) | `GET /`, `POST /patients/create` | `PatientController::showAdmissionForm()`, `create()` | `AdmissionService`, `EpisodeOfCareService`, `FieldValidationService` | `pages/patient/admission.php` ; `form.js`, `form-sections.js`, `allergy-picker.js`, `date-input.js` |
| Patient déjà connu à l'admission (AURA-15) | `GET /patients/recherche`, `/patients/fiche`, `/patients/recherche-ipp` | `PatientController::search()`, `record()`, `searchByIpp()` | `PatientSearchService`, `IppSearchService` | `admission-search.js` |
| Liste des patients (AURA-12.1) | `GET /patients?q=` | `PatientController::list()` | `PatientListService`, `EpisodeHistoryService` | `pages/patient/list.php` |
| Modifier l'identité (AURA-12.2) | `GET` et `POST /patients/modifier?id=` | `PatientController::edit()`, `update()` | `PatientUpdateService` | `pages/patient/edit.php` |
| Supprimer un patient (AURA-12.3) | `GET` et `POST /patients/supprimer?id=` | `PatientController::confirmDeletion()`, `delete()` | `PatientDeletionService` (suppression logique) | `pages/patient/delete.php` |
| Dossier du séjour (AURA-14) | `GET /sejour?id=` | `EpisodeOfCareController::show()` | `PatientRecordService`, `DerivedMeasuresService`, `EpisodeHistoryService` | `pages/episode/record.php`, `components/patient_banner.php` ; `record-menu.js`, `confirm.js`, `form.js` |
| Allergies depuis le dossier (AURA-13.2) | `POST /sejour/allergies/ajouter`, `/retirer` | `EpisodeOfCareController::addAllergy()`, `removeAllergy()` | `AllergyIntoleranceService` | `pages/episode/record.php` |
| Édition en ligne d'un champ | `POST /sejour/champ/modifier` | `RecordController::update()` | `RecordEditService` | `inline-edit.js` |
| Partie du dossier, fin de séjour, décès | `POST /sejour/partie/modifier`, `/sejour/cloturer`, `/sejour/deces` | `RecordController::updatePart()`, `endStay()`, `declareDeath()` | `RecordSectionService` | `components/part_form.php` ; `part-edit.js` |
| Prescriptions (AURA-20 à 23) | `GET /care-plans`, `/care-plans/new`, `/care-plans/protocol-lines` ; `POST /care-plans/create`, `/validate`, `/stop` | `CarePlanController` | `CarePlanService`, `PlanDefinitionService` (protocoles) | `pages/careplan/list.php`, `pages/careplan/form.php` (écran B3) ; `care-plan.js`, `form.js` |
| Agenda du patient, jour et semaine (AURA-24, 25) | `GET /agenda-patient?id=&date=&vue=semaine` | `EpisodeOfCareController::getAgenda()` | `AgendaService::getAgenda()`, `presentDay()`, `presentWeek()` | `pages/episode/patient-agenda.php`, `components/agenda_table.php` ; `agenda.js`, `auto-submit.js` |
| Valider un soin, non effectué, note (AURA-26, 28) | `POST /agenda-patient/soin` | `CareController::act()` | `AppointmentService::apply()` | `components/care_buttons.php`, `components/validation_dialog.php` ; `agenda.js` |
| Agenda du service (AURA-29, 32) | `GET /agenda-service?idHealthcareService=&date=&vue=lits\|liste&sejour=` | `ServiceController::getAgenda()` | `AgendaService::getAgendaService()`, `presentService()`, `presentBoard()` | `pages/service/consolidated-view.php`, `components/care_line.php` |
| Équipes par rotation (AURA-30) | `GET /equipes`, `POST /equipes/creer`, `/equipes/retirer` | `TeamController::teams()`, `create()`, `remove()` | `CareTeamService` | `pages/team/teams.php` |
| Mon planning (AURA-31) | `GET /mon-planning?idPractitioner=&date=&rotation=` | `TeamController::planning()` | `AgendaService::presentShift()` | `pages/team/planning.php` |
| Génération quotidienne de l'agenda (AURA-24.2, 24.3) | `php bin/generate-agenda.php` | aucun | `AgendaGenerationService`, `RequestLine::plannedTimesBetween()` | aucune |

Communs à tous les écrans : `layouts/main.php`, `components/topbar.php` (navigation, thème), `theme.js`,
`layout.js`, `components/icon.php` (icônes Lucide en SVG), `components/status_badge.php`.

## 4. Règles de gestion RG1 à RG10

Énoncés du dossier de cadrage (Notion, « Règles de gestion »), et où le code les applique. Les tests qui les
couvrent sont listés dans [TESTS.md](TESTS.md).

| Règle | Énoncé (cadrage) | Où elle est codée |
| --- | --- | --- |
| RG1 | Un séjour a un numéro unique généré à l'admission et au minimum le nom et le prénom du patient ; l'IPP est facultatif | `AdmissionService::validate()` et `validatePatientIdentity()` ; `EpisodeOfCareDao::insert()` (numéro `AAAA-NNNNNN`) ; `EpisodeOfCare.episodeNumber UNIQUE` dans `schema.sql` |
| RG2 | Identité et allergies connues en tête de tout écran d'agenda ou de prescription | `PatientRecordService::presentBanner()` et `components/patient_banner.php` (« Aucune allergie connue » explicite) ; alerte allergie de l'écran B3 : `CarePlanService::allergyConflicts()` et `checkAllergies()` ; allergies dans la fenêtre de validation d'un soin : `AgendaService::getAgendaService()`, `components/care_buttons.php` |
| RG3 | Prescription unique ou récurrente ; une récurrente a une date de début, la fin est facultative | `CarePlanService::checkLine()` ; `RequestLine::isRecurring()`, `plannedTimes()` |
| RG4 | Prescription rattachée à un séjour et à un prescripteur identifié | `CarePlanService::checkInput()` et `prescribe()` ; `CarePlan::getIdEpisodeOfCare()`, `getIdPrescriber()` |
| RG5 | Prescription d'urgence ou paramédicale hors droits du prescripteur : en attente de validation par un médecin, visible « non validée » dans l'agenda | `CarePlanService::prescribe()` (validée d'office si le prescripteur a `canValidate`), `validateCarePlan()` ; `Practitioner::canValidate()` ; marqueur `notValidated` dans `AgendaService` (fonction `presentItem()`) ; `components/to_validate_link.php` |
| RG6 | Chaque soin planifié découle d'une prescription, avec un horaire, un type de soin et un statut | `CarePlanDao` (soins créés à l'enregistrement de l'ordonnance), `RequestLine::getIdActivityDefinition()`, `CarePlanService::prescribe()` (type de soin obligatoire) ; statuts `Appointment::STATUS_*` |
| RG7 | Soin validé par un soignant identifié, avec heure de réalisation et heure de saisie ; catégorie habilitée | `AppointmentService::apply()` et `canPerform()` ; `AppointmentDao` (heure de réalisation, heure de saisie) ; fenêtre de validation jusqu'à la fin de la rotation : `AgendaService::shiftEnd()` |
| RG8 | Un soin non validé après son horaire passe « en retard » | `AgendaService::presentItem()` : calcul d'affichage (statut `PLANIFIE` dont l'heure est passée), jamais stocké ; retards en tête : `presentDay()`, liste de travail : `presentBoard()` |
| RG9 | L'agenda d'une journée est généré à partir des prescriptions actives et conservé, sans recalcul rétroactif | `AgendaGenerationService::generate()` (idempotent, rien dans le passé) ; `bin/generate-agenda.php` |
| RG10 | Toute prescription, validation ou réalisation est tracée (qui, quand) | `AuditEventDao` et `AuditEvent` ; horodatages de `CarePlanDao` ; suppression logique (`removedAt`) ; annulation des soins (`ANNULE`) au lieu d'un effacement ; refus de suppression d'un patient : `PatientDeletionService::refusalReason()` |

## 5. Conventions

- **Langue** : code (classes, méthodes, variables, clés JSON, fichiers, classes CSS) en anglais, avec les noms
  HL7 (`EpisodeOfCare`, `CarePlan`, `Appointment`). Commentaires, messages affichés, URL, commits et pull
  requests en français. Les valeurs stockées en base restent en français (`PLANIFIE`, `REALISE`, `ANNULE`,
  `NON_FAIT`).
- **SQL** : tables en PascalCase, colonnes en camelCase anglais ; aucun nom de colonne hors de `src/Dao/`.
- **Identifiants** : beaucoup sont des `VARCHAR(20)` sans `AUTO_INCREMENT`. Pour en créer un, suivre le motif
  existant (`MAX(CAST(id AS UNSIGNED)) + 1 … FOR UPDATE`) dans une transaction.
- **Suppression** : logique (`removedAt`), filtrée par les DAO ; unicité limitée aux lignes actives par la
  colonne générée `activeKey`. Exception connue : `AllergyIntoleranceDao::delete()` efface la ligne.
- **Validation** : côté serveur d'abord, dans une méthode pure d'un service, testée par `#[DataProvider]` ;
  l'HTML (`required`, `min`) et le JavaScript ne sont qu'un confort.
- **Formulaires** : un `<form data-ajax>` est envoyé par `form.js` ; le serveur répond 422
  `{ok: false, errors: {champ: message}}` ou `{ok: true, redirect: '…'}`. Sans JavaScript, le contrôleur
  réaffiche la page avec les valeurs et les erreurs. Une écriture réussie se termine toujours par une
  redirection.
- **Sécurité** : requêtes préparées, `View::e()` sur toute donnée affichée, jeton CSRF sur tout POST
  (`Csrf::check()`), corps non UTF-8 refusé dans `public/index.php`. Ne jamais versionner `config/config.php`.
- **Vues** : réutiliser `components/` avant d'écrire du HTML ; commentaires en `<?php /* … */ ?>` (un
  commentaire `<!-- -->` part au navigateur).
- **JavaScript** : un fichier par fonctionnalité, sans bibliothèque. Lire l'URL d'un formulaire par
  `form.getAttribute('action')` : un champ nommé `action` masque `form.action`.
- **Git** : pas de push sur `main` ; une branche par ticket (`feature/AURA-NN-…`, `fix/…`, `docs/…`, `chore/…`) ;
  fusion par pull request après CI vert et relecture d'un autre membre. Commits au format
  `type(portée): résumé` en français.

## 6. Hypothèses non validées

Choix faits faute de réponse du service ou du Product Owner. Chacun est signalé dans le code par « HYPOTHÈSE »
ou dans le commentaire de la constante concernée.

- **Rotations** : matin 6 h à 14 h, après-midi 14 h à 22 h, nuit 22 h à 6 h (`CareTeam::SHIFTS`). Le cadrage parle
  de deux équipes par jour ; le code en a trois.
- **Fenêtre de validation d'un soin** : un soin est validable s'il est en retard ou prévu avant la fin de la
  rotation en cours (`AgendaService::shiftEnd()`), pas au-delà.
- **Retard** : dès que l'heure prévue est passée, sans délai de tolérance (question encore ouverte dans le
  cadrage).
- **Catégorie habilitée (RG7)** : reconnue par le libellé du rôle (`PractitionerRole.title`) ; seul
  « aide-soignant » est exclu des médicaments (`AppointmentService::NOT_FOR_MEDICATION`). Il n'existe pas de
  table d'habilitation par type de soin.
- **Horizon de planification** : 7 jours (`RequestLine::PLANNING_HORIZON_DAYS`), à l'enregistrement de
  l'ordonnance puis chaque jour par le cron.
- **Ordre d'urgence de l'agenda du service** : nombre de retards, plus ancien retard, soins à faire dans la
  rotation, prochain soin, puis lit (`AgendaService::presentBoard()`).
- **Allergies de l'agenda du service** : affichées seulement dans le panneau du lit choisi et dans la fenêtre
  de validation, pas sur chaque ligne.
- **Valeurs calculées du dossier** (surface corporelle, poids théorique, volume courant) :
  `DerivedMeasuresService`, formules sourcées dans le code, indicatives.

## 7. Dette connue

- **Authentification factice** (AURA-50, non commencé dans le backlog) : il n'y a ni compte, ni session
  utilisateur, ni mot de passe vérifié. Les écrans qui engagent un praticien (partie du dossier, fin de séjour,
  décès, validation ou arrêt d'une prescription, action sur un soin) demandent de choisir un praticien et de
  saisir un mot de passe quelconque. L'auteur enregistré dans `AuditEvent` et dans les colonnes `idValidator`,
  `idStoppedBy` est donc déclaratif. « Mon planning » se choisit dans une liste.
- **CSRF** : protection présente sur tous les POST, mais minimale (`Csrf`) : un seul jeton par session PHP, pas
  de renouvellement, cookie sans attribut `Secure` (la VM sert en HTTP).
- **Changement d'heure** : les heures sont des `DATETIME` sans fuseau, en heure de Paris. L'heure de 2 h à 3 h de
  la nuit du passage à l'heure d'hiver existe deux fois et ne se distingue pas en base. Les intervalles
  (`+N hours` de PHP, dans `RequestLine::plannedTimesBetween()` et `AgendaService::presentBoard()`) sont comptés
  en heure murale : le 25/10/2026, 0 h + 6 h donne 6 h, soit 7 heures réelles. Aucun test ne couvre ces nuits.
- **Retrait d'une allergie physique** : `AllergyIntoleranceDao::delete()` efface la ligne ; l'historique de
  l'allergie connue au moment d'un soin est perdu.
- **Trois définitions des rotations** : `CareTeam::SHIFTS` (référence), `CareTeamService::SHIFTS` (libellés
  d'affichage) et `AgendaService::SHIFTS` (découpage au jour civil) répètent les bornes 6, 14 et 22 h. Changer
  une borne impose de modifier les trois.
- **Accesseurs d'entité utilisés seulement par les tests** : par exemple `Medication::getForm()`,
  `ActivityDefinition::getDescription()`, `Appointment::getTimeRecorded()`. Ils reflètent des colonnes et
  servent aux tests des DAO ; ils n'ont pas d'usage dans l'application.
- **JavaScript non testé automatiquement** : les suites PHPUnit ne l'exécutent pas.
- **Pas de migrations versionnées** : `database/migrations/` contient des scripts additifs datés, à rejouer à la
  main sur une base existante ; sinon recréer la base depuis `schema.sql`.
- **OpenEMR** : aucune interconnexion (hors périmètre du premier lot).

## 8. Ajouter une page ou une règle

### Une page

1. **Service** : écrire la vue-modèle dans un service (`src/Service/`), en méthode statique pure si possible
   (date courante injectée), avec son test dans `tests/Service/`.
2. **DAO** : si une lecture manque, l'ajouter dans `src/Dao/` (requête préparée, entité en retour), testée dans
   `tests/Dao/` (sautée sans base, transaction annulée en `tearDown`).
3. **Contrôleur** : une méthode publique qui lit les paramètres, appelle le service et termine par
   `View::page('dossier/vue', $donnees, 'AURA - Titre', ['/js/mon-script.js'])` ou `View::error(404, '…')`.
4. **Vue** : `src/View/pages/<dossier>/<vue>.php`, avec un en-tête qui liste les variables (`@var`), le bandeau
   `components/patient_banner` si la page concerne un séjour (RG2), et `View::e()` sur toute donnée.
5. **Route** : dans `public/index.php`, une closure qui construit le contrôleur et ses dépendances, puis
   `$router->get('/mon-chemin', …)` (ou `post`). Pas de paramètre dans le chemin : les identifiants passent en
   query string.
6. **Style et script** : un bloc en fin de `public/css/style.css` sous `/* ===== AURA-NN : … ===== */` ; un
   fichier `public/js/<fonctionnalité>.js` si besoin.
7. **Lien** : ajouter l'entrée dans `components/topbar.php` ou un lien depuis un écran existant.
8. **Tests** : rendu de la vue sans base (`tests/View/PagesRenderTest.php`), et parcours HTTP dans `tests/Http/`
   pour les écritures.

### Une règle métier

1. Écrire d'abord le test : cas valides et refusés par `#[DataProvider]`, date injectée
   (`new DateTimeImmutable('2026-10-14')`), sans base.
2. Coder la règle dans la méthode de validation pure du service concerné (par exemple
   `AdmissionService::validate()`, `CarePlanService::checkLine()`) : elle renvoie un tableau
   `champ => message en français`.
3. Si la règle demande la base (unicité, existence), la vérifier dans la méthode d'écriture, dans
   `Transaction::run()`, et lever `InvalidArgumentException` avec un message affichable.
4. Le contrôleur n'a rien à faire de plus : il renvoie déjà les erreurs (422 en JSON ou formulaire réaffiché).
   Si le nom du champ du formulaire diffère de la clé du domaine, compléter sa table de correspondance.
5. Citer la règle dans le commentaire (`RGn`, `AURA-NN`) et ajouter la ligne correspondante dans
   [TESTS.md](TESTS.md).
6. Une contrainte qui demande une colonne ou un `CHECK` passe par le responsable BDD : proposer le diff de
   `schema.sql` et un fichier additif dans `database/migrations/` dans la pull request.
