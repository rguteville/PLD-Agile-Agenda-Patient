# Journal des changements

Format : une entrée par pull request, de la plus récente à la plus ancienne.

## 2026-11-04

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
  `docs/TESTS.md` (création de la base de test), `docs/ui.md` et `docs/UI-GUIDE.md` (agenda du service,
  validation d'un soin, composants récents). Aucun changement de code.

### Retours des médecins du 2026-11-04 et audit IHM (`feat/retours-2026-11-04`, PR #54)

- Validation anticipée jusqu'à la fin de la rotation en cours, décès déclarable, filtres derrière un bouton,
  groupe sanguin verrouillé, écrans plus sobres (docs/audit/2026-11-04-audit-ihm.md), données de démo enrichies.

## 2026-11-03

### Suite de tests complète (`test/suite-complete`, AURA-35)
- PHPUnit en deux suites : `aura` (métier, rendu, contrôleurs, DAO et intégration sur la base du CI) et `http`
  (bout en bout : serveur `php -S`, pages, parcours qui écrivent, CSRF, refus). La suite `http` remplace le
  smoke shell local, que le CI GitHub ne lançait pas. `composer test:fast`, `test:http`, `test:coverage`.
- Nouveaux tests : DAO manquants (Appointment, ReferenceData, Medication, ActivityDefinition), `Csrf`, `View`,
  `Transaction`, contraintes du schéma (cas repris de `database/test_contraintes.sql`, Didac Espin).
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
