# Soutenance AURA : tests et démonstration

Document de l'équipe pour la soutenance du projet. Il contient trois choses : ce qu'il faut préparer avant, comment
présenter et lancer les tests PHPUnit, et un scénario de démonstration complet qui explique nos choix fonctionnels,
techniques et d'architecture. Les durées sont indicatives, à caler sur le temps de passage réel.

## 1. Préparer la veille

La démonstration se fait sur la VM de démo, qui sert la branche `main`. Tout ce qui est montré ci-dessous doit donc
être fusionné et déployé avant.

1. Faire relire puis fusionner les pull requests dans cet ordre, chacune vers `main` : #52, #53, #54, #55. Après
   chaque fusion, attendre que le CI GitHub soit vert.
2. Sur la VM : `bash scripts/deploy.sh` (voir le README, section « VM de démo et déploiement »).
3. Recharger la base de démo, la recaler sur le jour de la soutenance et générer l'agenda :

   ```bash
   mariadb -e "DROP DATABASE IF EXISTS aura; CREATE DATABASE aura CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
   mariadb aura < database/schema.sql
   mariadb aura < database/test.sql
   mariadb aura < database/demo-today.sql
   php bin/generate-agenda.php
   ```

   `demo-today.sql` décale toutes les dates de démonstration pour que le jour de référence (14/10/2026) devienne
   aujourd'hui. Ne jamais l'appliquer à une base de test : les tests s'appuient sur les dates fixes.
4. Répéter le scénario une fois en entier sur la VM, avec le chronomètre. Recharger la base après la répétition
   (les validations et le décès de la répétition restent sinon).
5. Préparer un terminal sur un poste avec la base de test (`aura_test`) pour la partie tests, et un onglet GitHub
   ouvert sur la dernière exécution du CI (onglet Actions) au cas où le réseau ou le poste lâche.

## 2. Les tests PHPUnit

### Ce qu'il faut dire

Le projet a 876 tests PHPUnit répartis en deux suites, définies dans `phpunit.xml.dist`.

La suite `aura` (845 tests) couvre tout sauf le HTTP. Les services métier sont testés sans base : la date est
injectée (`new DateTimeImmutable('2026-10-14')`) et les variantes passent par des `#[DataProvider]`. Les DAO et les
tests d'intégration tournent sur une vraie base MariaDB, chacun dans une transaction annulée à la fin, avec des
données aux noms improbables (`Zz…`) pour ne jamais toucher aux données de démo. Les vues sont testées par leur
rendu HTML (échappement, accessibilité, éléments attendus). Le fichier `tests/Dao/SchemaConstraintsTest.php`
vérifie chaque contrainte de `schema.sql` (unicité, CHECK, clés étrangères) directement par la base.

La suite `http` (31 tests) lance un vrai serveur PHP sur l'application et enchaîne de vraies requêtes, comme un
navigateur : session et cookie, jeton CSRF, POST, redirections. Elle refuse de tourner si la base ouverte n'est pas
une base de test, parce qu'elle écrit pour de vrai.

Sans les variables `DB_*`, tout ce qui demande la base est sauté plutôt qu'en échec : un développeur sans MariaDB
lance quand même les tests purs. La configuration est stricte (`failOnRisky`, `failOnWarning`) : un test sans
assertion ou un avertissement PHP fait échouer la suite.

Chaque règle de gestion du cahier des charges (RG1 à RG10) est reliée aux tests qui la garantissent dans
`docs/TESTS.md`. C'est la réponse à « comment savez-vous que la règle est respectée ? ».

À chaque pull request, le CI GitHub (`.github/workflows/ci.yml`) part d'une MariaDB neuve, charge `schema.sql` et
`test.sql`, vérifie la syntaxe et le style (PSR-12), puis lance les deux suites avec la couverture de code (pcov). Le
rapport HTML de couverture est téléchargeable dans l'artefact « couverture » de l'exécution. Une PR ne se fusionne
qu'avec un CI vert et la relecture d'un autre membre.

### Ce qu'il faut montrer, dans l'ordre

1. Sans base, en dix secondes, pour montrer que les règles métier se testent seules :

   ```bash
   composer test:fast
   ```

   Résultat attendu : « OK, but some tests were skipped », 845 tests dont 300 sautés (ceux qui demandent la base).

2. Avec la base de test, la suite complète (environ dix secondes) :

   ```bash
   export DB_HOST=127.0.0.1 DB_PORT=3306 DB_NAME=aura_test DB_USER=aura_ci DB_PASSWORD=aura_ci
   composer test
   ```

   Résultat attendu : « OK (876 tests, … assertions) ».

3. Un test lisible à l'écran, pour montrer comment une règle s'écrit en test. Bon exemple court :
   `tests/Service/AgendaServiceShiftEndTest.php`. Un `#[DataProvider]` donne six instants (après minuit, dernière
   minute de la nuit, début du matin…) et la fin de rotation attendue ; une seule méthode de test les vérifie tous.

4. Un test qui échoue, pour montrer que les tests protègent vraiment. Dans `src/Service/AgendaService.php`,
   méthode `shiftEnd()`, remplacer `22` par `23` dans `[6, 14, 22]`, puis :

   ```bash
   vendor/bin/phpunit --filter AgendaServiceShiftEndTest
   ```

   Deux cas échouent, « après-midi » (attendu 22:00, obtenu 23:00) et « fin de mois » (attendu le 01/11 à 06:00,
   obtenu le 31/10 à 23:00), avec la valeur attendue et la valeur obtenue côte à côte. Annuler la modification
   (`git checkout src/Service/AgendaService.php`) et relancer : vert. (Répété le 2026-11-04 : c'est exactement ce
   qui s'affiche.)

5. Si on a encore une minute : l'onglet Actions de GitHub, une exécution du CI, l'étape des tests et l'artefact
   de couverture.

### Questions probables

« Pourquoi des tests sur une vraie base plutôt que des simulacres ? » Les DAO contiennent le SQL : un simulacre ne
dirait pas si une colonne est mal nommée ou si une contrainte bloque. Le cas s'est produit : deux colonnes renommées
dans le schéma (`Substance.name`, `PractitionerRole.title`) ont cassé deux DAO, corrigés par la PR #52 ; c'est
exactement le genre d'erreur qu'un test sur la vraie base attrape et qu'un simulacre laisse passer. Les services, eux, sont testés sans base quand ils sont
purs (`AgendaService::presentDay()`, `presentBoard()`, les `validate()`).

« Et le JavaScript ? » Il n'est pas testé automatiquement. Le serveur revalide tout ce que le navigateur envoie, et la
suite `http` vérifie ces refus côté serveur. Nous avons appris la limite à nos dépens : le bouton « Valider » d'un
soin n'envoyait rien à cause d'un champ nommé `action` dans le formulaire, et aucun test ne le voyait, puisque la suite
`http` appelle le serveur directement. Le défaut a été trouvé en manipulant l'application, corrigé, puis vérifié en
pilotant un vrai Chrome. Un test navigateur (Playwright) serait la suite logique ; c'est un outil de plus à
maintenir, à décider en équipe.

« Quelle couverture ? » Le chiffre est dans le rapport du CI. Le citer depuis l'artefact de la dernière exécution
plutôt que de mémoire. La couverture ne compte que le code exécuté dans le processus PHPUnit : les tests `http`
passent par un serveur séparé et n'y entrent pas.

## 3. Scénario de démonstration

Fil conducteur : une journée en réanimation médicale, de l'admission d'un patient à la fin de son séjour. Chaque
étape dit ce qu'on fait, ce qu'on montre et ce qu'on explique. Les personnages sont fictifs (choix de l'équipe :
pas de vraies personnes, même parodiées). L'authentification est factice : on choisit un soignant dans une liste et
n'importe quel mot de passe non vide est accepté. Le dire dès le début.

### Étape 1. L'architecture en une minute (avant d'ouvrir le navigateur)

À montrer : l'arborescence du dépôt et `public/index.php`.

À expliquer :

L'application est en PHP 8.4 sans framework, avec un MVC écrit par l'équipe. `public/index.php` déclare toutes les
routes et câble les dépendances à la main ; un routeur d'une cinquantaine de lignes (`src/Core/Router.php`) associe chaque route à un contrôleur. Les couches
sont strictes. Le contrôleur lit la requête et répond, il ne décide rien. Le service porte les règles métier ; sa
validation est une fonction pure, testable sans base. Le DAO est le seul endroit où une colonne SQL apparaît, toujours
en requête préparée. La vue n'affiche que ce qu'on lui donne et échappe tout (`View::e()`).

Le modèle de données suit les ressources HL7 FHIR (`Patient`, `EpisodeOfCare`, `CarePlan`, `ServiceRequest`,
`MedicationRequest`, `Appointment`, `AllergyIntolerance`) : un vocabulaire standard du dossier de soins, en anglais
dans le code et la base, en français à l'écran. Rien ne s'efface : une suppression est logique (`removedAt`), chaque
modification du dossier est tracée (`AuditEvent` : qui, quand, avant, après), et une contrainte d'unicité sur les
lignes actives empêche les doublons.

[À confirmer par l'équipe : la raison du « sans framework » si le jury la demande, consigne du module ou choix
pour maîtriser chaque couche.]

### Étape 2. Admission d'un nouveau patient (2 min)

À faire : onglet « Admission ». Taper « simpson » dans « Patient connu ? » pour montrer la recherche, puis effacer
et admettre un nouveau patient : nom, prénom, sexe, service « Réanimation médicale », une allergie (Pénicilline,
criticité Élevée). Laisser l'IPP vide. « Admettre ».

À montrer : la carte du patient connu (identité, groupe sanguin en lecture seule), puis le dossier du nouveau séjour
avec son numéro `AAAA-NNNNNN` et l'IPP attribué.

À expliquer :

Avant de créer un patient, on cherche s'il existe déjà (identitovigilance) : un doublon de dossier patient est un
risque réel à l'hôpital. Si le patient est connu, son identité et son groupe sanguin ne se modifient pas à
l'admission ; le groupe sanguin ne se corrige que dans le dossier, validé par un médecin. Le numéro de séjour est
généré par la base dans une transaction qui verrouille la ligne (`FOR UPDATE`) : deux admissions simultanées ne
peuvent pas recevoir le même numéro (RG1). La validation est d'abord côté serveur ; le HTML (`required`) n'est qu'un
confort.

### Étape 3. Le dossier du séjour (2 min)

À faire : dans le dossier, montrer le bandeau patient, le résumé, les parties repliables du dossier complet.
Corriger une valeur au crayon (par exemple les antécédents). Ouvrir « Historique des modifications ».

À montrer : le bandeau en tête de chaque écran de soins (identité, allergies, ou « Aucune allergie connue » écrit en
toutes lettres), la modification enregistrée sans recharger la page, la trace dans l'historique.

À expliquer :

Le bandeau répond à RG2 : sur tout écran de soins, on voit qui est le patient et ses allergies. « Aucune allergie
connue » est écrit explicitement, parce qu'un champ vide ne dit pas si l'information manque ou si le patient n'a pas
d'allergie. L'interface a été allégée après les retours des médecins : l'essentiel est visible, le détail s'ouvre à
la demande, mais ce qui touche à la sécurité du patient ne se replie jamais (allergènes, consignes importantes,
retards, décès).

### Étape 4. Prescription avec alerte allergène (3 min)

À faire : « Nouvelle prescription » sur le dossier de Marge Simpson (allergique à la pénicilline). Choisir le
prescripteur Dr Karim Benali, la catégorie médicament, Amoxicilline. Montrer l'alerte. Confirmer en connaissance de
cause, saisir le mot de passe du médecin, enregistrer. Puis montrer une prescription paramédicale saisie par une
infirmière : elle part « en attente de validation » (à vérifier pendant la répétition avec les listes de la démo).

À montrer : l'alerte « contient un allergène du patient », la case de confirmation obligatoire, le refus sans mot de
passe médecin, la liste « En attente de validation par un médecin ».

À expliquer :

L'amoxicilline contient de la pénicilline : l'application le sait par le référentiel des médicaments et bloque
l'enregistrement tant que le médecin n'a pas confirmé explicitement. La confirmation porte sur la liste exacte des
médicaments en cause : si on change la prescription après avoir coché, il faut reconfirmer. Une prescription d'urgence
ou paramédicale entre dans l'agenda mais reste marquée « non validée » jusqu'à la validation d'un médecin
authentifié (RG5). À l'enregistrement, chaque ligne génère ses soins dans l'agenda, un par horaire (RG6).

### Étape 5. L'agenda du patient et la validation d'un soin (3 min)

À faire : « Agenda du jour » depuis le dossier. Montrer les soins en retard en tête, les rotations (matin, après-midi,
nuit), le « + » qui ouvre la carte de la prescription, les filtres. Valider un soin : soignant Christine Chapel (IDE),
mot de passe quelconque, heure de réalisation. Montrer la vue Semaine.

À montrer : le soin passe à « Effectué, à HH:MM, par Christine Chapel ». Tenter de le revalider : refus.

À expliquer :

Un soin non validé après son heure est en retard, sans tolérance (décision de l'équipe avec les retours médecins),
et remonte en tête (RG8). La validation enregistre qui et quand ; elle ne se fait qu'une fois (RG7). Un soin fait en
avance se valide jusqu'à la fin de la rotation en cours, pas au-delà : on ne valide pas à 8 h le soin de 20 h. La
nuit chevauche minuit, la règle en tient compte. Une aide-soignante ne peut pas valider l'administration d'un
médicament (catégorie habilitée). Le serveur applique toutes ces règles ; l'écran ne fait que les refléter.

### Étape 6. L'agenda du service (2 min)

À faire : onglet « Agenda service ». Onglet « Lits » : cliquer un patient. Onglet « Liste de travail ».

À montrer : les lits triés du plus urgent au moins urgent, le panneau du patient choisi avec ses allergies et ses
soins de la rotation, la liste de travail (en retard, maintenant, plus tard, réalisés).

À expliquer :

La première version était une grille heures par patients ; les médecins l'ont trouvée trop chargée. Nous avons
comparé trois maquettes inspirées des tableaux de service et des dossiers d'administration des médicaments, et gardé
deux vues : une pour savoir où aller (les lits par urgence), une pour savoir quoi faire (la liste de travail). Le
détail s'ouvre à la demande. Le tri et les sections sont calculés par une fonction pure
(`AgendaService::presentBoard()`), testée sans base.

### Étape 7. Équipes et « Mon planning » (1 min)

À faire : onglet « Équipes », vacation de jour ; puis « Mon planning » d'une infirmière.

À expliquer :

Une équipe de vacation (jour 8 h à 20 h, nuit 20 h à 8 h) regroupe des soignants et des patients ; un patient n'a
qu'une équipe par vacation. « Mon planning » montre à un soignant les soins de ses patients pendant sa vacation, avec
les mêmes actions que l'agenda.

### Étape 8. Fin de séjour par décès (1 min)

À faire : dans le dossier d'un patient, « Déclarer le décès », date et heure du décès, médecin, mot de passe.

À montrer : le séjour passe « Clos », le cœur barré apparaît à côté du nom dans la liste des patients, le patient
quitte l'agenda du service, ses soins prévus après l'heure du décès sont annulés, tout est dans l'historique.

À expliquer :

La déclaration du décès est une seule transaction : patient décédé, séjour terminé à l'heure du décès, soins
suivants annulés. Si une étape échoue (heure dans le futur, avant l'admission), rien n'est écrit. Les soins sont
annulés, pas effacés : la traçabilité reste complète (RG10).

### Étape 9. Conclusion (1 min)

Rappeler la méthode : backlog dans Notion, une branche et une pull request par ticket, relecture par un autre membre,
CI vert obligatoire, démonstration régulière au PO sur la VM. Puis les limites assumées (section 4) et ce qu'on ferait
ensuite.

## 4. Limites assumées

Les dire avant qu'on les découvre.

L'authentification est factice : le mot de passe n'est pas vérifié, c'est une décision de l'équipe pour ce lot (il
n'y a ni compte ni session de praticien). Les règles d'habilitation sont pourtant appliquées sur le rôle choisi.

Le JavaScript n'a pas de tests automatiques (voir section 2).

Sous 400 px de large, les écrans débordent en largeur : l'application vise les postes du service et les tablettes.

L'agenda du service lit un jour civil : la nuit, les soins de l'autre côté de minuit sont sur le jour voisin.

La sortie normale d'un séjour n'annule pas les soins prévus après la sortie (seul le décès le fait).

Hypothèses à faire valider par les médecins : bornes des rotations (6 h, 14 h, 22 h), ordre d'urgence de l'agenda du
service, fenêtre « maintenant » de deux heures, horizon de génération de l'agenda.
