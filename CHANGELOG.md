# Journal des changements

Format : une entrée par pull request, de la plus récente à la plus ancienne.

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
