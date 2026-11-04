# Reprendre AURA

Ce document s'adresse à une équipe qui reprend le projet sans l'équipe actuelle. Il dit comment lancer le
site, comment le code est organisé, où se trouve chaque fonctionnalité et chaque règle métier, ce qui reste
fragile et comment ajouter une page ou une règle. Il complète le [README](../README.md) (installation détaillée
par système, VM, déploiement, scénarios de démo) sans le répéter.

Autres documents : [ARCHITECTURE.md](ARCHITECTURE.md) (diagrammes, modèle de données), [TESTS.md](TESTS.md)
(suites, base de test, règles RG reliées aux tests), [UI-GUIDE.md](UI-GUIDE.md) (charte, composants,
accessibilité). Le cahier des charges et le dossier de cadrage (dont les règles de gestion RG1 à RG10) sont dans
Notion, page « PLD Agile - Agenda Patient » (lien en tête du README) ; les règles sont aussi résumées plus bas.

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

La base `aura_test` se crée comme indiqué dans [TESTS.md](TESTS.md#22-créer-la-base-de-test-aura_test-et-lutilisateur-aura_ci). Les tests ne couvrent pas le
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
| `public/css/style.css` | Feuille unique | Nouveau bloc en fin de fichier, sous un commentaire `/* ===== Sujet du bloc ===== */` |
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

| Fonctionnalité | Routes | Contrôleur | Services | Vues et scripts |
| --- | --- | --- | --- | --- |
| Admission | `GET /`, `POST /patients/create` | `PatientController::showAdmissionForm()`, `create()` | `AdmissionService`, `EpisodeOfCareService`, `FieldValidationService` | `pages/patient/admission.php` ; `form.js`, `form-sections.js`, `allergy-picker.js`, `date-input.js` |
| Patient déjà connu à l'admission | `GET /patients/recherche`, `/patients/fiche`, `/patients/recherche-ipp` | `PatientController::search()`, `record()`, `searchByIpp()` | `PatientSearchService`, `IppSearchService` | `admission-search.js` |
| Liste des patients | `GET /patients?q=` | `PatientController::list()` | `PatientListService`, `EpisodeHistoryService` | `pages/patient/list.php` |
| Modifier l'identité | `GET` et `POST /patients/modifier?id=` | `PatientController::edit()`, `update()` | `PatientUpdateService` | `pages/patient/edit.php` |
| Supprimer un patient | `GET` et `POST /patients/supprimer?id=` | `PatientController::confirmDeletion()`, `delete()` | `PatientDeletionService` (suppression logique) | `pages/patient/delete.php` |
| Dossier du séjour | `GET /sejour?id=` | `EpisodeOfCareController::show()` | `PatientRecordService`, `DerivedMeasuresService`, `EpisodeHistoryService` | `pages/episode/record.php`, `components/patient_banner.php` ; `record-menu.js`, `confirm.js`, `form.js` |
| Allergies depuis le dossier | `POST /sejour/allergies/ajouter`, `/retirer` | `EpisodeOfCareController::addAllergy()`, `removeAllergy()` | `AllergyIntoleranceService` | `pages/episode/record.php` |
| Édition en ligne d'un champ | `POST /sejour/champ/modifier` | `RecordController::update()` | `RecordEditService` ; authentification factice d'un médecin par `RecordSectionService::inlineAuthor()` | `inline-edit.js`, `part-edit.js` (fenêtre de validation) |
| Partie du dossier, fin de séjour, décès | `POST /sejour/partie/modifier`, `/sejour/cloturer`, `/sejour/deces` | `RecordController::updatePart()`, `endStay()`, `declareDeath()` | `RecordSectionService` | `components/part_form.php` ; `part-edit.js` |
| Prescriptions | `GET /care-plans`, `/care-plans/new`, `/care-plans/protocol-lines` ; `POST /care-plans/create`, `/validate`, `/stop` | `CarePlanController` | `CarePlanService`, `PlanDefinitionService` (protocoles) | `pages/careplan/list.php`, `pages/careplan/form.php` (écran B3) ; `care-plan.js`, `form.js` |
| Agenda du patient, jour et semaine | `GET /agenda-patient?id=&date=&vue=semaine` | `EpisodeOfCareController::getAgenda()` | `AgendaService::getAgenda()`, `presentDay()`, `presentWeek()` | `pages/episode/patient-agenda.php`, `components/agenda_table.php` ; `agenda.js`, `auto-submit.js` |
| Valider un soin, non effectué, note | `POST /agenda-patient/soin` | `CareController::act()` | `AppointmentService::apply()` | `components/care_buttons.php`, `components/validation_dialog.php` ; `agenda.js` |
| Agenda du service | `GET /agenda-service?idHealthcareService=&date=&vue=lits\|liste&sejour=` | `ServiceController::getAgenda()` | `AgendaService::getAgendaService()`, `presentService()`, `presentBoard()` | `pages/service/consolidated-view.php`, `components/care_line.php` |
| Équipes par rotation | `GET /equipes`, `POST /equipes/creer`, `/equipes/retirer` | `TeamController::teams()`, `create()`, `remove()` | `CareTeamService` | `pages/team/teams.php` |
| Mon planning | `GET /mon-planning?idPractitioner=&date=&rotation=` | `TeamController::planning()` | `AgendaService::presentShift()` | `pages/team/planning.php` |
| Génération quotidienne de l'agenda | `php bin/generate-agenda.php` | aucun | `AgendaGenerationService`, `RequestLine::plannedTimesBetween()` | aucune |

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
- **Git** : pas de push sur `main` ; une branche par fonctionnalité (`feature/nom-court`, `fix/…`, `docs/…`, `chore/…`) ;
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

- **Authentification factice** : il n'y a ni compte, ni session
  utilisateur, ni mot de passe vérifié. Les écrans qui engagent un praticien (édition en ligne d'un champ, partie du
  dossier, fin de séjour, décès, enregistrement par un médecin, validation ou arrêt d'une prescription, action
  sur un soin) demandent de choisir un praticien et de
  saisir un mot de passe quelconque. L'auteur enregistré dans `AuditEvent` et dans les colonnes `idValidator`,
  `idStoppedBy` est donc déclaratif. « Mon planning » se choisit dans une liste.
- **CSRF** : protection présente sur tous les POST, mais minimale (`Csrf`) : un seul jeton par session PHP, pas
  de renouvellement, cookie sans attribut `Secure` (la VM sert en HTTP).
- **Changement d'heure** : les heures sont des `DATETIME` sans fuseau, en heure de Paris. L'heure de 2 h à 3 h de
  la nuit du passage à l'heure d'hiver existe deux fois et ne se distingue pas en base. Les intervalles
  (`+N hours` de PHP, dans `RequestLine::plannedTimesBetween()` et `AgendaService::presentBoard()`) sont comptés
  en heure murale : le 25/10/2026, 0 h + 6 h donne 6 h, soit 7 heures réelles. Aucun test ne couvre ces nuits.
- **Modification de l'identité non tracée** : `POST /patients/modifier` (`PatientUpdateService`) met à jour le
  patient sans écrire dans `AuditEvent`, alors que les modifications faites depuis le dossier du séjour sont
  tracées champ par champ.
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
6. **Style et script** : un bloc en fin de `public/css/style.css` sous `/* ===== Sujet du bloc ===== */` ; un
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
5. Citer la règle dans le commentaire (`RGn`) et ajouter la ligne correspondante dans
   [TESTS.md](TESTS.md).
6. Une contrainte qui demande une colonne ou un `CHECK` passe par le responsable BDD : proposer le diff de
   `schema.sql` et un fichier additif dans `database/migrations/` dans la pull request.

## Glossaire

Les acronymes et les termes techniques ou métier employés dans ce document.

| Terme | Signification |
| --- | --- |
| AAAA-NNNNNN | Format du numéro de séjour : année d'admission sur quatre chiffres, un tiret, puis un compteur sur six chiffres qui repart à 000001 chaque année (exemple : 2026-000108). Généré par l'application, jamais saisi. |
| Accesseur (getter) | Méthode qui renvoie une valeur d'un objet sans la modifier (`getLastName()`). |
| Admission | Entrée d'un patient dans le service : création ou reprise du patient, ouverture d'un séjour, saisie des allergies. C'est la page d'accueil d'AURA (`/`). |
| Agenda du patient | Écran des soins planifiés d'un séjour, pour un jour (par tranches de rotation, retards en tête) ou une semaine. |
| Agenda du service | Écran de tous les patients du service : onglet « Lits » (un patient par ligne, du plus urgent au moins urgent) et onglet « Liste de travail ». |
| Aide-soignant(e) | Soignant qui assiste l'infirmier pour les soins d'hygiène et de confort. Hypothèse du projet : un aide-soignant peut valider un soin, pas l'administration d'un médicament. |
| AJAX | Envoi d'une requête par JavaScript sans recharger la page. Ici `form.js` envoie le formulaire et reçoit une réponse JSON (erreurs ou adresse de redirection). |
| Allergène | Substance à laquelle un patient est allergique. Dans AURA, les allergènes sont choisis dans un référentiel (table `Substance`). |
| Authentification factice | Fenêtre qui demande de choisir un praticien dans une liste et de saisir un mot de passe, mais qui ne vérifie pas ce mot de passe. Elle remplace provisoirement une vraie authentification ; le nom choisi est enregistré dans la trace. |
| AUTO_INCREMENT | Numérotation automatique d'une clé par la base. Seules `AuditEvent` et `CareTeam` l'utilisent ; les autres identifiants sont calculés par l'application (`MAX + 1` dans une transaction verrouillée). |
| Autoload (PSR-4) | Chargement automatique des classes PHP par Composer : la classe `Aura\Service\AgendaService` est lue dans `src/Service/AgendaService.php`, selon la norme PSR-4. |
| B3 (écran B3) | Numéro, dans le cahier des charges, de l'écran de saisie des prescriptions (`/care-plans/new`). |
| Bandeau patient | Zone fixée en haut de chaque écran de séjour : identité du patient, séjour, poids, taille et allergies (règle RG2). |
| BDD | Base de données. Le « responsable BDD » est le membre de l'équipe qui décide des changements de `database/schema.sql`. |
| Branche | Ligne de développement séparée dans Git. On crée une branche par fonctionnalité ou correction, puis on la fusionne dans `main` par une pull request. |
| camelCase | Écriture des noms composés en collant les mots, le premier en minuscule et les suivants avec une majuscule (`lastName`, `admissionDate`). Utilisée pour les colonnes SQL et les variables. |
| Catégorie habilitée | Catégorie de soignant autorisée à valider un type de soin (règle RG7). Hypothèse du projet : reconnue par le libellé du rôle du soignant. |
| CHECK | Contrainte SQL qui refuse une valeur hors d'une liste ou d'un intervalle (par exemple une rotation autre que MATIN, APRES_MIDI ou NUIT). |
| CI | Intégration continue : vérifications lancées automatiquement par GitHub Actions à chaque push sur `main` et à chaque pull request (syntaxe, style, schéma, tests). Fichier `.github/workflows/ci.yml`. |
| Closure | Fonction anonyme PHP. Dans `public/index.php`, chaque route reçoit une closure qui construit le contrôleur et ses dépendances au moment où la route est appelée. |
| Colonne générée (`activeKey`) | Colonne calculée par la base à partir d'une autre colonne. Ici `activeKey` vaut 1 tant que `removedAt` est vide et NULL sinon : une contrainte d'unicité qui l'inclut ne porte que sur les lignes actives. |
| Commit | Enregistrement d'un ensemble de changements dans l'historique Git, avec un message. |
| Composant | Morceau de vue réutilisable de `src/View/components/` (bandeau patient, formulaire, badge de statut…). |
| Composer | Gestionnaire de dépendances de PHP. `composer install` installe les bibliothèques ; `composer test`, `composer cs`… lancent les commandes déclarées dans `composer.json`. |
| Contrainte d'unicité (UNIQUE) | Contrainte SQL qui interdit deux lignes avec la même valeur dans une colonne ou un groupe de colonnes. Exemple : `EpisodeOfCare.episodeNumber`, le numéro de séjour. |
| Contrôleur | Classe de `src/Controller/` qui lit la requête, traduit les champs, appelle un service et répond en HTML ou en JSON. Elle ne contient pas de règle métier. |
| Contrôleur frontal | Point d'entrée unique de toutes les requêtes : `public/index.php`, qui choisit la route et construit les objets. |
| cron | Planificateur de tâches de Linux. Sur la VM, il lance chaque jour `bin/generate-agenda.php` (ligne ajoutée par `crontab -e`). |
| CSRF | Cross-Site Request Forgery : attaque qui fait envoyer une requête par le navigateur d'un utilisateur à son insu. Parade : un jeton de session secret, exigé sur chaque POST (`Csrf::check()`). |
| CSS | Langage de mise en forme des pages web. AURA a un seul fichier : `public/css/style.css`. |
| DAO | Data Access Object : classe de `src/Dao/` qui lit et écrit une table. C'est le seul endroit où apparaissent les noms de colonnes SQL. |
| DataProvider | Attribut PHPUnit (`#[DataProvider]`) qui fait tourner une même méthode de test sur une liste de cas (valeurs d'entrée et résultat attendu). |
| Déclaration du décès | Action réservée à un médecin (authentification factice) : le patient est marqué décédé, le séjour en cours se termine à l'heure du décès et ses soins encore prévus sont annulés (`POST /sejour/deces`). |
| Dette technique | Défaut ou raccourci connu, laissé volontairement pour plus tard. |
| Données de démo | Jeu de données fictives de `database/test.sql`, dont le jour de référence est le mercredi 14 octobre 2026. `database/demo-today.sql` le recale sur le jour courant pour une démonstration. |
| Dossier de cadrage | Document de départ du projet, dans Notion : besoins du service, règles de gestion RG1 à RG10, modèle de données. |
| Édition en ligne | Modification d'un seul champ du dossier directement dans la page (crayon, puis « Confirmer »). Elle passe par l'authentification factice d'un médecin et est tracée. |
| Entité immuable | Objet de `src/Domain/` qui représente une ligne de données (patient, séjour…). Ses valeurs sont fixées à la création et lues par des accesseurs (`get…()`). |
| Équipe de rotation | Groupe de soignants composé pour une rotation donnée, avec les patients dont il a la charge. Un patient n'appartient qu'à une équipe par rotation. |
| Fuseau horaire | Zone d'heure légale. AURA fixe Europe/Paris, quel que soit le réglage de PHP. |
| Fusion (merge) | Intégration des changements d'une branche dans une autre, ici d'une branche de fonctionnalité dans `main`, par une pull request. |
| GET, POST | Méthodes HTTP. GET lit une page sans rien modifier ; POST envoie des données à enregistrer. |
| Git | Logiciel de gestion de versions : il garde l'historique de chaque fichier et permet de travailler à plusieurs sur des branches. |
| Heure murale | Heure affichée par l'horloge locale, qui saute d'une heure aux changements d'heure. Ajouter 6 heures en heure murale ne fait donc pas toujours 6 heures réelles. |
| HL7 | Health Level Seven : famille de normes d'échange de données de santé. AURA reprend les noms anglais des ressources de sa norme FHIR (`Patient`, `EpisodeOfCare`, `CarePlan`, `Appointment`…) pour ses tables et classes. |
| Horizon de planification | Nombre de jours à l'avance pour lesquels les soins planifiés sont créés : 7 jours, à l'enregistrement d'une ordonnance puis chaque nuit par `bin/generate-agenda.php`. |
| HTML | Langage des pages web. Les vues PHP de `src/View/` produisent du HTML. |
| HTTP | Protocole d'échange entre le navigateur et le serveur web. Un code de réponse dit le résultat : 200 réussi, 303 redirection, 400 requête invalide, 403 refusé, 404 introuvable, 409 conflit, 422 saisie invalide. |
| Hypothèse | Choix fait faute de réponse du service ou du PO, signalé « HYPOTHÈSE » dans le code et à confirmer. |
| Idempotent | Se dit d'une opération qu'on peut relancer sans changer le résultat : un second passage de `bin/generate-agenda.php` ne crée aucun soin. |
| Identitovigilance | Ensemble des précautions qui évitent de confondre deux patients. Ici, le choix d'un patient existant à l'admission est toujours une sélection explicite de l'utilisateur. |
| Interblocage | Situation où deux transactions attendent chacune un verrou tenu par l'autre. La base en annule une ; AURA la rejoue (trois essais au plus pour l'admission). |
| IPP | Identifiant permanent du patient dans l'établissement. Dans AURA : 6 à 9 chiffres, facultatif à la saisie (patient admis en urgence), attribué automatiquement s'il manque ; celui d'un patient supprimé reste réservé. |
| JavaScript (JS) | Langage exécuté par le navigateur. Dans AURA, un fichier par fonctionnalité dans `public/js/`, sans bibliothèque ; il n'est qu'un confort, le serveur revalide tout. |
| Jeton CSRF | Valeur secrète liée à la session du navigateur, envoyée avec chaque POST (champ `csrf` ou en-tête `X-CSRF-Token`). Sans jeton valide, le serveur refuse l'écriture (403). |
| JJ/MM/AAAA | Format de saisie des dates (jour, mois, année), converti par le serveur (`DateInput`). |
| JSON | Format texte d'échange de données (`{"ok": true}`), utilisé par les réponses aux requêtes envoyées en JavaScript. |
| Ligne arrêtée | Ligne de prescription stoppée par un médecin (`stoppedAt`) : ses soins encore prévus sont annulés, rien n'est effacé. |
| Liste de travail | Onglet de l'agenda du service : les soins de tout le service regroupés par urgence (en retard, doses de lignes arrêtées à tracer, maintenant, plus tard, réalisés). |
| Lit, box | Emplacement du patient dans le service. Le séjour garde un numéro de lit et un numéro de box (chambre ou espace individuel). |
| Lucide | Jeu d'icônes libre (licence ISC). Les tracés utilisés sont copiés dans `components/icon.php`. |
| `MAX(CAST(id AS UNSIGNED)) + 1` | Calcul du prochain identifiant d'une table sans numérotation automatique : le plus grand identifiant existant, lu comme un nombre, plus un. Fait dans une transaction, avec un verrou `FOR UPDATE`. |
| Médecin référent | Médecin désigné responsable du patient pendant son séjour, choisi à l'admission. |
| Méthode pure | Méthode qui ne lit ni la base ni la requête et dont le résultat ne dépend que de ses paramètres (date courante comprise) : elle se teste sans base. |
| Migration | Script SQL de `database/migrations/` qui ajoute à une base existante ce qu'un changement de `schema.sql` a introduit (colonnes, index, contraintes), sans effacer les données. |
| Mon planning | Écran d'un soignant : les soins des patients de ses équipes pendant une rotation, avec les mêmes actions que l'agenda. |
| MVC | Modèle, Vue, Contrôleur : découpage du code entre données et règles (modèle : services, DAO, entités), affichage (vues) et traitement de la requête (contrôleurs). |
| Notion | Outil de documentation en ligne où se trouvent le cahier des charges, le dossier de cadrage et le backlog (lien en tête du README). |
| OpenEMR | Logiciel libre de dossier patient. AURA n'y est pas connecté : c'est hors périmètre. |
| Ordonnance | Ensemble de lignes de prescription enregistrées ensemble pour un séjour (table `CarePlan`). Elle est validée en bloc par un médecin. |
| Panneau du lit | Zone à côté du tableau des lits de l'agenda du service, qui détaille le patient choisi (`?sejour=`) : allergies, référent, soins de la rotation avec leurs actions. |
| PascalCase | Écriture des noms composés en collant les mots, chacun avec une majuscule (`EpisodeOfCare`). Utilisée pour les classes et les tables. |
| PDO | PHP Data Objects : interface de PHP pour parler à une base de données. AURA ouvre une seule connexion PDO (`Database::get()`), seulement quand une page en a besoin. |
| PHP | Langage du serveur (version 8.4). AURA n'utilise aucun framework. |
| PHPUnit | Outil de tests automatiques pour PHP (version 10). Configuration dans `phpunit.xml.dist`. |
| PLD Agile, hexanôme | Cadre pédagogique du projet à l'INSA Lyon : projet de 4e année mené en méthode agile par un hexanôme, l'équipe d'étudiants qui a réalisé AURA. |
| PO (Product Owner) | Personne qui porte le besoin, priorise le backlog et valide ce qui est livré. |
| Poids théorique | Poids prédit à partir du sexe et de la taille (formule du protocole ARDSNet). Il sert à régler le volume courant d'un patient ventilé. |
| PR (pull request) | Demande de fusion d'une branche dans une autre sur GitHub. Elle est relue par un autre membre et doit avoir un CI vert avant d'être fusionnée. |
| Praticien, prescripteur | Membre du personnel enregistré dans `Practitioner` (médecin, infirmier, aide-soignant…), avec un rôle. Le prescripteur est le praticien qui crée une ordonnance ; seul un rôle avec `canValidate` peut la valider. |
| Prescription | Décision d'un prescripteur de donner un médicament ou de faire un soin à un patient, avec une date de début, une fréquence éventuelle et une date de fin facultative. Chaque ligne de prescription génère des soins planifiés. |
| Prescription d'urgence, paramédicale | Modes d'une ordonnance (`EMERGENCY`, `PARAMEDICAL`) faite hors des droits habituels du prescripteur ; elle reste « non validée » jusqu'à la validation d'un médecin (RG5). |
| Protocole | Modèle d'ordonnance (table `PlanDefinition`) : un groupe de lignes de prescription types, choisi pour pré-remplir une nouvelle ordonnance. |
| PSR-12 | Règles de présentation du code PHP (indentation, accolades, longueur des lignes), publiées par le PHP-FIG. Vérifiées par `composer cs`. |
| PSR-4 | Norme de chargement automatique des classes PHP : le nom complet de la classe donne le chemin du fichier (`Aura\` correspond à `src/`). |
| Push | Envoi des commits d'une branche locale vers le dépôt GitHub. |
| Query string | Partie d'une adresse après le `?`, qui porte des paramètres (`/sejour?id=1`). AURA y passe tous les identifiants. |
| Rattachement | Ouverture d'un séjour sur un patient déjà présent dans la base, choisi par l'utilisateur, au lieu de créer un nouveau patient. Il est tracé. |
| Redirection après POST | Après une écriture réussie, le serveur répond par une redirection (303) vers une page GET. Recharger la page n'envoie donc pas une seconde fois les données. |
| Référentiel | Liste de valeurs de référence communes (services, médicaments, substances, types de soin, praticiens), chargée par `database/test.sql`. |
| Requête préparée | Requête SQL envoyée à la base séparément de ses valeurs. Une valeur saisie ne peut donc jamais être exécutée comme du SQL (protection contre l'injection SQL). |
| RG (RG1 à RG10) | Règle de gestion : règle métier numérotée du dossier de cadrage. Leur énoncé et le code qui les applique sont dans `docs/REPRISE.md`, leurs tests dans `docs/TESTS.md`. |
| Rotation | Période de travail d'une équipe soignante. AURA en a trois par jour : matin de 6 h à 14 h, après-midi de 14 h à 22 h, nuit de 22 h à 6 h (`CareTeam::SHIFTS`, bornes à confirmer avec le service). Paramètre d'URL `rotation`. |
| Schéma | Structure de la base (tables, colonnes, contraintes), entièrement décrite par `database/schema.sql`. |
| Séjour | Passage d'un patient dans le service, de l'admission à la sortie ou au décès (table `EpisodeOfCare`). Un patient n'a qu'un séjour en cours à la fois ; chaque séjour a un numéro unique. |
| Service (classe) | Classe de `src/Service/` qui porte les règles métier. Sa validation est pure (sans base ni requête), ses écritures passent par une transaction. À distinguer du service hospitalier (réanimation). |
| Session, cookie | La session est une mémoire du serveur associée à un navigateur par un cookie. AURA ne s'en sert que pour le jeton CSRF : il n'y a pas de compte utilisateur. L'attribut `Secure` réserverait le cookie aux connexions HTTPS. |
| Soin en retard | Soin planifié non validé dont l'heure prévue est passée (règle RG8). Le retard est calculé à l'affichage, jamais enregistré, et ces soins sont épinglés en tête de l'agenda. |
| Soin planifié | Occurrence datée d'une ligne de prescription : une administration ou un acte à faire à une heure donnée (table `Appointment`). Statuts : prévu, réalisé, non effectué, annulé. |
| SQL | Langage des bases de données relationnelles (création de tables, lectures, écritures). |
| Suppression logique | La ligne n'est pas effacée : une date est écrite dans `removedAt` et les DAO l'ignorent ensuite. L'historique reste en base (traçabilité). |
| SVG | Format d'image vectorielle écrit en texte, inséré directement dans la page pour les icônes. |
| Traçabilité, trace | Enregistrement de qui a fait quoi et quand (table `AuditEvent` et colonnes d'horodatage), exigé par la règle RG10. |
| Transaction | Groupe d'écritures en base qui réussissent toutes ou sont toutes annulées. Dans AURA : `Transaction::run()`. |
| URL | Adresse d'une page web (`http://localhost:8000/sejour?id=1`). |
| UTF-8 | Codage des caractères (accents compris) utilisé partout. Une saisie qui n'est pas de l'UTF-8 valide est refusée (400). |
| Validation d'un soin | Déclaration qu'un soin planifié a été réalisé ou non effectué, avec le soignant, l'heure de réalisation et l'heure de saisie (règle RG7). Possible une seule fois, au plus tard jusqu'à la fin de la rotation en cours. |
| Validation d'une ordonnance | Acceptation d'une ordonnance par un médecin habilité (rôle avec `canValidate`, règle RG5). Une ordonnance faite par un prescripteur sans ce droit (urgence, paramédicale) reste « non validée » jusque-là ; celle d'un médecin habilité est validée d'office. |
| VARCHAR, INT, DATETIME, TEXT | Types de colonnes SQL : texte de longueur limitée, nombre entier, date et heure, texte long. |
| Verrou (`FOR UPDATE`) | Clause SQL qui verrouille les lignes lues jusqu'à la fin de la transaction. Deux admissions simultanées ne peuvent donc pas calculer le même numéro. |
| VM | Machine virtuelle : ordinateur simulé. La VM de démonstration (Debian, Apache, MariaDB, PHP) sert la branche `main`. |
| Vt (volume courant) | Volume d'air insufflé à chaque cycle par le respirateur. AURA affiche une plage de 6 à 8 ml par kg de poids théorique, à titre indicatif. |
| Vue-modèle | Tableau de données préparé par un service pour une vue (méthodes `present…()`), déjà trié et mis en forme, testable sans base. |
