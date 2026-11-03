<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Core\Database;
use Aura\Dao\AllergyIntoleranceDao;
use Aura\Dao\AuditEventDao;
use Aura\Dao\PatientDao;
use Aura\Dao\ReferenceDataDao;
use Aura\Dao\EpisodeOfCareDao;
use Aura\Dao\Transaction;
use Aura\Service\AdmissionService;
use Aura\Service\RecordEditService;
use Aura\Service\EpisodeOfCareService;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * AURA-10.4, définition de fini, sur la base MariaDB du CI (ignoré hors CI) :
 *  - une admission crée exactement une ligne Sejour et, pour un nouveau patient, une ligne Patient ;
 *  - une erreur à l'insertion du séjour ne laisse pas de Patient orphelin ;
 *  - AURA-10.3 : deux admissions successives donnent deux numéros de séjour différents ;
 *  - AURA-10.1 : taille, poids et précautions saisis sont relus identiques depuis la base.
 *
 * La transaction réelle est testée ici : pas de transaction englobante, les lignes
 * créées sont supprimées à la fin.
 */
final class AdmissionIntegrationTest extends TestCase
{
    private const LAST_NAME = 'Zzadmissionintegration';

    private PDO $pdo;

    protected function setUp(): void
    {
        $host = getenv('DB_HOST');
        $name = getenv('DB_NAME');
        if ($host === false || $host === '' || $name === false || $name === '') {
            $this->markTestSkipped('DB_HOST et DB_NAME non définis : test d\'intégration ignoré (hors CI).');
        }

        $this->pdo = Database::get();
        $this->clean();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->clean();
        }
    }

    public function testAdmissionCreatesPatientAndEpisode(): void
    {
        $service = new AdmissionService(
            null,
            new PatientDao($this->pdo),
            new EpisodeOfCareService(new EpisodeOfCareDao($this->pdo)),
            new Transaction($this->pdo)
        );
        [$patientsBefore, $episodesBefore] = $this->countRows();

        $episodeOfCare = $service->admit(['lastName' => self::LAST_NAME, 'firstName' => 'Alice', 'sex' => 'F']);

        [$patientsAfter, $episodesAfter] = $this->countRows();
        self::assertSame($patientsBefore + 1, $patientsAfter, 'exactement un patient créé');
        self::assertSame($episodesBefore + 1, $episodesAfter, 'exactement un séjour créé');
        self::assertGreaterThan(0, $episodeOfCare->getIdEpisodeOfCare());
        self::assertTrue($episodeOfCare->isOngoing());

        // Le dossier est lisible comme le fera EpisodeOfCareController après la redirection.
        $reread = (new EpisodeOfCareDao($this->pdo))->findById((int) $episodeOfCare->getIdEpisodeOfCare());
        self::assertNotNull($reread);
        self::assertSame($episodeOfCare->getEpisodeNumber(), $reread->getEpisodeNumber());

        // Rejouer la même admission : le patient (sans IPP) est recréé mais c'est bien
        // un second POST, pas un rafraîchissement ; la redirection POST → GET couvre ce cas.
    }

    /** AURA-10.1 : les mesures de l'admission sont relues identiques depuis la base. */
    public function testAdmissionMeasuresAreReread(): void
    {
        $service = new AdmissionService(
            null,
            new PatientDao($this->pdo),
            new EpisodeOfCareService(new EpisodeOfCareDao($this->pdo)),
            new Transaction($this->pdo)
        );
        $precautions = "Isolement contact (BMR)\nRisque de chute : barrières relevées";

        $episodeOfCare = $service->admit([
            'lastName' => self::LAST_NAME,
            'firstName' => 'Alice',
            'height' => '172.5',
            'weight' => '68.25',
            'precautions' => $precautions,
        ]);

        $reread = (new EpisodeOfCareDao($this->pdo))->findById((int) $episodeOfCare->getIdEpisodeOfCare());
        self::assertNotNull($reread);
        self::assertSame(172.5, $reread->getHeight());
        self::assertSame(68.25, $reread->getWeight());
        self::assertSame($precautions, $reread->getPrecautions(), 'accents et retour à la ligne conservés');

        // Sans mesures saisies, les colonnes restent NULL.
        $withoutMeasures = $service->admit(['lastName' => self::LAST_NAME, 'firstName' => 'Bob']);
        $reread = (new EpisodeOfCareDao($this->pdo))->findById((int) $withoutMeasures->getIdEpisodeOfCare());
        self::assertNotNull($reread);
        self::assertNull($reread->getHeight());
        self::assertNull($reread->getWeight());
        self::assertNull($reread->getPrecautions());
    }

    /** AURA-10.3 : deux admissions successives produisent deux numéros de séjour différents. */
    public function testTwoSuccessiveAdmissionsHaveDifferentNumbers(): void
    {
        $service = new AdmissionService(
            null,
            new PatientDao($this->pdo),
            new EpisodeOfCareService(new EpisodeOfCareDao($this->pdo)),
            new Transaction($this->pdo)
        );

        $first = $service->admit(['lastName' => self::LAST_NAME, 'firstName' => 'Alice']);
        $second = $service->admit(['lastName' => self::LAST_NAME, 'firstName' => 'Bob']);

        self::assertMatchesRegularExpression('/^\d{4}-\d{6}$/', $first->getEpisodeNumber(), 'format AAAA-NNNNNN');
        self::assertMatchesRegularExpression('/^\d{4}-\d{6}$/', $second->getEpisodeNumber(), 'format AAAA-NNNNNN');
        self::assertNotSame($first->getEpisodeNumber(), $second->getEpisodeNumber());
    }

    public function testRejectedEpisodeLeavesNoOrphanPatient(): void
    {
        $episodesOfCare = $this->createMock(EpisodeOfCareService::class);
        $episodesOfCare->method('openEpisodeOfCare')
            ->willThrowException(new InvalidArgumentException('Le lit L1 est déjà occupé'));

        $service = new AdmissionService(
            null,
            new PatientDao($this->pdo),
            $episodesOfCare,
            new Transaction($this->pdo)
        );
        [$patientsBefore, $episodesBefore] = $this->countRows();

        try {
            $service->admit(['lastName' => self::LAST_NAME, 'firstName' => 'Bob']);
            self::fail('L\'admission aurait dû être refusée.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('déjà occupé', $e->getMessage());
        }

        self::assertSame([$patientsBefore, $episodesBefore], $this->countRows(), 'rien n\'a été écrit');
        self::assertFalse($this->pdo->inTransaction(), 'la transaction est bien terminée');
    }

    /**
     * AURA-15 : réadmettre un patient déjà connu par son IPP ouvre un nouveau séjour sans
     * recréer le patient : la table Patient garde une seule ligne pour cet IPP.
     */
    public function testPatientKnownByIppIsNotRecreatedOnReadmission(): void
    {
        $episodeOfCareService = new EpisodeOfCareService(new EpisodeOfCareDao($this->pdo));
        $service = new AdmissionService(
            null,
            new PatientDao($this->pdo),
            $episodeOfCareService,
            new Transaction($this->pdo)
        );
        $ipp = '987654315';

        $first = $service->admit(['lastName' => self::LAST_NAME, 'firstName' => 'Alice', 'ipp' => $ipp, 'sex' => 'F']);
        // Un patient n'a qu'un séjour en cours à la fois : le premier est clos avant la réadmission.
        $episodeOfCareService->closeEpisodeOfCare((int) $first->getIdEpisodeOfCare());
        [$patientsBefore, $episodesBefore] = $this->countRows();

        // Identité volontairement différente : c'est l'IPP qui fait foi, pas les champs saisis.
        $second = $service->admit(['lastName' => self::LAST_NAME, 'firstName' => 'Autre', 'ipp' => $ipp]);

        [$patientsAfter, $episodesAfter] = $this->countRows();
        self::assertSame($patientsBefore, $patientsAfter, 'aucun doublon de Patient');
        self::assertSame($episodesBefore + 1, $episodesAfter, 'exactement un séjour de plus');
        self::assertSame($first->getIdPatient(), $second->getIdPatient(), 'même patient');
        self::assertNotSame($first->getEpisodeNumber(), $second->getEpisodeNumber());

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM Patient WHERE ipp = :ipp');
        $stmt->execute(['ipp' => $ipp]);
        self::assertSame(1, (int) $stmt->fetchColumn(), 'une seule ligne Patient pour cet IPP');
        self::assertSame('Alice', (new PatientDao($this->pdo))->findByIpp($ipp)?->getFirstName(), 'identité inchangée');
    }

    /**
     * Champs complémentaires : antécédents (patient), histoire de la maladie, scores, chirurgie (séjour)
     * et allergies du référentiel sont relus identiques depuis la base après l'admission.
     */
    public function testAdditionalFieldsAndAllergiesAreReread(): void
    {
        $service = new AdmissionService(
            null,
            new PatientDao($this->pdo),
            new EpisodeOfCareService(new EpisodeOfCareDao($this->pdo)),
            new Transaction($this->pdo),
            new AllergyIntoleranceDao($this->pdo)
        );
        $substances = (new \Aura\Dao\SubstanceDao($this->pdo))->findAll();
        self::assertNotEmpty($substances, 'le référentiel des allergènes doit être chargé (test.sql)');
        $idSubstance = $substances[0]->getIdSubstance();

        $episodeOfCare = $service->admit([
            'lastName' => self::LAST_NAME,
            'firstName' => 'Alice',
            'sex' => 'F',
            'medicalHistory' => "HTA\nDiabète de type 2",
            'trustedPersonName' => 'Jeanne Zzconfiance',
            'admissionReason' => 'Choc septique',
            'diseaseHistory1' => 'Fièvre depuis 3 jours',
            'asaScore' => 'III',
            'girScore' => '4',
            'surgeryDate' => '2026-10-12',
            'completedBy' => 'Dr Zztest',
            'allergies' => [$idSubstance],
            'allergyCriticalities' => [$idSubstance => 'Élevée'],
        ]);

        $rereadEpisodeOfCare = (new EpisodeOfCareDao($this->pdo))->findById((int) $episodeOfCare->getIdEpisodeOfCare());
        self::assertNotNull($rereadEpisodeOfCare);
        self::assertSame('Choc septique', $rereadEpisodeOfCare->getDetail('admissionReason'));
        self::assertSame('Fièvre depuis 3 jours', $rereadEpisodeOfCare->getDetail('diseaseHistory1'));
        self::assertSame('III', $rereadEpisodeOfCare->getDetail('asaScore'));
        self::assertSame('4', $rereadEpisodeOfCare->getDetail('girScore'));
        self::assertSame('2026-10-12', $rereadEpisodeOfCare->getDetail('surgeryDate'));
        self::assertSame('Dr Zztest', $rereadEpisodeOfCare->getDetail('completedBy'));
        self::assertNull($rereadEpisodeOfCare->getDetail('diseaseHistory2'), 'un champ non saisi reste NULL');

        $rereadPatient = (new PatientDao($this->pdo))->findById($episodeOfCare->getIdPatient());
        self::assertNotNull($rereadPatient);
        self::assertSame("HTA\nDiabète de type 2", $rereadPatient->getDetail('medicalHistory'));
        self::assertSame('Jeanne Zzconfiance', $rereadPatient->getDetail('trustedPersonName'));

        $allergies = (new AllergyIntoleranceDao($this->pdo))->findByPatient($episodeOfCare->getIdPatient());
        self::assertCount(1, $allergies);
        self::assertSame($idSubstance, $allergies[0]->getIdSubstance());
        self::assertSame('Élevée', $allergies[0]->getCriticality(), 'criticité choisie à l\'admission enregistrée');
    }

    /** Un patient connu par son IPP : les champs saisis complètent son dossier, les vides ne l'effacent pas. */
    public function testReadmissionCompletesRecordWithoutErasingExisting(): void
    {
        $episodeOfCareService = new EpisodeOfCareService(new EpisodeOfCareDao($this->pdo));
        $service = new AdmissionService(
            null,
            new PatientDao($this->pdo),
            $episodeOfCareService,
            new Transaction($this->pdo)
        );
        $ipp = '987654316';

        $first = $service->admit([
            'lastName' => self::LAST_NAME, 'firstName' => 'Alice', 'ipp' => $ipp,
            'medicalHistory' => 'HTA', 'lifestyle' => 'Non fumeur',
        ]);
        $episodeOfCareService->closeEpisodeOfCare((int) $first->getIdEpisodeOfCare());

        $service->admit([
            'lastName' => self::LAST_NAME, 'firstName' => 'Alice', 'ipp' => $ipp,
            'medicalHistory' => 'HTA, diabète', 'lifestyle' => '',
        ]);

        $reread = (new PatientDao($this->pdo))->findByIpp($ipp);
        self::assertNotNull($reread);
        self::assertSame('HTA, diabète', $reread->getDetail('medicalHistory'), 'champ saisi : mis à jour');
        self::assertSame('Non fumeur', $reread->getDetail('lifestyle'), 'champ vide : conservé');
    }

    /**
     * Recherche d'un patient existant (A′) : un séjour créé après sélection pointe vers le patient
     * existant sans doublon, le rattachement est tracé avec le mode de recherche, et la pré-saisie
     * modifiée met à jour la fiche existante champ par champ (trace par champ).
     */
    public function testEpisodeIsLinkedToSelectedPatientAndTraced(): void
    {
        $service = $this->fullService();
        $first = $service->admit([
            'lastName' => self::LAST_NAME, 'firstName' => 'Alice', 'sex' => 'F', 'birthDate' => '1970-01-01',
            'medicalHistory' => 'HTA', 'lifestyle' => 'Non fumeur',
        ]);
        (new EpisodeOfCareService(new EpisodeOfCareDao($this->pdo)))
            ->closeEpisodeOfCare((int) $first->getIdEpisodeOfCare());
        $idPatient = $first->getIdPatient();
        [$patientsBefore, $episodesBefore] = $this->countRows();

        // Formulaire pré-rempli depuis la fiche, puis modifié : prénom corrigé, ATCD complété, mode de vie vidé.
        $second = $service->admit([
            'idPatient' => (string) $idPatient, 'searchMode' => 'name',
            'lastName' => self::LAST_NAME, 'firstName' => 'Alice-Marie', 'sex' => 'F', 'birthDate' => '1970-01-01',
            'medicalHistory' => 'HTA, diabète', 'lifestyle' => '',
        ]);

        [$patientsAfter, $episodesAfter] = $this->countRows();
        self::assertSame($patientsBefore, $patientsAfter, 'aucun doublon de Patient');
        self::assertSame($episodesBefore + 1, $episodesAfter);
        self::assertSame($idPatient, $second->getIdPatient(), 'séjour rattaché au patient sélectionné');

        $reread = (new PatientDao($this->pdo))->findById($idPatient);
        self::assertNotNull($reread);
        self::assertSame('Alice-Marie', $reread->getFirstName(), 'identité corrigée');
        self::assertSame('HTA, diabète', $reread->getDetail('medicalHistory'));
        self::assertNull($reread->getDetail('lifestyle'), 'un champ vidé après pré-remplissage est effacé (tracé)');

        $trace = (new AuditEventDao($this->pdo))->findForRecord($idPatient, (int) $second->getIdEpisodeOfCare(), 50);
        $byField = [];
        foreach ($trace as $event) {
            $byField[$event->getEntity() . '.' . $event->getField()] = $event;
        }
        self::assertArrayHasKey('EpisodeOfCare.idPatient', $byField, 'rattachement tracé');
        self::assertSame((string) $idPatient, $byField['EpisodeOfCare.idPatient']->getNewValue());
        self::assertSame('rattachement après recherche par nom', $byField['EpisodeOfCare.idPatient']->getDetail());
        self::assertSame('non identifié', $byField['EpisodeOfCare.idPatient']->getAuthor());
        self::assertSame('Alice', $byField['Patient.firstName']->getOldValue());
        self::assertSame('Alice-Marie', $byField['Patient.firstName']->getNewValue());
        self::assertSame('HTA', $byField['Patient.medicalHistory']->getOldValue());
        self::assertSame('Non fumeur', $byField['Patient.lifestyle']->getOldValue());
        self::assertNull($byField['Patient.lifestyle']->getNewValue());
        self::assertArrayNotHasKey('Patient.lastName', $byField, 'un champ inchangé n\'est pas tracé');
    }

    public function testNonexistentSelectedPatientIsRejectedWithoutWriting(): void
    {
        $service = $this->fullService();
        [$patientsBefore, $episodesBefore] = $this->countRows();

        try {
            $service->admit(['idPatient' => '999999999', 'lastName' => self::LAST_NAME, 'firstName' => 'Bob']);
            self::fail('Le patient sélectionné n\'existe pas : l\'admission aurait dû être refusée.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('introuvable', $e->getMessage());
        }

        self::assertSame([$patientsBefore, $episodesBefore], $this->countRows(), 'rien n\'a été écrit');
    }

    public function testReadmissionByIppIsTracedAsLink(): void
    {
        $service = $this->fullService();
        $ipp = '987654317';
        $first = $service->admit(['lastName' => self::LAST_NAME, 'firstName' => 'Alice', 'ipp' => $ipp]);
        (new EpisodeOfCareService(new EpisodeOfCareDao($this->pdo)))
            ->closeEpisodeOfCare((int) $first->getIdEpisodeOfCare());

        $second = $service->admit(['lastName' => self::LAST_NAME, 'firstName' => 'Alice', 'ipp' => $ipp]);

        $trace = (new AuditEventDao($this->pdo))
            ->findForRecord($first->getIdPatient(), (int) $second->getIdEpisodeOfCare());
        self::assertCount(1, $trace);
        self::assertSame('rattachement par IPP saisi', $trace[0]->getDetail());
    }

    /** Service d'admission tel que câblé dans public/index.php (allergies, trace, édition tracée). */
    private function fullService(): AdmissionService
    {
        return new AdmissionService(
            null,
            new PatientDao($this->pdo),
            new EpisodeOfCareService(new EpisodeOfCareDao($this->pdo)),
            new Transaction($this->pdo),
            new AllergyIntoleranceDao($this->pdo),
            new AuditEventDao($this->pdo),
            new RecordEditService(
                new PatientDao($this->pdo),
                new EpisodeOfCareDao($this->pdo),
                new AuditEventDao($this->pdo),
                new Transaction($this->pdo),
                new ReferenceDataDao($this->pdo)
            )
        );
    }

    /** Une valeur refusée sur un champ du patient sélectionné annule toute l'admission (rien n'est écrit). */
    public function testRejectedValueOnSelectedPatientCancelsAdmission(): void
    {
        $service = $this->fullService();
        $first = $service->admit([
            'lastName' => self::LAST_NAME, 'firstName' => 'Alice', 'trustedPersonEmail' => 'a@example.com',
        ]);
        (new EpisodeOfCareService(new EpisodeOfCareDao($this->pdo)))
            ->closeEpisodeOfCare((int) $first->getIdEpisodeOfCare());
        [$patientsBefore, $episodesBefore] = $this->countRows();

        // Le contrôle d'admission accepte la clé idPatient ; la validation du champ est celle de RecordEditService.
        try {
            $service->admit([
                'idPatient' => (string) $first->getIdPatient(), 'lastName' => self::LAST_NAME, 'firstName' => 'Alice',
                'trustedPersonEmail' => 'pas-un-mail',
            ]);
            self::fail('Une adresse invalide aurait dû être refusée.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('e-mail', $e->getMessage());
        }

        self::assertSame([$patientsBefore, $episodesBefore], $this->countRows(), 'aucun séjour créé');
        self::assertSame(
            'a@example.com',
            (new PatientDao($this->pdo))->findById($first->getIdPatient())?->getDetail('trustedPersonEmail')
        );
    }

    /** Retours du 2026-11-02 : un nouveau patient admis sans IPP reçoit un IPP unique, dans la plage autorisée. */
    public function testNewPatientWithoutIppGetsAutomaticIpp(): void
    {
        $service = $this->fullService();

        $first = $service->admit(['lastName' => self::LAST_NAME, 'firstName' => 'Alice']);
        $second = $service->admit(['lastName' => self::LAST_NAME, 'firstName' => 'Bob']);

        $dao = new PatientDao($this->pdo);
        $ippA = $dao->findById($first->getIdPatient())?->getIpp();
        $ippB = $dao->findById($second->getIdPatient())?->getIpp();
        self::assertNotNull($ippA);
        self::assertNotNull($ippB);
        self::assertMatchesRegularExpression('/^\d{6,9}$/', $ippA);
        self::assertNotSame($ippA, $ippB, 'deux admissions, deux IPP');
        self::assertGreaterThanOrEqual(PatientDao::IPP_MIN, (int) $ippA);
    }

    /** Un IPP saisi (patient connu de l'hôpital, nouveau dans le service) est conservé tel quel. */
    public function testEnteredIppIsKept(): void
    {
        $episodeOfCare = $this->fullService()->admit(
            ['lastName' => self::LAST_NAME, 'firstName' => 'Alice', 'ipp' => '987654318']
        );

        self::assertSame(
            '987654318',
            (new PatientDao($this->pdo))->findById($episodeOfCare->getIdPatient())?->getIpp()
        );
    }

    /**
     * Patient retrouvé : la criticité d'une allergie déjà connue, changée à l'admission, est appliquée et
     * tracée ; laissée vide, elle ne remplace pas la criticité connue.
     */
    public function testKnownAllergyCriticalityChangedAtAdmissionIsAppliedAndTraced(): void
    {
        $service = $this->fullService();
        $substances = (new \Aura\Dao\SubstanceDao($this->pdo))->findAll();
        [$a, $b] = [$substances[0]->getIdSubstance(), $substances[1]->getIdSubstance()];

        $first = $service->admit([
            'lastName' => self::LAST_NAME, 'firstName' => 'Alice', 'allergies' => [$a, $b],
            'allergyCriticalities' => [$a => 'Faible', $b => 'Élevée'],
        ]);
        (new EpisodeOfCareService(new EpisodeOfCareDao($this->pdo)))
            ->closeEpisodeOfCare((int) $first->getIdEpisodeOfCare());
        $idPatient = $first->getIdPatient();

        $service->admit([
            'idPatient' => (string) $idPatient, 'searchMode' => 'name',
            'lastName' => self::LAST_NAME, 'firstName' => 'Alice',
            'allergies' => [$a, $b], 'allergyCriticalities' => [$a => 'Élevée', $b => ''],
        ]);

        $criticalities = [];
        foreach ((new AllergyIntoleranceDao($this->pdo))->findByPatient($idPatient) as $allergy) {
            $criticalities[$allergy->getIdSubstance()] = $allergy->getCriticality();
        }
        self::assertSame('Élevée', $criticalities[$a], 'criticité changée appliquée');
        self::assertSame('Élevée', $criticalities[$b], 'criticité laissée vide : la valeur connue est gardée');

        $traces = array_filter(
            (new AuditEventDao($this->pdo))->findForRecord($idPatient, 0, 50),
            static fn ($e): bool => $e->getField() === 'allergyCriticality'
        );
        self::assertCount(1, $traces);
        self::assertStringEndsWith(': Élevée', (string) array_values($traces)[0]->getNewValue());
    }

    /** L'IPP attribué automatiquement est tracé comme venant d'AURA. */
    public function testAutomaticallyAssignedIppIsTraced(): void
    {
        $episodeOfCare = $this->fullService()->admit(['lastName' => self::LAST_NAME, 'firstName' => 'Alice']);

        $traces = array_values(array_filter(
            (new AuditEventDao($this->pdo))->findForRecord($episodeOfCare->getIdPatient(), 0, 50),
            static fn ($e): bool => $e->getField() === 'ipp'
        ));
        self::assertCount(1, $traces);
        self::assertStringContainsString('attribué automatiquement', (string) $traces[0]->getDetail());
    }

    /** @return array{int, int} */
    private function countRows(): array
    {
        return [
            (int) $this->pdo->query('SELECT COUNT(*) FROM Patient')->fetchColumn(),
            (int) $this->pdo->query('SELECT COUNT(*) FROM EpisodeOfCare')->fetchColumn(),
        ];
    }

    private function clean(): void
    {
        $this->pdo->prepare(
            'DELETE FROM AuditEvent WHERE (entity = \'Patient\' AND entityId IN
                (SELECT idPatient FROM Patient WHERE lastName = :lastName))
             OR (entity = \'EpisodeOfCare\' AND entityId IN (SELECT idEpisodeOfCare FROM EpisodeOfCare
                WHERE idPatient IN (SELECT idPatient FROM Patient WHERE lastName = :lastName2)))'
        )->execute(['lastName' => self::LAST_NAME, 'lastName2' => self::LAST_NAME]);
        $this->pdo->prepare(
            'DELETE FROM AllergyIntolerance WHERE idPatient IN '
            . '(SELECT idPatient FROM Patient WHERE lastName = :lastName)'
        )->execute(['lastName' => self::LAST_NAME]);
        $this->pdo->prepare(
            'DELETE FROM EpisodeOfCare WHERE idPatient IN (SELECT idPatient FROM Patient WHERE lastName = :lastName)'
        )->execute(['lastName' => self::LAST_NAME]);
        $this->pdo->prepare('DELETE FROM Patient WHERE lastName = :lastName')->execute(['lastName' => self::LAST_NAME]);
    }
}
