<?php

declare(strict_types=1);

namespace Aura\Tests\Dao;

use Aura\Core\Database;
use Aura\Dao\ReferenceDataDao;
use Aura\Domain\Practitioner;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Référentiels des listes déroulantes (médicaments, services, types de soin, praticiens, médecins)
 * sur la base du CI. Les valeurs attendues viennent de database/test.sql ; les cas limites (forme
 * vide, praticien sans rôle, homonymes) sont des lignes « Zz… » insérées dans une transaction
 * annulée en tearDown. Ignoré sans DB_HOST/DB_NAME.
 *
 * Tri : la base est en utf8mb4_unicode_ci, « é » se range avec « e » (Céfotaxime avant Chlorure).
 */
final class ReferenceDataDaoTest extends TestCase
{
    private PDO $pdo;
    private ReferenceDataDao $dao;

    protected function setUp(): void
    {
        $host = getenv('DB_HOST');
        $name = getenv('DB_NAME');
        if ($host === false || $host === '' || $name === false || $name === '') {
            $this->markTestSkipped('DB_HOST et DB_NAME non définis : test d\'intégration ignoré (hors CI).');
        }

        $this->pdo = Database::get();
        $this->pdo->beginTransaction();
        $this->dao = new ReferenceDataDao($this->pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testMedicationsAreSortedByInnWithFormInParentheses(): void
    {
        self::assertSame(
            [
                'MED08' => 'Altéplase (Poudre et solvant pour solution injectable)',
                'MED04' => 'Amoxicilline (Poudre pour solution injectable)',
                'MED06' => 'Céfotaxime (Poudre pour solution injectable)',
                'MED07' => 'Chlorure de sodium 0,9 % (Solution pour perfusion)',
                'MED03' => 'Héparine sodique (Solution injectable)',
                'MED05' => 'Midazolam (Solution injectable)',
                'MED02' => 'Noradrénaline (Solution pour perfusion)',
                'MED01' => 'Paracétamol (Solution injectable)',
            ],
            $this->dao->medications()
        );
    }

    public function testMedicationWithoutFormShowsInnAlone(): void
    {
        $this->pdo->exec(
            "INSERT INTO Medication (idMedication, inn, form) VALUES
                ('ZZMED1', 'Zzmédicament sans forme', NULL),
                ('ZZMED2', 'Zzmédicament forme blanche', '   ')"
        );

        $medications = $this->dao->medications();

        self::assertSame('Zzmédicament sans forme', $medications['ZZMED1'], 'pas de « () » vide');
        self::assertSame('Zzmédicament forme blanche', $medications['ZZMED2'], 'forme faite d\'espaces = sans forme');
    }

    public function testMedicationsByInnGroupsFormsUnderTheirInn(): void
    {
        $this->pdo->exec(
            "INSERT INTO Medication (idMedication, inn, form) VALUES
                ('ZZMED3', 'Paracétamol', 'Comprimé'),
                ('ZZMED4', 'Zzmédicament sans forme', NULL)"
        );

        $byInn = $this->dao->medicationsByInn();

        self::assertSame(
            ['ZZMED3' => 'Comprimé', 'MED01' => 'Solution injectable'],
            $byInn['Paracétamol'],
            'deux formes de la même DCI, triées par forme'
        );
        self::assertSame(['MED02' => 'Solution pour perfusion'], $byInn['Noradrénaline']);
        self::assertSame(
            ['ZZMED4' => 'Zzmédicament sans forme'],
            $byInn['Zzmédicament sans forme'],
            'sans forme : la DCI sert de libellé'
        );
        self::assertSame('Altéplase', array_key_first($byInn), 'DCI par ordre alphabétique');
        self::assertCount(9, $byInn, '8 DCI de démonstration + 1 insérée');
    }

    public function testHealthcareServicesAreSortedByName(): void
    {
        self::assertSame(
            [
                'SRV02' => 'Réanimation chirurgicale',
                'SRV01' => 'Réanimation médicale',
                'SRV03' => 'Soins continus',
            ],
            $this->dao->healthcareServices()
        );
    }

    public function testActivityDefinitionsAreSortedByName(): void
    {
        self::assertSame(
            [
                'TYP01' => 'Administration médicamenteuse',
                'TYP05' => 'Aspiration trachéale',
                'TYP03' => 'Prélèvement sanguin',
                'TYP02' => 'Prise des constantes',
                'TYP04' => 'Réfection de pansement',
                'TYP06' => 'Surveillance neurologique',
            ],
            $this->dao->activityDefinitions()
        );
    }

    public function testPractitionersAreSortedByLastNameWithRoleAndValidationRight(): void
    {
        $practitioners = $this->dao->practitioners();

        // Les 15 praticiens de database/test.sql, triés par nom puis prénom.
        self::assertSame(
            [
                'PRA02', 'PRA09', 'PRA15', 'PRA11', 'PRA03', 'PRA13', 'PRA04', 'PRA08',
                'PRA07', 'PRA12', 'PRA01', 'PRA06', 'PRA14', 'PRA05', 'PRA10',
            ],
            array_keys($practitioners)
        );
        self::assertContainsOnlyInstancesOf(Practitioner::class, $practitioners);

        $morel = $practitioners['PRA01'];
        self::assertSame('Claire', $morel->getFirstName());
        self::assertSame('Morel', $morel->getLastName());
        self::assertSame('Médecin réanimateur', $morel->getRole(), 'libellé lu dans PractitionerRole.title');
        self::assertTrue($morel->canValidate(), 'RG5 : le médecin réanimateur valide');

        self::assertSame('Interne', $practitioners['PRA03']->getRole());
        self::assertFalse($practitioners['PRA03']->canValidate(), 'RG5 : l\'interne ne valide pas');
        self::assertSame('Infirmier(e) diplômé(e) d\'État', $practitioners['PRA04']->getRole());
        self::assertFalse($practitioners['PRA06']->canValidate());
    }

    public function testPractitionerWithoutRoleHasNoRoleAndCannotValidate(): void
    {
        $this->pdo->exec(
            "INSERT INTO Practitioner (idPractitioner, idPractitionerRole, firstName, lastName) VALUES
                ('ZZPRA1', NULL, 'Zzsansrole', 'Zzpraticien')"
        );

        $practitioner = $this->dao->practitioners()['ZZPRA1'];

        self::assertNull($practitioner->getRole());
        self::assertFalse($practitioner->canValidate(), 'sans rôle, aucun droit de validation');
    }

    public function testHomonymousPractitionersAreSortedByFirstName(): void
    {
        $this->pdo->exec(
            "INSERT INTO Practitioner (idPractitioner, idPractitionerRole, firstName, lastName) VALUES
                ('ZZPRA2', 'QUA02', 'Zzbob', 'Zzhomonyme'),
                ('ZZPRA3', 'QUA02', 'Zzalice', 'Zzhomonyme')"
        );

        $ids = array_keys($this->dao->practitioners());

        self::assertLessThan(array_search('ZZPRA2', $ids, true), array_search('ZZPRA3', $ids, true));
    }

    public function testDoctorsAreOnlyPractitionersAllowedToValidate(): void
    {
        self::assertSame(
            [
                'PRA02' => 'Dr Karim Benali',
                'PRA09' => 'Dr John Carter',
                'PRA08' => 'Dr Meredith Grey',
                'PRA07' => 'Dr Gregory House',
                'PRA01' => 'Dr Claire Morel',
            ],
            $this->dao->doctors()
        );
    }

    public function testDoctorsFollowTheRoleRightNotItsTitle(): void
    {
        // Un rôle qui valide sans s'appeler « Médecin… » : c'est canValidate qui compte (RG5).
        $this->pdo->exec(
            "INSERT INTO PractitionerRole (idPractitionerRole, title, canValidate) VALUES ('ZZQUA', 'Zzchef', TRUE)"
        );
        $this->pdo->exec(
            "INSERT INTO Practitioner (idPractitioner, idPractitionerRole, firstName, lastName) VALUES
                ('ZZPRA4', 'ZZQUA', 'Zzprénom', 'Zzchef')"
        );

        self::assertSame('Dr Zzprénom Zzchef', $this->dao->doctors()['ZZPRA4']);
    }

    public function testPractitionerByIdWithRole(): void
    {
        $practitioner = $this->dao->practitioner('PRA03');

        self::assertNotNull($practitioner);
        self::assertSame('PRA03', $practitioner->getIdPractitioner());
        self::assertSame('Léa Fontaine', $practitioner->getFullName());
        self::assertSame('Interne', $practitioner->getRole());
        self::assertFalse($practitioner->canValidate());
        self::assertTrue($this->dao->practitioner('PRA02')->canValidate());
    }

    public function testUnknownPractitionerIsNull(): void
    {
        self::assertNull($this->dao->practitioner('ZZ_INCONNU'));
        self::assertNull($this->dao->practitioner(''));
    }
}
