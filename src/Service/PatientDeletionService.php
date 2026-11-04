<?php

declare(strict_types=1);

namespace Aura\Service;

use Aura\Dao\AppointmentDao;
use Aura\Dao\AuditEventDao;
use Aura\Dao\EpisodeOfCareDao;
use Aura\Dao\PatientDao;
use Aura\Dao\Transaction;
use Aura\Domain\Appointment;
use Aura\Domain\AuditEvent;
use Aura\Domain\EpisodeOfCare;
use Aura\Domain\Patient;
use InvalidArgumentException;

/**
 * Suppression d'un patient, après confirmation. Suppression logique (Patient.removedAt,
 * README, « Conventions ») : le patient disparaît de la liste et des recherches, sa ligne et ses séjours restent en
 * base. Refusée (RG10, traçabilité) si le patient a un séjour en cours ou si au moins un soin a été
 * réalisé pendant l'un de ses séjours. La règle (refusalReason()) est pure et testée sans base.
 */
final class PatientDeletionService
{
    public const REFUSED_ONGOING_EPISODE =
        'Suppression impossible : ce patient a un séjour en cours. Mettez d\'abord fin au séjour.';

    public const REFUSED_COMPLETED_CARE =
        'Suppression impossible : au moins un soin a été réalisé pendant un séjour de ce patient. '
        . 'Son dossier doit être conservé (traçabilité).';

    /** Valeur enregistrée dans l'historique (AuditEvent.newValue) pour une suppression. */
    public const AUDIT_VALUE = 'supprimé';

    /** Longueur maximale de AuditEvent.detail (VARCHAR(200)). */
    private const AUDIT_DETAIL_MAX = 200;

    public function __construct(
        private PatientDao $patientDao,
        private EpisodeOfCareDao $episodeOfCareDao,
        private AppointmentDao $appointmentDao,
        private AuditEventDao $auditEventDao,
        private Transaction $transaction
    ) {
    }

    /**
     * Règle de suppression : null si le patient peut être supprimé, sinon le message du refus.
     * Le séjour en cours est vérifié d'abord : c'est le motif que l'utilisateur peut lever lui-même.
     */
    public static function refusalReason(bool $hasOngoingEpisode, int $completedCareCount): ?string
    {
        if ($hasOngoingEpisode) {
            return self::REFUSED_ONGOING_EPISODE;
        }
        if ($completedCareCount > 0) {
            return self::REFUSED_COMPLETED_CARE;
        }

        return null;
    }

    /**
     * Vue-modèle de la page de confirmation : identité du patient, nombre de séjours et, si la
     * suppression est impossible, le motif. Null si le patient n'existe pas ou est déjà supprimé.
     *
     * @return array{identity: array{idPatient: int, lastName: string, firstName: string, ipp: ?string,
     *                                birthDate: ?string}, episodes: int, refusal: ?string}|null
     */
    public function prepare(int $idPatient): ?array
    {
        $patient = $this->patientDao->findById($idPatient);
        if ($patient === null) {
            return null;
        }
        $episodes = $this->episodeOfCareDao->findByPatient($idPatient);

        return [
            'identity' => self::presentIdentity($patient),
            'episodes' => count($episodes),
            'refusal' => $this->refusalFor($idPatient, $episodes),
        ];
    }

    /**
     * Supprime le patient (suppression logique) et trace la suppression dans l'historique, dans une
     * seule transaction. Renvoie null si c'est fait, ou le motif du refus (rien n'est alors écrit).
     *
     * @throws InvalidArgumentException si le patient n'existe pas ou est déjà supprimé
     */
    public function delete(int $idPatient): ?string
    {
        return $this->transaction->run(function () use ($idPatient): ?string {
            // Même verrou que l'admission : pas de suppression pendant qu'un séjour s'ouvre.
            $this->patientDao->lockForUpdate($idPatient);
            $patient = $this->patientDao->findById($idPatient);
            if ($patient === null) {
                throw new InvalidArgumentException(sprintf('Aucun patient ne porte l\'identifiant %d.', $idPatient));
            }

            $refusal = $this->refusalFor($idPatient, $this->episodeOfCareDao->findByPatient($idPatient));
            if ($refusal !== null) {
                return $refusal;
            }

            if (!$this->patientDao->markRemoved($idPatient)) {
                throw new InvalidArgumentException(sprintf('Le patient %d a déjà été supprimé.', $idPatient));
            }
            $this->auditEventDao->insert(new AuditEvent(
                null,
                AuditEvent::ENTITY_PATIENT,
                $idPatient,
                'removedAt',
                null,
                self::AUDIT_VALUE,
                AuditEvent::AUTHOR_UNKNOWN,
                detail: self::auditDetail($patient)
            ));

            return null;
        });
    }

    /**
     * Motif de refus pour ce patient (refusalReason()), le nombre de soins réalisés lu en base.
     *
     * @param EpisodeOfCare[] $episodes séjours du patient
     */
    private function refusalFor(int $idPatient, array $episodes): ?string
    {
        $hasOngoingEpisode = array_filter($episodes, static fn (EpisodeOfCare $e): bool => $e->isOngoing()) !== [];

        return self::refusalReason(
            $hasOngoingEpisode,
            // Inutile de compter les soins si le séjour en cours suffit déjà à refuser.
            $hasOngoingEpisode
                ? 0
                : $this->appointmentDao->countByPatientAndStatus($idPatient, Appointment::STATUS_DONE)
        );
    }

    /**
     * @return array{idPatient: int, lastName: string, firstName: string, ipp: ?string, birthDate: ?string}
     */
    private static function presentIdentity(Patient $patient): array
    {
        return [
            'idPatient' => (int) $patient->getIdPatient(),
            'lastName' => $patient->getLastName(),
            'firstName' => $patient->getFirstName(),
            'ipp' => $patient->getIpp(),
            'birthDate' => $patient->getBirthDate()?->format('d/m/Y'),
        ];
    }

    /** Précision de l'historique : qui a été supprimé (l'identité reste lisible même si la ligne change). */
    private static function auditDetail(Patient $patient): string
    {
        $detail = sprintf(
            'Suppression logique du patient %s %s (IPP %s)',
            mb_strtoupper($patient->getLastName()),
            $patient->getFirstName(),
            $patient->getIpp() ?? 'non attribué'
        );

        return mb_substr($detail, 0, self::AUDIT_DETAIL_MAX);
    }
}
