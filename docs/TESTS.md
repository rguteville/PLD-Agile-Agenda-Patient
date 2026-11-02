# Tests

PHPUnit 10, configuration `phpunit.xml.dist` (`failOnRisky` et `failOnWarning` : un test sans assertion ou une
dépréciation casse le CI). Style PSR-12 vérifié par `phpcs` (un avertissement suffit à faire échouer `composer cs`).

## Lancer

```bash
composer test                                   # unitaires ; les tests avec base sont sautés sans DB_HOST
composer cs && composer cs:fix                  # style
bash .claude/scripts/ci-local.sh                # séquence complète du CI : validate, php -l, phpcs, base aura_test
                                                # recréée (schema.sql + test.sql), phpunit avec base, smoke HTTP
```

Tests avec base, à la main :

```bash
export DB_HOST=127.0.0.1 DB_PORT=3306 DB_NAME=aura_test DB_USER=aura_ci DB_PASSWORD=aura_ci
composer test
```

## Base de test

- `aura_test`, utilisateur `aura_ci` / `aura_ci` (mêmes valeurs que le CI GitHub : pas un secret). Jamais la base
  de développement `aura`.
- Création : voir le message d'aide de `ci-local.sh` (création de l'utilisateur par accès administrateur), puis
  `schema.sql` et `test.sql`.
- `config/config.example.php` lit les variables `DB_*` ; `Database` ne l'utilise que si `DB_HOST` est défini.

## Écrire un test

- Un fichier par classe, même arborescence que `src/` (`tests/Service/XxxServiceTest.php`), classe `final`,
  namespace `Aura\Tests\…`, noms de méthodes en français qui énoncent la règle.
- Service pur : date injectée (`new DateTimeImmutable('2026-10-14')`), variantes par `#[DataProvider]` statique.
- Avec base : en `setUp`, sauter si `DB_HOST` ou `DB_NAME` manquent (`markTestSkipped`), `Database::get()`,
  `beginTransaction()`, `rollBack()` en `tearDown`. Données aux noms improbables (`Zz…`). Ne pas dépendre de
  `test.sql` au-delà de ce que `DonneesDemoTest` vérifie.
- Contrôleur qui envoie des en-têtes ou lit la session : `#[RunInSeparateProcess]` + `#[PreserveGlobalState(false)]`,
  sortie capturée par `ob_start()`.
- Vues : `View::render()` avec un vue-modèle fixe (`tests/View/*RenderTest.php`), assertions sur le balisage et
  l'échappement (`&lt;`).
- Les classes doublées par `createMock()` ne doivent pas être `final`.

## Couverture (état au 2026-11-02, 351 tests)

| Zone | Couvert | Non couvert |
| --- | --- | --- |
| Core | `Router`, `Database` | `View` (couvert indirectement par les tests de rendu), `Csrf` (indirectement par `RecordControllerTest`) |
| Admission | validation (identité, mesures, champs complémentaires, échelles, référentiels), intégration (création, mesures, numéros, patient connu par IPP, rattachement tracé, champs complémentaires, allergies) | — |
| Recherche de patient | découpage, IPP, homonymes, limite, DAO (casse, accents, ordre, tiret, apostrophe, tri), endpoints JSON | rendu de la combobox dans un navigateur |
| Dossier | vue-modèles (bandeau, séjour, sections, calculs), allergies (ajout, retrait), édition en ligne (service, endpoint, DAO AuditEvent), rendu | script `inline-edit.js` (vérifié par curl et captures) |
| Prescriptions | `CarePlan`, `RequestLine`, DAO | `CarePlanService` (seul le smoke HTTP le traverse) |
| Agenda | `AgendaService::presentDay()`, rendu | `AgendaService::getAgenda()` (smoke seulement) |
| Liste et modification des patients | service, DAO, rendu | — |
| Données de démo | `DonneesDemoTest` (ids numériques, allergies, dossiers lisibles, patient sans IPP) | — |
| IHM | rendu des composants et pages (sans base) | JavaScript (aucun test navigateur), contraste des couleurs |
