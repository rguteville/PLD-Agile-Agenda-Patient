# AURA : système de design de l'IHM

> Document d'origine de la refonte AURA-UI (2026-11-01). Les jetons, règles de couleur et composants ont été
> complétés le 2026-11-02 par [docs/UI-GUIDE.md](UI-GUIDE.md), qui fait foi en cas de divergence (notamment :
> police Source Sans 3 vendorisée, base 16 px, **retard = rouge**, orange = avertissement).

Guide pour reprendre ou étendre l'interface sans la casser. Référence visuelle : Philips IntelliSpace
Critical Care (dense, chronologique, piloté par les statuts), rendue plus calme : typographie, espacements et
clarté, sans animation décorative ni dégradé.

## Principes

- **Serveur PHP, CSS natif, JS vanilla.** Aucune bibliothèque front, aucun CDN, aucun build : la VM de démo sert
  les fichiers tels quels. Un seul fichier de styles, `public/css/style.css`.
- **La sécurité du patient passe avant l'esthétique.** Le bandeau identité + allergies ouvre chaque écran de séjour
  (règle RG2), reste collé en haut et ne se replie jamais. « Aucune allergie connue » est un état distinct, affiché
  avec une coche verte, jamais un bandeau vide.
- **Jamais la couleur seule.** Chaque statut combine une icône, un texte et une couleur (personnel daltonien,
  écrans de mauvaise qualité, impression noir et blanc).
- **Le serveur valide, l'IHM conforte.** Les attributs HTML (`required`, `min`…) et le JavaScript ne sont jamais la
  garantie.

## Jetons de design (`:root` dans `style.css`, bloc « AURA-UI »)

| Famille | Jetons | Notes |
| --- | --- | --- |
| Police | `--font-ui`, `--font-mono` | Pile système (Segoe UI sous Windows). `font-variant-numeric: tabular-nums` sur `body` : heures et doses s'alignent. |
| Tailles | `--fs-0` (12 px) à `--fs-5` (24 px), `--lh` | Base 14 px, pensée pour un poste 1366 × 768 et une tablette. |
| Espacements | `--sp-1` (4 px) à `--sp-6` (32 px) | Multiples de 4. |
| Rayons | `--radius` (4 px), `--radius-lg` (6 px) | Petits rayons, bordures de 1 px plutôt que des ombres. |
| Neutres | `--bg`, `--bg-raised`, `--bg-muted`, `--line`, `--line-strong`, `--fg`, `--fg-muted`, `--fg-subtle` | `--bg-raised` = cartes et tableaux, `--bg-muted` = en-têtes de tableau, champs verrouillés. |
| Accent | `--accent`, `--accent-hover`, `--accent-soft`, `--accent-fg` | Un seul accent (bleu profond). `--accent-soft` = ligne en cours d'édition, survol. |
| Statuts | `--st-<statut>-fg / -bg / -line` pour `planned`, `done`, `late`, `validate`, `alert`, `warn`, `neutral` | Retard et alerte bloquante (allergie, non fait) = rouge ; avertissement = orange (`warn`). |
| Focus | `--focus-ring`, `--focus-offset` | Anneau visible sur tout élément focalisable (`:focus-visible`). |
| Transitions | `--transition` (120 ms) | Uniquement en retour d'état (survol, ouverture du menu). Jamais décoratives. |

### Mode sombre

Deux mécanismes, mêmes valeurs :

1. `@media (prefers-color-scheme: dark)` s'applique si l'utilisateur n'a rien choisi (`:root:not([data-theme="light"])`).
2. `:root[data-theme="dark"]` s'applique quand le bouton de thème (lune / soleil dans la barre supérieure) a été
   utilisé. `public/js/theme.js`, chargé dans `<head>`, pose l'attribut avant le premier rendu et mémorise le choix
   dans `localStorage` (clé `aura-theme`, stockée dans le navigateur seulement).

Pour modifier une couleur du mode sombre, la changer **dans les deux blocs** (ils sont volontairement identiques).
Contrastes à conserver : texte / fond ≥ 4,5:1 (WCAG AA) dans les deux modes.

### Impression

`@media print` : barre, menu, boutons et formulaires disparaissent, le bandeau devient statique, les badges passent
en noir sur blanc avec bordure. L'agenda s'imprime en vue du jour pour les transmissions (bouton « Imprimer la
journée »).

## Composants (`src/View/components/`)

| Composant | Usage | Paramètres |
| --- | --- | --- |
| `icon` | Icône Lucide (1.66.0, licence ISC) en SVG inline. | `name` ; `label` (texte alternatif, sinon décorative) ; `class` (`icon--lg`). |
| `status_badge` | Icône + texte + couleur d'un statut. | `status` (clé, voir ci-dessous) ; `label` facultatif pour remplacer le texte. |
| `empty_state` | Écran ou section sans donnée, avec explication. | `title` ; `text` ; `icon`. |
| `patient_banner` | Identité, IPP, séjour, poids, taille, allergies (RG2). Collant, jamais replié. | `bandeau` (vue-modèle de `PatientRecordService::presentBanner()`). |
| `agenda_table` | Tableau d'une tranche de l'agenda. | `items`, `caption`, `validationEnabled`. |
| `validation_dialog` | `<dialog>` d'action sur un soin : « Réalisé » (qui, quand) ou « Non effectué » (motif), ou note seule ouverte par le bouton « Note ». Envoyé par `agenda.js` à `POST /agenda-patient/soin`. | `validators`, `csrfToken`, `back`. |
| `care_line` | Ligne de soin de l'agenda du service, liste de travail et panneau du lit (heure, patient, soin, statut, actions). | `item`, `showPatient`, `showStatus`, `validationEnabled`, `agendaUrl`. |
| `care_buttons` | Boutons « Valider » (soin en retard ou prévu avant la fin de la rotation en cours) et « Note ». | `item`, `idEpisodeOfCare`, `context`. |
| `form` + `field` | Formulaires (inchangés) : label associé, `aria-invalid`, `aria-describedby`, `role="alert"`. | voir `.claude/rules/views-ui.md`. |

Classes utilitaires : `.button` (+ `.button--secondary`, `.button--danger`), `.page-header` / `.page-actions`,
`.stats` (résumé chiffré), `.table-scroll` (tableau défilant sur petit écran), `.sr-only`, `.skip-link`.

### Statuts et clés

| Clé | Libellé | Icône | Couleur | Origine |
| --- | --- | --- | --- | --- |
| `planned` | Prévu | `clock` | bleu-gris | `Appointment.status = PLANIFIE`, heure non dépassée |
| `late` | En retard | `clock-alert` | rouge (ambre avant la PR ui-polish) | `PLANIFIE` et heure prévue dépassée (**calcul d'affichage**, pas une donnée) |
| `done` | Effectué | `circle-check` | vert | `REALISE` |
| `not_done` | Non fait | `octagon-x` | rouge | `NON_FAIT` |
| `cancelled` | Annulé | `circle-x` | gris | `ANNULE` |
| `to_validate` | À valider par un médecin | `pen-line` | violet | `CarePlan` sans `idValidator` (RG5) |
| `validated` | Validée | `shield-check` | vert | `CarePlan` validée |
| `stopped` | Arrêtée | `ban` | gris | ligne arrêtée (AURA-22) |

Une valeur inconnue en base s'affiche telle quelle, en neutre, avec l'icône `circle-alert` : on ne cache rien.

### Ajouter un statut

1. **Service** : traduire la valeur en base vers une clé (`AgendaService::STATUS_KEYS` pour l'agenda).
2. **Badge** : ajouter la clé dans `$statuses` de `components/status_badge.php` (libellé français + icône Lucide ;
   si l'icône manque, coller son tracé dans `components/icon.php`).
3. **Couleurs** : dans `style.css`, réutiliser une famille `--st-*` existante ou en créer une (clair **et** sombre),
   puis déclarer `.badge--<clé>`.
4. **Tests** : un cas dans `tests/Service/AgendaServicePresentDayTest.php` (traduction) et un dans
   `tests/View/ComponentsRenderTest.php` (rendu).
5. Mettre à jour le tableau ci-dessus.

## Écrans

- **Agenda patient** (`pages/episode/patient-agenda.php`, `GET /agenda-patient`) : un jour, chronologique. Les
  retards sont épinglés en tête (figure 4 du cahier des charges), puis quatre tranches de rotation : Nuit (fin)
  de 0 h à 6 h, Matin de 6 h à 14 h, Après-midi de 14 h à 22 h, Nuit (début) de 22 h à 0 h. **Bornes = hypothèse**
  à confirmer avec le service (`AgendaService::SHIFTS`). Un soin se valide s'il est en retard ou prévu avant la
  fin de la rotation en cours (`AgendaService::shiftEnd()`). La colonne « Responsable » (IDE / IDE-AS) est déduite
  du type de ligne faute de donnée en base. Toute la logique est dans `AgendaService::presentDay()` (pur, testé) ;
  `?vue=semaine` affiche la semaine (`presentWeek()`).
- **Prescriptions** (`pages/careplan/list.php`) : badge `to_validate` = « petit stylo » du SI réel (RG5).
- **Vue du service** (`pages/service/consolidated-view.php`, `GET /agenda-service`, refonte du 2026-11-04) : onglet
  « Lits » (une ligne par patient, triée par urgence : nombre de retards, plus ancien retard, à faire dans la
  rotation, prochain soin, lit ; panneau de détail du lit choisi, `?sejour=`) et onglet « Liste de travail »
  (`?vue=liste` : en retard, doses de lignes arrêtées, maintenant, plus tard, réalisés). Allergies (RG2) dans le
  panneau du patient choisi seulement (choix du 2026-11-04 : ni le tableau ni la liste de travail ne les
  répètent). La rotation est bornée au jour affiché (la nuit : « de 22 h à 0 h » puis « de 0 h à 6 h » le
  lendemain). Vue-modèle pur `AgendaService::presentBoard()`, ligne de soin partagée `components/care_line`. Le
  contrat du vue-modèle est documenté en tête du fichier et couvert par un test.
- **Équipes et « Mon planning »** (`pages/team/teams.php`, `pages/team/planning.php`, `GET /equipes`,
  `GET /mon-planning`) : les trois rotations de l'agenda, 6 h, 14 h, 22 h (`CareTeam::SHIFTS`, hypothèse).

## Vérifier

```bash
composer cs && composer test                 # PSR-12 + tests (dont tests/View/*RenderTest, sans base)
bash .claude/scripts/ci-local.sh             # CI complet en local
bash .claude/scripts/screenshot.sh "/agenda-patient?id=1&date=2026-10-14"   # capture Chrome headless
```

Les deux scripts `.claude/scripts/` sont des outils locaux, non versionnés : sans eux, suivre `docs/TESTS.md` pour
la base de test et faire la capture à la main.

Contrôles manuels avant une PR IHM : navigation au clavier (Tab, Entrée, Échap sur le menu et le dialogue), focus
visible, lecteur d'écran sur les badges et le bandeau, rendu en 1366 px et 390 px, mode clair et sombre, aperçu avant
impression de l'agenda.

## Hors périmètre (volontairement)

Mis à jour le 2026-11-04. La validation d'un soin (`POST /agenda-patient/soin`, AURA-26, AURA-28) et la vue
consolidée du service (`GET /agenda-service`, AURA-29) existent désormais ; ils étaient hors périmètre lors de la
refonte du 2026-11-01.

- **Authentification du praticien** (AURA-50 ; praticiens et rôles : AURA-18) : toute « validation » reste auto-déclarée par une liste déroulante
  et un mot de passe non vérifié (authentification factice).
- **Nettoyage du CSS historique** : les anciennes règles restent et sont surchargées par le bloc AURA-UI pour
  limiter les conflits de fusion ; à nettoyer quand les branches ouvertes seront fusionnées.
