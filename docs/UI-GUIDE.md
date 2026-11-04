# Guide de l'interface AURA

Référence unique pour écrire ou modifier un écran sans casser la cohérence visuelle ni l'accessibilité : principes,
jetons de design, couleurs, composants, statuts, écrans, vérifications.
Public : soignants et médecins de réanimation, souvent de nuit, sur poste fixe (1366 px) ou tablette de service
(1024 px). Objectif : calme, lisible d'un coup d'œil, aucune information portée par la couleur seule.

Référence visuelle de départ : Philips IntelliSpace Critical Care (dense, chronologique, piloté par les statuts),
rendue plus calme : typographie, espacements et clarté, sans animation décorative ni dégradé.

## Principes

1. La sécurité du patient d'abord : bandeau identité + allergies en tête de tout écran de séjour (RG2), collant,
   jamais replié. « Aucune allergie connue » est un état distinct, affiché avec une coche verte, jamais un bandeau vide.
2. Jamais la couleur seule : chaque état combine une icône, un texte et une couleur (personnel daltonien, écrans de
   mauvaise qualité, impression en noir et blanc).
3. Le serveur valide ; l'interface conforte et explique (aide courte sous le libellé, erreur sous le champ). Les
   attributs HTML (`required`, `min`…) et le JavaScript ne sont jamais la garantie.
4. Un seul bouton primaire par écran ; le reste en secondaire.
5. Aucune donnée masquée : une valeur absente s'affiche « Non renseigné », jamais une ligne qui disparaît.
6. Pas de CDN, pas de build, pas de framework : serveur PHP, CSS natif, JavaScript vanilla, tout est servi tel quel
   depuis `public/` (VM sans accès internet). Un seul fichier de styles : `public/css/style.css`.

## Jetons (`:root`, fin de `public/css/style.css`, bloc « AURA-POLISH »)

Les jetons ont d'abord été posés par le bloc « AURA-UI » de `style.css` (2026-11-01), puis redéfinis par le bloc
« AURA-POLISH » (2026-11-02). Les valeurs ci-dessous sont celles qui s'appliquent.

| Famille | Jetons | Valeurs (clair) | Usage |
| --- | --- | --- | --- |
| Police | `--font-ui`, `--font-mono` | « Source Sans 3 », puis pile système ; police à chasse fixe du système | Une seule police, vendorisée (`public/fonts/`). Chiffres à largeur fixe (`font-variant-numeric: tabular-nums`) sur `body` : heures et doses s'alignent. |
| Tailles | `--fs-0` 13 px, `--fs-1` 14, `--fs-2` 16 (base), `--fs-3` 18, `--fs-4` 22, `--fs-5` 26 ; `--lh` 1.5 | Base ≥ 16 px, interlignes généreux. `--fs-0`/`--fs-1` réservés aux métadonnées. |
| Espacements | `--sp-1` 4 px à `--sp-6` 32 px | Multiples de 4. |
| Rayons, ombres | `--radius` 6 px, `--radius-lg` 8 px, `--shadow-soft` | Ombres très légères sur les cartes, aucune en mode sombre. |
| Neutres | `--bg` #f6f3ee, `--bg-raised` #fffdf9, `--bg-muted` #efebe4, `--line`, `--line-strong`, `--fg` #1f2328, `--fg-muted`, `--fg-subtle` | Fond clair chaud, pas de blanc pur (reflets, fatigue de nuit). `--bg-raised` : cartes et tableaux ; `--bg-muted` : en-têtes de tableau, champs verrouillés. |
| Accent | `--accent` #1b5e8c, `--accent-hover`, `--accent-soft`, `--accent-fg` | Liens, bouton primaire, ligne active. Un seul accent. `--accent-soft` : ligne en cours d'édition, survol. |
| Statuts | `--st-<statut>-fg`, `-bg`, `-line` pour `alert` et `late` (rouge), `warn` (orange), `done` (vert), `planned` (bleu-gris), `validate` (violet), `neutral` (gris) | Voir « Couleurs ». |
| Focus | `--focus-ring`, `--focus-offset` | Anneau visible sur tout élément focalisable (`:focus-visible`). |
| Cibles | `--tap` 44 px | Hauteur minimale des boutons, champs, lignes cliquables. |
| Mouvement | `--transition` 160 ms | Retour d'état seulement (survol, ouverture). `prefers-reduced-motion` coupe tout. |
| Bandeau | `--bandeau-height` | Posée par `public/js/layout.js` : en-têtes de tableau collants et `scroll-padding-top` se placent sous le bandeau. |

### Mode sombre

Même jeu de jetons, redéfini dans deux blocs aux valeurs identiques :

1. `@media (prefers-color-scheme: dark)` avec `:root:not([data-theme="light"])` : s'applique si l'utilisateur n'a
   rien choisi et que son système est en sombre.
2. `:root[data-theme="dark"]` : s'applique après un clic sur le bouton de thème (lune ou soleil dans la barre du
   haut). `public/js/theme.js`, chargé dans `<head>`, pose l'attribut avant le premier rendu et mémorise le choix
   dans le `localStorage` du navigateur (clé `aura-theme`).

Toute nouvelle couleur se déclare dans les trois endroits (clair, média sombre, `data-theme`). Contraste texte sur
fond ≥ 4,5:1 dans les deux modes. Capture ou test en clair quel que soit le système : `?theme=light` dans l'URL
(`?theme=dark` pour le sombre).

### Impression

`@media print` : barre du haut, actions, navigation par date, dialogues et formulaires disparaissent, fond blanc et
texte noir. Les agendas ont un bouton « Imprimer » ; l'agenda du patient s'imprime en vue du jour pour les
transmissions.

## Couleurs : règles d'usage

| Couleur | Réservée à | Jamais pour |
| --- | --- | --- |
| Rouge (`--st-alert-*`, `--st-late-*`) | Allergies connues, soin en retard, soin non fait, erreur de saisie, bouton « Retirer » | Décoration, titres, boutons primaires |
| Orange (`--st-warn-*`) | Avertissements : allergies non renseignées, homonymes, données reprises d'un dossier existant | Erreurs bloquantes |
| Vert (`--st-done-*`) | Soin effectué, prescription validée, « Aucune allergie connue », message « Enregistré » | Boutons d'action |
| Bleu accent | Navigation, action principale, ligne en cours d'édition | États cliniques |
| Gris / bleu-gris | Prévu, annulé, arrêté, métadonnées | Alertes |

Contraste : texte sur fond ≥ 4,5:1, composants et icônes ≥ 3:1 (WCAG 2.2 AA). Les couleurs ci-dessus ont été
choisies pour respecter ce seuil sur `--bg-raised` et `--bg`. Vérifier toute nouvelle paire avec un outil de contraste.

## Bandeau patient (`components/patient_banner`)

Paramètre `banner` : vue-modèle de `PatientRecordService::presentBanner()`. Nom (capitales), prénom, sexe, date de
naissance et âge, IPP, numéro de séjour et date d'admission, poids, taille, puis la zone allergies avec **trois
états distincts** :

| État | Clé `allergiesStatus` | Rendu |
| --- | --- | --- |
| Allergies connues | `known` | Pas de fond coloré autour, icône triangle, une étiquette par allergie colorée selon sa criticité : **Élevée** rouge plein, **Faible** orange, **non évaluée** contour rouge ; la criticité est toujours écrite entre parenthèses |
| Aucune allergie connue | `none` | Fond vert pâle, coche, texte « Aucune allergie connue » |
| Allergies non renseignées | `unknown` | Fond orange pâle, icône alerte, texte « Allergies non renseignées » |

Limite actuelle : le service ne produit que `known` et `none` (une liste vide vaut « aucune connue », décision
RG2 antérieure). Produire `unknown` demande une donnée d'évaluation des allergies à l'admission (colonne
`Patient.allergyStatus` à proposer au responsable BDD). Le composant et le CSS sont prêts.

## Composants (`src/View/components/`)

Chaque composant déclare ses paramètres en `@var` dans son en-tête ; les principaux sont rappelés ici.

| Composant | Rôle | Règles et paramètres |
| --- | --- | --- |
| `icon` | Icône Lucide (1.66.0, licence ISC) en SVG dans la page | `name` ; `label` (texte alternatif, sinon icône décorative) ; `class` (`icon--lg`). Icône manquante : coller son tracé dans le composant. |
| `status_badge` | Icône + texte + couleur d'un statut | `status` (voir « Statuts et clés ») ; `label` pour remplacer le texte ; `href` rend le badge cliquable (« À valider par un médecin » mène à l'ordonnance à valider) ; `title` pour l'infobulle. |
| `empty_state` | Écran ou section sans donnée | `title`, `text` (explication), `icon` ; action principale à côté quand elle existe. |
| `breadcrumb` | Fil d'Ariane sur les écrans de séjour (Patients › Dossier › Agenda / Prescriptions) | Dernier élément = page courante (`aria-current`), sans lien. |
| `episode_menu` | Menu figé des écrans de séjour : bouton principal en tête (« Agenda du jour » dans le dossier, « Dossier du séjour » dans l'agenda, au même endroit) | Même composant pour les deux écrans. |
| `.record-menu` | Menu figé à gauche du dossier patient : « Agenda du jour » en tête, Dossier, Allergies, Dossier complet (sous-catégories), Prescriptions en cours | Collant sous le bandeau dès 1024 px, en tête de page en dessous ; partie visible signalée par `aria-current="location"` (`record-menu.js`), sous-catégorie = ouverture de sa section. |
| `part_form` + `.part-edit` | Crayon « Modifier » à côté du titre d'une partie du dossier : la partie entière devient un formulaire | Valeurs d'origine en `data-old` (concurrence) ; « Enregistrer » ouvre la fenêtre de validation (`#auth-dialog`). |
| `.inline-edit` | Valeur éditable du dossier (bouton) | Crayon au survol ou au focus ; clic ou Entrée : le champ remplace la valeur sur place (jamais affichée deux fois) avec un seul bouton « Confirmer » ; Entrée confirme, Échap annule. « Confirmer » ouvre la fenêtre de validation par un médecin (`#auth-dialog`), puis « Enregistré » est annoncé en `aria-live`. Groupe sanguin et décès n'ont pas de crayon : ils passent par « Modifier » ou par « Déclarer le décès ». |
| `#auth-dialog` | Fenêtre de validation par un médecin (édition en ligne d'un champ, modification d'une partie, fin de séjour, décès) | **Factice** : le mot de passe n'est pas vérifié, ce que la fenêtre affiche ; `<dialog>` modal, Échap ferme. |
| `form` + `field` | Formulaires : une colonne, libellé au-dessus, aide (`help`) sous le libellé, erreur sous le champ | Types : text, date, number, email, tel, radio, select (`empty`), textarea, checkbox-group, hidden, heading ; `listbox` pour une combobox. Label associé, `aria-invalid`, `aria-describedby`, `role="alert"`. |
| `form` → `sections` | Sections repliables `<details>` des formulaires longs, compteur « n sur m renseignés » | Repliées par défaut, ouvertes si un champ est en erreur (`form-sections.js`). |
| `.patient-card` | Carte en lecture seule du patient existant choisi à l'admission (identité, âge, IPP, groupe sanguin, dernier séjour, allergies par criticité, alerte si décédé) | Créée par `admission-search.js` dès la sélection, sans confirmation intermédiaire ; masque les champs d'identité ; pas de « Corriger l'identité » (la correction se fait dans le dossier, tracée) ; « Ce n'est pas ce patient » vide le formulaire. |
| `allergy_picker` (type de champ `allergy-picker`) | Allergies à l'admission : liste filtrable du référentiel, criticité par allergène coché | Envoie `allergies[]` et `allergyCriticality[id]` ; filtre sans accents ni casse ; un allergène coché reste visible quel que soit le filtre. |
| `.suggestions` | Liste de suggestions d'une combobox | `role="listbox"`, options `role="option"`, avertissement d'homonymie en tête. |
| `agenda_table` | Tableau d'une tranche de l'agenda du patient | `items` (lignes de `AgendaService::presentDay()`), `caption` (titre lu par les lecteurs d'écran), `validationEnabled` (colonne d'actions), `idEpisodeOfCare`. |
| `validation_dialog` + `care_buttons` | Action sur un soin depuis les agendas et « Mon planning » : « Valider » (Réalisé ou Non effectué) et « Note » | « Valider » seulement pour un soin en retard ou prévu avant la fin de la rotation en cours ; soignant + mot de passe non vérifié (factice) ; motif obligatoire pour « Non effectué ». Envoi par `agenda.js` à `POST /agenda-patient/soin`. Paramètres : `validators`, `csrfToken`, `back` (dialogue) ; `item`, `idEpisodeOfCare`, `context` (boutons). |
| `care_line` | Ligne de soin de l'agenda du service (liste de travail et panneau du lit) | Heure, patient dans la liste de travail (lit, nom, décès), soin avec le « + » de la prescription à droite du titre, statut, actions. Pas d'allergies sur la ligne : elles sont dans le panneau du patient choisi. Paramètres : `item`, `showPatient`, `showStatus`, `validationEnabled`, `agendaUrl`. |
| `deceased_tag` | Décès d'un patient : icône cœur barré + libellé accordé (« Décédé », « Décédée », « Décédé(e) ») | `iconOnly` dans la liste des patients : icône seule à côté du nom, libellé en infobulle et en texte alternatif. |

Classes utilitaires : `.button` (+ `.button--secondary`, `.button--danger`), `.page-header` / `.page-actions`,
`.stats` (résumé chiffré), `.table-scroll` (tableau défilant sur petit écran), `.sr-only` (texte pour lecteur
d'écran seulement), `.skip-link` (lien d'évitement vers le contenu).

## Statuts et clés

| Clé | Libellé | Icône | Couleur | Origine |
| --- | --- | --- | --- | --- |
| `planned` | Prévu | `clock` | bleu-gris | `Appointment.status = PLANIFIE`, heure non dépassée |
| `late` | En retard | `clock-alert` | rouge | `PLANIFIE` et heure prévue dépassée (**calcul d'affichage**, pas une donnée) |
| `done` | Effectué | `circle-check` | vert | `REALISE` |
| `not_done` | Non fait | `octagon-x` | rouge | `NON_FAIT` |
| `cancelled` | Annulé | `circle-x` | gris | `ANNULE` |
| `to_validate` | À valider par un médecin | `pen-line` | violet | `CarePlan` sans `idValidator` (RG5) |
| `validated` | Validée | `shield-check` | vert | `CarePlan` validée |
| `stopped` | Arrêtée | `ban` | gris | ligne de prescription arrêtée |

Une valeur inconnue en base s'affiche telle quelle, en neutre, avec l'icône `circle-alert` : on ne cache rien.

Ajouter un statut :

1. **Service** : traduire la valeur en base vers une clé (`AgendaService::STATUS_KEYS` pour l'agenda).
2. **Badge** : ajouter la clé dans `$statuses` de `components/status_badge.php` (libellé français + icône Lucide ;
   si l'icône manque, coller son tracé dans `components/icon.php`).
3. **Couleurs** : dans `style.css`, réutiliser une famille `--st-*` existante ou en créer une (clair **et** sombre),
   puis déclarer `.badge--<clé>`.
4. **Tests** : un cas dans `tests/Service/AgendaServiceTest.php` (traduction) et un dans
   `tests/View/ComponentsRenderTest.php` (rendu).
5. Mettre à jour le tableau ci-dessus.

## Écrans

- **Agenda du patient** (`pages/episode/patient-agenda.php`, `GET /agenda-patient`) : un jour, chronologique. Les
  retards sont épinglés en tête (figure 4 du cahier des charges), puis quatre tranches de rotation : Nuit (fin)
  de 0 h à 6 h, Matin de 6 h à 14 h, Après-midi de 14 h à 22 h, Nuit (début) de 22 h à 0 h. Ces tranches
  découpent au jour civil les trois rotations de `CareTeam::SHIFTS` (`AgendaService::SHIFTS`) ; **bornes =
  hypothèse** à confirmer avec le service. Un soin se valide s'il est en retard ou prévu avant la fin de la
  rotation en cours (`AgendaService::shiftEnd()`). Toute la logique est dans `AgendaService::presentDay()` (pur,
  testé) ; `?vue=semaine` affiche la semaine du lundi au dimanche (`presentWeek()`).
- **Prescriptions** (`pages/careplan/list.php`, `pages/careplan/form.php`) : badge `to_validate` = « petit stylo »
  du SI réel (RG5), cliquable vers l'ordonnance à valider.
- **Agenda du service** (`pages/service/consolidated-view.php`, `GET /agenda-service`) : onglet « Lits » (une ligne
  par patient, triée par urgence : nombre de retards, plus ancien retard, soins à faire dans la rotation, prochain
  soin, lit ; toute la ligne ouvre le panneau de détail du lit choisi, `?sejour=`) et onglet « Liste de travail »
  (`?vue=liste` : en retard, doses de lignes arrêtées à tracer, maintenant, plus tard, réalisés). Allergies (RG2)
  dans le panneau du patient choisi et dans la fenêtre de validation seulement : ni le tableau ni la liste de
  travail ne les répètent. La rotation est bornée au jour affiché (la nuit : « de 22 h à 0 h » puis « de 0 h à
  6 h » le lendemain). Vue-modèle pur : `AgendaService::presentService()` (jour et soins de chaque patient) puis
  `presentBoard()` (tableau, panneau, liste de travail) ; ligne de soin partagée `components/care_line`. Le contrat
  du vue-modèle est documenté en tête de `presentBoard()` et couvert par des tests.
- **Équipes et « Mon planning »** (`pages/team/teams.php`, `pages/team/planning.php`, `GET /equipes`,
  `GET /mon-planning`) : les trois rotations de l'agenda, 6 h, 14 h, 22 h (`CareTeam::SHIFTS`, hypothèse),
  choisies par le paramètre `rotation`.

## Formulaires

- Chaque champ montre un exemple grisé en italique (`placeholder`) ; les dates se saisissent en JJ/MM/AAAA
  dans un champ texte (`date-input.js`), converties par le serveur (`Aura\Core\DateInput`).
- Libellé au-dessus, étoile rouge pour l'obligatoire, aide courte sous le libellé, erreur sous le champ avec
  bordure rouge à gauche (GOV.UK : message spécifique, à côté du champ, jamais effacer la saisie).
- Admission : deux colonnes dès 1100 px (identité → note sur les allergies à gauche, sections complémentaires à
  droite), recherche du patient sur toute la largeur au-dessus ; une seule colonne en dessous (retours du 2026-11-02).
- Champs ≥ 44 px de haut, cases et boutons radio 20 px avec libellé cliquable ≥ 44 px.
- Sections repliables pour tout formulaire de plus d'une dizaine de champs ; l'essentiel reste visible en haut.
- Un seul bouton primaire (« Admettre », « Enregistrer ») ; recherche et actions secondaires en style secondaire.

## Tableaux et listes

- Lignes aérées (12 px vertical), en-têtes en petites capitales sur fond `--bg-muted`, collants sous le bandeau
  à partir de 1024 px (`position: sticky; top: var(--bandeau-height)`), défilement horizontal en dessous.
- Survol de ligne en `--accent-soft`. État vide explicite avec le bouton d'action principal à côté.

## Accessibilité (WCAG 2.2 niveau AA)

- Focus visible partout (`--focus-ring`), jamais masqué par le bandeau collant (`scroll-padding-top`, critère 2.4.11).
- Cibles ≥ 44 × 44 px pour les actions (au-delà du minimum 24 px du critère 2.5.8).
- Formulaires : `label for`, `aria-describedby` (aide + erreur), `aria-invalid`, `role="alert"` ; messages
  annoncés par `aria-live` ; combobox conforme (`aria-expanded`, `aria-activedescendant`).
- Navigable au clavier : Tab, Entrée, Échap, flèches dans les listes ; aucun `div` cliquable.
- Fonctionne à 1024 px sans défilement horizontal de page ; mode sombre et clair vérifiés.
- `prefers-reduced-motion` respecté.

## Vérifier un écran

```bash
composer cs && composer test                                        # PSR-12, tests (dont tests/View, sans base)
bash .claude/scripts/screenshot.sh "/sejour?id=1&theme=light" 1280   # clair, poste fixe
bash .claude/scripts/screenshot.sh "/sejour?id=1&theme=light" 1024   # tablette de service
bash .claude/scripts/screenshot.sh "/sejour?id=1" 1280               # thème du système (sombre sur un Mac en sombre)
```

`screenshot.sh` est un outil local de l'équipe d'origine, non versionné (capture Chrome headless) : sans lui,
faire la capture à la main aux mêmes largeurs. Pour la base de test, suivre [TESTS.md](TESTS.md).

Contrôles manuels avant une pull request qui touche l'interface : navigation au clavier (Tab, Entrée, Échap sur le
menu et les dialogues), focus visible, lecteur d'écran sur les badges et le bandeau, rendu en 1366 px, 1024 px et
390 px, mode clair et sombre, aperçu avant impression de l'agenda.

## Hors périmètre (volontairement)

- **Authentification du praticien** : toute « validation » reste auto-déclarée par une liste déroulante et un mot
  de passe non vérifié (authentification factice).
- **Nettoyage du CSS historique** : les anciennes règles restent et sont surchargées par les blocs « AURA-UI » et
  « AURA-POLISH » pour limiter les conflits de fusion.

## Sources consultées

- NHS Service Manual, design system : typographie (base 19 px sur grand écran, 16 px sur petit ; fond gris clair
  plutôt que blanc ; texte aligné à gauche) https://service-manual.nhs.uk/design-system/styles/typography ;
  couleurs (rouge = urgence / erreur, jaune = avertissement et focus, contraste AA minimum, jamais la couleur
  seule) https://service-manual.nhs.uk/design-system/styles/colour ; care cards (variantes non-urgent / urgent /
  emergency, texte caché annonçant l'urgence) https://service-manual.nhs.uk/design-system/components/care-cards ;
  pages de question https://service-manual.nhs.uk/design-system/patterns/question-pages
- GOV.UK Design System : message d'erreur (spécifique, à côté du champ, en rouge, préfixe « Error » caché,
  ne jamais effacer la saisie) https://design-system.service.gov.uk/components/error-message/ ; pages de question
  https://design-system.service.gov.uk/patterns/question-pages ; aide contextuelle
  https://www.gov.uk/service-manual/design/help-text
- W3C, What's New in WCAG 2.2 (2.4.11 Focus Not Obscured, 2.5.7 Dragging Movements, 2.5.8 Target Size
  Minimum 24 px, 3.2.6 Consistent Help, 3.3.7 Redundant Entry) https://www.w3.org/WAI/standards-guidelines/wcag/new-in-22/
- NHS Common User Interface, Patient Banner design guidance (2015, document historique) :
  https://github.com/nhsuk/nhsuk-service-manual-backlog/files/3365263/Patient.Banner.QIG.pdf

Aucun CSS propriétaire n'a été copié ; seuls les principes ont été repris.

## Licences des ressources vendorisées

| Ressource | Fichiers | Licence |
| --- | --- | --- |
| Source Sans 3 (Adobe), police variable, sous-ensembles latin et latin-ext | `public/fonts/source-sans-3-latin.woff2`, `public/fonts/source-sans-3-latin-ext.woff2`, `public/fonts/LICENSE-source-sans-3.txt` | SIL Open Font License 1.1 |
| Lucide (icônes SVG en ligne, `components/icon.php`) | tracés copiés dans le composant | ISC, https://lucide.dev |

## Glossaire

Les acronymes et les termes techniques ou métier employés dans ce document.

| Terme | Signification |
| --- | --- |
| Admission | Entrée d'un patient dans le service : création ou reprise du patient, ouverture d'un séjour, saisie des allergies. C'est la page d'accueil d'AURA (`/`). |
| Agenda du patient | Écran des soins planifiés d'un séjour, pour un jour (par tranches de rotation, retards en tête) ou une semaine. |
| Agenda du service | Écran de tous les patients du service : onglet « Lits » (un patient par ligne, du plus urgent au moins urgent) et onglet « Liste de travail ». |
| Allergène | Substance à laquelle un patient est allergique. Dans AURA, les allergènes sont choisis dans un référentiel (table `Substance`). |
| Attributs ARIA | Attributs HTML (`aria-invalid`, `aria-describedby`, `aria-live`…) qui décrivent aux lecteurs d'écran l'état et le rôle d'un élément. |
| Authentification factice | Fenêtre qui demande de choisir un praticien dans une liste et de saisir un mot de passe, mais qui ne vérifie pas ce mot de passe. Elle remplace provisoirement une vraie authentification ; le nom choisi est enregistré dans la trace. |
| Bandeau patient | Zone fixée en haut de chaque écran de séjour : identité du patient, séjour, poids, taille et allergies (règle RG2). |
| BDD | Base de données. Le « responsable BDD » est le membre de l'équipe qui décide des changements de `database/schema.sql`. |
| CDN | Content Delivery Network : serveur externe qui fournit des fichiers communs (polices, bibliothèques). AURA n'en utilise aucun : tout est dans le dépôt, car la VM n'a pas accès à internet. |
| Chrome headless | Chrome lancé sans fenêtre, pour faire des captures d'écran par script. |
| Collant (sticky) | Élément qui reste visible en haut de l'écran pendant le défilement (bandeau, en-têtes de tableau). |
| Combobox | Champ de saisie accompagné d'une liste de suggestions qu'on parcourt au clavier (recherche d'un patient à l'admission). |
| Composant | Morceau de vue réutilisable de `src/View/components/` (bandeau patient, formulaire, badge de statut…). |
| Concurrence optimiste | Protection contre deux modifications simultanées : le navigateur renvoie l'ancienne valeur, et le serveur refuse l'écriture (conflit) si la valeur en base a changé entre-temps. |
| Contraste | Écart de luminosité entre un texte et son fond, exprimé en rapport (au moins 4,5:1 pour le texte au niveau AA). |
| Criticité | Gravité estimée d'une allergie : « Élevée » ou « Faible », ou non évaluée. Elle règle la couleur de l'allergie dans le bandeau. |
| CSS | Langage de mise en forme des pages web. AURA a un seul fichier : `public/css/style.css`. |
| Déclaration du décès | Action réservée à un médecin (authentification factice) : le patient est marqué décédé, le séjour en cours se termine à l'heure du décès et ses soins encore prévus sont annulés (`POST /sejour/deces`). |
| Édition en ligne | Modification d'un seul champ du dossier directement dans la page (crayon, puis « Confirmer »). Elle passe par l'authentification factice d'un médecin et est tracée. |
| Équipe de rotation | Groupe de soignants composé pour une rotation donnée, avec les patients dont il a la charge. Un patient n'appartient qu'à une équipe par rotation. |
| Exemple grisé (`placeholder`) | Texte d'exemple affiché dans un champ vide, qui disparaît à la saisie. |
| Fenêtre modale (`<dialog>`) | Fenêtre ouverte au-dessus de la page, qui bloque le reste jusqu'à sa fermeture (Échap ferme). |
| Fil d'Ariane | Ligne de liens qui montre où se trouve la page (Patients › Dossier › Agenda) et permet de remonter. |
| Focus | Élément de la page qui reçoit les frappes du clavier. Il doit toujours être visible (anneau `--focus-ring`). |
| GET, POST | Méthodes HTTP. GET lit une page sans rien modifier ; POST envoie des données à enregistrer. |
| GOV.UK Design System | Guide de conception des services en ligne du gouvernement britannique, source des règles sur les messages d'erreur. |
| Homonyme | Patient qui porte le même nom et le même prénom qu'un autre. Signalé dans la recherche de l'admission pour éviter une confusion. |
| HTML | Langage des pages web. Les vues PHP de `src/View/` produisent du HTML. |
| Hypothèse | Choix fait faute de réponse du service ou du PO, signalé « HYPOTHÈSE » dans le code et à confirmer. |
| Infobulle | Texte qui apparaît au survol d'un élément. |
| IPP | Identifiant permanent du patient dans l'établissement. Dans AURA : 6 à 9 chiffres, facultatif à la saisie (patient admis en urgence), attribué automatiquement s'il manque ; celui d'un patient supprimé reste réservé. |
| ISC (licence) | Licence libre très permissive, celle des icônes Lucide. |
| JavaScript (JS) | Langage exécuté par le navigateur. Dans AURA, un fichier par fonctionnalité dans `public/js/`, sans bibliothèque ; il n'est qu'un confort, le serveur revalide tout. |
| Jeton de design | Variable CSS nommée (`--accent`, `--fs-2`…) déclarée dans `:root` et utilisée partout à la place d'une valeur écrite en dur. |
| JJ/MM/AAAA | Format de saisie des dates (jour, mois, année), converti par le serveur (`DateInput`). |
| Lecteur d'écran | Logiciel qui lit la page à voix haute ou en braille pour les personnes malvoyantes. |
| Ligne arrêtée | Ligne de prescription stoppée par un médecin (`stoppedAt`) : ses soins encore prévus sont annulés, rien n'est effacé. |
| Liste de travail | Onglet de l'agenda du service : les soins de tout le service regroupés par urgence (en retard, doses de lignes arrêtées à tracer, maintenant, plus tard, réalisés). |
| Lit, box | Emplacement du patient dans le service. Le séjour garde un numéro de lit et un numéro de box (chambre ou espace individuel). |
| localStorage | Stockage du navigateur, propre à chaque poste. AURA n'y garde que le choix du thème. |
| Lucide | Jeu d'icônes libre (licence ISC). Les tracés utilisés sont copiés dans `components/icon.php`. |
| Méthode pure | Méthode qui ne lit ni la base ni la requête et dont le résultat ne dépend que de ses paramètres (date courante comprise) : elle se teste sans base. |
| Mode sombre | Thème aux couleurs foncées, choisi par le réglage du système ou par le bouton lune / soleil de la barre du haut. |
| Mon planning | Écran d'un soignant : les soins des patients de ses équipes pendant une rotation, avec les mêmes actions que l'agenda. |
| NHS | National Health Service, système de santé public du Royaume-Uni. Son guide de conception d'interfaces a servi de source. |
| OFL (SIL Open Font License) | Licence libre de la police Source Sans 3, qui permet de la copier dans le dépôt. |
| Ordonnance | Ensemble de lignes de prescription enregistrées ensemble pour un séjour (table `CarePlan`). Elle est validée en bloc par un médecin. |
| Panneau du lit | Zone à côté du tableau des lits de l'agenda du service, qui détaille le patient choisi (`?sejour=`) : allergies, référent, soins de la rotation avec leurs actions. |
| Philips IntelliSpace Critical Care | Logiciel commercial de dossier de réanimation, pris comme référence visuelle. Ni code ni CSS n'en a été repris. |
| PHP | Langage du serveur (version 8.4). AURA n'utilise aucun framework. |
| PR (pull request) | Demande de fusion d'une branche dans une autre sur GitHub. Elle est relue par un autre membre et doit avoir un CI vert avant d'être fusionnée. |
| prefers-reduced-motion | Réglage du système qui demande moins d'animations. AURA coupe alors toutes les transitions. |
| Prescription | Décision d'un prescripteur de donner un médicament ou de faire un soin à un patient, avec une date de début, une fréquence éventuelle et une date de fin facultative. Chaque ligne de prescription génère des soins planifiés. |
| PSR-12 | Règles de présentation du code PHP (indentation, accolades, longueur des lignes), publiées par le PHP-FIG. Vérifiées par `composer cs`. |
| RG (RG1 à RG10) | Règle de gestion : règle métier numérotée du dossier de cadrage. Leur énoncé et le code qui les applique sont dans `docs/REPRISE.md`, leurs tests dans `docs/TESTS.md`. |
| Rotation | Période de travail d'une équipe soignante. AURA en a trois par jour : matin de 6 h à 14 h, après-midi de 14 h à 22 h, nuit de 22 h à 6 h (`CareTeam::SHIFTS`, bornes à confirmer avec le service). Paramètre d'URL `rotation`. |
| Séjour | Passage d'un patient dans le service, de l'admission à la sortie ou au décès (table `EpisodeOfCare`). Un patient n'a qu'un séjour en cours à la fois ; chaque séjour a un numéro unique. |
| SI | Système d'information. AURA est un « SI miroir » : il reproduit, pour le projet, les fonctions du système d'information d'un service de réanimation. |
| Soin en retard | Soin planifié non validé dont l'heure prévue est passée (règle RG8). Le retard est calculé à l'affichage, jamais enregistré, et ces soins sont épinglés en tête de l'agenda. |
| Soin planifié | Occurrence datée d'une ligne de prescription : une administration ou un acte à faire à une heure donnée (table `Appointment`). Statuts : prévu, réalisé, non effectué, annulé. |
| SVG | Format d'image vectorielle écrit en texte, inséré directement dans la page pour les icônes. |
| Transmissions | Passage des informations sur les patients d'une équipe soignante à la suivante, au changement de rotation. |
| URL | Adresse d'une page web (`http://localhost:8000/sejour?id=1`). |
| Validation d'un soin | Déclaration qu'un soin planifié a été réalisé ou non effectué, avec le soignant, l'heure de réalisation et l'heure de saisie (règle RG7). Possible une seule fois, au plus tard jusqu'à la fin de la rotation en cours. |
| Validation d'une ordonnance | Acceptation d'une ordonnance par un médecin habilité (rôle avec `canValidate`, règle RG5). Une ordonnance faite par un prescripteur sans ce droit (urgence, paramédicale) reste « non validée » jusque-là ; celle d'un médecin habilité est validée d'office. |
| Vendorisé | Copié dans le dépôt au lieu d'être chargé depuis internet (police, icônes). |
| VM | Machine virtuelle : ordinateur simulé. La VM de démonstration (Debian, Apache, MariaDB, PHP) sert la branche `main`. |
| Vue-modèle | Tableau de données préparé par un service pour une vue (méthodes `present…()`), déjà trié et mis en forme, testable sans base. |
| W3C | World Wide Web Consortium, organisme qui publie les normes du web, dont les WCAG. |
| WCAG 2.2 AA | Règles internationales d'accessibilité des contenus web (W3C). Le niveau AA fixe notamment un contraste minimal et des cibles assez grandes. |
