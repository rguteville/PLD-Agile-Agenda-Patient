# Tests

PHPUnit 10, configuration `phpunit.xml.dist` (`failOnRisky` et `failOnWarning` : un test sans assertion ou une
dépréciation casse le CI). Style PSR-12 vérifié par `phpcs` (un avertissement suffit à faire échouer `composer cs`).

## Les suites

| Suite | Dossier | Ce qu'elle vérifie | Base |
| --- | --- | --- | --- |
| `aura` | `tests/` sauf `Http` | règles métier pures (services), entités, rendu HTML, contrôleurs isolés ; DAO et `*IntegrationTest` sur la vraie base, chacun dans une transaction annulée | sautés sans `DB_*` |
| `http` | `tests/Http` | bout en bout : un serveur `php -S` sert l'application, chaque test enchaîne de vraies requêtes (session, CSRF, POST, redirections) et nettoie ce qu'il écrit | sautés sans `DB_*` |

## Lancer

```bash
composer test            # les deux suites (sans DB_*, tout ce qui demande la base est sauté)
composer test:fast       # suite « aura » seulement
composer test:http       # suite « http » seulement (base requise)
composer test:coverage   # couverture texte + HTML dans build/coverage (extension pcov requise)
composer cs && composer cs:fix
```

Avec la base de test (ce que fait le CI) :

```bash
export DB_HOST=127.0.0.1 DB_PORT=3306 DB_NAME=aura_test DB_USER=aura_ci DB_PASSWORD=aura_ci
composer test
```

Le CI GitHub (`.github/workflows/ci.yml`, à chaque push sur `main` et à chaque pull request) valide
`composer.json`, vérifie la syntaxe (`php -l` sur `src`, `tests`, `public`, `config`, `bin`) et le style, charge
`schema.sql` et `test.sql` dans une MariaDB 11.8 jetable (base `aura_test`, utilisateur `aura` / `aura_ci`), puis
lance les deux suites avec couverture (pcov) : le résumé est dans le journal, le rapport HTML dans l'artefact
« couverture ».

**Couverture en local** : `pecl install pcov` (une fois), puis `composer test:coverage`. La couverture ne compte
que le code exécuté dans le processus PHPUnit : les tests HTTP passent par un serveur séparé et n'y entrent pas
(ils vérifient le comportement de bout en bout, les autres suites mesurent la couverture).

## Base de test

- Base jetable `aura_test`, jamais la base de développement `aura`. Mots de passe sans valeur (pas des secrets) :
  le CI GitHub utilise l'utilisateur `aura` / `aura_ci` ; en local, l'exemple ci-dessus utilise `aura_ci` / `aura_ci`.
- Création en local (une fois, en administrateur MariaDB), puis chargement à chaque changement du schéma :

  ```sql
  CREATE DATABASE aura_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER 'aura_ci'@'localhost' IDENTIFIED BY 'aura_ci';
  GRANT ALL PRIVILEGES ON aura_test.* TO 'aura_ci'@'localhost';
  ```

  ```bash
  mariadb -u aura_ci -paura_ci aura_test < database/schema.sql
  mariadb -u aura_ci -paura_ci aura_test < database/test.sql
  ```
- Le script `.claude/scripts/ci-local.sh` (outil local de Raphaël, non versionné) enchaîne ces étapes et rejoue
  le CI ; sans lui, les commandes ci-dessus suffisent.
- `config/config.example.php` lit les variables `DB_*` ; `Database` ne l'utilise que si `DB_HOST` est défini.
- Les tests s'appuient sur les dates fixes de `test.sql` (jour de référence : mercredi 14 octobre 2026). Ne jamais
  y appliquer `database/demo-today.sql`.

## Écrire un test

- Un fichier par classe, même arborescence que `src/` (`tests/Service/XxxServiceTest.php`), classe `final`,
  namespace `Aura\Tests\…`. Noms de méthodes en anglais qui énoncent la règle
  (`testCareOfAComingDayCannotBeValidatedAhead`) ; commentaires et messages d'assertion en français.
- Service pur : date injectée (`new DateTimeImmutable('2026-10-14')`), variantes par `#[DataProvider]` statique.
- Avec base : en `setUp`, sauter si `DB_HOST` ou `DB_NAME` manquent (`markTestSkipped`), `Database::get()`,
  `beginTransaction()`, `rollBack()` en `tearDown`. Données aux noms improbables (`Zz…`, `ZZ_…`), dates écrites
  par le test (jamais `NOW()` de la base : son fuseau peut différer de celui de l'application).
- HTTP : hériter de `tests/Http/HttpTestCase` ; `get()`, `postForm()`, `postJson()`, `csrfFrom($page)` pour le jeton
  de la session du test. Ce qui est écrit en base doit être effacé dans un `finally` (pas de transaction côté
  serveur).
- Contrôleur testé en isolation qui envoie des en-têtes ou lit la session : `#[RunInSeparateProcess]` +
  `#[PreserveGlobalState(false)]`, sortie capturée par `ob_start()`.
- Vues : `View::render()` avec un vue-modèle fixe (`tests/View/*RenderTest.php`), assertions sur le balisage et
  l'échappement (`&lt;`).
- Les classes doublées par `createMock()` ne doivent pas être `final`.

## Règles de gestion → tests (AURA-35)

Chaque règle du cahier des charges et les tests qui la garantissent. Une règle sans test ici est une dette.

| Règle | Énoncé | Tests |
| --- | --- | --- |
| RG1 | Patient identifié (nom, prénom), IPP facultatif ; numéro de séjour unique `AAAA-NNNNNN` généré | `AdmissionServiceTest`, `AdmissionServiceAdmitTest`, `AdmissionIntegrationTest`, `EpisodeOfCareDaoTest`, `WorkflowsHttpTest::testAdmissionRejects…` |
| RG2 | Identité et allergies en tête de tout écran de séjour, « aucune allergie connue » explicite | `PatientRecordServiceTest`, `ComponentsRenderTest` (bandeau), `PagesRenderTest`, `PagesHttpTest::testEveryScreenOfAnEpisodeShowsThePatientBannerWithAllergies`, `CarePlanServiceSafetyTest` (allergène prescrit) |
| RG3 | Prescription récurrente sans date de début refusée | `RequestLineTest`, `CarePlanTest`, `CarePlanServiceFormTest` |
| RG4 | Prescription rattachée à un séjour, un prescripteur et un type de soin | `CarePlanServiceFormTest`, `CarePlanDaoTest`, `CarePlanSafetyIntegrationTest` |
| RG5 | Prescription d'urgence ou paramédicale « non validée » jusqu'à la validation d'un médecin authentifié | `CarePlanTest`, `CarePlanServiceSafetyTest`, `CarePlanSafetyIntegrationTest`, `AgendaServicePresentWeekTest` (marqueur dans l'agenda) |
| RG6 | Agenda généré à partir des prescriptions actives (une occurrence par horaire) | `RequestLineTest`, `CarePlanDaoTest::testSavingPrescriptionCreatesItsScheduledCare`, `AgendaGenerationServiceTest`, `AgendaGenerationIntegrationTest` |
| RG7 | Validation d'un soin : qui, quand, catégorie habilitée, une seule fois, au plus tard jusqu'à la fin de la rotation en cours | `AppointmentServiceTest`, `AppointmentIntegrationTest`, `AgendaServiceShiftEndTest`, `WorkflowsHttpTest::testCareIsValidatedOnce…` |
| RG8 | Soin prévu non validé après son heure = en retard, en tête de l'agenda | `AgendaServicePresentDayTest`, `AgendaServicePresentServiceTest`, `AgendaServicePresentBoardTest` (agenda du service), `AgendaServicePresentWeekTest`, `CareTeamServiceTest` (rotation) |
| RG9 | Pas de recalcul rétroactif ; génération idempotente ; jour passé consultable | `AgendaGenerationServiceTest`, `AgendaGenerationIntegrationTest`, `PagesHttpTest` (jour passé) |
| RG10 | Traçabilité : auteur et horodatage, rien n'est effacé (suppression logique, annulation tracée) | `AuditEventDaoTest`, `RecordEditServiceTest`, `RecordSectionServiceTest` (dont le décès qui termine le séjour), `PatientDeletionServiceTest`, `PatientDeletionIntegrationTest`, `CarePlanDaoTest::testStoppingALine…`, `WorkflowsHttpTest` |

Contraintes de la base (unicité, CHECK, NOT NULL, clés étrangères) : `tests/Dao/SchemaConstraintsTest.php`, cas
repris de `database/test_contraintes.sql` (Didac Espin, branche `tests/AURA-16.2` ; ce fichier n'est pas dans
cette branche).

## Ce qui n'est pas testé automatiquement

- **JavaScript** (`public/js/*.js`) : aucun test navigateur. Le serveur revalide tout ce que le JavaScript envoie,
  et la suite `http` vérifie ces refus côté serveur ; le comportement de l'interface se vérifie par captures
  (Chrome headless ; `.claude/scripts/screenshot.sh` est un outil local non versionné) et à la main. Un test navigateur (Playwright) serait un outil de plus à
  installer et maintenir : à décider par l'équipe.
- **Contraste des couleurs** et lecture d'écran : vérifiés à la main.
