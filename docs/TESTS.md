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

PHP 8.4 avec les extensions `pdo_mysql` et `mbstring`, plus `curl` pour les tests HTTP, Composer 2, puis à la racine
du dépôt :

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
s'appuient sur les dates fixes de `test.sql` (jour de référence : mercredi 14 octobre 2026) : ne jamais lancer
`database/demo-today.sql` ni `bin/generate-agenda.php` sur la base de test. Le premier décale toutes les dates, le
second crée des soins à partir du jour courant.

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

Qui l'emporte entre `config/config.php` et les variables `DB_*` : `Database` lit `config/config.php` s'il existe,
sinon `config/config.example.php` quand `DB_HOST` est défini. Un `config.php` copié du modèle garde ses
`getenv('DB_…') ?: valeur` : les variables `DB_*` l'emportent donc, et les tests visent bien `aura_test`. Le risque
n'existe que si `config.php` contient des valeurs écrites en dur (par exemple `'name' => 'aura'` à la place de
`getenv('DB_NAME') ?: 'aura'`) : les tests sur base utiliseraient alors la base qu'il désigne. Dans ce cas, lancez-les
depuis un clone ou un worktree sans `config/config.php`, comme le CI.

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
pas une base de test (souvent un `config/config.php` aux valeurs écrites en dur, qui ignore `DB_*`). Pour voir le motif de chaque
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

Une pull request ne se fusionne qu'avec un CI vert et la relecture d'un autre membre. Pour rejouer ces étapes en
local avant de pousser : `composer validate --strict`, `php -l` sur chaque fichier PHP modifié, `composer cs`, puis
les commandes des sections 2.3 et 2.4 (recharger `aura_test`, exporter les variables `DB_*`, `composer test`).

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
| Contraintes essentielles de `schema.sql` (unicité, CHECK, clé étrangère, suppression refusée) | `SchemaConstraintsTest`, cas choisis parmi ceux du fichier `test_contraintes.sql` écrit par Didac Espin (resté sur une branche, absent de `main`) |

## 4. Ce qui n'est pas testé, et pourquoi

- **Le JavaScript** (`public/js/*.js`) n'a aucun test automatique. Le serveur revalide tout ce que le navigateur
  envoie, et la suite `http` vérifie ces refus côté serveur. Le comportement de l'interface se vérifie à la main : lancer
  `php -S localhost:8000 -t public public/index.php` sur une base de démonstration, puis refaire le parcours dans le
  navigateur (par exemple « Valider » un soin depuis l'agenda du service et vérifier qu'il passe à « Réalisé »).
  La limite est connue : le bouton « Valider » d'un soin n'envoyait rien à cause d'un champ nommé `action` dans le
  formulaire, et aucun test ne l'a vu, puisque la suite `http` appelle le serveur directement. Un test navigateur
  (Playwright) serait la suite logique ; c'est un outil de plus à installer et à maintenir, à décider en équipe.
- **Le contraste des couleurs et la lecture d'écran** : vérifiés à la main.
- **Écarté volontairement** : getters et constructeurs, balisage fin (classes CSS, icônes, attributs au caractère
  près), libellés et formats d'affichage, tris des listes déroulantes, liste des patients et historique des séjours,
  contenu des données de démo, variantes multiples d'un même cas. Ces points se voient à l'écran ; un test pour
  chacun coûterait plus en maintenance qu'il ne protège.

## Glossaire

Les acronymes et les termes techniques ou métier employés dans ce document.

| Terme | Signification |
| --- | --- |
| AAAA-NNNNNN | Format du numéro de séjour : année d'admission sur quatre chiffres, un tiret, puis un compteur sur six chiffres qui repart à 000001 chaque année (exemple : 2026-000108). Généré par l'application, jamais saisi. |
| Aide-soignant(e) | Soignant qui assiste l'infirmier pour les soins d'hygiène et de confort. Hypothèse du projet : un aide-soignant peut valider un soin, pas l'administration d'un médicament. |
| Artefact | Fichier produit par une exécution du CI et téléchargeable depuis GitHub, ici le rapport de couverture HTML. |
| Assertion | Vérification écrite dans un test (`assertSame(attendu, obtenu)`). Si elle est fausse, le test échoue. |
| Authentification factice | Fenêtre qui demande de choisir un praticien dans une liste et de saisir un mot de passe, mais qui ne vérifie pas ce mot de passe. Elle remplace provisoirement une vraie authentification ; le nom choisi est enregistré dans la trace. |
| Branche | Ligne de développement séparée dans Git. On crée une branche par fonctionnalité ou correction, puis on la fusionne dans `main` par une pull request. |
| Catégorie habilitée | Catégorie de soignant autorisée à valider un type de soin (règle RG7). Hypothèse du projet : reconnue par le libellé du rôle du soignant. |
| CHECK | Contrainte SQL qui refuse une valeur hors d'une liste ou d'un intervalle (par exemple une rotation autre que MATIN, APRES_MIDI ou NUIT). |
| CI | Intégration continue : vérifications lancées automatiquement par GitHub Actions à chaque push sur `main` et à chaque pull request (syntaxe, style, schéma, tests). Fichier `.github/workflows/ci.yml`. |
| Clé étrangère (FK) | Colonne qui désigne une ligne d'une autre table (par exemple `EpisodeOfCare.idPatient`). La base refuse une valeur qui ne correspond à aucune ligne, et refuse d'effacer une ligne encore désignée. |
| Composer | Gestionnaire de dépendances de PHP. `composer install` installe les bibliothèques ; `composer test`, `composer cs`… lancent les commandes déclarées dans `composer.json`. |
| Contrainte d'unicité (UNIQUE) | Contrainte SQL qui interdit deux lignes avec la même valeur dans une colonne ou un groupe de colonnes. Exemple : `EpisodeOfCare.episodeNumber`, le numéro de séjour. |
| Couverture de code | Part du code exécutée par les tests, mesurée par l'extension pcov. |
| CSRF | Cross-Site Request Forgery : attaque qui fait envoyer une requête par le navigateur d'un utilisateur à son insu. Parade : un jeton de session secret, exigé sur chaque POST (`Csrf::check()`). |
| CSS | Langage de mise en forme des pages web. AURA a un seul fichier : `public/css/style.css`. |
| DAO | Data Access Object : classe de `src/Dao/` qui lit et écrit une table. C'est le seul endroit où apparaissent les noms de colonnes SQL. |
| DataProvider | Attribut PHPUnit (`#[DataProvider]`) qui fait tourner une même méthode de test sur une liste de cas (valeurs d'entrée et résultat attendu). |
| Déclaration du décès | Action réservée à un médecin (authentification factice) : le patient est marqué décédé, le séjour en cours se termine à l'heure du décès et ses soins encore prévus sont annulés (`POST /sejour/deces`). |
| Données de démo | Jeu de données fictives de `database/test.sql`, dont le jour de référence est le mercredi 14 octobre 2026. `database/demo-today.sql` le recale sur le jour courant pour une démonstration. |
| Échappement | Transformation des caractères spéciaux (`<`, `>`, `&`, guillemets) avant de les afficher dans une page, pour qu'une donnée ne soit jamais interprétée comme du HTML ou du JavaScript. Fait par `View::e()`. |
| Édition en ligne | Modification d'un seul champ du dossier directement dans la page (crayon, puis « Confirmer »). Elle passe par l'authentification factice d'un médecin et est tracée. |
| Équipe de rotation | Groupe de soignants composé pour une rotation donnée, avec les patients dont il a la charge. Un patient n'appartient qu'à une équipe par rotation. |
| Fusion (merge) | Intégration des changements d'une branche dans une autre, ici d'une branche de fonctionnalité dans `main`, par une pull request. |
| GET, POST | Méthodes HTTP. GET lit une page sans rien modifier ; POST envoie des données à enregistrer. |
| GitHub, GitHub Actions | Site qui héberge le dépôt Git, les pull requests et le CI (GitHub Actions, onglet « Actions » du dépôt). |
| HTML | Langage des pages web. Les vues PHP de `src/View/` produisent du HTML. |
| HTTP | Protocole d'échange entre le navigateur et le serveur web. Un code de réponse dit le résultat : 200 réussi, 303 redirection, 400 requête invalide, 403 refusé, 404 introuvable, 409 conflit, 422 saisie invalide. |
| HTTPS | Version chiffrée de HTTP. Un cookie marqué `Secure` n'est envoyé par le navigateur que sur une connexion HTTPS. |
| Idempotent | Se dit d'une opération qu'on peut relancer sans changer le résultat : un second passage de `bin/generate-agenda.php` ne crée aucun soin. |
| IPP | Identifiant permanent du patient dans l'établissement. Dans AURA : 6 à 9 chiffres, facultatif à la saisie (patient admis en urgence), attribué automatiquement s'il manque ; celui d'un patient supprimé reste réservé. |
| JavaScript (JS) | Langage exécuté par le navigateur. Dans AURA, un fichier par fonctionnalité dans `public/js/`, sans bibliothèque ; il n'est qu'un confort, le serveur revalide tout. |
| Jeton CSRF | Valeur secrète liée à la session du navigateur, envoyée avec chaque POST (champ `csrf` ou en-tête `X-CSRF-Token`). Sans jeton valide, le serveur refuse l'écriture (403). |
| Ligne arrêtée | Ligne de prescription stoppée par un médecin (`stoppedAt`) : ses soins encore prévus sont annulés, rien n'est effacé. |
| MariaDB | Système de base de données SQL (version 11.8), compatible avec MySQL. |
| Méthode pure | Méthode qui ne lit ni la base ni la requête et dont le résultat ne dépend que de ses paramètres (date courante comprise) : elle se teste sans base. |
| pcov | Extension PHP qui mesure la couverture de code pendant les tests (`composer test:coverage`). |
| PHP-FIG | PHP Framework Interop Group : groupe qui publie les recommandations PSR suivies par le code (PSR-4 pour le chargement des classes, PSR-12 pour la présentation). |
| phpcs | PHP_CodeSniffer : outil qui vérifie le style du code (`composer cs`) ; `phpcbf` (`composer cs:fix`) corrige automatiquement. |
| PHPUnit | Outil de tests automatiques pour PHP (version 10). Configuration dans `phpunit.xml.dist`. |
| Playwright | Outil qui pilote un vrai navigateur pour tester l'interface, JavaScript compris. Il n'est pas installé dans AURA. |
| PR (pull request) | Demande de fusion d'une branche dans une autre sur GitHub. Elle est relue par un autre membre et doit avoir un CI vert avant d'être fusionnée. |
| Prescription | Décision d'un prescripteur de donner un médicament ou de faire un soin à un patient, avec une date de début, une fréquence éventuelle et une date de fin facultative. Chaque ligne de prescription génère des soins planifiés. |
| Prescription d'urgence, paramédicale | Modes d'une ordonnance (`EMERGENCY`, `PARAMEDICAL`) faite hors des droits habituels du prescripteur ; elle reste « non validée » jusqu'à la validation d'un médecin (RG5). |
| PSR-12 | Règles de présentation du code PHP (indentation, accolades, longueur des lignes), publiées par le PHP-FIG. Vérifiées par `composer cs`. |
| Redirection après POST | Après une écriture réussie, le serveur répond par une redirection (303) vers une page GET. Recharger la page n'envoie donc pas une seconde fois les données. |
| RG (RG1 à RG10) | Règle de gestion : règle métier numérotée du dossier de cadrage. Leur énoncé et le code qui les applique sont dans `docs/REPRISE.md`, leurs tests dans `docs/TESTS.md`. |
| Rotation | Période de travail d'une équipe soignante. AURA en a trois par jour : matin de 6 h à 14 h, après-midi de 14 h à 22 h, nuit de 22 h à 6 h (`CareTeam::SHIFTS`, bornes à confirmer avec le service). Paramètre d'URL `rotation`. |
| Schéma | Structure de la base (tables, colonnes, contraintes), entièrement décrite par `database/schema.sql`. |
| Séjour | Passage d'un patient dans le service, de l'admission à la sortie ou au décès (table `EpisodeOfCare`). Un patient n'a qu'un séjour en cours à la fois ; chaque séjour a un numéro unique. |
| Session, cookie | La session est une mémoire du serveur associée à un navigateur par un cookie. AURA ne s'en sert que pour le jeton CSRF : il n'y a pas de compte utilisateur. L'attribut `Secure` réserverait le cookie aux connexions HTTPS. |
| setUp, tearDown, rollBack | `setUp()` et `tearDown()` sont lancées par PHPUnit avant et après chaque test. Les tests sur base y ouvrent une transaction puis l'annulent (`rollBack()`), pour ne rien laisser en base. |
| Simulacre (mock, stub) | Faux objet qui remplace un DAO dans un test sans base : il renvoie des valeurs prévues (stub) ou vérifie la façon dont il est appelé (mock). |
| Soin en retard | Soin planifié non validé dont l'heure prévue est passée (règle RG8). Le retard est calculé à l'affichage, jamais enregistré, et ces soins sont épinglés en tête de l'agenda. |
| Soin planifié | Occurrence datée d'une ligne de prescription : une administration ou un acte à faire à une heure donnée (table `Appointment`). Statuts : prévu, réalisé, non effectué, annulé. |
| SQL | Langage des bases de données relationnelles (création de tables, lectures, écritures). |
| Suppression logique | La ligne n'est pas effacée : une date est écrite dans `removedAt` et les DAO l'ignorent ensuite. L'historique reste en base (traçabilité). |
| Test d'intégration | Test qui fait travailler ensemble un service, ses DAO et une vraie base de test. |
| Test de bout en bout | Test qui envoie de vraies requêtes HTTP à un serveur PHP lancé pour l'occasion, comme le ferait un navigateur, et vérifie les réponses (`tests/Http`). |
| Test risqué | Test sans aucune assertion (lettre R). La configuration du projet le compte comme un échec. |
| Test sauté | Test non exécuté (lettre S), par exemple faute de base de données. Il n'a rien vérifié. |
| Test unitaire | Test d'une règle isolée, sans base de données, avec des données et une date fixées par le test. |
| Traçabilité, trace | Enregistrement de qui a fait quoi et quand (table `AuditEvent` et colonnes d'horodatage), exigé par la règle RG10. |
| Transaction | Groupe d'écritures en base qui réussissent toutes ou sont toutes annulées. Dans AURA : `Transaction::run()`. |
| TypeError | Erreur PHP levée quand une fonction reçoit une valeur d'un type inattendu. |
| UTF-8 | Codage des caractères (accents compris) utilisé partout. Une saisie qui n'est pas de l'UTF-8 valide est refusée (400). |
| Validation d'un soin | Déclaration qu'un soin planifié a été réalisé ou non effectué, avec le soignant, l'heure de réalisation et l'heure de saisie (règle RG7). Possible une seule fois, au plus tard jusqu'à la fin de la rotation en cours. |
| Validation d'une ordonnance | Acceptation d'une ordonnance par un médecin habilité (rôle avec `canValidate`, règle RG5). Une ordonnance faite par un prescripteur sans ce droit (urgence, paramédicale) reste « non validée » jusque-là ; celle d'un médecin habilité est validée d'office. |
| Worktree | Second dossier de travail Git lié au même dépôt, sur une autre branche. Utile pour lancer les tests sans le `config/config.php` du dossier principal quand celui-ci contient des valeurs écrites en dur. |
