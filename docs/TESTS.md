# Tests

AURA a une suite de tests volontairement courte : 244 tests PHPUnit 10, chacun lié à une règle de gestion, à la
sécurité du patient ou à un défaut réel déjà rencontré. Un test qui ne vérifie qu'un getter, un constructeur ou le
balisage au caractère près n'a pas sa place ici : il casse à chaque retouche sans rien protéger.

Configuration : `phpunit.xml.dist`, avec `failOnRisky` et `failOnWarning` (un test sans assertion ou un
avertissement PHP fait échouer la suite). Style PSR-12 vérifié par `phpcs` : un seul avertissement suffit à faire
échouer `composer cs`.

## 1. La démarche : quoi est testé, à quel niveau, et pourquoi

Chaque règle est testée au niveau le plus bas qui suffit à la prouver.

| Niveau | Où | Ce qu'on y vérifie | Pourquoi ce niveau |
| --- | --- | --- | --- |
| Unitaire pur, sans base | `tests/Service/*ServiceTest.php`, `tests/Domain`, `tests/Core`, `tests/Controller`, `tests/View` | règles métier (`validate()`, présentateurs de l'agenda, rotations, alerte allergène), codes HTTP des contrôleurs, échappement | rapide, déterministe (date injectée), tourne sur n'importe quel poste sans MariaDB |
| Sur la vraie base | `tests/Dao/*`, `tests/Service/*IntegrationTest.php` | SQL des DAO, contraintes de `schema.sql`, transactions, enchaînements service + DAO | un simulacre ne voit ni une colonne renommée ni une contrainte qui bloque (cas réel : la PR #52 a corrigé deux DAO cassés par un renommage) |
| HTTP de bout en bout | `tests/Http` | parcours réels : session, jeton CSRF, POST, redirections, codes 400/404 | vérifie le câblage de `public/index.php`, que les autres niveaux ne voient pas |

Répartition (244 tests) :

| Dossier | Tests | Dont sur base |
| --- | --- | --- |
| `tests/Core` | 9 | 0 |
| `tests/Controller` | 9 | 0 |
| `tests/Domain` | 6 | 0 |
| `tests/Dao` | 35 | 35 |
| `tests/Service` | 160 | 36 |
| `tests/View` | 5 | 0 |
| `tests/Http` | 20 | 20 |

Les deux suites de `phpunit.xml.dist` : `aura` (tout sauf `tests/Http`, 224 tests) et `http` (`tests/Http`,
20 tests).

### Écrire un test

- Un fichier par classe, même arborescence que `src/` (`tests/Service/XxxServiceTest.php`), classe `final`,
  namespace `Aura\Tests\…`. Nom de méthode en anglais qui énonce la règle (`testCareOfAComingDayCannotBeValidatedAhead`),
  commentaires et messages d'assertion en français, courts.
- Service pur : date injectée (`new DateTimeImmutable('2026-10-14')`), DAO doublés par `createMock()` ou
  `createStub()`. `#[DataProvider]` statique seulement quand il évite une vraie répétition.
- Avec base : dans `setUp()`, `markTestSkipped()` si `DB_HOST` ou `DB_NAME` manque, puis `Database::get()` et
  `beginTransaction()` ; `rollBack()` dans `tearDown()`. Données aux noms improbables (`Zz…`), dates écrites par le
  test (jamais `NOW()` de la base, dont le fuseau peut différer). Exception : `AdmissionIntegrationTest` teste la
  vraie transaction, il efface ses lignes `Zzadmissionintegration` avant et après chaque test.
- HTTP : hériter de `tests/Http/HttpTestCase` (`get()`, `postForm()`, `postJson()`, `csrfFrom($page)`). Tout ce qui
  est écrit en base est effacé dans un `finally` : il n'y a pas de transaction côté serveur.
- Contrôleur testé seul qui envoie des en-têtes ou ouvre la session : `#[RunInSeparateProcess]` et
  `#[PreserveGlobalState(false)]`, sortie capturée par `ob_start()`.
- Tester un comportement public, pas un détail interne : un test ne doit pas casser quand on supprime du code mort
  ou qu'on réorganise une classe sans changer ce qu'elle fait.

## 2. Exécuter les tests pas à pas

### 2.1 Installation

PHP 8.4 avec les extensions `pdo_mysql` et `curl`, Composer 2, puis à la racine du dépôt :

```bash
composer install
```

Sans aucune base, les tests purs tournent déjà :

```bash
composer test:fast
```

Résultat attendu : « OK, but some tests were skipped! », 224 tests dont 71 sautés (ceux qui demandent la base).

### 2.2 Créer la base de test `aura_test` et l'utilisateur `aura_ci`

Une seule fois, en administrateur MariaDB (MariaDB 11.8). Ces mots de passe ne protègent rien : ce ne sont pas des
secrets, ce sont les mêmes valeurs qu'au CI.

```sql
CREATE DATABASE aura_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'aura_ci'@'localhost' IDENTIFIED BY 'aura_ci';
CREATE USER 'aura_ci'@'127.0.0.1' IDENTIFIED BY 'aura_ci';
GRANT ALL PRIVILEGES ON aura_test.* TO 'aura_ci'@'localhost';
GRANT ALL PRIVILEGES ON aura_test.* TO 'aura_ci'@'127.0.0.1';
```

Jamais la base de développement `aura` : les tests HTTP écrivent pour de vrai et refusent de tourner sur une base
dont le nom ne contient pas « test ».

### 2.3 Charger `schema.sql` puis `test.sql`

```bash
mariadb -u aura_ci -paura_ci -h 127.0.0.1 aura_test < database/schema.sql
mariadb -u aura_ci -paura_ci -h 127.0.0.1 aura_test < database/test.sql
```

À refaire après chaque changement de `schema.sql` (pas de migration : on supprime et on recrée la base). Les tests
s'appuient sur les dates fixes de `test.sql` (jour de référence : mercredi 14 octobre 2026) : ne jamais appliquer
`database/demo-today.sql` à la base de test.

### 2.4 Lancer les tests avec la base

```bash
export DB_HOST=127.0.0.1 DB_PORT=3306 DB_NAME=aura_test DB_USER=aura_ci DB_PASSWORD=aura_ci
composer test            # les deux suites : « OK (244 tests, … assertions) »
composer test:fast       # suite « aura » seulement (224 tests)
composer test:http       # suite « http » seulement (20 tests, base requise)
composer test:coverage   # couverture texte, et HTML dans build/coverage (extension pcov requise)
```

Pour un seul fichier ou une seule méthode :

```bash
vendor/bin/phpunit tests/Service/AgendaServiceTest.php
vendor/bin/phpunit --filter testShiftEndFollowsTheRotations
```

Attention : `Database` lit d'abord `config/config.php` s'il existe, et ne lit les variables `DB_*` (via
`config/config.example.php`) qu'en son absence. Dans un clone qui a un `config/config.php` pointant vers la base de
développement, les tests sur base l'utiliseraient : lancez-les depuis un clone ou un worktree sans
`config/config.php`, comme le CI.

Couverture en local : `pecl install pcov` une fois, puis `composer test:coverage`. Elle ne compte que le code
exécuté dans le processus PHPUnit : les tests HTTP passent par un serveur séparé et n'y entrent pas.

### 2.5 Ce que signifient les tests sautés

Sans `DB_HOST` ou `DB_NAME`, chaque test qui demande la base est marqué « sauté » (S) au lieu d'échouer : 71 dans
la suite `aura`, les 20 de la suite `http`. PHPUnit affiche « OK, but some tests were skipped! » et rend la main
avec le code 0.

Un test sauté n'a rien vérifié. Une suite verte avec des sautés prouve les règles pures, pas les DAO, les
contraintes de la base ni les parcours HTTP. Avant de pousser, lancez la suite avec la base (ou laissez le CI le
faire) : elle doit afficher « OK (244 tests, … assertions) », sans aucun saut.

Les tests HTTP peuvent aussi être sautés avec le message « Base ouverte « … » » : la base réellement ouverte n'est
pas une base de test (souvent un `config/config.php` qui prend le pas sur `DB_*`). Pour voir le motif de chaque
saut : `vendor/bin/phpunit --display-skipped`.

### 2.6 Lire un échec

Pendant l'exécution, chaque test affiche un caractère : `.` réussi, `F` échec (une assertion est fausse), `E` erreur
(exception ou `TypeError`), `S` sauté, `R` risqué (aucune assertion), `W` avertissement. Avec la configuration du
projet, `R` et `W` font échouer la suite comme `F` et `E`.

Pour chaque échec, PHPUnit donne :

1. la classe et la méthode, et pour un `#[DataProvider]` le nom du cas (`with data set "fin de mois : …"`) : le nom
   de la méthode dit quelle règle est cassée ;
2. le message d'assertion en français quand il y en a un (« le retard n'apparaît qu'une fois ») ;
3. pour `assertSame`, les valeurs attendue et obtenue côte à côte (`--- Expected` / `+++ Actual`) ;
4. le fichier et la ligne de l'assertion dans le test.

Ensuite : relancer ce seul test avec `--filter`, lire la règle dans le test, puis le code dans `src/`. Pour un test
sur base, vérifier d'abord que `aura_test` a été rechargée avec le `schema.sql` et le `test.sql` du moment. Pour un
test HTTP, le corps de la réponse est souvent joint au message (`assertSame(200, $response->status, $response->body)`) :
l'erreur PHP du serveur y apparaît.

### 2.7 Le CI GitHub

`.github/workflows/ci.yml` tourne à chaque push sur `main` et à chaque pull request, quelle que soit sa base :

1. valide `composer.json` et installe les dépendances ;
2. vérifie la syntaxe (`php -l` sur `src`, `tests`, `public`, `config`, `bin`) et le style (`composer cs`) ;
3. démarre une MariaDB 11.8 neuve (base `aura_test`, utilisateur `aura`, mot de passe `aura_ci`), y charge
   `schema.sql` puis `test.sql` ;
4. lance les deux suites avec les variables `DB_*` et la couverture (pcov) : résumé dans le journal, rapport HTML
   dans l'artefact « couverture » de l'exécution.

Une pull request ne se fusionne qu'avec un CI vert et la relecture d'un autre membre. Le script local
`.claude/scripts/ci-local.sh` (outil de Raphaël, non versionné) rejoue ces étapes sur une base jetable ; sans lui,
les commandes des sections 2.2 à 2.4 suffisent.

## 3. Règles de gestion → tests

Chaque règle du cahier des charges et les tests qui la garantissent. Une règle sans test ici est une dette.

| Règle | Énoncé | Tests |
| --- | --- | --- |
| RG1 | Patient identifié (nom, prénom), IPP facultatif ; numéro de séjour unique `AAAA-NNNNNN` généré | `AdmissionServiceTest` (nom et prénom obligatoires, IPP de 6 à 9 chiffres, IPP d'un patient supprimé refusé), `AdmissionIntegrationTest` (un patient et un séjour, numéro propre à chaque séjour, patient connu réutilisé, décédé non réadmis, IPP d'une autre personne refusé, groupe sanguin verrouillé), `EpisodeOfCareDaoTest` (format, compteur annuel, numéro saisi ignoré), `SchemaConstraintsTest` (IPP et numéro en double), `WorkflowsHttpTest::testAdmissionRejectsMissingNamesThenAdmitsAndOpensTheRecord` |
| RG2 | Identité et allergies en tête de tout écran de séjour, « Aucune allergie connue » explicite | `PatientRecordServiceTest`, `ComponentsRenderTest`, `PagesHttpTest::testEveryScreenOfAnEpisodeShowsThePatientBannerWithAllergies`, `AllergyIntoleranceServiceTest`, `AllergyIntoleranceDaoTest`, `CarePlanServiceTest` et `CarePlanIntegrationTest` (alerte allergène à la prescription, confirmation exacte, allergène du nom du médicament), `AgendaServiceTest::testWorklistCareCarriesAllergiesAndDeath` |
| RG3 | Prescription sans date de début refusée | `CarePlanServiceTest::testStartDateIsRequired`, `CarePlanServiceTest::testPrescriptionDatesAreBounded`, `RequestLineTest` |
| RG4 | Prescription rattachée à un séjour, un prescripteur et un type de soin | `CarePlanServiceTest::testPrescriptionNeedsAPrescriberAndACareType`, `CarePlanDaoTest::testSavingPrescriptionCreatesItsScheduledCare` |
| RG5 | Prescription d'urgence ou paramédicale « non validée » jusqu'à la validation d'un médecin authentifié | `CarePlanTest`, `CarePlanServiceTest::testValidationNeedsADoctorAndAPassword`, `CarePlanIntegrationTest` (validation par un médecin, mot de passe du prescripteur médecin), `ReferenceDataDaoTest`, `AgendaServiceTest::testStoppedLineAndUnvalidatedPrescriptionAreFlagged`, `SchemaConstraintsTest` (valideur sans date) |
| RG6 | Agenda généré à partir des prescriptions actives, une occurrence par horaire | `RequestLineTest` (génération limitée à 7 jours), `CarePlanDaoTest`, `AgendaGenerationServiceTest`, `AgendaGenerationIntegrationTest`, `SchemaConstraintsTest` (même heure deux fois sur une ligne) |
| RG7 | Validation d'un soin : qui, quand, catégorie habilitée, une seule fois, au plus tard jusqu'à la fin de la rotation en cours | `AppointmentServiceTest`, `AppointmentIntegrationTest` (une seule fois, aide-soignant et médicament, jour à venir, rotation en cours, passage de minuit, ligne arrêtée), `AppointmentDaoTest`, `AgendaServiceTest::testShiftEndFollowsTheRotations`, `CareTeamServiceTest::testAgendaShiftEndUsesTheSameRotations`, `WorkflowsHttpTest::testCareIsValidatedOnceWithCsrfAndAuthorShownInTheAgenda` |
| RG8 | Soin prévu non validé après son heure = en retard, en tête de l'agenda | `AgendaServiceTest` (retard dès l'heure passée, seul un soin prévu est en retard, soin de la veille reporté, lits en retard d'abord), `CareTeamServiceTest::testNightShiftCollectsCareOfBothCalendarDays`, `PagesRenderTest::testLateCareIsShownOnceAtTheTopOfTheAgenda` |
| RG9 | Pas de recalcul rétroactif ; génération idempotente ; jour passé consultable | `AgendaGenerationServiceTest`, `AgendaGenerationIntegrationTest`, `CarePlanDaoTest::testStoppingALineCancelsItsFutureScheduledCareOnly`, `PagesHttpTest` (agenda du 14/10/2026) |
| RG10 | Traçabilité : auteur et horodatage, rien n'est effacé (suppression logique, annulation tracée) | `RecordEditServiceTest::testSavedFieldIsTraced`, `RecordSectionIntegrationTest`, `PatientDeletionServiceTest`, `PatientDeletionIntegrationTest`, `AdmissionIntegrationTest::testStayIsLinkedToTheSelectedPatientAndTraced`, `AppointmentIntegrationTest::testValidatedCareIsTracedAndCannotBeValidatedTwice`, `CarePlanIntegrationTest` (confirmation d'allergie tracée), `SchemaConstraintsTest` (suppression refusée, arrêt sans auteur) |

Autres garanties essentielles :

| Sujet | Tests |
| --- | --- |
| Rotations 6 h, 14 h, 22 h (`CareTeam::SHIFTS`), une seule équipe par rotation pour un patient | `CareTeamServiceTest`, `CareTeamDaoTest`, `SchemaConstraintsTest` (rotation inconnue, nom d'équipe), `WorkflowsHttpTest::testTeamIsComposedAndRemovedForAShift` |
| Décès : termine le séjour en cours, annule les soins suivants, heure invalide refusée | `RecordSectionIntegrationTest` (décès, heure de décès ou de sortie invalide), `RecordEditServiceTest::testDeathIsRefusedWithoutAnIdentifiedDoctor`, `RecordControllerTest::testDeathIsNeverChangedInline`, `WorkflowsHttpTest::testDeclaringDeathNeedsCsrfAndEndsTheStay` |
| Édition en ligne : authentification factice exigée, séjour affiché seulement | `RecordControllerTest`, `RecordEditServiceTest`, `RecordSectionIntegrationTest::testPartNeedsTheFactitiousAuthenticationOfADoctor`, `WorkflowsHttpTest::testInlineEditNeedsFactitiousDoctorAuthenticationAndTracesTheDoctor` |
| Ligne arrêtée | `CarePlanDaoTest::testStoppingALineCancelsItsFutureScheduledCareOnly`, `AppointmentIntegrationTest::testStoppedLineAcceptsOnlyCareDoneBeforeTheStop`, `AgendaServiceTest`, `WorkflowsHttpTest::testStoppingALineNeedsCsrfAndADoctorPasswordAndCancelsItsFutureCare` |
| CSRF sur les POST | `CsrfTest`, `RecordControllerTest::testWithoutCsrfTokenNothingIsWritten`, `WorkflowsHttpTest` (admission, soin, équipe, arrêt d'une ligne, décès : refusés sans jeton) |
| Échappement HTML, UTF-8 invalide | `ViewTest`, `ComponentsRenderTest`, `PagesRenderTest`, `PagesHttpTest` (octet UTF-8 invalide : 400) |
| Paramètres malformés : 400 ou 404, jamais un plantage | `PagesHttpTest`, `RecordControllerTest::testMalformedRequestGives400`, `PatientControllerTest`, `AgendaServiceTest::testMalformedDayFallsBackToToday`, `CareTeamServiceTest::testUnknownRotationInTheUrlIsIgnored` |
| Contraintes essentielles de `schema.sql` (unicité, CHECK, clé étrangère, suppression refusée) | `SchemaConstraintsTest`, cas choisis parmi ceux de `database/test_contraintes.sql` (écrit par Didac Espin) |

## 4. Ce qui n'est pas testé, et pourquoi

- **Le JavaScript** (`public/js/*.js`) n'a aucun test automatique. Le serveur revalide tout ce que le navigateur
  envoie, et la suite `http` vérifie ces refus côté serveur. Le comportement de l'interface se vérifie à la main et
  dans Chrome, piloté par un script local (`.claude/scripts/e2e-validate.mjs`, non versionné) et par des captures.
  La limite est connue : le bouton « Valider » d'un soin n'envoyait rien à cause d'un champ nommé `action` dans le
  formulaire, et aucun test ne l'a vu, puisque la suite `http` appelle le serveur directement. Un test navigateur
  (Playwright) serait la suite logique ; c'est un outil de plus à installer et à maintenir, à décider en équipe.
- **Le contraste des couleurs et la lecture d'écran** : vérifiés à la main.
- **Écarté volontairement** : getters et constructeurs, balisage fin (classes CSS, icônes, attributs au caractère
  près), libellés et formats d'affichage, tris des listes déroulantes, liste des patients et historique des séjours,
  contenu des données de démo, variantes multiples d'un même cas. Ces points se voient à l'écran ; un test pour
  chacun coûterait plus en maintenance qu'il ne protège.
