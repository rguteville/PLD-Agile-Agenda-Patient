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
 * Admission (RG1) sur la base du CI : un patient et un séjour créés ensemble ou pas du tout, numéro de séjour
 * unique, patient connu réutilisé (IPP ou sélection), refus qui protègent l'identité (décès, IPP d'une autre
 * personne, groupe sanguin connu). La vraie transaction est testée : pas de transaction englobante, les
 * lignes « Zzadmissionintegration » sont effacées avant et après chaque test. Ignoré sans DB_HOST/DB_NAME.
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

    public function testAdmissionCreatesExactlyOnePatientAndOneStay(): void
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

    /** Deux admissions successives produisent deux numéros de séjour différents. */
    public function testEachStayGetsItsOwnNumber(): void
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

    public function testRejectedStayLeavesNoOrphanPatient(): void
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
     * Réadmettre un patient déjà connu par son IPP ouvre un nouveau séjour sans
     * recréer le patient : la table Patient garde une seule ligne pour cet IPP.
     */
    public function testPatientKnownByIppIsReusedOnReadmission(): void
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
    public function testRecordFieldsAndAllergiesEnteredAtAdmissionAreSaved(): void
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

    /**
     * Recherche d'un patient existant (A′) : un séjour créé après sélection pointe vers le patient
     * existant sans doublon, le rattachement est tracé avec le mode de recherche, et la pré-saisie
     * modifiée met à jour la fiche existante champ par champ (trace par champ).
     */
    public function testStayIsLinkedToTheSelectedPatientAndTraced(): void
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

    /**
     * Retours du 2026-11-04 : le groupe sanguin déjà connu d'un patient sélectionné ne se change pas à
     * l'admission (refus, rien n'est écrit) ; inconnu, il peut être complété.
     */
    public function testKnownBloodTypeIsLockedAtAdmission(): void
    {
        $service = $this->fullService();
        $first = $service->admit(['lastName' => self::LAST_NAME, 'firstName' => 'Alice', 'bloodType' => 'A+']);
        (new EpisodeOfCareService(new EpisodeOfCareDao($this->pdo)))
            ->closeEpisodeOfCare((int) $first->getIdEpisodeOfCare());
        $idPatient = $first->getIdPatient();
        [$patientsBefore, $episodesBefore] = $this->countRows();

        try {
            $service->admit([
                'idPatient' => (string) $idPatient, 'searchMode' => 'name',
                'lastName' => self::LAST_NAME, 'firstName' => 'Alice', 'bloodType' => 'O-',
            ]);
            self::fail('Groupe sanguin connu modifié à l\'admission : refus attendu.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('groupe sanguin', $e->getMessage());
        }
        self::assertSame([$patientsBefore, $episodesBefore], $this->countRows(), 'rien n\'a été écrit');

        // Champ masqué à l'écran (envoyé vide) : le groupe connu est conservé, l'admission passe.
        $service->admit([
            'idPatient' => (string) $idPatient, 'searchMode' => 'name',
            'lastName' => self::LAST_NAME, 'firstName' => 'Alice', 'bloodType' => '',
        ]);
        $reread = (new PatientDao($this->pdo))->findById($idPatient);
        self::assertNotNull($reread);
        self::assertSame('A+', $reread->getDetail('bloodType'));
    }

    /** Relecture adversariale du 2026-11-04 : un patient déclaré décédé n'est pas réadmis (sélection ou IPP). */
    public function testDeceasedPatientIsNotReadmitted(): void
    {
        $service = $this->fullService();
        $ipp = '987654398';
        $first = $service->admit(['lastName' => self::LAST_NAME, 'firstName' => 'Dora', 'ipp' => $ipp]);
        (new EpisodeOfCareService(new EpisodeOfCareDao($this->pdo)))
            ->closeEpisodeOfCare((int) $first->getIdEpisodeOfCare());
        (new PatientDao($this->pdo))->updateDetails($first->getIdPatient(), ['deceased' => '1']);
        [$patientsBefore, $episodesBefore] = $this->countRows();

        foreach (
            [
                'sélection' => ['idPatient' => (string) $first->getIdPatient(), 'searchMode' => 'name'],
                'IPP saisi' => ['ipp' => $ipp],
            ] as $case => $how
        ) {
            try {
                $service->admit($how + ['lastName' => self::LAST_NAME, 'firstName' => 'Dora']);
                self::fail('Patient décédé réadmis (' . $case . ').');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('décédé', $e->getMessage(), $case);
            }
        }
        self::assertSame([$patientsBefore, $episodesBefore], $this->countRows(), 'rien n\'a été écrit');
    }

    /** Un IPP saisi qui appartient à une autre personne (nom ou naissance différents) est refusé. */
    public function testIppOfAnotherPersonIsRefused(): void
    {
        $service = $this->fullService();
        $ipp = '987654397';
        $first = $service->admit([
            'lastName' => self::LAST_NAME, 'firstName' => 'Eve', 'ipp' => $ipp, 'birthDate' => '1955-03-26',
        ]);
        (new EpisodeOfCareService(new EpisodeOfCareDao($this->pdo)))
            ->closeEpisodeOfCare((int) $first->getIdEpisodeOfCare());

        foreach (
            [
                'autre nom' => ['lastName' => self::LAST_NAME . 'x', 'birthDate' => '1955-03-26'],
                'autre naissance' => ['lastName' => self::LAST_NAME, 'birthDate' => '1990-05-05'],
            ] as $case => $identity
        ) {
            try {
                $service->admit($identity + ['firstName' => 'Bob', 'ipp' => $ipp]);
                self::fail('Séjour rattaché à un autre patient (' . $case . ').');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('autre patient', $e->getMessage(), $case);
            }
        }
        // Même nom (casse et accents ignorés), même naissance : c'est bien lui, la réadmission passe.
        $again = $service->admit([
            'lastName' => mb_strtoupper(self::LAST_NAME), 'firstName' => 'Eve', 'ipp' => $ipp,
            'birthDate' => '1955-03-26',
        ]);
        self::assertSame($first->getIdPatient(), $again->getIdPatient());
    }

    /** Patient sélectionné : IPP, naissance et sexe connus ne sont pas effacés par des champs envoyés vides. */
    public function testKnownIdentityIsNotErasedByEmptyFieldsAtAdmission(): void
    {
        $service = $this->fullService();
        $first = $service->admit([
            'lastName' => self::LAST_NAME, 'firstName' => 'Fred', 'ipp' => '987654396',
            'birthDate' => '1960-01-02', 'sex' => 'M',
        ]);
        (new EpisodeOfCareService(new EpisodeOfCareDao($this->pdo)))
            ->closeEpisodeOfCare((int) $first->getIdEpisodeOfCare());

        $service->admit([
            'idPatient' => (string) $first->getIdPatient(), 'searchMode' => 'name',
            'lastName' => self::LAST_NAME, 'firstName' => 'Fred', 'ipp' => '', 'birthDate' => '', 'sex' => '',
        ]);

        $reread = (new PatientDao($this->pdo))->findById($first->getIdPatient());
        self::assertSame('987654396', $reread?->getIpp());
        self::assertSame('1960-01-02', $reread?->getBirthDate()?->format('Y-m-d'));
        self::assertSame('M', $reread?->getSex());
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

    /** Retours du 2026-11-02 : un nouveau patient admis sans IPP reçoit un IPP unique, dans la plage autorisée. */
    public function testNewPatientWithoutIppGetsAUniqueIpp(): void
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
