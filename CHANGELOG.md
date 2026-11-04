# Journal des changements

Format : une entrée par pull request, de la plus récente à la plus ancienne.

## 2026-11-04

### Documentation de reprise vérifiée (`docs/glossaires-coherence`)

- Documents d'audit (`docs/audit/`) et document de soutenance supprimés ; `docs/ui.md` fusionné dans
  `docs/UI-GUIDE.md`, seul document sur l'interface.
- Un glossaire à la fin de chaque document (acronymes, termes techniques et métier).
- Documentation relue contre le code de `main` : CSRF présent sur l'admission et la modification de l'identité,
  édition en ligne soumise à l'authentification factice d'un médecin, colonne « Responsable » retirée de la
  description de l'agenda du patient, liens internes réparés, routes alignées entre README, ARCHITECTURE et REPRISE.

### Sans références aux tickets (`chore/sans-tickets`, PR #59)

- Références aux tickets retirées du code et de la documentation de reprise.

### Nettoyage pour la reprise (`chore/livraison-finale`, PR #58)

- Code sans usage réel supprimé : `PatientService` (jamais câblé), méthodes sans appelant, clés de vue-modèle de
  l'ancienne grille de l'agenda du service (`slots`, `cells`, `lateItems`…), filtre par type de soin d'`agenda.js`,
  paramètres de vue jamais passés, règles CSS des anciennes vues. Aucun changement de comportement :
  pages comparées à `main` par capture.
- Suite de tests réduite aux tests essentiels (`test/suite-essentielle`) : de 902 à 244 tests, chacun lié à une
  règle de gestion, à la sécurité du patient ou à un défaut réel ; `docs/TESTS.md` réécrit.
- Commentaires : historique retiré (la règle reste), commentaires faux corrigés, en-têtes complétés.
- Nouveau document [docs/REPRISE.md](docs/REPRISE.md) : lancer, architecture, admission de bout en bout,
  fonctionnalités, RG1 à RG10, conventions, hypothèses, dette, ajouter une page ou une règle.

### Retours du 2026-11-04 (3)

- Agenda du patient : colonne « Responsable » retirée.
- Admission : plus de compteur « N allergènes cochés ».

- Trois rotations, une seule référence (décision de Raphaël) : les équipes et « Mon planning » suivent les rotations
  de l'agenda (matin 6 h à 14 h, après-midi 14 h à 22 h, nuit 22 h à 6 h, `CareTeam::SHIFTS`) au lieu de vacations
  de 12 h. Paramètre `rotation` (l'ancien `vacation` est encore lu). Schéma : `CareTeam.shift` VARCHAR(10), CHECK
  sur MATIN, APRES_MIDI, NUIT (proposition pour Didac, migration `2026-11-04-trois-rotations.sql`). Démo : équipes
  pour les trois rotations.

- Édition en ligne du dossier : « Confirmer » passe par la fenêtre de validation par un médecin (authentification
  factice, comme « Modifier ») ; l'historique porte le nom du médecin au lieu de « non identifié ». La route
  `/sejour/champ/modifier` est câblée avec le service des parties (l'authentification y est vérifiée).

- Liste des patients : cœur barré à gauche du nom d'un patient décédé.
- Badge « Ordonnance non validée » cliquable partout où il s'affiche (agendas, Mon planning, liste des
  prescriptions) : il ouvre l'écran « Nouvelles prescriptions » sur l'ordonnance à valider (#ordonnance-<id>).
- Agenda du service, onglet « Lits » : toute la ligne d'un patient ouvre son panneau.
- Prescription « Une fois » : un seul champ « Le » ; « du … au … » seulement pour une prescription récurrente.
- Correctif : une date de fin saisie sans heure était ignorée (champ datetime-local considéré vide par le
  navigateur). Jour et heure sont maintenant deux champs ; sans heure, la fin vaut 23 h 59 ce jour-là, le début
  demande l'heure.

### Relecture adversariale (`feat/agenda-service-refonte`)

- Trois relectures adversariales (points d'entrée et DAO, services patient et prescription, agendas et
  JavaScript) : chaque défaut reproduit puis corrigé, 17 tests de non-régression (893 tests).
- Sécurité du patient : retards de la veille et de la vacation précédente toujours visibles, décès qui termine
  le séjour en cours même déclaré depuis un séjour clos, patient décédé non réadmissible, IPP d'une autre
  personne refusé, alerte allergie sur le nom du médicament, allergies dans la fenêtre de validation, double
  envoi impossible.
- Robustesse : filet d'erreur global, UTF-8 invalide refusé, bornes sur les noms, IPP, dates et durées de
  prescription, génération limitée à 7 jours, transactions rejouées sur conflit, verrous, CSRF à l'admission.

### Refonte de l'agenda du service (`feat/agenda-service-refonte`, AURA-29, AURA-32)

- Retour de Raphaël : la grille créneaux × patients montrait trop d'informations. Trois maquettes comparées
  (tableau des lits, liste de travail, grille compacte), A + B retenues.
- « Lits » : une ligne par patient triée par urgence, panneau de détail du lit choisi (soins de la rotation,
  allergies, référent, liens vers l'agenda et le dossier).
- « Liste de travail » : en retard, maintenant (deux heures), plus tard dans la rotation, réalisés.
- `AgendaService::presentBoard()` (pur, testé), composant `care_line`.
- Décès : « Déclarer le décès » (`POST /sejour/deces`, médecin, authentification factice) termine le séjour en
  cours à l'heure du décès et annule ses soins encore prévus ; tracé.
- Retours du 2026-11-04 (2) : allergies seulement dans le panneau du patient choisi de l'agenda du service ;
  « + » de la prescription à droite du titre du soin ; filtres ouverts à droite du bouton ; cœur barré à côté du
  nom d'un patient décédé dans la liste ; rotation de nuit « de 22 h à 0 h » ; libellés sans tirets longs ni
  points médians.
- Documentation relue et complétée : docblocks de `src/` (classes, méthodes, exceptions, vues avec leurs `@var`,
  en-têtes des scripts `public/js/` avec l'endpoint appelé), README (toutes les routes, scénario de démo à jour,
  tests, CI), `docs/ARCHITECTURE.md` (routes complètes, diagramme ER régénéré avec les équipes, décisions),
  `docs/TESTS.md` (création de la base de test), `docs/ui.md` (depuis fusionné dans `docs/UI-GUIDE.md`) et
  `docs/UI-GUIDE.md` (agenda du service, validation d'un soin, composants récents). Aucun changement de code.

### Retours des médecins du 2026-11-04 et audit IHM (`feat/retours-2026-11-04`, PR #54)

- Validation anticipée jusqu'à la fin de la rotation en cours, décès déclarable, filtres derrière un bouton,
  groupe sanguin verrouillé, écrans plus sobres (suite à l'audit IHM), données de démo enrichies.

## 2026-11-03

### Suite de tests complète (`test/suite-complete`, AURA-35)
- PHPUnit en deux suites : `aura` (métier, rendu, contrôleurs, DAO et intégration sur la base du CI) et `http`
  (bout en bout : serveur `php -S`, pages, parcours qui écrivent, CSRF, refus). La suite `http` remplace le
  smoke shell local, que le CI GitHub ne lançait pas. `composer test:fast`, `test:http`, `test:coverage`.
- Nouveaux tests : DAO manquants (Appointment, ReferenceData, Medication, ActivityDefinition), `Csrf`, `View`,
  `Transaction`, contraintes du schéma (cas repris du fichier `test_contraintes.sql` de Didac Espin, absent de `main`).
- CI GitHub : couverture pcov (résumé dans le journal, rapport HTML en artefact).
- `docs/TESTS.md` : matrice des règles de gestion RG1–RG10 vers leurs tests.
- Défauts trouvés et corrigés : `View::render()` laissait un tampon ouvert sur un gabarit en échec ; les CHECK
  sur valeurs codées acceptaient d'autres casses et des espaces finales (migration `2026-11-03-check-binaire.sql`).
- Relecture : ordonnance d'un médecin signée par son mot de passe, confirmation d'allergie liée aux médicaments
  exacts, CSRF sur l'arrêt d'une ligne et sur les allergies, décès visible dans tous les agendas.

### Retours des médecins sur l'admission et l'agenda (`feat/admission-agenda-retours`)
- Admission : plus de « Corriger l'identité », groupe sanguin sur la carte du patient connu, avertissement si
  le patient est déclaré décédé, erreur hors champ à côté du bouton « Admettre ».
- Agendas, équipes, planning : calendrier à droite, affichage dès le choix d'une date (sans bouton « Afficher »).
- Agenda : un « + » sur chaque soin déplie la carte de sa prescription (dose, voie, fréquence, période,
  consigne, mode, prescripteur, validation, note, arrêt).

### Retours des médecins sur la vue Prescription (`feat/prescription-retours`)
- Écran B3 : les listes « 1. », « 2. », « 3. » deviennent « Catégorie », « Médicament (DCI) » / « Soin » /
  « Protocole » (selon la catégorie) et « Forme galénique » ; « Mode » et « Prescripteur » sans deux-points.
- Note libre de l'ordonnance (`CarePlan.note`, 1 000 caractères au plus, validée côté serveur), affichée dans la
  liste des prescriptions, l'attente de validation et « Prescriptions en cours » du dossier.
- Alerte allergie : dès le choix d'un médicament dont le principe actif (`Medication.idSubstance`) est un
  allergène du patient (`role="alert"`) ; le serveur refuse l'ordonnance sauf case « Je prescris malgré
  l'allergie » cochée, et trace la confirmation (`AuditEvent`, entité `CarePlan`). Lignes concernées signalées
  dans la liste, le dossier et l'attente de validation.
- Validation d'une ordonnance en attente : médecin + mot de passe (authentification factice, même règle et même
  message que la fenêtre du dossier) ; jeton CSRF sur l'enregistrement et la validation (403 sinon).
- Proposition de schéma : `CarePlan.note TEXT NULL` (migration additive `2026-11-03-note-ordonnance.sql`) ;
  données de démo : amoxicilline → pénicilline, céfotaxime → céphalosporines.

### Liste des patients et dossier : retours des médecins (`feat/patients-retours`)
- Dossier toujours accessible depuis la liste : séjour en cours, sinon dernier séjour (« Dernier dossier, clos
  le … ») ; « Aucun séjour : pas de dossier » sans lien mort.
- Historique des séjours (numéro, statut, admission, sortie, service, motif), du plus récent au plus ancien,
  dépliable dans la liste et en section « Séjours du patient » du dossier, le séjour affiché signalé.
- Séjour en cours mis en avant dans la liste (numéro, lit, service, depuis le …) et encart en tête du dossier
  d'un séjour antérieur qui mène au séjour en cours.
- Décès (`Patient.deceased`, colonne existante) : « Décédé(e) » icône + texte dans le bandeau, le dossier et la
  liste ; déclaré dans la partie « Administratif complémentaire », validé par un médecin et tracé ; absent de
  l'admission et refusé par l'édition en ligne.

### Génération de l'agenda et suppression d'un patient (`feat/agenda-generation-suppression`)
- AURA-24.2 / 24.3 : `bin/generate-agenda.php` (cron quotidien) crée les soins planifiés manquants des lignes
  actives des séjours en cours jusqu'à J + 7, sans doublon, sans modifier l'existant ni créer de soin passé (RG9).
  Un seul algorithme d'occurrences (`RequestLine::plannedTimesBetween()`).
- AURA-12.3 : suppression logique d'un patient depuis la liste ou la page de modification, avec page de
  confirmation ; refusée si séjour en cours ou soin réalisé (RG10) ; tracée dans l'historique (AuditEvent).
- Proposition de schéma : `Patient.removedAt`, clés uniques (ligne, `plannedTime`) sur `Appointment` ; migrations
  additives du 2026-11-03.

## 2026-11-02

### Retours d'usage du 2 novembre (`feat/retours-2026-11-02`)
- Édition en ligne du dossier : crayon au survol, la saisie remplace la valeur sur place, un seul bouton
  « Confirmer » (la valeur d'origine restait affichée sous le champ).
- Prescriptions : « Du / Au (date de fin) », colonne « Période », catégorie « Protocoles » ; un médicament déjà
  prescrit peut être prescrit à nouveau, avec un avertissement non bloquant.
- Allergies : couleur selon la criticité (élevée, faible, non évaluée) ; à l'admission, liste filtrable avec
  une criticité par allergène coché.
- Admission sur deux colonnes ; IPP attribué automatiquement à un nouveau patient admis sans IPP.
- Dossier patient : menu figé à gauche (agenda en tête, parties et sous-catégories du dossier).
- Deuxième série : crayon « Modifier » par partie du dossier (identité et séjour compris), fin de séjour,
  fenêtre de validation par un médecin (authentification factice) ; prescriptions en cours dans une colonne
  à droite ; agenda avec le même menu ; groupe sanguin ; exemples grisés et dates en JJ/MM/AAAA ; recherche
  du patient sur deux colonnes ; allergies en étiquettes seules ; sans menu à trois barres ni lien
  « Modifier » dans la liste ; fil d'Ariane et fiche de prescription alignés ; fuseau horaire Europe/Paris.

### Intégration de la pile #43 à #46 dans la branche principale (`chore/integration-pile-2026-11-02`)
- #43 à #46 avaient été fusionnées chacune dans la branche de la PR précédente, pas dans la branche
  principale : cette PR les y apporte, par-dessus #41 (protocoles).
- Correctif : `/care-plans` et `/care-plans/new` levaient une TypeError depuis #41 (argument `SubstanceDao`
  manquant), et le test des protocoles rendait le CI rouge.

### #41 Protocoles (`aura-epic4`)
- Tables `Pathology`, `PlanDefinition`, `PlanDefinitionAction`, lien `CarePlan.idPlanDefinition` ; un protocole
  pré-remplit une ordonnance (`GET /care-plans/protocol-lines`).

### #46 Documentation du dépôt (`docs/documentation-repo`)
- README : installation sur la VM Debian LAMP (Apache), tests, structure à jour, conventions de commit et de PR,
  dépannage, section « Comment tester ».
- `docs/ARCHITECTURE.md` (modules, trajet d'une requête, endpoints, diagramme ER généré depuis `schema.sql`,
  décisions), `docs/TESTS.md`, ce journal.
- PHPDoc sur les classes et méthodes publiques qui en manquaient ; `docs/ui.md` aligné sur `docs/UI-GUIDE.md`.

### #45 Refonte visuelle (`feat/ui-polish`)
- Police Source Sans 3 vendorisée (OFL), base 16 px, fond clair chaud, accent unique, rouge réservé aux alertes
  et retards, orange aux avertissements, cibles ≥ 44 px, formulaires en une colonne, tableaux à en-têtes collants.
- Fil d'Ariane sur les écrans de séjour ; bandeau patient à trois états d'allergies.
- `docs/UI-GUIDE.md` (jetons, règles de couleur, composants, accessibilité WCAG 2.2 AA, sources, licences).

### #44 Édition champ par champ du dossier (`feat/dossier-patient-edition-inline`)
- `POST /sejour/champ/modifier` : liste blanche, validation partagée, concurrence optimiste, jeton CSRF de session.
- Chaque valeur du dossier complet se modifie en place avec confirmation ; panneau « Historique des modifications ».

### #43 Recherche d'un patient existant à l'admission (`feat/admission-recherche-patient`)
- `GET /patients/recherche` (IPP ou mots du nom / prénom, 20 résultats, homonymes signalés, identité seulement),
  `GET /patients/fiche` ; combobox accessible, confirmation explicite, pré-remplissage, « Changer de patient ».
- Rattachement du séjour au patient existant, tracé ; modifications tracées champ par champ.
- Table `AuditEvent`, `RecordEditService`, index sur `Patient.lastName`, `firstName`, `birthLastName`.

### #42 Champs complets de l'admission et du dossier (`feat/admission-champs-complets`)
- 21 colonnes sur `Patient`, 18 sur `EpisodeOfCare` (schéma + migration additive) : identité de naissance, INS,
  personne de confiance, DMP, directives anticipées, antécédents, histoire de la maladie, scores (ASA, NYHA, Mac
  Cabe, Knaus, GIR, INTERMACS), chirurgie, prothèses.
- Formulaire d'admission en sections repliables avec compteur ; allergies choisies dans le référentiel.
- Dossier complet avec « Non renseigné » explicite ; IMC, surface corporelle, poids théorique et Vt (formules sourcées).

## Avant le 2026-11-02

Voir l'historique Git et les pull requests #1 à #41 (admission AURA-10, liste et modification des patients AURA-12,
allergies AURA-13, dossier de séjour AURA-14, recherche par IPP AURA-15, prescriptions Epic 4, agenda AURA-24,
refonte IHM AURA-UI).

## Glossaire

Les acronymes et les termes techniques ou métier employés dans ce document.

| Terme | Signification |
| --- | --- |
| Agenda du patient | Écran des soins planifiés d'un séjour, pour un jour (par tranches de rotation, retards en tête) ou une semaine. |
| Agenda du service | Écran de tous les patients du service : onglet « Lits » (un patient par ligne, du plus urgent au moins urgent) et onglet « Liste de travail ». |
| Allergène | Substance à laquelle un patient est allergique. Dans AURA, les allergènes sont choisis dans un référentiel (table `Substance`). |
| Apache | Serveur web utilisé sur la VM de démonstration (pile LAMP). Il sert le dossier `public/` et envoie toute adresse sans fichier vers `index.php`. |
| Artefact | Fichier produit par une exécution du CI et téléchargeable depuis GitHub, ici le rapport de couverture HTML. |
| ASA | Classification de l'état de santé d'un patient avant une anesthésie, de I (bonne santé) à VI, publiée par l'American Society of Anesthesiologists. Valeurs et source dans `ClinicalScales`. |
| Authentification factice | Fenêtre qui demande de choisir un praticien dans une liste et de saisir un mot de passe, mais qui ne vérifie pas ce mot de passe. Elle remplace provisoirement une vraie authentification ; le nom choisi est enregistré dans la trace. |
| B3 (écran B3) | Numéro, dans le cahier des charges, de l'écran de saisie des prescriptions (`/care-plans/new`). |
| Bandeau patient | Zone fixée en haut de chaque écran de séjour : identité du patient, séjour, poids, taille et allergies (règle RG2). |
| Branche | Ligne de développement séparée dans Git. On crée une branche par fonctionnalité ou correction, puis on la fusionne dans `main` par une pull request. |
| CHECK | Contrainte SQL qui refuse une valeur hors d'une liste ou d'un intervalle (par exemple une rotation autre que MATIN, APRES_MIDI ou NUIT). |
| CI | Intégration continue : vérifications lancées automatiquement par GitHub Actions à chaque push sur `main` et à chaque pull request (syntaxe, style, schéma, tests). Fichier `.github/workflows/ci.yml`. |
| Combobox | Champ de saisie accompagné d'une liste de suggestions qu'on parcourt au clavier (recherche d'un patient à l'admission). |
| Concurrence optimiste | Protection contre deux modifications simultanées : le navigateur renvoie l'ancienne valeur, et le serveur refuse l'écriture (conflit) si la valeur en base a changé entre-temps. |
| Criticité | Gravité estimée d'une allergie : « Élevée » ou « Faible », ou non évaluée. Elle règle la couleur de l'allergie dans le bandeau. |
| cron | Planificateur de tâches de Linux. Sur la VM, il lance chaque jour `bin/generate-agenda.php` (ligne ajoutée par `crontab -e`). |
| CSRF | Cross-Site Request Forgery : attaque qui fait envoyer une requête par le navigateur d'un utilisateur à son insu. Parade : un jeton de session secret, exigé sur chaque POST (`Csrf::check()`). |
| CSS | Langage de mise en forme des pages web. AURA a un seul fichier : `public/css/style.css`. |
| DAO | Data Access Object : classe de `src/Dao/` qui lit et écrit une table. C'est le seul endroit où apparaissent les noms de colonnes SQL. |
| datetime-local | Type de champ HTML qui saisit une date et une heure ensemble. Remplacé par deux champs séparés (jour, heure). |
| DCI | Dénomination commune internationale : nom générique du principe actif d'un médicament (paracétamol plutôt qu'un nom de marque). Colonne `Medication.inn`. |
| Déclaration du décès | Action réservée à un médecin (authentification factice) : le patient est marqué décédé, le séjour en cours se termine à l'heure du décès et ses soins encore prévus sont annulés (`POST /sejour/deces`). |
| Diagramme entité-association (ER) | Schéma des tables, de leurs colonnes et des clés qui les relient. |
| Directives anticipées | Volontés écrites d'un patient sur sa fin de vie, à respecter s'il ne peut plus s'exprimer. |
| DMP | Dossier médical partagé : dossier de santé national d'un patient en France. AURA ne stocke que son identifiant, saisi à la main. |
| Édition en ligne | Modification d'un seul champ du dossier directement dans la page (crayon, puis « Confirmer »). Elle passe par l'authentification factice d'un médecin et est tracée. |
| Epic | Grand ensemble de fonctionnalités du backlog, découpé en tickets (ici l'Epic 4 : les prescriptions). |
| Équipe de rotation | Groupe de soignants composé pour une rotation donnée, avec les patients dont il a la charge. Un patient n'appartient qu'à une équipe par rotation. |
| Fil d'Ariane | Ligne de liens qui montre où se trouve la page (Patients › Dossier › Agenda) et permet de remonter. |
| Forme galénique | Forme sous laquelle un médicament est présenté (comprimé, solution injectable…). Colonne `Medication.form`. |
| Fuseau horaire | Zone d'heure légale. AURA fixe Europe/Paris, quel que soit le réglage de PHP. |
| Fusion (merge) | Intégration des changements d'une branche dans une autre, ici d'une branche de fonctionnalité dans `main`, par une pull request. |
| GIR | Groupe iso-ressources de la grille AGGIR : niveau de dépendance d'une personne, de GIR 1 (dépendance la plus forte) à GIR 6 (autonome). |
| Homonyme | Patient qui porte le même nom et le même prénom qu'un autre. Signalé dans la recherche de l'admission pour éviter une confusion. |
| HTML | Langage des pages web. Les vues PHP de `src/View/` produisent du HTML. |
| IHM | Interface homme-machine : les écrans de l'application. |
| IMC | Indice de masse corporelle : poids (kg) divisé par le carré de la taille (m). Calculé par AURA à partir des mesures d'admission. |
| INS | Identité nationale de santé : identifiant national d'un patient en France. AURA stocke le « matricule INS » saisi, sans le vérifier. |
| INTERMACS | Classification de l'insuffisance cardiaque avancée en sept profils, du profil 1 (choc cardiogénique critique) au profil 7. |
| IPP | Identifiant permanent du patient dans l'établissement. Dans AURA : 6 à 9 chiffres, facultatif à la saisie (patient admis en urgence), attribué automatiquement s'il manque ; celui d'un patient supprimé reste réservé. |
| Jeton CSRF | Valeur secrète liée à la session du navigateur, envoyée avec chaque POST (champ `csrf` ou en-tête `X-CSRF-Token`). Sans jeton valide, le serveur refuse l'écriture (403). |
| JJ/MM/AAAA | Format de saisie des dates (jour, mois, année), converti par le serveur (`DateInput`). |
| Knaus | Échelle de l'état de santé antérieur d'un patient admis en réanimation (activité et suivi médical des six mois précédents), classes A à D. |
| LAMP | Linux, Apache, MariaDB (ou MySQL), PHP : pile logicielle de la VM de démonstration. |
| Ligne arrêtée | Ligne de prescription stoppée par un médecin (`stoppedAt`) : ses soins encore prévus sont annulés, rien n'est effacé. |
| Liste blanche | Liste fermée des champs autorisés. Un champ qui n'y figure pas est refusé, quel que soit ce qu'envoie le navigateur. |
| Liste de travail | Onglet de l'agenda du service : les soins de tout le service regroupés par urgence (en retard, doses de lignes arrêtées à tracer, maintenant, plus tard, réalisés). |
| Mac Cabe | Échelle du pronostic de la maladie sous-jacente d'un patient de réanimation : 0 (pas de maladie mortelle), 1 (mortelle à 5 ans), 2 (mortelle à 1 an). |
| Médecin référent | Médecin désigné responsable du patient pendant son séjour, choisi à l'admission. |
| Migration | Script SQL de `database/migrations/` qui ajoute à une base existante ce qu'un changement de `schema.sql` a introduit (colonnes, index, contraintes), sans effacer les données. |
| Mon planning | Écran d'un soignant : les soins des patients de ses équipes pendant une rotation, avec les mêmes actions que l'agenda. |
| NYHA | Classification de la New York Heart Association : gêne causée par une insuffisance cardiaque, de la classe I (aucune limitation) à IV (symptômes au repos). |
| OFL (SIL Open Font License) | Licence libre de la police Source Sans 3, qui permet de la copier dans le dépôt. |
| Ordonnance | Ensemble de lignes de prescription enregistrées ensemble pour un séjour (table `CarePlan`). Elle est validée en bloc par un médecin. |
| Panneau du lit | Zone à côté du tableau des lits de l'agenda du service, qui détaille le patient choisi (`?sejour=`) : allergies, référent, soins de la rotation avec leurs actions. |
| pcov | Extension PHP qui mesure la couverture de code pendant les tests (`composer test:coverage`). |
| Personne de confiance | Proche désigné par le patient pour l'accompagner et être consulté s'il ne peut plus s'exprimer. |
| PHPDoc, docblock | Commentaire structuré placé au-dessus d'une classe ou d'une méthode (`/** … */`), qui décrit son rôle, ses paramètres et ses exceptions. |
| PHPUnit | Outil de tests automatiques pour PHP (version 10). Configuration dans `phpunit.xml.dist`. |
| Poids théorique | Poids prédit à partir du sexe et de la taille (formule du protocole ARDSNet). Il sert à régler le volume courant d'un patient ventilé. |
| PR (pull request) | Demande de fusion d'une branche dans une autre sur GitHub. Elle est relue par un autre membre et doit avoir un CI vert avant d'être fusionnée. |
| PR empilées | Suite de pull requests dont chacune part de la branche de la précédente. Fusionnée dans sa branche de base, une PR empilée n'arrive pas dans `main` sans fusion supplémentaire. |
| Prescription | Décision d'un prescripteur de donner un médicament ou de faire un soin à un patient, avec une date de début, une fréquence éventuelle et une date de fin facultative. Chaque ligne de prescription génère des soins planifiés. |
| Principe actif | Substance d'un médicament qui produit son effet. AURA relie chaque médicament à sa substance pour alerter si elle est un allergène du patient. |
| Protocole | Modèle d'ordonnance (table `PlanDefinition`) : un groupe de lignes de prescription types, choisi pour pré-remplir une nouvelle ordonnance. |
| Rattachement | Ouverture d'un séjour sur un patient déjà présent dans la base, choisi par l'utilisateur, au lieu de créer un nouveau patient. Il est tracé. |
| Relecture adversariale | Relecture qui cherche activement à casser l'application (saisies malformées, accès directs, actions simultanées) pour en trouver les défauts. |
| RG (RG1 à RG10) | Règle de gestion : règle métier numérotée du dossier de cadrage. Leur énoncé et le code qui les applique sont dans `docs/REPRISE.md`, leurs tests dans `docs/TESTS.md`. |
| Rotation | Période de travail d'une équipe soignante. AURA en a trois par jour : matin de 6 h à 14 h, après-midi de 14 h à 22 h, nuit de 22 h à 6 h (`CareTeam::SHIFTS`, bornes à confirmer avec le service). Paramètre d'URL `rotation`. |
| Schéma | Structure de la base (tables, colonnes, contraintes), entièrement décrite par `database/schema.sql`. |
| Séjour | Passage d'un patient dans le service, de l'admission à la sortie ou au décès (table `EpisodeOfCare`). Un patient n'a qu'un séjour en cours à la fois ; chaque séjour a un numéro unique. |
| Smoke test | Test rapide qui appelle les pages principales pour vérifier qu'elles répondent sans erreur. Le smoke test local en shell a été remplacé par la suite `http`. |
| Soin en retard | Soin planifié non validé dont l'heure prévue est passée (règle RG8). Le retard est calculé à l'affichage, jamais enregistré, et ces soins sont épinglés en tête de l'agenda. |
| Soin planifié | Occurrence datée d'une ligne de prescription : une administration ou un acte à faire à une heure donnée (table `Appointment`). Statuts : prévu, réalisé, non effectué, annulé. |
| Suppression logique | La ligne n'est pas effacée : une date est écrite dans `removedAt` et les DAO l'ignorent ensuite. L'historique reste en base (traçabilité). |
| Test de bout en bout | Test qui envoie de vraies requêtes HTTP à un serveur PHP lancé pour l'occasion, comme le ferait un navigateur, et vérifie les réponses (`tests/Http`). |
| Ticket | Élément numéroté du backlog Notion. Les identifiants de tickets cités dans ce journal sont historiques : le code et la documentation de reprise ne s'y réfèrent plus. |
| Traçabilité, trace | Enregistrement de qui a fait quoi et quand (table `AuditEvent` et colonnes d'horodatage), exigé par la règle RG10. |
| Transaction | Groupe d'écritures en base qui réussissent toutes ou sont toutes annulées. Dans AURA : `Transaction::run()`. |
| TypeError | Erreur PHP levée quand une fonction reçoit une valeur d'un type inattendu. |
| UTF-8 | Codage des caractères (accents compris) utilisé partout. Une saisie qui n'est pas de l'UTF-8 valide est refusée (400). |
| Vacation | Ancien nom des périodes de travail des équipes (deux de 12 h par jour), remplacées par les trois rotations de 8 h. L'ancien paramètre d'URL `vacation` est encore lu. |
| Validation d'une ordonnance | Acceptation d'une ordonnance par un médecin habilité (rôle avec `canValidate`, règle RG5). Une ordonnance faite par un prescripteur sans ce droit (urgence, paramédicale) reste « non validée » jusque-là ; celle d'un médecin habilité est validée d'office. |
| VARCHAR, INT, DATETIME, TEXT | Types de colonnes SQL : texte de longueur limitée, nombre entier, date et heure, texte long. |
| Vendorisé | Copié dans le dépôt au lieu d'être chargé depuis internet (police, icônes). |
| VM | Machine virtuelle : ordinateur simulé. La VM de démonstration (Debian, Apache, MariaDB, PHP) sert la branche `main`. |
| Vt (volume courant) | Volume d'air insufflé à chaque cycle par le respirateur. AURA affiche une plage de 6 à 8 ml par kg de poids théorique, à titre indicatif. |
| Vue-modèle | Tableau de données préparé par un service pour une vue (méthodes `present…()`), déjà trié et mis en forme, testable sans base. |
| WCAG 2.2 AA | Règles internationales d'accessibilité des contenus web (W3C). Le niveau AA fixe notamment un contraste minimal et des cibles assez grandes. |
