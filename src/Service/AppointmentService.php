<?php

declare(strict_types=1);

namespace Aura\Service;

use Aura\Dao\AppointmentDao;
use Aura\Dao\AuditEventDao;
use Aura\Dao\Transaction;
use Aura\Domain\Appointment;
use Aura\Domain\AuditEvent;
use DateTimeImmutable;
use LogicException;

/**
 * Actions sur un soin planifié depuis l'agenda (valider un soin réalisé, déclarer non effectué, note) :
 * « réalisé » (qui, quand, note facultative), « non effectué » (motif obligatoire), ou note seule par
 * le bouton « Note » de l'agenda (ACTION_NOTE). Réalisé ou non effectué : seulement pour un soin prévu
 * avant la fin de la rotation en cours (AgendaService::shiftEnd()). Qui agit est tracé dans AuditEvent (RG10) : la
 * table Appointment n'a pas de colonne « réalisé par », et l'historique existant suffit sans modifier
 * le schéma.
 *
 * Authentification FACTICE, comme les parties du dossier : un soignant de la liste et un mot de
 * passe non vide, non vérifié, en attendant l'identification des praticiens.
 */
final class AppointmentService
{
    public const ACTION_DONE = 'done';
    public const ACTION_NOT_DONE = 'not_done';
    public const ACTION_NOTE = 'note';
    public const ACTIONS = [self::ACTION_DONE, self::ACTION_NOT_DONE, self::ACTION_NOTE];

    /** Longueur de la colonne Appointment.comment. */
    public const COMMENT_MAX = 100;

    /** Marge tolérée pour une heure de réalisation saisie « dans le futur » (horloges décalées). */
    private const FUTURE_TOLERANCE_MINUTES = 5;

    public const STATUS_SAVED = 'saved';
    public const STATUS_INVALID = 'invalid';
    public const STATUS_AUTH = 'auth';
    public const STATUS_NOT_FOUND = 'not_found';
    public const STATUS_CONFLICT = 'conflict';

    private const AUTH_ERROR = 'Choisissez le soignant et saisissez son mot de passe.';

    /**
     * Catégorie habilitée (HYPOTHÈSE à valider avec le service, documentée dans le README) :
     * l'administration d'un médicament est un acte infirmier ou médical ; un(e) aide-soignant(e) peut
     * valider un soin, pas un médicament. Le rôle est reconnu par son libellé (PractitionerRole.title).
     */
    private const NOT_FOR_MEDICATION = ['aide-soignant'];

    /**
     * @param array<string, string> $performers soignants pouvant agir : idPractitioner => « Prénom Nom (rôle) »
     * @param array<string, ?string> $roles rôle de chaque soignant (PractitionerRole.title)
     */
    public function __construct(
        private ?AppointmentDao $appointmentDao = null,
        private ?AuditEventDao $auditEventDao = null,
        private ?Transaction $transaction = null,
        private array $performers = [],
        private array $roles = [],
    ) {
    }

    /** Catégorie habilitée : ce rôle peut-il valider ou déclarer non fait ce soin ? PURE. */
    public static function canPerform(?string $role, bool $isMedication): bool
    {
        if (!$isMedication || $role === null) {
            return true;
        }
        foreach (self::NOT_FOR_MEDICATION as $forbidden) {
            if (str_contains(mb_strtolower($role), $forbidden)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, string> soignants proposés par le dialogue : idPractitioner => libellé */
    public function performers(): array
    {
        return $this->performers;
    }

    /** Un soin peut être fait au plus tôt 8 h (une rotation) avant son heure prévue. */
    public const EARLIEST_COMPLETION_HOURS = 8;

    /**
     * Contrôle d'une demande, PUR (sans base) : action connue, soignant de la liste et mot de passe
     * (factice), heure de réalisation passée pour « réalisé », motif pour « non effectué » et note.
     *
     * @param array<string, mixed> $input action, idPerformer, password, completedAt (AAAA-MM-JJTHH:MM), comment
     * @param array<string, string> $performers
     * @return array<string, string> erreurs indexées par champ du dialogue ('auth' pour l'authentification)
     */
    public static function validate(array $input, array $performers, DateTimeImmutable $now): array
    {
        $errors = [];
        $action = $input['action'] ?? null;
        if (!is_string($action) || !in_array($action, self::ACTIONS, true)) {
            return ['action' => 'Action inconnue.'];
        }

        $idPerformer = $input['idPerformer'] ?? null;
        $password = $input['password'] ?? null;
        if (
            !is_string($idPerformer) || !isset($performers[$idPerformer])
            || !is_string($password) || trim($password) === ''
        ) {
            $errors['auth'] = self::AUTH_ERROR;
        }

        if ($action === self::ACTION_DONE) {
            $completedAt = self::parseCompletion($input['completedAt'] ?? null);
            if ($completedAt === null) {
                $errors['completedAt'] = 'Indiquez la date et l\'heure de réalisation.';
            } elseif ($completedAt > $now->modify('+' . self::FUTURE_TOLERANCE_MINUTES . ' minutes')) {
                $errors['completedAt'] = 'Un soin ne peut pas être validé à une heure à venir.';
            }
        }

        $comment = self::comment($input['comment'] ?? null);
        if ($comment !== null && mb_strlen($comment) > self::COMMENT_MAX) {
            $errors['comment'] = sprintf('La note ne doit pas dépasser %d caractères.', self::COMMENT_MAX);
        } elseif ($comment === null && $action === self::ACTION_NOT_DONE) {
            $errors['comment'] = 'Indiquez pourquoi le soin n\'a pas été effectué.';
        } elseif ($comment === null && $action === self::ACTION_NOTE) {
            $errors['comment'] = 'Saisissez la note.';
        }

        return $errors;
    }

    /**
     * Applique l'action sur un soin du séjour indiqué. Le soin doit appartenir à ce séjour, en cours ;
     * « réalisé » et « non effectué » ne s'appliquent qu'à un soin encore prévu (écriture conditionnelle :
     * si quelqu'un l'a validé entre-temps, rien n'est écrit et la réponse est un conflit), prévu avant la
     * fin de la rotation en cours, par une catégorie habilitée (canPerform()) ; sur une ligne arrêtée,
     * seule une dose faite avant l'arrêt se valide. Une note ne se modifie que par son auteur.
     *
     * @param array<string, mixed> $input voir validate()
     * @return array{status: string, errors?: array<string, string>, message?: string} status = STATUS_*
     * @throws LogicException si le service a été construit sans AppointmentDao, AuditEventDao ou Transaction
     */
    public function apply(int $idEpisodeOfCare, string $idAppointment, array $input, DateTimeImmutable $now): array
    {
        if ($this->appointmentDao === null || $this->auditEventDao === null || $this->transaction === null) {
            throw new LogicException(
                'AppointmentService::apply() requiert AppointmentDao, AuditEventDao et Transaction.'
            );
        }

        $errors = self::validate($input, $this->performers, $now);
        if (isset($errors['auth'])) {
            return ['status' => self::STATUS_AUTH, 'errors' => $errors];
        }
        if ($errors !== []) {
            return ['status' => self::STATUS_INVALID, 'errors' => $errors];
        }

        $found = $this->appointmentDao->findWithEpisode($idAppointment);
        if ($found === null || $found['idEpisodeOfCare'] !== $idEpisodeOfCare) {
            return ['status' => self::STATUS_NOT_FOUND, 'message' => 'Ce soin n\'existe pas dans ce séjour.'];
        }
        if (!$found['ongoing']) {
            return [
                'status' => self::STATUS_INVALID,
                'message' => 'Le séjour est terminé : ses soins ne se modifient plus.',
            ];
        }

        $appointment = $found['appointment'];
        $action = (string) $input['action'];
        $comment = self::comment($input['comment'] ?? null);
        $author = mb_substr($this->performers[(string) $input['idPerformer']] . ', authentification factice', 0, 100);

        if ($action === self::ACTION_NOTE) {
            // Une note se modifie par son auteur seulement (une note sans trace reste libre).
            $noteBy = $this->auditEventDao->appointmentNoteAuthors([$appointment->getIdAppointment()])
                [$appointment->getIdAppointment()] ?? null;
            if ($appointment->getComment() !== null && $noteBy !== null && !str_starts_with($noteBy, $author)) {
                return [
                    'status' => self::STATUS_INVALID,
                    'errors' => ['comment' => 'Seul l\'auteur de la note peut la modifier (' . $noteBy . ').'],
                ];
            }
            $this->transaction->run(function () use ($appointment, $comment, $author): void {
                $this->appointmentDao->updateComment($appointment->getIdAppointment(), $comment);
                $this->auditEventDao->insert(new AuditEvent(
                    null,
                    AuditEvent::ENTITY_APPOINTMENT,
                    $appointment->getIdAppointment(),
                    'comment',
                    $appointment->getComment(),
                    $comment,
                    $author
                ));
            });

            return ['status' => self::STATUS_SAVED, 'message' => 'Note enregistrée.'];
        }

        $idPerformer = (string) $input['idPerformer'];
        if (!self::canPerform($this->roles[$idPerformer] ?? null, $appointment->getIdMedicationRequest() !== null)) {
            return [
                'status' => self::STATUS_INVALID,
                'errors' => ['auth' => 'Catégorie non habilitée : un(e) aide-soignant(e) ne valide pas '
                    . 'l\'administration d\'un médicament.'],
            ];
        }
        if ($appointment->getStatus() !== Appointment::STATUS_PLANNED) {
            return [
                'status' => self::STATUS_CONFLICT,
                'message' => 'Ce soin a déjà été validé ou marqué non effectué.',
            ];
        }
        $planned = $appointment->getPlannedTime();
        // Réalisé ou non effectué : seulement pour un soin prévu avant la fin de la rotation en cours (même
        // règle que AgendaService : canValidate). Un soin fait en avance dans la rotation se valide ; celui
        // d'une rotation à venir, non. L'heure de réalisation, elle, n'est jamais dans le futur (validate()).
        $shiftEnd = AgendaService::shiftEnd($now);
        if ($planned !== null && $planned >= $shiftEnd) {
            return [
                'status' => self::STATUS_INVALID,
                'errors' => [($action === self::ACTION_DONE ? 'completedAt' : 'comment') => sprintf(
                    'Soin prévu le %s, après la fin de la rotation en cours (%s) : il se valide pendant '
                    . 'sa rotation, pas à l\'avance.',
                    $planned->format('d/m à H:i'),
                    $shiftEnd->format('d/m à H:i')
                )],
            ];
        }
        // Ligne arrêtée. Seule une dose faite AVANT l'arrêt peut encore être tracée.
        $stoppedAt = $found['lineStoppedAt'] ?? null;
        $completion = $action === self::ACTION_DONE ? self::parseCompletion($input['completedAt']) : null;
        if ($stoppedAt !== null && $completion !== null && $completion >= $stoppedAt) {
            return [
                'status' => self::STATUS_INVALID,
                'errors' => ['completedAt' => sprintf(
                    'Prescription arrêtée le %s : seul un soin fait avant l\'arrêt peut être validé.',
                    $stoppedAt->format('d/m à H:i')
                )],
            ];
        }
        // L'heure de réalisation reste proche de l'heure prévue (au plus EARLIEST_COMPLETION_HOURS avant, la
        // durée d'une rotation) : un soin « fait en 2020 » est une faute de saisie.
        if ($completion !== null && $planned !== null) {
            $earliest = DateTimeImmutable::createFromInterface($planned)
                ->modify('-' . self::EARLIEST_COMPLETION_HOURS . ' hours');
            if ($completion < $earliest) {
                return [
                    'status' => self::STATUS_INVALID,
                    'errors' => ['completedAt' => sprintf(
                        'Heure de réalisation trop éloignée de l\'heure prévue (%s) : vérifiez la saisie.',
                        $planned->format('d/m à H:i')
                    )],
                ];
            }
        }
        $status = $action === self::ACTION_DONE ? Appointment::STATUS_DONE : Appointment::STATUS_NOT_DONE;
        $completedAt = $completion;
        $id = $appointment->getIdAppointment();
        $saved = $this->transaction->run(function () use ($id, $status, $completedAt, $now, $comment, $author): bool {
            if (!$this->appointmentDao->close($id, $status, $completedAt, $now, $comment)) {
                return false;
            }
            $this->auditEventDao->insert(new AuditEvent(
                null,
                AuditEvent::ENTITY_APPOINTMENT,
                $id,
                'status',
                Appointment::STATUS_PLANNED,
                $status,
                $author,
                detail: $comment
            ));

            return true;
        });
        if (!$saved) {
            return [
                'status' => self::STATUS_CONFLICT,
                'message' => 'Ce soin a été validé entre-temps : rechargez l\'agenda.',
            ];
        }

        return [
            'status' => self::STATUS_SAVED,
            'message' => $status === Appointment::STATUS_DONE ? 'Soin validé.' : 'Soin marqué non effectué.',
        ];
    }

    /** « 2026-10-14T08:05 » (champ datetime-local) → date, ou null si absente ou invalide. */
    private static function parseCompletion(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value) !== 1) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value);

        return $date !== false && $date->format('Y-m-d\TH:i') === $value ? $date : null;
    }

    /** Note nettoyée : fins de ligne du navigateur unifiées, espaces de bord retirés ; vide = null. */
    private static function comment(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $clean = trim(str_replace("\r\n", "\n", $value));

        return $clean === '' ? null : $clean;
    }
}
