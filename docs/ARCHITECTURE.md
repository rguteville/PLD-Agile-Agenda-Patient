# Architecture d'AURA

Application PHP 8.4 sans framework, MariaDB 11.8, servie telle quelle (aucun build). Ce document décrit les
modules, le trajet d'une requête, le modèle de données réel et les décisions de conception retrouvées dans le dépôt.

## Modules

| Dossier | Rôle | Règle |
| --- | --- | --- |
| `public/index.php` | Point d'entrée : déclare les routes et construit chaque contrôleur avec ses dépendances (injection manuelle par closures). | La connexion PDO (`Database::get()`) n'est ouverte que dans la closure de la route qui en a besoin. |
| `src/Core` | `Router` (routes exactes GET / POST), `Database` (PDO unique, paresseux, config sans secret dans la trace), `View` (rendu des gabarits, `e()` pour échapper), `Csrf` (jeton de session des écritures), `DateInput` (dates JJ/MM/AAAA), `ConfigurationException`. | Aucune logique métier. |
| `src/Controller` | Lit `$_GET` / `$_POST` / le corps JSON, mappe les champs de formulaire vers les clés du domaine, appelle un service, répond en HTML (`View::page`) ou JSON. | Ne valide pas. Détecte l'AJAX par `Accept: application/json` ou `X-Requested-With`. |
| `src/Service` | Règles métier. `validate()` est pure (sans PDO ni HTTP, date injectée) ; les écritures passent par `Transaction::run()`. | Un service par cas d'usage ; les méthodes `present*()` préparent des vue-modèles testables sans base. |
| `src/Dao` | Seul endroit où apparaissent les colonnes SQL (camelCase anglais HL7). Requêtes préparées, ids `VARCHAR(20)` calculés par `MAX(CAST(id AS UNSIGNED)) + 1 … FOR UPDATE`. | Traduction colonnes ↔ entités ; listes blanches de colonnes pour les mises à jour partielles. |
| `src/Domain` | Entités immuables (getters), constantes métier (`ClinicalScales`, `RecordFields`, statuts). | Pas d'accès aux données. |
| `src/View` | `layouts/main.php`, `components/` (form, field, patient_banner, breadcrumb, status_badge, empty_state, icon, agenda_table, care_line, care_buttons, validation_dialog…), `pages/`. Chaque gabarit déclare ses variables en `@var` dans son en-tête. | Tout est échappé par `View::e()` ; pas de fonction nommée dans une vue. |
| `public/css/style.css`, `public/js/*.js`, `public/fonts/` | Un seul fichier CSS (blocs ajoutés en fin de fichier), scripts vanilla par fonctionnalité, police vendorisée. | Aucun CDN. |
| `database/` | `schema.sql` (structure complète, rejouée sur base vide par le CI), `test.sql` (données de démo fictives), `demo-today.sql` (recale la démo sur le jour courant), `migrations/` (ALTER additifs pour une base existante). | Le schéma est géré par le responsable BDD. |
| `bin/generate-agenda.php` | Génération quotidienne de l'agenda (cron, AURA-24.2 / 24.3), voir le README. | Même configuration que le site. |

## Trajet d'une requête

1. `public/index.php` lit le chemin ; un fichier statique existant est servi directement (serveur PHP intégré).
2. Le `Router` trouve la closure de la route (méthode + chemin exact) ou rend la page 404.
3. La closure ouvre le PDO si nécessaire et construit le contrôleur avec ses services et DAO.
4. Le contrôleur mappe la requête vers les clés du domaine et appelle le service.
5. Le service valide (pur), puis écrit dans une transaction via les DAO.
6. Le contrôleur répond : page HTML (`View::page`, layout + bandeau + contenu), redirection 303 après un POST réussi
   (POST → redirect → GET), ou JSON (`{ok, erreurs}` en 422, `{ok, redirect}` ou données en 200).
7. `ConfigurationException` (config absente, base injoignable) est rattrapée dans `index.php` : message en texte brut, sans identifiant.

## Pages et endpoints

Liste complète des routes de `public/index.php`. Les identifiants passent en query string ou dans le corps du
POST (pas de paramètre dans le chemin). « CSRF » : jeton de session obligatoire (en-tête `X-CSRF-Token` ou champ
`csrf`), 403 sinon. « Auth. factice » : praticien choisi dans une liste et mot de passe non vérifié, en attendant
l'authentification des praticiens (AURA-50 ; praticiens et rôles : AURA-18).

| Méthode et chemin | Rôle |
| --- | --- |
| `GET /` | Formulaire d'admission (recherche d'un patient existant, identité, motif, service, médecin référent, allergies, sections repliables). Affichable sans base. |
| `POST /patients/create` | Admission : validation, création ou rattachement du patient, ouverture du séjour, allergies, traces ; JSON ou HTML. Pas de CSRF. |
| `GET /patients?q=` | Liste des patients : séjour en cours, sinon dernier dossier, historique des séjours dépliable, décès signalé ; recherche facultative. |
| `GET /patients/modifier?id=`, `POST /patients/modifier?id=` | Modification de l'identité d'un patient (AURA-12.2). Pas de CSRF. |
| `GET /patients/supprimer?id=`, `POST /patients/supprimer?id=` | Confirmation puis suppression logique d'un patient (AURA-12.3, CSRF) ; 409 si refusée (séjour en cours ou soin réalisé). |
| `GET /patients/recherche-ipp?ipp=` | Identité d'un patient par IPP (JSON, AURA-15, conservé). |
| `GET /patients/recherche?q=` | Recherche par IPP ou mots du nom / prénom (JSON, identité seulement, 20 max). |
| `GET /patients/fiche?id=` | Fiche complète d'un patient pour pré-remplir l'admission (JSON, à la sélection d'un résultat). |
| `GET /sejour?id=` | Dossier du séjour : bandeau, allergies, dossier complet éditable, prescriptions validées, séjours du patient, historique. |
| `POST /sejour/allergies/ajouter`, `POST /sejour/allergies/retirer` | Allergies du patient depuis le dossier (CSRF). Le retrait est physique (dette connue). |
| `POST /sejour/champ/modifier` | Modification d'un champ du dossier (JSON, CSRF, concurrence optimiste, trace AuditEvent). Groupe sanguin et décès refusés ici. |
| `POST /sejour/partie/modifier` | Modification d'une partie entière du dossier (identité, séjour, sections), JSON, CSRF, auth. factice d'un médecin, tout ou rien, tracée. Seule voie pour modifier le groupe sanguin. |
| `POST /sejour/cloturer` | Fin du séjour (date et heure de sortie), JSON, CSRF, auth. factice d'un médecin, tracée. |
| `POST /sejour/deces` | Déclaration du décès (date et heure) : le patient est marqué décédé, le séjour en cours se termine à cette heure et ses soins encore prévus sont annulés. JSON, CSRF, auth. factice d'un médecin, tracée. |
| `GET /care-plans?idEpisodeOfCare=` | Prescriptions du séjour (statut de validation RG5, période, note, allergène signalé). |
| `GET /care-plans/new?idEpisodeOfCare=`, `POST /care-plans/create?idEpisodeOfCare=` | Nouvelle prescription (écran B3) ; l'enregistrement (CSRF, auth. factice du prescripteur) planifie aussi ses soins (AURA-24.4). |
| `POST /care-plans/validate?idEpisodeOfCare=` | Validation d'une ordonnance par un médecin habilité (RG5, CSRF, auth. factice). |
| `POST /care-plans/stop?idEpisodeOfCare=` | Arrêt d'une ligne par un médecin (AURA-22, CSRF, auth. factice) ; ses soins encore prévus sont annulés. |
| `GET /care-plans/protocol-lines?idPlanDefinition=&startDate=` | Lignes d'un protocole datées à partir d'une date de début, pour pré-remplir l'ordonnance (JSON, #41). |
| `GET /agenda-patient?id=` ou `?numSejour=`, `&date=`, `&vue=semaine` | Agenda du séjour, jour (retards épinglés, rotations) ou semaine (lundi à dimanche). |
| `POST /agenda-patient/soin` | Action sur un soin (AURA-26, AURA-28) : réalisé, non effectué (motif obligatoire) ou note. JSON ou formulaire, CSRF, auth. factice du soignant ; validation possible jusqu'à la fin de la rotation en cours, une seule fois. |
| `GET /agenda-service?idHealthcareService=&date=&vue=&sejour=` | Agenda du service (AURA-29, AURA-32) : « Lits » (`vue=lits`, par défaut ; `sejour=` choisit le patient du panneau de détail) ou « Liste de travail » (`vue=liste`). |
| `GET /equipes?idHealthcareService=&date=&vacation=` | Équipes de la vacation (jour ou nuit) et formulaire de composition (AURA-30). |
| `POST /equipes/creer`, `POST /equipes/retirer` | Composer une équipe ou la retirer (suppression logique), CSRF, retour à la vacation. |
| `GET /mon-planning?idPractitioner=&date=&vacation=` | Soins des patients des équipes d'un soignant pendant la vacation (AURA-31), avec les mêmes actions que l'agenda. |

Toute autre adresse : page 404 (`View::error()`).

## Modèle de données (généré depuis `database/schema.sql`)

```mermaid
erDiagram
    Patient {
        VARCHAR_20 idPatient PK
        INT ipp UK
        VARCHAR_50 firstName
        VARCHAR_50 lastName
        VARCHAR_10 phoneNumber
        VARCHAR_50 email
        VARCHAR_1 sex
        DATE birthDate
        BOOLEAN deceased
        VARCHAR_100 generalPractitioner
        VARCHAR_100 address
        VARCHAR_3 bloodType
        VARCHAR_50 birthLastName
        VARCHAR_50 birthFirstName
        VARCHAR_20 insNumber
        VARCHAR_100 trustedPersonName
        VARCHAR_20 trustedPersonPhone
        VARCHAR_100 trustedPersonEmail
        VARCHAR_100 dmpIdentifier
        TEXT advanceDirectives
        TEXT allergyNote
        TEXT medicalHistory
        TEXT surgicalHistory
        TEXT obstetricHistory
        TEXT transfusionHistory
        TEXT vaccinationHistory
        TEXT anestheticHistory
        TEXT toxicHistory
        TEXT familySocialHistory
        TEXT lifestyle
        TEXT foreignTravel
        TEXT comorbidities
        TEXT currentTreatment
        DATETIME removedAt
    }
    HealthcareService {
        VARCHAR_20 idHealthcareService PK
        VARCHAR_50 serviceName
    }
    ActivityDefinition {
        VARCHAR_20 idActivityDefinition PK
        VARCHAR_100 name
        VARCHAR_200 description
    }
    PractitionerRole {
        VARCHAR_20 idPractitionerRole PK
        VARCHAR_50 title
        BOOLEAN canValidate
    }
    Substance {
        VARCHAR_20 idSubstance PK
        VARCHAR_100 name UK
    }
    Medication {
        VARCHAR_20 idMedication PK
        VARCHAR_100 inn
        VARCHAR_100 form
        VARCHAR_20 idSubstance
    }
    AllergyIntolerance {
        VARCHAR_20 idAllergy PK
        VARCHAR_20 idPatient
        VARCHAR_20 idSubstance
        VARCHAR_100 manifestation
        VARCHAR_100 criticality
        DATETIME recordedAt
    }
    Practitioner {
        VARCHAR_20 idPractitioner PK
        VARCHAR_20 idPractitionerRole
        VARCHAR_50 firstName
        VARCHAR_50 lastName
        VARCHAR_10 phoneNumber
        VARCHAR_50 email
    }
    EpisodeOfCare {
        VARCHAR_20 idEpisodeOfCare PK
        VARCHAR_20 idPatient
        VARCHAR_20 idHealthcareService
        VARCHAR_20 episodeNumber UK
        DATETIME admissionDate
        DATETIME dischargeDate
        VARCHAR_20 bedNumber
        VARCHAR_20 boxNumber
        VARCHAR_1000 admissionReason
        DECIMAL_5_2 weight
        DECIMAL_5_2 height
        VARCHAR_500 precautions
        VARCHAR_100 specialStatus
        TEXT whitePlan
        TEXT diseaseHistory1
        TEXT diseaseHistory2
        TEXT diseaseHistory3
        TEXT diseaseHistory4
        TEXT diseaseHistory5
        VARCHAR_5 asaScore
        VARCHAR_5 nyhaClass
        VARCHAR_5 mcCabeScore
        VARCHAR_5 knausScore
        VARCHAR_5 girScore
        VARCHAR_5 intermacsProfile
        TEXT intubationCriteria
        DATE surgeryDate
        TEXT materialLeftInPlace
        VARCHAR_200 removableProstheses
        VARCHAR_100 completedBy
        VARCHAR_20 idReferringPractitioner
    }
    Pathology {
        VARCHAR_20 idPathology PK
        VARCHAR_100 label UK
    }
    PlanDefinition {
        VARCHAR_20 idPlanDefinition PK
        VARCHAR_100 title
        VARCHAR_20 idPathology
        BOOLEAN active
    }
    PlanDefinitionAction {
        VARCHAR_20 idPlanDefinitionAction PK
        VARCHAR_20 idPlanDefinition
        VARCHAR_20 kind
        VARCHAR_20 idActivityDefinition
        VARCHAR_20 idMedication
        VARCHAR_50 dose
        VARCHAR_50 route
        VARCHAR_200 important
        VARCHAR_200 nurseInstructions
        VARCHAR_50 maxDose
        VARCHAR_50 dilutionVolume
        VARCHAR_200 solvent
        VARCHAR_50 administrationDuration
        VARCHAR_200 antibioticReason
        VARCHAR_200 germ
        VARCHAR_200 renalAdjustment
        VARCHAR_200 adjustmentCriterion
        VARCHAR_200 offGhsReason
        SMALLINT startOffsetHours
        SMALLINT durationHours
        SMALLINT frequencyHours
        VARCHAR_200 timeConstraint
    }
    CarePlan {
        VARCHAR_20 idCarePlan PK
        VARCHAR_20 idEpisodeOfCare
        VARCHAR_20 idPrescriber
        VARCHAR_20 mode
        DATETIME recordedAt
        VARCHAR_20 idValidator
        DATETIME validatedAt
        VARCHAR_20 idPlanDefinition
        TEXT note
    }
    ServiceRequest {
        VARCHAR_20 idServiceRequest PK
        VARCHAR_20 idCarePlan
        VARCHAR_20 idActivityDefinition
        DATETIME startDate
        DATETIME endDate
        SMALLINT frequencyHours
        VARCHAR_200 timeConstraint
        VARCHAR_20 idStoppedBy
        DATETIME stoppedAt
    }
    MedicationRequest {
        VARCHAR_20 idMedicationRequest PK
        VARCHAR_20 idCarePlan
        VARCHAR_20 idActivityDefinition
        VARCHAR_20 idMedication
        VARCHAR_50 dose
        VARCHAR_50 route
        VARCHAR_200 important
        VARCHAR_200 nurseInstructions
        VARCHAR_50 maxDose
        VARCHAR_50 dilutionVolume
        VARCHAR_200 solvent
        VARCHAR_50 administrationDuration
        VARCHAR_200 antibioticReason
        VARCHAR_200 germ
        VARCHAR_200 renalAdjustment
        VARCHAR_200 adjustmentCriterion
        VARCHAR_200 offGhsReason
        DATETIME startDate
        DATETIME endDate
        SMALLINT frequencyHours
        VARCHAR_200 timeConstraint
        VARCHAR_20 idStoppedBy
        DATETIME stoppedAt
    }
    Appointment {
        VARCHAR_20 idAppointment PK
        VARCHAR_20 idServiceRequest
        VARCHAR_20 idMedicationRequest
        DATETIME plannedTime
        VARCHAR_20 status
        DATETIME timeOfCompletion
        DATETIME timeRecorded
        VARCHAR_100 comment
    }
    AuditEvent {
        INT idAuditEvent PK
        VARCHAR_20 entity
        VARCHAR_20 entityId
        VARCHAR_50 field
        TEXT oldValue
        TEXT newValue
        VARCHAR_100 author
        VARCHAR_200 detail
        DATETIME recordedAt
    }
    CareTeam {
        INT idCareTeam PK
        VARCHAR_20 idHealthcareService
        DATE shiftDate
        VARCHAR_5 shift
        VARCHAR_30 name
        DATETIME removedAt
        TINYINT activeKey
    }
    CareTeamMember {
        INT idCareTeam PK
        VARCHAR_20 idPractitioner PK
    }
    CareTeamPatient {
        INT idCareTeam PK
        VARCHAR_20 idEpisodeOfCare PK
    }
    Substance ||--o{ Medication : "idSubstance"
    Patient ||--o{ AllergyIntolerance : "idPatient"
    Substance ||--o{ AllergyIntolerance : "idSubstance"
    PractitionerRole ||--o{ Practitioner : "idPractitionerRole"
    Patient ||--o{ EpisodeOfCare : "idPatient"
    HealthcareService ||--o{ EpisodeOfCare : "idHealthcareService"
    Practitioner ||--o{ EpisodeOfCare : "idReferringPractitioner"
    Pathology ||--o{ PlanDefinition : "idPathology"
    PlanDefinition ||--o{ PlanDefinitionAction : "idPlanDefinition"
    ActivityDefinition ||--o{ PlanDefinitionAction : "idActivityDefinition"
    Medication ||--o{ PlanDefinitionAction : "idMedication"
    EpisodeOfCare ||--o{ CarePlan : "idEpisodeOfCare"
    Practitioner ||--o{ CarePlan : "idPrescriber"
    Practitioner ||--o{ CarePlan : "idValidator"
    PlanDefinition ||--o{ CarePlan : "idPlanDefinition"
    CarePlan ||--o{ ServiceRequest : "idCarePlan"
    ActivityDefinition ||--o{ ServiceRequest : "idActivityDefinition"
    Practitioner ||--o{ ServiceRequest : "idStoppedBy"
    CarePlan ||--o{ MedicationRequest : "idCarePlan"
    ActivityDefinition ||--o{ MedicationRequest : "idActivityDefinition"
    Medication ||--o{ MedicationRequest : "idMedication"
    Practitioner ||--o{ MedicationRequest : "idStoppedBy"
    ServiceRequest ||--o{ Appointment : "idServiceRequest"
    MedicationRequest ||--o{ Appointment : "idMedicationRequest"
    HealthcareService ||--o{ CareTeam : "idHealthcareService"
    CareTeam ||--o{ CareTeamMember : "idCareTeam"
    Practitioner ||--o{ CareTeamMember : "idPractitioner"
    CareTeam ||--o{ CareTeamPatient : "idCareTeam"
    EpisodeOfCare ||--o{ CareTeamPatient : "idEpisodeOfCare"
```

Lecture : `EpisodeOfCare` = séjour (numéro `AAAA-NNNNNN` généré), `CarePlan` = ordonnance, `ServiceRequest` /
`MedicationRequest` = lignes de prescription (soin / médicament), `Appointment` = soin planifié (rendez-vous de soin),
`AllergyIntolerance` = allergie d'un patient à une `Substance` du référentiel, `Medication.idSubstance` = principe
actif d'un médicament (alerte allergie), `PlanDefinition` = protocole (groupe de prescriptions modèle) et
`PlanDefinitionAction` ses lignes, `Pathology` = référentiel des pathologies d'un protocole, `CareTeam` = équipe
d'une vacation avec ses soignants (`CareTeamMember`) et ses patients (`CareTeamPatient`), `AuditEvent` = journal des
modifications (sans clé étrangère). Les colonnes `activeKey` sont générées (unicité sur les seules lignes actives).
Régénérer le diagramme : parser les `CREATE TABLE` et `FOREIGN KEY` de `schema.sql` (version régénérée ainsi le
2026-11-04 ; les clés uniques sur plusieurs colonnes ne sont pas marquées `UK`).

## Décisions de conception retrouvées dans le dépôt

- **Identité et IPP** : nom et prénom obligatoires, IPP facultatif (RG1, patient admis en urgence). L'existence d'un
  patient est vérifiée dans la base locale d'AURA (par IPP, puis par nom / prénom, sélection explicite d'un résultat) ;
  **OpenEMR n'est pas connecté** (AURA-38 hors périmètre), le SI est autonome.
- **Numéro de séjour** `AAAA-NNNNNN` généré uniquement par `EpisodeOfCareDao::insert()` dans une transaction verrouillée,
  jamais saisi (AURA-10.3).
- **Nommage** : SQL camelCase anglais HL7 (diagramme de classes du dossier de cadrage) ; tout le code en anglais
  HL7 (décision du 2026-11-03, qui remplace celle du 2026-10-21) ; messages, URL, commentaires, commits en français.
- **Soin planifié = rendez-vous de soin** (`Appointment`), généré à l'enregistrement d'une prescription (AURA-24.4) ;
  le retard est un calcul d'affichage (`AgendaService::presentDay()`), pas une donnée. Chaque jour,
  `bin/generate-agenda.php` prolonge l'agenda jusqu'à J + 7 sans doublon (RG9).
- **Rotations et vacations (hypothèses)** : l'agenda découpe la journée en rotations qui commencent à 0 h, 6 h,
  14 h et 22 h (`AgendaService::SHIFTS`) ; un soin se valide s'il est en retard ou prévu avant la fin de la
  rotation en cours (`AgendaService::shiftEnd()`). Les équipes suivent des vacations de 8 h à 20 h et de 20 h à
  8 h (`CareTeamService::SHIFTS`). Les deux découpages sont à confirmer avec le service.
- **Validation médicale** (RG5) : une ordonnance est validée dans son ensemble par un praticien dont le rôle a
  `canValidate` ; seules les lignes validées et non arrêtées apparaissent au dossier et à l'agenda.
- **Suppression** : logique en principe (`removedAt`, voir README, « Conventions ») : `Patient`, `CareTeam`.
  `AllergyIntolerance` n'a pas de `removedAt` : le retrait d'une allergie est physique (dette tracée, décision du
  2026-10-21). Les lignes de prescription s'arrêtent (`stoppedAt`) et les soins s'annulent (`ANNULE`) : rien
  n'est supprimé.
- **Pas d'authentification** (AURA-50, authentification des praticiens ; AURA-18, praticiens et rôles) : l'édition en ligne trace « non identifié ». La modification d'une
  partie, la fin de séjour, le décès, l'enregistrement, la validation et l'arrêt d'une prescription, et les
  actions sur un soin passent par une **authentification factice** (praticien choisi dans une liste + mot de
  passe non vérifié) ; la trace porte le nom du praticien. Le groupe sanguin et le décès sont réservés au
  médecin : jamais par l'édition en ligne ; à l'admission d'un patient connu, un groupe sanguin déjà renseigné
  ne change pas. Jeton CSRF sur toutes les écritures sauf l'admission et la modification de l'identité.
- **Heure** : l'application fixe son fuseau à Europe/Paris (`public/index.php`), quel que soit `date.timezone`.
- **Formulaires** : validation côté serveur d'abord (`AdmissionService::validate()`, `FieldValidationService`),
  l'HTML (`required`, `min`…) n'est qu'un confort ; envoi AJAX par `form.js` avec repli sans JavaScript.
- **Champs complémentaires** : catalogue déclaratif `RecordFields` (champ = colonne = clé de domaine), liste blanche
  pour l'admission et l'édition en ligne ; échelles cliniques sourcées (`ClinicalScales`), formules sourcées
  (`DerivedMeasuresService`).
- **Schéma** : toute modification se fait dans `schema.sql` (base recréée) ; les fichiers `migrations/` sont
  additifs et rejouables pour les bases existantes.
