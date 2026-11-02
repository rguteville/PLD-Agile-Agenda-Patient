<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Core\Database;
use Aura\Dao\AllergieDao;
use Aura\Dao\PatientDao;
use Aura\Dao\SejourDao;
use Aura\Dao\Transaction;
use Aura\Service\AdmissionService;
use Aura\Service\SejourService;
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
    private const NOM = 'Zzadmissionintegration';

    private PDO $pdo;

    protected function setUp(): void
    {
        $host = getenv('DB_HOST');
        $name = getenv('DB_NAME');
        if ($host === false || $host === '' || $name === false || $name === '') {
            $this->markTestSkipped('DB_HOST et DB_NAME non définis : test d\'intégration ignoré (hors CI).');
        }

        $this->pdo = Database::get();
        $this->nettoyer();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->nettoyer();
        }
    }

    public function testUneAdmissionCreeUnPatientEtUnSejour(): void
    {
        $service = new AdmissionService(
            null,
            new PatientDao($this->pdo),
            new SejourService(new SejourDao($this->pdo)),
            new Transaction($this->pdo)
        );
        [$patientsAvant, $sejoursAvant] = $this->compter();

        $sejour = $service->admettre(['nom' => self::NOM, 'prenom' => 'Alice', 'sexe' => 'F']);

        [$patientsApres, $sejoursApres] = $this->compter();
        self::assertSame($patientsAvant + 1, $patientsApres, 'exactement un patient créé');
        self::assertSame($sejoursAvant + 1, $sejoursApres, 'exactement un séjour créé');
        self::assertGreaterThan(0, $sejour->getIdSejour());
        self::assertTrue($sejour->estEnCours());

        // Le dossier est lisible comme le fera SejourController après la redirection.
        $relu = (new SejourDao($this->pdo))->findById((int) $sejour->getIdSejour());
        self::assertNotNull($relu);
        self::assertSame($sejour->getNumSejour(), $relu->getNumSejour());

        // Rejouer la même admission : le patient (sans IPP) est recréé mais c'est bien
        // un second POST, pas un rafraîchissement ; la redirection POST → GET couvre ce cas.
    }

    /** AURA-10.1 : les mesures de l'admission sont relues identiques depuis la base. */
    public function testLesMesuresDAdmissionSontRelues(): void
    {
        $service = new AdmissionService(
            null,
            new PatientDao($this->pdo),
            new SejourService(new SejourDao($this->pdo)),
            new Transaction($this->pdo)
        );
        $precautions = "Isolement contact (BMR)\nRisque de chute : barrières relevées";

        $sejour = $service->admettre([
            'nom' => self::NOM,
            'prenom' => 'Alice',
            'taille' => '172.5',
            'poids' => '68.25',
            'precautions' => $precautions,
        ]);

        $relu = (new SejourDao($this->pdo))->findById((int) $sejour->getIdSejour());
        self::assertNotNull($relu);
        self::assertSame(172.5, $relu->getTaille());
        self::assertSame(68.25, $relu->getPoids());
        self::assertSame($precautions, $relu->getPrecautions(), 'accents et retour à la ligne conservés');

        // Sans mesures saisies, les colonnes restent NULL.
        $sansMesures = $service->admettre(['nom' => self::NOM, 'prenom' => 'Bob']);
        $relu = (new SejourDao($this->pdo))->findById((int) $sansMesures->getIdSejour());
        self::assertNotNull($relu);
        self::assertNull($relu->getTaille());
        self::assertNull($relu->getPoids());
        self::assertNull($relu->getPrecautions());
    }

    /** AURA-10.3 : deux admissions successives produisent deux numéros de séjour différents. */
    public function testDeuxAdmissionsSuccessivesOntDeuxNumerosDifferents(): void
    {
        $service = new AdmissionService(
            null,
            new PatientDao($this->pdo),
            new SejourService(new SejourDao($this->pdo)),
            new Transaction($this->pdo)
        );

        $premier = $service->admettre(['nom' => self::NOM, 'prenom' => 'Alice']);
        $second = $service->admettre(['nom' => self::NOM, 'prenom' => 'Bob']);

        self::assertMatchesRegularExpression('/^\d{4}-\d{6}$/', $premier->getNumSejour(), 'format AAAA-NNNNNN');
        self::assertMatchesRegularExpression('/^\d{4}-\d{6}$/', $second->getNumSejour(), 'format AAAA-NNNNNN');
        self::assertNotSame($premier->getNumSejour(), $second->getNumSejour());
    }

    public function testUnSejourRefuseNeLaissePasDePatientOrphelin(): void
    {
        $sejours = $this->createMock(SejourService::class);
        $sejours->method('ouvrirSejour')->willThrowException(new InvalidArgumentException('Le lit L1 est déjà occupé'));

        $service = new AdmissionService(
            null,
            new PatientDao($this->pdo),
            $sejours,
            new Transaction($this->pdo)
        );
        [$patientsAvant, $sejoursAvant] = $this->compter();

        try {
            $service->admettre(['nom' => self::NOM, 'prenom' => 'Bob']);
            self::fail('L\'admission aurait dû être refusée.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('déjà occupé', $e->getMessage());
        }

        self::assertSame([$patientsAvant, $sejoursAvant], $this->compter(), 'rien n\'a été écrit');
        self::assertFalse($this->pdo->inTransaction(), 'la transaction est bien terminée');
    }

    /**
     * AURA-15 : réadmettre un patient déjà connu par son IPP ouvre un nouveau séjour sans
     * recréer le patient : la table Patient garde une seule ligne pour cet IPP.
     */
    public function testUnPatientDejaConnuParSonIppNEstPasRecreeALaReadmission(): void
    {
        $sejourService = new SejourService(new SejourDao($this->pdo));
        $service = new AdmissionService(null, new PatientDao($this->pdo), $sejourService, new Transaction($this->pdo));
        $ipp = '987654315';

        $premier = $service->admettre(['nom' => self::NOM, 'prenom' => 'Alice', 'ipp' => $ipp, 'sexe' => 'F']);
        // Un patient n'a qu'un séjour en cours à la fois : le premier est clos avant la réadmission.
        $sejourService->cloturerSejour((int) $premier->getIdSejour());
        [$patientsAvant, $sejoursAvant] = $this->compter();

        // Identité volontairement différente : c'est l'IPP qui fait foi, pas les champs saisis.
        $second = $service->admettre(['nom' => self::NOM, 'prenom' => 'Autre', 'ipp' => $ipp]);

        [$patientsApres, $sejoursApres] = $this->compter();
        self::assertSame($patientsAvant, $patientsApres, 'aucun doublon de Patient');
        self::assertSame($sejoursAvant + 1, $sejoursApres, 'exactement un séjour de plus');
        self::assertSame($premier->getIdPatient(), $second->getIdPatient(), 'même patient');
        self::assertNotSame($premier->getNumSejour(), $second->getNumSejour());

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM Patient WHERE ipp = :ipp');
        $stmt->execute(['ipp' => $ipp]);
        self::assertSame(1, (int) $stmt->fetchColumn(), 'une seule ligne Patient pour cet IPP');
        self::assertSame('Alice', (new PatientDao($this->pdo))->findByIpp($ipp)?->getPrenom(), 'identité inchangée');
    }

    /**
     * Champs complémentaires : antécédents (patient), histoire de la maladie, scores, chirurgie (séjour)
     * et allergies du référentiel sont relus identiques depuis la base après l'admission.
     */
    public function testLesChampsComplementairesEtLesAllergiesSontRelus(): void
    {
        $service = new AdmissionService(
            null,
            new PatientDao($this->pdo),
            new SejourService(new SejourDao($this->pdo)),
            new Transaction($this->pdo),
            new AllergieDao($this->pdo)
        );
        $substances = (new \Aura\Dao\SubstanceDao($this->pdo))->findAll();
        self::assertNotEmpty($substances, 'le référentiel des allergènes doit être chargé (test.sql)');
        $idSubstance = $substances[0]->getIdSubstance();

        $sejour = $service->admettre([
            'nom' => self::NOM,
            'prenom' => 'Alice',
            'sexe' => 'F',
            'medicalHistory' => "HTA\nDiabète de type 2",
            'trustedPersonName' => 'Jeanne Zzconfiance',
            'admissionReason' => 'Choc septique',
            'diseaseHistory1' => 'Fièvre depuis 3 jours',
            'asaScore' => 'III',
            'girScore' => '4',
            'surgeryDate' => '2026-10-12',
            'completedBy' => 'Dr Zztest',
            'allergies' => [$idSubstance],
        ]);

        $reluSejour = (new SejourDao($this->pdo))->findById((int) $sejour->getIdSejour());
        self::assertNotNull($reluSejour);
        self::assertSame('Choc septique', $reluSejour->getDetail('admissionReason'));
        self::assertSame('Fièvre depuis 3 jours', $reluSejour->getDetail('diseaseHistory1'));
        self::assertSame('III', $reluSejour->getDetail('asaScore'));
        self::assertSame('4', $reluSejour->getDetail('girScore'));
        self::assertSame('2026-10-12', $reluSejour->getDetail('surgeryDate'));
        self::assertSame('Dr Zztest', $reluSejour->getDetail('completedBy'));
        self::assertNull($reluSejour->getDetail('diseaseHistory2'), 'un champ non saisi reste NULL');

        $reluPatient = (new PatientDao($this->pdo))->findById($sejour->getIdPatient());
        self::assertNotNull($reluPatient);
        self::assertSame("HTA\nDiabète de type 2", $reluPatient->getDetail('medicalHistory'));
        self::assertSame('Jeanne Zzconfiance', $reluPatient->getDetail('trustedPersonName'));

        $allergies = (new AllergieDao($this->pdo))->findByPatient($sejour->getIdPatient());
        self::assertCount(1, $allergies);
        self::assertSame($idSubstance, $allergies[0]->getIdAllergene());
    }

    /** Un patient connu par son IPP : les champs saisis complètent son dossier, les vides ne l'effacent pas. */
    public function testLaReadmissionCompleteLeDossierSansEffacerLExistant(): void
    {
        $sejourService = new SejourService(new SejourDao($this->pdo));
        $service = new AdmissionService(null, new PatientDao($this->pdo), $sejourService, new Transaction($this->pdo));
        $ipp = '987654316';

        $premier = $service->admettre([
            'nom' => self::NOM, 'prenom' => 'Alice', 'ipp' => $ipp,
            'medicalHistory' => 'HTA', 'lifestyle' => 'Non fumeur',
        ]);
        $sejourService->cloturerSejour((int) $premier->getIdSejour());

        $service->admettre([
            'nom' => self::NOM, 'prenom' => 'Alice', 'ipp' => $ipp,
            'medicalHistory' => 'HTA, diabète', 'lifestyle' => '',
        ]);

        $relu = (new PatientDao($this->pdo))->findByIpp($ipp);
        self::assertNotNull($relu);
        self::assertSame('HTA, diabète', $relu->getDetail('medicalHistory'), 'champ saisi : mis à jour');
        self::assertSame('Non fumeur', $relu->getDetail('lifestyle'), 'champ vide : conservé');
    }

    /** @return array{int, int} */
    private function compter(): array
    {
        return [
            (int) $this->pdo->query('SELECT COUNT(*) FROM Patient')->fetchColumn(),
            (int) $this->pdo->query('SELECT COUNT(*) FROM EpisodeOfCare')->fetchColumn(),
        ];
    }

    private function nettoyer(): void
    {
        $this->pdo->prepare(
            'DELETE FROM AllergyIntolerance WHERE idPatient IN (SELECT idPatient FROM Patient WHERE lastName = :nom)'
        )->execute(['nom' => self::NOM]);
        $this->pdo->prepare(
            'DELETE FROM EpisodeOfCare WHERE idPatient IN (SELECT idPatient FROM Patient WHERE lastName = :nom)'
        )->execute(['nom' => self::NOM]);
        $this->pdo->prepare('DELETE FROM Patient WHERE lastName = :nom')->execute(['nom' => self::NOM]);
    }
}
