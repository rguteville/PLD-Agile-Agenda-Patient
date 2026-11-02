# Guide de l'interface AURA

Référence pour écrire ou modifier un écran sans casser la cohérence visuelle ni l'accessibilité.
Public : soignants et médecins de réanimation, souvent de nuit, sur poste fixe (1366 px) ou tablette de service
(1024 px). Objectif : calme, lisible d'un coup d'œil, aucune information portée par la couleur seule.

Complète `docs/ui.md` (système de design initial : statuts, agenda, impression). En cas de divergence, ce guide
l'emporte pour les jetons et les règles d'usage des couleurs.

## Principes

1. La sécurité du patient d'abord : bandeau identité + allergies en tête de tout écran de séjour, collant, jamais replié.
2. Jamais la couleur seule : chaque état combine une icône, un texte et une couleur.
3. Le serveur valide ; l'interface conforte et explique (aide courte sous le libellé, erreur sous le champ).
4. Un seul bouton primaire par écran ; le reste en secondaire.
5. Aucune donnée masquée : une valeur absente s'affiche « Non renseigné », jamais une ligne qui disparaît.
6. Pas de CDN, pas de build, pas de framework : tout est servi tel quel depuis `public/` (VM sans accès internet).

## Jetons (`:root`, fin de `public/css/style.css`, bloc « AURA-POLISH »)

| Famille | Jetons | Valeurs (clair) | Usage |
| --- | --- | --- | --- |
| Police | `--font-ui` | « Source Sans 3 », puis pile système | Une seule police, vendorisée (`public/fonts/`). |
| Tailles | `--fs-0` 13 px, `--fs-1` 14, `--fs-2` 16 (base), `--fs-3` 18, `--fs-4` 22, `--fs-5` 26 ; `--lh` 1.5 | Base ≥ 16 px, interlignes généreux. `--fs-0`/`--fs-1` réservés aux métadonnées. |
| Espacements | `--sp-1` 4 px à `--sp-6` 32 px | Multiples de 4. |
| Rayons, ombres | `--radius` 6 px, `--radius-lg` 8 px, `--shadow-soft` | Ombres très légères sur les cartes, aucune en mode sombre. |
| Neutres | `--bg` #f6f3ee, `--bg-raised` #fffdf9, `--bg-muted` #efebe4, `--line`, `--line-strong`, `--fg` #1f2328, `--fg-muted`, `--fg-subtle` | Fond clair chaud, pas de blanc pur (reflets, fatigue de nuit). |
| Accent | `--accent` #1b5e8c, `--accent-hover`, `--accent-soft`, `--accent-fg` | Liens, bouton primaire, ligne active. Un seul accent. |
| Statuts | `--st-alert-*` rouge, `--st-late-*` rouge, `--st-warn-*` orange, `--st-done-*` vert, `--st-planned-*` bleu-gris, `--st-validate-*` violet, `--st-neutral-*` gris | Voir « Couleurs ». |
| Cibles | `--tap` 44 px | Hauteur minimale des boutons, champs, lignes cliquables. |
| Mouvement | `--transition` 160 ms | Retour d'état seulement (survol, ouverture). `prefers-reduced-motion` coupe tout. |
| Bandeau | `--bandeau-height` | Posée par `public/js/layout.js` : en-têtes de tableau collants et `scroll-padding-top` se placent sous le bandeau. |

Mode sombre : conservé (il existait avant cette refonte et il est complet). Même jeu de jetons, redéfini dans les
deux blocs (`prefers-color-scheme` et `data-theme="dark"`). Toute nouvelle couleur se déclare dans les trois endroits
(clair, média sombre, `data-theme`). Capture ou test en clair quel que soit le système : `?theme=light` dans l'URL.

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

## Bandeau patient (`components/bandeau_patient`)

Nom (capitales), prénom, sexe, date de naissance et âge, IPP, numéro de séjour et date d'admission, poids, taille,
puis la zone allergies avec **trois états distincts** :

| État | Clé `allergiesStatus` | Rendu |
| --- | --- | --- |
| Allergies connues | `known` | Fond rouge pâle, icône triangle, une étiquette par allergie colorée selon sa criticité : **Élevée** rouge plein, **Faible** orange, **non évaluée** contour rouge ; la criticité est toujours écrite entre parenthèses |
| Aucune allergie connue | `none` | Fond vert pâle, coche, texte « Aucune allergie connue » |
| Allergies non renseignées | `unknown` | Fond orange pâle, icône alerte, texte « Allergies non renseignées » |

Limite actuelle : le service ne produit que `known` et `none` (une liste vide vaut « aucune connue », décision
RG2 antérieure). Produire `unknown` demande une donnée d'évaluation des allergies à l'admission (colonne
`Patient.allergyStatus` à proposer au responsable BDD). Le composant et le CSS sont prêts.

## Composants

| Composant | Rôle | Règles |
| --- | --- | --- |
| `breadcrumb` | Fil d'Ariane sur les écrans de séjour (Patients › Dossier › Agenda / Prescriptions) | Dernier élément = page courante (`aria-current`), sans lien. |
| `form` + `field` | Formulaires : une colonne, libellé au-dessus, aide (`help`) sous le libellé, erreur sous le champ | Types : text, date, number, email, tel, radio, select (`empty`), textarea, checkbox-group, hidden, heading ; `listbox` pour une combobox. |
| `form` → `sections` | Sections repliables `<details>` des formulaires longs, compteur « n sur m renseignés » | Repliées par défaut, ouvertes si un champ est en erreur (`form-sections.js`). |
| `status_badge` | Icône + texte + couleur d'un statut | Clés dans `docs/ui.md`. `late` est désormais rouge. |
| `empty_state` | Écran ou section sans donnée | Titre, explication, action principale à côté quand elle existe. |
| `.confirm-box` | Confirmation en place du rattachement d'un patient à l'admission | Texte de confirmation, Confirmer / Annuler, `role="group"`, jamais une modale centrée. |
| `.patient-banner` | Bandeau orange « Données issues du dossier existant… vérifiez-les » | Avec « Changer de patient ». |
| `allergy_picker` (type de champ `allergy-picker`) | Allergies à l'admission : liste filtrable du référentiel, criticité par allergène coché | Envoie `allergies[]` et `allergyCriticality[id]` ; filtre sans accents ni casse ; un allergène coché reste visible quel que soit le filtre. |
| `.suggestions` | Liste de suggestions d'une combobox | `role="listbox"`, options `role="option"`, avertissement d'homonymie en tête. |
| `.inline-edit` | Valeur éditable du dossier (bouton) | Crayon au survol ou au focus ; clic ou Entrée → le champ remplace la valeur sur place (jamais affichée deux fois) avec un seul bouton « Confirmer » ; Entrée confirme, Échap annule ; « Enregistré » en `aria-live`. |

## Formulaires

- Une colonne, libellé au-dessus, étoile rouge pour l'obligatoire, aide courte sous le libellé, erreur sous le
  champ avec bordure rouge à gauche (GOV.UK : message spécifique, à côté du champ, jamais effacer la saisie).
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

## Vérifier un écran

```bash
bash .claude/scripts/screenshot.sh "/sejour?id=1&theme=light" 1280   # clair, poste fixe
bash .claude/scripts/screenshot.sh "/sejour?id=1&theme=light" 1024   # tablette de service
bash .claude/scripts/screenshot.sh "/sejour?id=1" 1280               # thème du système (sombre sur un Mac en sombre)
composer test                                                       # tests de rendu sans base (tests/View)
```
