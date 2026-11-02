# Audit backlog Notion ↔ dépôt — AURA (PLD Agile, hexanôme 14)

Date : 2026-10-13 · Sprint courant : **Sprint 2 (12 → 14 octobre)** (page Notion « Planning - Sprint ») · Dépôt : `rguteville/PLD-Agile-Agenda-Patient`, branche `main` à `5931126`.

Sources : base Notion « Product Backlog » (69 pages : 7 épics, 62 items), corps de chaque page (section « Dépendances »), `git log --all`, branches distantes, API GitHub (PR, runs CI, clés de déploiement), exécution locale de `composer test` et `composer cs`.

Conventions de l'audit :
- **Terminé** = critères d'acceptation observables dans le code de `main`. **En cours** = trace partielle (sur `main` ou sur une branche non fusionnée, précisé). **Non commencé** = aucune trace dans le dépôt. **Non vérifiable** = livrable hors dépôt (Notion, réunion).
- La base Notion n'a **pas** de propriété relation « Bloqué par » : les dépendances sont du texte libre en fin de page (« ### Dépendances »). Elles ont été lues page par page. Les sous-tâches (AURA-NN.M) n'ont pas de section Dépendances : elles **héritent des dépendances de leur story parente**.
- « Démarrable maintenant » = non terminé **et** toutes les dépendances Terminées *selon cet audit* (pas selon Notion).

---

## 1. Résumé

- Avancement constaté : **Epic 1 : 1 item terminé sur 8** (AURA-01) ; **Epics 2 à 7 : 0 terminé**. Notion affiche 3 Done (AURA-01, 03, 06) ; l'audit ne confirme que AURA-01 (AURA-03 hors dépôt, AURA-06 incomplet : pas de routeur ni de 404 sur `main`).
- Le dépôt contient le socle (CI, déploiement, README, config) et un squelette MVC d'une dizaine de fichiers ; deux branches non fusionnées : `feature/AURA-11` (routeur, PR #4 ouverte sans relecture) et `feature/AURA-12` (schéma V2 « WIP », SQL invalide, sans PR, contenu = AURA-16.1).
- Risque 1 : le Sprint 2 (36 points sur 3 jours, fin demain) repose entièrement sur AURA-04, AURA-06 et AURA-11, tous inachevés ; 7 stories du sprint sont bloquées, 3 « bientôt démarrables », 1 seule démarrable (AURA-37).
- Risque 2 : socle incohérent : namespace `Aura\Model` dans `src/Domain/` (autoload PSR-4 cassé dès qu'on utilisera `Patient`), `PatientDao` interroge une table `patients(id, name, email)` absente du schéma, deux conventions SQL concurrentes (snake_case FR sur `main`, camelCase EN sur la branche) sans décision AURA-02.
- Risque 3 : aucun test automatisé, `database/test.sql` non chargé par le CI, et AURA-16.2 / AURA-16.3 marquées « In Progress » sans aucune trace.

---

## 2. Tableau complet

Épic → sous-tâches. Sprint « S1 » = Sprint 1, etc. Commits en abrégé (7 caractères, `git log --all`).

| ID | Titre | Epic | Sprint | Statut Notion | Statut constaté | Preuve (fichier, commit) | Écart ? |
|---|---|---|---|---|---|---|---|
| Epic 1 | Socle Technique | — | — | In Progress | En cours (1/8 terminé) | voir items | non |
| AURA-01 | Dépôt, CI et VM de démo | 1 | S1 | Done | **Terminé** (réserve) | `.github/workflows/ci.yml` (0227ea7, e48179c, e85e3a6, 4628f7f : PHP 8.4, MariaDB 11.8) ; `scripts/deploy.sh` (65caa86) ; clé de déploiement GitHub « Démo VM » en lecture seule (API, créée 2026-10-06) ; CI vert sur `main` depuis le 2026-10-07 | Mineur : critère « branche `main` protégée » impossible (API GitHub 403 : protection de branche indisponible sur dépôt privé en plan gratuit) |
| AURA-02 | Décision : convention de nommage (français ou HL7) | 1 | S1 | In Progress | **Non commencé** (aucune trace dans le dépôt) | README sans mention (grep « convention / nommage / HL7 » vide) ; conventions mixtes : `database/schema.sql` snake_case FR (ac01c72), `feature/AURA-12` camelCase EN (2735798), PHP anglais `name` / `email` (e7f3e29) | À confirmer : décision éventuellement prise hors dépôt, non documentée |
| AURA-03 | Questions PO pour la V1 | 1 | S1 | Done | Non vérifiable (hors dépôt) | aucune trace dans le dépôt (attendu) | — |
| AURA-04 | Schéma de base de la V1 | 1 | S1 | In Progress | **En cours** | `database/schema.sql` (ac01c72, 6fd50c5) : tables `Patient`, `Sejour` seulement ; manquent `praticien`, `allergene`, `allergie_patient`, `medicament`, `type_soin`, `prescription`, `soin_planifie` ; `ipp` ni UNIQUE ni NOT NULL ; `nom` / `prenom` nullables ; le `CHECK (100000<=ipp<1000000000)` est évalué comme `(100000<=ipp) < 1000000000`, donc toujours vrai ; CI charge le schéma sans erreur (run vert 2026-10-07) | non |
| AURA-05 | Données fictives de test | 1 | S1 | In Progress | **En cours** | `database/test.sql` (ac01c72) : 1 patient, 1 séjour (critère : ≥ 3 patients, 1 avec allergies, 1 sans IPP) ; non chargé par le CI (`ci.yml` ne charge que `schema.sql`) ; le patient de test porte le nom d'un membre de l'équipe (pas une donnée fictive) | non |
| AURA-06 | Squelette MVC | 1 | S1 | Done | **En cours** | Couches `src/Controller`, `src/Dao`, `src/Service`, `src/Domain`, `src/View` (e7f3e29, dc30f95, 819e42c, 3509858, 5931126). Manquent sur `main` : routeur dans `public/index.php` (commentaire « le routeur MVC viendra ici », appel direct de `PatientController::list()`), page 404, page d'erreur générique. Routeur présent uniquement sur `feature/AURA-11` (2d67d03, PR #4 ouverte ; 404 = texte brut, pas de page d'erreur). Défauts : namespace `Aura\Model` dans `src/Domain/Patient.php` alors que composer.json mappe `Aura\` → `src/` (autoload cassé) ; `src/Modele/Patient.php` toujours suivi par git, identique à `src/Domain/Patient.php` (5931126 a ajouté le nouveau sans supprimer l'ancien ; la suppression existe dans l'arbre de travail mais n'est pas commitée) ; `PatientDao` lit `patients(id, name, email)`, table absente du schéma | **OUI** : Done → In Progress |
| AURA-07 | Tests avec base de données dans le CI | 1 | S1 | In Progress | **En cours** | CI charge `schema.sql` dans MariaDB (0227ea7, e85e3a6, 4628f7f) ; aucun test (`tests/.gitkeep`, f7e2cfc ; `composer test` → « No tests executed! ») ; aucune configuration BD pour PHPUnit ; l'étape « Tests » du CI est sautée s'il n'y a pas de fichier `*Test.php` | non |
| AURA-39 | Annuler la dernière modification (Ctrl+Z) | 1 | Backlog | Not Started | Non commencé | aucune trace dans le dépôt | non |
| Epic 2 | Patient et Séjour | — | — | In Progress | En cours (0/21 terminé) | voir items | non |
| AURA-10 | Créer un séjour (admission non programmée) | 2 | S2 | In Progress | **En cours** (minime) | `src/View/layout.html` (819e42c) : formulaire 2 champs `usename` / `birthname` avec `action="layout.html"` ; `feature/AURA-11` (2d67d03) : `PatientController::create()` lit `useName` / `birthName` et renvoie un JSON vide (`// TODO`) ; aucune insertion, aucun numéro de séjour | non |
| AURA-10.1 | Formulaire d'admission (IHM) | 2 | **S1** | Not Started | **En cours** | `src/View/layout.html` (819e42c) : formulaire existant mais champs non conformes (manquent `ipp`, `dateNaissance`, `sexe`, `taille`, `poids`, `precautions`), POST vers un fichier HTML statique, pas d'échappement ni de réaffichage | **OUI** : Not Started → In Progress ; item Sprint 1 non livré |
| AURA-10.2 | Contrôle serveur des champs obligatoires | 2 | S2 | Not Started | Non commencé | aucune trace (le `create()` de la branche ne valide rien) | non |
| AURA-10.3 | Génération du numéro de séjour unique | 2 | S2 | Not Started | Non commencé | aucune trace (pas de colonne UNIQUE `num_sejour`, pas de génération) | non |
| AURA-10.4 | Insertion patient + séjour en base | 2 | S2 | Not Started | Non commencé | aucune trace dans le dépôt | non |
| AURA-11 | Relier la BD au front (couche d'accès) | 2 | S2 | In Progress | **En cours** | `config/config.example.php` (c05eef0) ; `.gitignore` exclut `config/config.php` (9e3d221) ; `src/Dao/PatientDao.php` (e7f3e29) : `findAll` / `findById` en PDO, `findById` en requête préparée, mais sur une table inexistante ; pas de classe de connexion, pas de DAO séjour, pas d'écriture. La PR #4 (`feature/AURA-11`, 2d67d03) n'ajoute **que** le routeur et un stub : rien sur la BD | non (mais la PR #4 porte le mauvais ticket, voir §3) |
| AURA-11.1 | Classe de connexion PDO + fichier de configuration | 2 | S2 | In Progress | **En cours** | Fait : `config/config.example.php` (c05eef0), `config.php` ignoré (9e3d221). Manque : classe `Database` / fabrique PDO (`ERRMODE_EXCEPTION`, utf8mb4), message propre si config absente — aucune trace | non |
| AURA-11.2 | Requêtes préparées patient / séjour | 2 | S2 | In Progress | **En cours** | `src/Dao/PatientDao.php` (e7f3e29, 819e42c) : 2 lectures sur `patients(id,name,email)` (table absente) ; aucun `insert` / `update` / `delete` / `findByIpp`, aucun DAO séjour | non |
| AURA-12 | CRUD patient (lire, modifier, supprimer) | 2 | S2 | Not Started | Non commencé | aucune trace. Attention : la branche `feature/AURA-12` (2735798) contient du **schéma SQL** (= AURA-16.1), pas de CRUD | non (branche mal nommée) |
| AURA-12.1 | Page liste des patients | 2 | S2 | Not Started | Non commencé | aucune trace (`PatientDao::findAll` existe mais aucune page / route) | non |
| AURA-12.2 | Formulaire de modification d'un patient | 2 | S2 | Not Started | Non commencé | aucune trace dans le dépôt | non |
| AURA-12.3 | Suppression d'un patient avec confirmation | 2 | S2 | Not Started | Non commencé | aucune trace dans le dépôt | non |
| AURA-13 | Gérer les allergies depuis la liste de référence | 2 | S2 | Not Started | Non commencé sur `main` | voir 13.1 | non |
| AURA-13.1 | Tables allergène / allergie et données de référence | 2 | S2 | Not Started | **En cours** (branche non fusionnée) | `feature/AURA-12` (2735798) : table `Allergene(idAllergene, substance)` ; pas de table d'association `Allergie`, pas de données de référence, script SQL globalement invalide (voir 16.1) | **OUI** (à confirmer) : Not Started → In Progress |
| AURA-13.2 | Association patient–allergie en IHM | 2 | S2 | Not Started | Non commencé | aucune trace dans le dépôt | non |
| AURA-14 | Visualiser le dossier patient (en-tête identité + allergies) | 2 | S2 | Not Started | Non commencé | aucune trace dans le dépôt | non |
| AURA-15 | Rechercher un patient par IPP et pré-remplir | 2 | S3 | Not Started | Non commencé | aucune trace dans le dépôt | non |
| AURA-16 | Compléter le schéma BD (praticien, prescription, soin planifié) | 2 | S2 | In Progress | **En cours** (branche non fusionnée) | `feature/AURA-12` (2735798, « Creation tables DB (WIP) », DoubleD3269, 2026-10-12), pas de PR | non |
| AURA-16.1 | Script SQL de création des nouvelles tables | 2 | S2 | In Progress | **En cours** (branche non fusionnée) | `database/schema.sql` sur `feature/AURA-12` (2735798) : ajoute `Service`, `Allergene`, `Qualification` (≈ TypePraticien), `Praticien`, `Prescription` (sans FK séjour / praticien / type de soin / médicament), `Soin_planifie` **incomplet** (colonne `idPrescription` sans type, parenthèse non fermée → SQL invalide). Manquent `TypeSoin`, `Medicament`, `Evaluation`, `Affectation`, `Allergie`, habilitation. Non rejouable : `CREATE DATABASE aura; USE aura;` (le CI charge dans `aura_test`), `DROP TABLE` sans `IF EXISTS`. FK `fk_qualification` référence `Service(idQualification)` (mauvaise table). **Ce script ferait échouer le CI en l'état** | non |
| AURA-16.2 | Contraintes d'intégrité | 2 | S2 | In Progress | **Non commencé** | aucune trace : sur la branche, 2 FK seulement, aucun UNIQUE / CHECK / NOT NULL nouveau (sauf `admissionDate`) | **OUI** : In Progress → Not Started |
| AURA-16.3 | Mise à jour du jeu de données de test | 2 | S2 | In Progress | **Non commencé** | `database/test.sql` inchangé depuis ac01c72 (1 patient, 1 séjour) ; aucune donnée praticien / type de soin / médicament / prescription | **OUI** : In Progress → Not Started |
| Epic 3 | Référentiels et praticiens | — | — | Not Started | Non commencé (0/3) | voir items | non |
| AURA-17 | Référentiel des types de soin | 3 | S2 | Not Started | Non commencé | aucune trace (ni table `TypeSoin` ni CRUD, même sur la branche) | non |
| AURA-18 | Praticiens et rôles | 3 | S2 | Not Started | Non commencé | aucune trace de CRUD ; les tables `Praticien` / `Qualification` de `feature/AURA-12` relèvent d'AURA-16.1 | non |
| AURA-19 | Référentiel médicaments | 3 | S4 | Not Started | Non commencé | aucune trace dans le dépôt | non |
| Epic 4 | Prescriptions | — | — | Not Started | Non commencé (0/7) | voir items | non |
| AURA-20 | Saisir une prescription de soin | 4 | S2 | Not Started | Non commencé | aucune trace (table `Prescription` embryonnaire sur la branche, sans FK ni IHM) | non |
| AURA-20.1 | Formulaire de prescription de soin | 4 | S2 | Not Started | Non commencé | aucune trace dans le dépôt | non |
| AURA-20.2 | Règle « récurrente sans date de début = refusée » | 4 | S2 | Not Started | Non commencé | aucune trace dans le dépôt | non |
| AURA-20.3 | Persistance et rattachement séjour / prescripteur | 4 | S2 | Not Started | Non commencé | aucune trace dans le dépôt | non |
| AURA-21 | Prescription de médicament | 4 | S4 | Not Started | Non commencé | aucune trace dans le dépôt | non |
| AURA-22 | Lister, modifier, arrêter les prescriptions | 4 | S4 | Not Started | Non commencé | aucune trace dans le dépôt | non |
| AURA-23 | Prescription d'urgence en attente de validation | 4 | S4 | Not Started | Non commencé | aucune trace dans le dépôt | non |
| Epic 5 | Agenda patient et suivi des soins | — | — | Not Started | Non commencé (0/8) | voir items | non |
| AURA-24 | Générer l'agenda quotidien | 5 | S3 | Not Started | Non commencé | aucune trace dans le dépôt | non |
| AURA-24.1 | Algorithme de génération des soins planifiés | 5 | S3 | Not Started | Non commencé | aucune trace dans le dépôt | non |
| AURA-24.2 | Déclenchement quotidien de la génération | 5 | S3 | Not Started | Non commencé | aucune trace dans le dépôt | non |
| AURA-24.3 | Non-recalcul rétroactif et idempotence | 5 | S3 | Not Started | Non commencé | aucune trace dans le dépôt | non |
| AURA-25 | Vue chronologique de l'agenda patient | 5 | S3 | Not Started | Non commencé | aucune trace dans le dépôt | non |
| AURA-26 | Valider un soin réalisé | 5 | S3 | Not Started | Non commencé | aucune trace dans le dépôt | non |
| AURA-27 | Détection et affichage des soins en retard | 5 | S3 | Not Started | Non commencé | aucune trace dans le dépôt | non |
| AURA-28 | Ajouter une note sur un soin planifié | 5 | S3 | Not Started | Non commencé | aucune trace dans le dépôt | non |
| Epic 6 | Organisation du service | — | — | Not Started | Non commencé (0/5) | voir items | non |
| AURA-29 | Agenda consolidé du service | 6 | S4 | Not Started | Non commencé | aucune trace dans le dépôt | non |
| AURA-30 | Équipes et vacations | 6 | S4 | Not Started | Non commencé | aucune trace dans le dépôt | non |
| AURA-31 | Agenda par soignant | 6 | S4 | Not Started | Non commencé | aucune trace dans le dépôt | non |
| AURA-32 | Alerte soins en retard (vue service) | 6 | S4 | Not Started | Non commencé | aucune trace dans le dépôt | non |
| AURA-33 | Note de charge par type de soin | 6 | Backlog | Not Started | Non commencé | aucune trace dans le dépôt | non |
| Epic 7 | Qualité, documentation et livraison | — | — | In Progress | En cours (0/10 terminé) | voir items | non |
| AURA-34 | Mettre à jour le dossier de cadrage et les diagrammes | 7 | S2 | In Progress | Non vérifiable (hors dépôt) | Livrable Notion. Page de cadrage modifiée le 2026-10-08 ; son diagramme Mermaid ne contient ni `TypeSoinHabilitation`, ni `dose` / `voie`, ni `Vacation` / `Equipe` (cohérent avec 34.1 Not Started) | — |
| AURA-34.1 | Réajuster le diagramme de classes | 7 | S2 | Not Started | Non vérifiable (Notion) ; diagramme non réajusté | voir AURA-34 | non |
| AURA-34.2 | Cas d'usage et user stories | 7 | S2 | Not Started | Non vérifiable (Notion) | — | non |
| AURA-34.3 | Mise à jour du glossaire | 7 | S2 | Not Started | Non vérifiable (Notion) ; le glossaire contient encore une définition en anglais (« Service ») | — | non |
| AURA-35 | Tests automatisés (PHPUnit RG1–RG10, tests SQL) | 7 | S3 | Not Started | Non commencé | `tests/` vide (f7e2cfc), aucun script SQL de test, aucun `tests/manuels/` | non |
| AURA-36 | README d'installation et de reprise + jeu de démo | 7 | S4 | Not Started | **En cours** (partiel) | `README.md` (cb3a376 → 91962b9) couvre déjà : installation macOS / Windows / Linux, création de la base, `config.php`, lancement, tests, CI, déploiement VM, checklist démo. Manquent : configuration Apache, procédure d'accès VM détaillée, jeu de démo (`sql/04_donnees_demo.sql` absent), checklist « pas de données réelles », schéma SQL commenté. Incohérence : le README cite `database/seed_fictif.sql`, fichier inexistant (le dépôt a `database/test.sql`) | **OUI** (optionnel) : Not Started → In Progress |
| AURA-37 | Préparer la revue du sprint 2 | 7 | S2 | Not Started | Non vérifiable (hors dépôt) | — | — |
| AURA-37b | Préparer la revue du sprint 3 | 7 | S3 | Not Started | Non vérifiable (hors dépôt) | — | — |
| AURA-37c | Préparer la livraison finale | 7 | S4 | Not Started | Non vérifiable (hors dépôt) | — | — |
| AURA-38 | Export / import OpenEMR | 7 | Backlog | Not Started | Non commencé | aucune trace dans le dépôt | non |

Récapitulatif par épic (items hors épics, selon l'audit) :

| Epic | Items | Terminé | En cours | Non commencé | Non vérifiable | % terminé (audit) | % Done (Notion) |
|---|---|---|---|---|---|---|---|
| 1 Socle technique | 8 | 1 | 4 | 2 | 1 | 12,5 % | 37,5 % |
| 2 Patient et séjour | 21 | 0 | 8 | 13 | 0 | 0 % | 0 % |
| 3 Référentiels | 3 | 0 | 0 | 3 | 0 | 0 % | 0 % |
| 4 Prescriptions | 7 | 0 | 0 | 7 | 0 | 0 % | 0 % |
| 5 Agenda patient | 8 | 0 | 0 | 8 | 0 | 0 % | 0 % |
| 6 Organisation | 5 | 0 | 0 | 5 | 0 | 0 % | 0 % |
| 7 Qualité / doc | 10 | 0 | 1 | 2 | 7 | 0 % | 0 % |
| **Total** | **62** | **1** | **13** | **40** | **8** | **1,6 %** | **4,8 %** |

---

## 3. Écarts Notion ↔ dépôt

Changements de statut à appliquer manuellement dans Notion (aucune modification n'a été faite) :

| # | Item | Notion | Constaté | Action proposée |
|---|---|---|---|---|
| 1 | AURA-06 Squelette MVC | Done | En cours | Repasser **In Progress** : pas de routeur ni de 404 sur `main` (critères cochés dans la page mais non satisfaits) ; ou acter que le routeur est livré par la PR #4 et rattacher cette PR à AURA-06 |
| 2 | AURA-10.1 Formulaire d'admission | Not Started (Sprint 1) | En cours | Passer **In Progress** ; décider si l'item reste en Sprint 1 (échéance dépassée) ou bascule en Sprint 2 avec sa story parente |
| 3 | AURA-16.2 Contraintes d'intégrité | In Progress | Non commencé | Repasser **Not Started** (ou pousser le travail local s'il existe) |
| 4 | AURA-16.3 Jeu de données de test | In Progress | Non commencé | Repasser **Not Started** (ou pousser le travail local s'il existe) |
| 5 | AURA-13.1 Tables allergène / allergie | Not Started | En cours (branche) | Passer **In Progress** si la table `Allergene` de `feature/AURA-12` est bien le début de 13.1 ; sinon laisser |
| 6 | AURA-36 README + jeu de démo | Not Started | En cours (partiel) | Passer **In Progress** (le README est largement écrit) ou laisser Not Started en considérant le README actuel comme livré par AURA-01 |
| 7 | AURA-01 Dépôt, CI, VM | Done | Terminé avec réserve | Laisser Done ; amender la page : « branche `main` protégée » impossible sur dépôt privé en plan gratuit (règle d'équipe « jamais de push direct » à faire respecter humainement) |
| 8 | AURA-02 Convention de nommage | In Progress | Aucune trace | Si la décision est prise : l'écrire dans le README et passer Done ; sinon la prendre avant de fusionner `feature/AURA-12` (qui impose camelCase anglais de fait) |

Autres écarts, sans changement de statut :

- **PR #4 « feature/AURA-11 »** (2d67d03, 0 relecture, CI vert) ne contient aucun travail d'AURA-11 (couche d'accès BD) : elle livre le routeur manquant d'AURA-06 et un stub `create()`. Renommer / re-décrire la PR, ou mettre à jour AURA-11 pour y inclure le routeur.
- **Branche `feature/AURA-12`** (2735798) contient le schéma V2 (= AURA-16.1), pas le CRUD patient (AURA-12). Renommer en `feature/AURA-16` pour la traçabilité. Pas de PR : le CI n'a jamais tourné dessus et **le SQL actuel échouerait** (voir tableau, ligne 16.1).
- `src/Modele/Patient.php` est encore suivi par git alors que 5931126 devait le renommer en `Domain` ; la suppression est présente dans l'arbre de travail local mais non commitée.
- Liens Notion cassés : AURA-04 et Epic 1 référencent en « Sous-élément » des pages supprimées (une page vide « Créer un(e) item », un doublon d'AURA-01).
- Le titre Notion d'AURA-02 contient des `**` (gras Markdown dans le champ Nom).
- README : référence à `database/seed_fictif.sql` (inexistant) au lieu de `database/test.sql`.

---

## 4. Démarrable maintenant

Tri : sprint courant d'abord, puis nombre d'items débloqués directement, puis estimation croissante. Seul AURA-01 est « Terminé » ; les items sans dépendance sont donc les seuls démarrables.

| Ordre | Item | Sprint | Est. | Débloque directement (transitivement) | Commentaire |
|---|---|---|---|---|---|
| 1 | **AURA-37** Préparer la revue du sprint 2 | **S2** | 1 | 0 | Sans dépendance ; revue demain |
| 2 | **AURA-04** Schéma de base V1 (à finir) | S1 (en retard) | 2 | 3 : AURA-10, 11, 16 (→ tout le backlog fonctionnel) | Levier n° 1 : ajouter `UNIQUE`/NOT NULL, corriger le CHECK `ipp`, trancher le périmètre V1 vs V2 avec AURA-16 |
| 3 | **AURA-05** Données fictives de test (à finir) | S1 (en retard) | 2 | 1 : AURA-36 | 3 patients, 1 allergique, 1 sans IPP ; faire charger `test.sql` par le CI ; remplacer le nom réel |
| 4 | **AURA-07** Tests avec BD dans le CI (à finir) | S1 (en retard) | 3 | 1 : AURA-35 | Un premier `*Test.php` branché sur `aura_test` |
| 5 | **AURA-06** Squelette MVC (à finir) | S1 (en retard) | 5 | 1 : AURA-11 | Fusionner le routeur de la PR #4, ajouter 404 / erreur, corriger le namespace `Aura\Model` → `Aura\Domain`, supprimer `src/Modele/` |
| 6 | **AURA-02** Convention de nommage | S1 (en retard) | 3 | 0 (de fait : AURA-16.1 l'attend) | Décision à écrire dans le README avant la fusion du schéma V2 |
| 7 | AURA-37b Préparer la revue du sprint 3 | S3 | 1 | 0 | Formellement démarrable, prématuré |
| 8 | AURA-37c Préparer la livraison finale | S4 | 1 | 0 | Formellement démarrable, prématuré |

Note : AURA-34.2 (cas d'usage) et AURA-34.3 (glossaire) n'ont pas besoin du schéma V2 bien qu'elles héritent formellement de la dépendance AURA-16 de leur story ; elles pourraient être démarrées dès maintenant côté Notion si vous acceptez cette lecture.

---

## 5. Bientôt démarrable / Bloqué

### Bientôt démarrable (exactement une dépendance ouverte)

| Item | Sprint | Est. | Dépendance bloquante |
|---|---|---|---|
| **AURA-16** (+ 16.1, 16.2, 16.3) Compléter le schéma BD | **S2**, Critical, échéance 14/10 | 5 | AURA-04 (En cours) |
| **AURA-18** Praticiens et rôles | **S2** | 3 | AURA-16 |
| **AURA-34** (+ 34.1, 34.2, 34.3) Dossier de cadrage | **S2**, échéance 14/10 | 3 | AURA-16 (+ « décisions PO en revue de sprint 2 ») |
| AURA-19 Référentiel médicaments | S4 | 2 | AURA-16 |

### Bloqué (plusieurs dépendances ouvertes)

| Item | Sprint | Dépendances ouvertes |
|---|---|---|
| **AURA-11** (+ 11.1, 11.2) Couche d'accès BD | **S2**, Critical, échéance 14/10 | AURA-06 (En cours : routeur), AURA-04 (En cours) |
| **AURA-10** (+ 10.1 à 10.4) Créer un séjour | **S2** | AURA-11, AURA-04 |
| **AURA-12** (+ 12.1 à 12.3) CRUD patient | **S2** | AURA-10, AURA-11 |
| **AURA-13** (+ 13.1, 13.2) Allergies | **S2** | AURA-11, AURA-16.1 |
| **AURA-14** Dossier patient | **S2** | AURA-10, AURA-13 |
| **AURA-17** Types de soin | **S2** | AURA-16, AURA-18 |
| **AURA-20** (+ 20.1 à 20.3) Prescription de soin | **S2** | AURA-16, AURA-17, AURA-18, AURA-14 |
| AURA-15 Recherche par IPP | S3 | AURA-10, AURA-13 |
| AURA-24 (+ 24.1 à 24.3) Génération agenda | S3 | AURA-16, AURA-20 |
| AURA-25 Vue chronologique | S3 | AURA-14, AURA-24 |
| AURA-26 Valider un soin | S3 | AURA-17, AURA-18, AURA-24, AURA-25 |
| AURA-27 Soins en retard | S3 | AURA-24, AURA-25 |
| AURA-28 Note sur un soin | S3 | AURA-25, AURA-26 |
| AURA-35 Tests automatisés | S3 | AURA-07, AURA-24, AURA-26, AURA-27 |
| AURA-21 Prescription de médicament | S4 | AURA-19, AURA-20 |
| AURA-22 Lister / modifier / arrêter | S4 | AURA-20, AURA-21, AURA-24 |
| AURA-23 Prescription d'urgence | S4 | AURA-18, AURA-20, AURA-25 |
| AURA-29 Agenda consolidé | S4 | AURA-24, AURA-25, AURA-27 |
| AURA-30 Équipes et vacations | S4 | AURA-18, AURA-29 |
| AURA-31 Agenda par soignant | S4 | AURA-26, AURA-30 |
| AURA-32 Alerte retard vue service | S4 | AURA-27, AURA-29, AURA-30 |
| AURA-36 README + jeu de démo | S4 | AURA-05 + « toutes les stories livrées » |
| AURA-33 Note de charge | Backlog | AURA-17, AURA-29, AURA-30 |
| AURA-38 OpenEMR | Backlog | AURA-15 + « lot 1 livré » |
| AURA-39 Ctrl+Z | Backlog | AURA-26, AURA-22 |

### Anomalies du graphe de dépendances

- **Aucune dépendance circulaire stricte** entre stories. Trois quasi-cycles au niveau des définitions de fini : (a) AURA-11.2 exige « l'admission (AURA-10) fonctionne via ces repositories » alors qu'AURA-10 dépend d'AURA-11 ; (b) AURA-13.2 modifie le bandeau d'AURA-14 alors qu'AURA-14 dépend d'AURA-13 ; (c) AURA-24.2 appelle `detecterRetards` (AURA-27) alors qu'AURA-27 dépend d'AURA-24.
- **Dépendances manquantes évidentes** : AURA-05 et AURA-07 ne déclarent pas AURA-04 (impossible de charger des données ou de tester sans schéma) ; AURA-12.3 (refus si soins réalisés) exige la table `SoinPlanifie` → AURA-12 devrait dépendre d'AURA-16 ; AURA-16.1 renvoie à la convention AURA-02 sans la déclarer en dépendance.
- **Incohérence de sprint** : AURA-10.1 est en Sprint 1 alors que sa story AURA-10 est en Sprint 2.
- **Items du sprint courant bloqués** : 7 stories (AURA-10, 11, 12, 13, 14, 17, 20) sur les 11 du Sprint 2, soit 26 points sur 36, dépendent d'AURA-04 / AURA-06 / AURA-11 inachevés. Le Sprint 2 se termine demain ; le Sprint 1 a livré 5 points sur 22.

---

## 6. Questions ouvertes

1. **Dépendances en texte libre.** La base Notion n'a pas de propriété relation « Bloqué par » ; j'ai lu la section « Dépendances » de chaque page. Souhaitez-vous que les 40 liens relevés soient saisis dans une vraie relation (à faire manuellement ou sur votre feu vert, je n'ai rien modifié) ?
2. **AURA-02** : une convention de nommage a-t-elle été décidée ? La branche `feature/AURA-12` passe au camelCase anglais (`firstName`, `birthDate`) alors que `main` est en snake_case français ; le PHP est en anglais ; le dossier de cadrage liste des noms HL7. Sans décision, le schéma V2 et le code vont diverger.
3. **AURA-06** : préférez-vous le repasser In Progress, ou le laisser Done et déplacer « routeur + 404 » vers AURA-11 (ce que fait déjà la PR #4) ?
4. **Travail non poussé ?** AURA-11.1, 11.2, 16.2, 16.3 sont In Progress avec échéance demain, mais le dépôt n'en contient rien (la PR #4 ne touche pas à la BD). Y a-t-il du travail local chez Sami, Didac ou Chema ?
5. **AURA-10.1** : item Sprint 1 non livré et non démarré selon Notion. Le reporter en Sprint 2 ?
6. **Hors dépôt** : AURA-03 (Done), AURA-34 (In Progress), AURA-37 — je ne peux pas les vérifier. Les comptes rendus / questions PO existent-ils dans Notion ailleurs que dans le backlog ?
7. **Capacité** : 36 points planifiés en Sprint 2 sur 3 jours, après 5 points livrés en Sprint 1. Faut-il re-prioriser maintenant (par exemple AURA-04 → AURA-16 → AURA-11 → AURA-10 seulement) plutôt qu'à la revue ?
8. **Suppression non commitée** de `src/Modele/Patient.php` dans votre arbre de travail : est-elle voulue ? Elle corrige le doublon mais pas le namespace `Aura\Model`.
9. **PR #4** : 0 relecture alors que la règle d'équipe exige CI vert **et** relecture. Qui relit ?
10. **Données de test** : le seul patient de `database/test.sql` porte le nom d'un membre de l'équipe. La règle du cadrage impose des données fictives uniquement ; à remplacer dans AURA-05 / 16.3.
11. Point de vigilance (hors backlog) : AURA-18 prévoit un « sélecteur de praticien » sans authentification pour le lot 1. La traçabilité RG10 (qui a prescrit / validé) repose alors sur une déclaration non vérifiée ; à signaler au PO comme limite assumée du lot 1.
